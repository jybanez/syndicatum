#!/usr/bin/env python3
"""Build the Syndicatum V1 release archive from immutable Git objects."""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys
import zipfile

PRODUCER_VERSION = "1.0.0"
CAPABILITIES = ["canonical-inventory-jsonl-v1", "regular-files-only-v1", "sha256-v1"]
SAFE_PATH = re.compile(r"^[\x20-\x7e]+$")
HEX40 = re.compile(r"^[0-9a-f]{40}$")


class ContractError(RuntimeError):
    pass


def git(repo: Path, *args: str, input_bytes: bytes | None = None) -> bytes:
    proc = subprocess.run(
        ["git", "-C", str(repo), *args], input=input_bytes, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, check=False,
    )
    if proc.returncode:
        raise ContractError(proc.stderr.decode("utf-8", "replace").strip() or "Git command failed")
    return proc.stdout


def git_text(repo: Path, *args: str) -> str:
    return git(repo, *args).decode("utf-8").strip()


def blob(repo: Path, commit: str, path: str) -> bytes:
    return git(repo, "show", f"{commit}:{path}")


def json_bytes(value: object) -> bytes:
    return (json.dumps(value, ensure_ascii=True, separators=(",", ":"), sort_keys=False) + "\n").encode("ascii")


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def safe_package_path(path: str) -> None:
    if (not path or len(path) > 1024 or not SAFE_PATH.fullmatch(path) or "\\" in path
            or ":" in path or re.search(r'[<>"|?*]', path)):
        raise ContractError(f"Unsafe package path: {path!r}")
    parts = path.split("/")
    reserved = {"con", "prn", "aux", "nul", *(f"com{i}" for i in range(1, 10)), *(f"lpt{i}" for i in range(1, 10))}
    for part in parts:
        base = part.split(".", 1)[0].lower()
        if not part or len(part) > 255 or part in {".", ".."} or part.endswith((".", " ")) or base in reserved:
            raise ContractError(f"Unsafe package path: {path!r}")
    if str(PurePosixPath(path)) != path:
        raise ContractError(f"Non-canonical package path: {path!r}")


def parse_tree(repo: Path, commit: str) -> dict[str, tuple[str, str, str]]:
    raw = git(repo, "ls-tree", "-r", "-z", "--full-tree", commit)
    result: dict[str, tuple[str, str, str]] = {}
    for record in raw.split(b"\0"):
        if not record:
            continue
        metadata, raw_path = record.split(b"\t", 1)
        mode, kind, object_id = metadata.decode("ascii").split(" ")
        path = raw_path.decode("utf-8")
        result[path] = (mode, kind, object_id)
    return result


def read_blobs(repo: Path, object_ids: list[str]) -> dict[str, bytes]:
    """Read immutable blobs through one batch process (important on Windows CI/dev)."""
    proc = subprocess.Popen(
        ["git", "-C", str(repo), "cat-file", "--batch"],
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    request = ("\n".join(object_ids) + "\n").encode("ascii")
    stdout, stderr = proc.communicate(request)
    if proc.returncode:
        raise ContractError(stderr.decode("utf-8", "replace").strip() or "Git batch blob read failed")
    result: dict[str, bytes] = {}
    offset = 0
    for expected in object_ids:
        line_end = stdout.find(b"\n", offset)
        if line_end < 0:
            raise ContractError("Git batch blob response ended before its header")
        header = stdout[offset:line_end].decode("ascii").split(" ")
        if len(header) != 3 or header[0] != expected or header[1] != "blob":
            raise ContractError(f"Git object is not the expected immutable blob: {expected}")
        size = int(header[2])
        start = line_end + 1
        end = start + size
        if stdout[end:end + 1] != b"\n":
            raise ContractError("Git batch blob response has invalid framing")
        result[expected] = stdout[start:end]
        offset = end + 1
    if offset != len(stdout):
        raise ContractError("Git batch blob response contains trailing data")
    return result


def under(path: str, root: str) -> bool:
    return path == root or path.startswith(root.rstrip("/") + "/")


def canonical_inventory_bytes(files: list[dict[str, object]]) -> bytes:
    records = []
    for entry in files:
        records.append(json.dumps({
            "path": entry["path"], "type": "file", "role": entry["role"],
            "mode": format(int(entry["mode"]), "04o"), "size": entry["size"],
            "sha256": entry["sha256"],
        }, ensure_ascii=True, separators=(",", ":"), sort_keys=False))
    return ("\n".join(records) + "\n").encode("ascii")


def validate_policy(policy: dict[str, object], tree: dict[str, tuple[str, str, str]]) -> list[dict[str, object]]:
    allowed_keys = {
        "contract_name", "format_version", "producer_version", "application_version",
        "candidate_roots", "excluded_roots", "allowed_top_level", "compatibility",
        "minimum_reader_version", "supported_upgrade_sources", "baseline", "sidecars",
        "dispositions",
    }
    if set(policy) != allowed_keys or policy.get("contract_name") != "syndicatum-canonical-release-policy":
        raise ContractError("Release policy shape or contract name is invalid")
    if policy.get("format_version") != "1.0" or policy.get("producer_version") != PRODUCER_VERSION:
        raise ContractError("Release policy version is unsupported")
    roots = policy["candidate_roots"]
    excluded_roots = policy["excluded_roots"]
    if not isinstance(roots, list) or not roots or not isinstance(excluded_roots, list):
        raise ContractError("Candidate and excluded roots must be lists")
    top_levels = {path.split("/", 1)[0] for path in tree}
    if top_levels != set(policy["allowed_top_level"]):
        raise ContractError("Repository top-level inventory is not closed by the release policy")
    if any(any(under(path, root) for root in excluded_roots) for path in tree if any(under(path, root) for root in roots)):
        raise ContractError("Candidate and excluded roots overlap")
    unclassified = [
        path for path in tree
        if not any(under(path, root) for root in roots)
        and not any(under(path, root) for root in excluded_roots)
    ]
    if unclassified:
        raise ContractError(f"Repository paths are absent from the closed ship/exclude policy: {unclassified[:5]}")

    dispositions = policy["dispositions"]
    if not isinstance(dispositions, list) or not dispositions:
        raise ContractError("Release policy dispositions are required")
    by_source: dict[str, dict[str, object]] = {}
    for item in dispositions:
        if not isinstance(item, dict) or set(item) != {"source", "action", "path", "role", "mode"}:
            raise ContractError("Every disposition must use the frozen source/action/path/role/mode shape")
        source = item["source"]
        if not isinstance(source, str) or source in by_source:
            raise ContractError("Disposition sources must be unique strings")
        if item["action"] not in {"ship", "exclude"}:
            raise ContractError(f"Unknown disposition action for {source}")
        by_source[source] = item

    candidate_files = {path for path in tree if any(under(path, root) for root in roots)}
    if candidate_files != set(by_source):
        missing = sorted(candidate_files - set(by_source))
        stale = sorted(set(by_source) - candidate_files)
        raise ContractError(f"Release policy is not exact (missing={missing[:5]}, stale={stale[:5]})")

    shipped: list[dict[str, object]] = []
    seen_dest: dict[str, str] = {}
    for source in sorted(by_source, key=lambda value: value.encode("utf-8")):
        item = by_source[source]
        mode, kind, object_id = tree[source]
        if kind != "blob" or mode in {"120000", "160000"}:
            raise ContractError(f"Non-regular candidate entry rejected: {source}")
        if item["action"] == "exclude":
            if item["path"] or item["role"] or item["mode"] != 0:
                raise ContractError(f"Excluded disposition must have empty output fields: {source}")
            continue
        destination = item["path"]
        role = item["role"]
        package_mode = item["mode"]
        if not isinstance(destination, str) or not isinstance(role, str) or package_mode not in {0o644, 0o755}:
            raise ContractError(f"Invalid shipped disposition: {source}")
        safe_package_path(destination)
        folded = destination.lower()
        if folded in seen_dest:
            raise ContractError(f"Case-folded destination collision: {destination}")
        for prior in seen_dest:
            if folded.startswith(prior + "/") or prior.startswith(folded + "/"):
                raise ContractError(f"Ancestor destination collision: {destination}")
        seen_dest[folded] = destination
        expected = next((r for prefix, r in [
            ("app/", "application"), ("schema/baselines/", "schema_baseline"),
            ("schema/legacy-upgrade/", "legacy_upgrade"),
            ("schema/post-baseline/", "post_baseline_migration"), ("metadata/", "package_metadata"),
            ("notices/", "notice"), ("plugins/", "plugin"), ("skills/", "skill"),
        ] if destination.startswith(prefix)), None)
        if role != expected:
            raise ContractError(f"Role does not match package namespace: {destination}")
        shipped.append({"source": source, "path": destination, "role": role, "mode": package_mode, "object": object_id})
    shipped.sort(key=lambda item: str(item["path"]).encode("ascii"))
    return shipped


def require_canonical_authority(repo: Path, commit: str, tag: str, policy_path: str) -> None:
    if os.name != "posix" or os.environ.get("CI") != "true" or os.environ.get("GITHUB_ACTIONS") != "true":
        raise ContractError("Canonical publication is restricted to Linux GitHub Actions CI")
    if os.environ.get("GITHUB_EVENT_NAME") != "push" or os.environ.get("GITHUB_REF_TYPE") != "tag":
        raise ContractError("Canonical publication requires a protected tag push")
    if os.environ.get("GITHUB_REF_NAME") != tag or os.environ.get("GITHUB_SHA") != commit:
        raise ContractError("CI tag/commit identity mismatch")
    if git_text(repo, "rev-parse", "HEAD") != commit or not HEX40.fullmatch(commit):
        raise ContractError("Canonical source commit must be the exact full HEAD")
    if git_text(repo, "cat-file", "-t", f"refs/tags/{tag}") != "tag":
        raise ContractError("Canonical release tag must be annotated")
    if git_text(repo, "rev-list", "-n", "1", f"refs/tags/{tag}") != commit:
        raise ContractError("Annotated release tag does not resolve to the source commit")
    subprocess.run(["git", "-C", str(repo), "merge-base", "--is-ancestor", commit, "origin/main"], check=True)
    if git(repo, "status", "--porcelain=v1", "--untracked-files=all"):
        raise ContractError("Canonical publication requires a clean tracked/untracked worktree")
    relative_self = Path(__file__).resolve().relative_to(repo.resolve()).as_posix()
    if Path(__file__).read_bytes() != blob(repo, commit, relative_self):
        raise ContractError("Checked-out producer differs from the immutable Git blob")
    if (repo / policy_path).read_bytes() != blob(repo, commit, policy_path):
        raise ContractError("Checked-out release policy differs from the immutable Git blob")


def build(args: argparse.Namespace) -> dict[str, str]:
    repo = Path(args.repository).resolve()
    commit = args.commit or os.environ.get("GITHUB_SHA") or git_text(repo, "rev-parse", "HEAD")
    commit = git_text(repo, "rev-parse", f"{commit}^{{commit}}")
    if not HEX40.fullmatch(commit):
        raise ContractError("Source commit must resolve to a full SHA-1 commit")
    tag = args.tag
    if args.canonical:
        require_canonical_authority(repo, commit, tag, args.policy)
    policy_raw = blob(repo, commit, args.policy)
    policy = json.loads(policy_raw.decode("utf-8"))
    tree = parse_tree(repo, commit)
    shipped = validate_policy(policy, tree)

    baseline_policy = policy["baseline"]
    baseline_meta_raw = blob(repo, commit, baseline_policy["metadata_source"])
    baseline_schema = blob(repo, commit, baseline_policy["schema_source"])
    baseline_meta = json.loads(baseline_meta_raw.decode("utf-8"))
    checks = {
        "baseline_id": baseline_meta["baseline_id"], "schema_head": baseline_meta["schema_head"],
        "source_commit": baseline_meta["source_commit"], "schema_sha256": sha256(baseline_schema),
    }
    for field, value in checks.items():
        if baseline_policy[field] != value:
            raise ContractError(f"Baseline policy mismatch: {field}")

    files: list[dict[str, object]] = []
    payload: list[tuple[str, bytes, int]] = []
    immutable_blobs = read_blobs(repo, [str(item["object"]) for item in shipped])
    for item in shipped:
        data = immutable_blobs[str(item["object"])]
        entry = {
            "path": item["path"], "type": "file", "role": item["role"],
            "mode": item["mode"], "size": len(data), "sha256": sha256(data),
        }
        files.append(entry)
        payload.append((str(item["path"]), data, int(item["mode"])))

    commit_time = git_text(repo, "show", "-s", "--format=%cI", commit)
    timestamp = dt.datetime.fromisoformat(commit_time).astimezone(dt.timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")
    sidecars = policy["sidecars"]
    manifest = {
        "contract_name": "syndicatum-package", "format_version": "1.0", "package_kind": "release",
        "required_capabilities": CAPABILITIES, "application_version": policy["application_version"],
        "source_commit": commit, "source_tag": tag, "schema_baseline": baseline_meta["baseline_id"],
        "schema_head": baseline_meta["schema_head"], "source_timestamp": timestamp,
        "compatibility": policy["compatibility"], "minimum_reader_version": policy["minimum_reader_version"],
        "supported_upgrade_sources": policy["supported_upgrade_sources"], "contains_data": False,
        "contains_persistent_assets": False, "files": files, "digest_algorithm": "sha256",
        "content_tree_sha256": sha256(canonical_inventory_bytes(files)),
        "detached_checksum_reference": sidecars["checksum"], "provenance_reference": sidecars["provenance"],
    }
    manifest_raw = json_bytes(manifest)
    output = Path(args.output_dir).resolve()
    output.mkdir(parents=True, exist_ok=True)
    archive_path = output / sidecars["archive"]
    with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_STORED, allowZip64=True) as archive:
        archive.comment = b""
        for path, data, mode in payload:
            info = zipfile.ZipInfo(path, (1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.compress_type = zipfile.ZIP_STORED
            info.external_attr = ((0o100000 | mode) & 0xFFFF) << 16
            info.extra = b""
            info.comment = b""
            archive.writestr(info, data)
    archive_raw = archive_path.read_bytes()
    archive_hash = sha256(archive_raw)
    manifest_path = output / sidecars["manifest"]
    manifest_path.write_bytes(manifest_raw)
    checksum_path = output / sidecars["checksum"]
    checksum_path.write_text(f"{archive_hash}  {sidecars['archive']}\n", encoding="ascii", newline="\n")
    provenance = {
        "contract_name": "syndicatum-release-provenance", "format_version": "1.0",
        "producer_version": PRODUCER_VERSION, "repository": os.environ.get("GITHUB_REPOSITORY", args.repository),
        "source_commit": commit, "source_tag": tag, "archive_sha256": archive_hash,
        "manifest_sha256": sha256(manifest_raw), "content_tree_sha256": manifest["content_tree_sha256"],
        "baseline_id": baseline_meta["baseline_id"], "schema_head": baseline_meta["schema_head"],
        "baseline_schema_sha256": baseline_meta["schema_sha256"],
        "baseline_source_commit": baseline_meta["source_commit"],
        "workflow_ref": os.environ.get("GITHUB_WORKFLOW_REF", "candidate"),
        "workflow_sha": os.environ.get("GITHUB_WORKFLOW_SHA", commit),
        "run_id": os.environ.get("GITHUB_RUN_ID", "candidate"),
        "run_attempt": os.environ.get("GITHUB_RUN_ATTEMPT", "1"),
        "toolchain": f"python-{sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}-zip-stored",
        "canonical": bool(args.canonical),
    }
    provenance_path = output / sidecars["provenance"]
    provenance_path.write_bytes(json_bytes(provenance))
    return {"archive": str(archive_path), "archive_sha256": archive_hash,
            "manifest": str(manifest_path), "manifest_sha256": sha256(manifest_raw),
            "checksum": str(checksum_path), "provenance": str(provenance_path)}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", default=".")
    parser.add_argument("--commit")
    parser.add_argument("--tag", required=True)
    parser.add_argument("--policy", default="release/canonical-release-policy-v1.json")
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--canonical", action="store_true")
    args = parser.parse_args()
    try:
        result = build(args)
    except (ContractError, KeyError, ValueError, json.JSONDecodeError, subprocess.CalledProcessError) as exc:
        print(f"canonical release producer rejected input: {exc}", file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
