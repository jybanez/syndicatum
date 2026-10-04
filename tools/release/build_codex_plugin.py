#!/usr/bin/env python3
"""Build deterministic provenance artifacts for the Syndicatum Codex plugin."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import zipfile

MARKETPLACE_FILE = Path(".agents/plugins/marketplace.json")
PACKAGE_ROOT = Path("plugins/codex")
FIXED_TIME = (2000, 1, 1, 0, 0, 0)
TEXT_SUFFIXES = {".json", ".md", ".mjs", ".toml", ".txt", ".yaml", ".yml"}


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def normalized_bytes(path: Path) -> bytes:
    data = path.read_bytes()
    if path.suffix.lower() in TEXT_SUFFIXES:
        data = data.replace(b"\r\n", b"\n").replace(b"\r", b"\n")
    return data


def package_sources(repository: Path) -> list[tuple[Path, bytes]]:
    paths = [MARKETPLACE_FILE]
    paths.extend(
        path.relative_to(repository)
        for path in (repository / PACKAGE_ROOT).rglob("*")
        if path.is_file() and "__pycache__" not in path.parts
    )
    sources: list[tuple[Path, bytes]] = []
    for relative in sorted(paths, key=lambda item: item.as_posix()):
        source = repository / relative
        if source.is_symlink() or not source.is_file():
            raise ValueError(f"Package source must be a regular file: {source}")
        sources.append((relative, normalized_bytes(source)))
    return sources


def build(
    repository: Path,
    output_dir: Path,
    source_commit: str,
    source_ref: str,
) -> dict[str, object]:
    repository = repository.resolve()
    plugin_manifest = json.loads(
        (repository / PACKAGE_ROOT / ".codex-plugin/plugin.json").read_text(encoding="utf-8")
    )
    version = plugin_manifest.get("version")
    if not isinstance(version, str) or not version:
        raise ValueError("Codex plugin manifest requires a non-empty version")
    expected_ref = f"codex-v{version}"
    if source_ref != expected_ref:
        raise ValueError(f"Codex stable ref must be {expected_ref}")
    if len(source_commit) != 40 or any(character not in "0123456789abcdef" for character in source_commit.lower()):
        raise ValueError("source_commit must be a full 40-character Git commit")

    sources = package_sources(repository)
    output_dir.mkdir(parents=True, exist_ok=True)
    archive_name = f"syndicatum-codex-plugin-v{version}.zip"
    archive_path = output_dir / archive_name
    with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_STORED) as archive:
        for relative, data in sources:
            info = zipfile.ZipInfo(PurePosixPath(relative).as_posix(), FIXED_TIME)
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data)

    archive_bytes = archive_path.read_bytes()
    digest = sha256(archive_bytes)
    checksum_path = output_dir / f"{archive_name}.sha256"
    checksum_path.write_text(f"{digest}  {archive_name}\n", encoding="ascii", newline="\n")

    package_manifest = {
        "format_version": 1,
        "package": "codex@syndicatum",
        "version": version,
        "stable_ref": source_ref,
        "source_commit": source_commit.lower(),
        "archive": archive_name,
        "sha256": digest,
        "files": [
            {"path": relative.as_posix(), "size": len(data), "sha256": sha256(data)}
            for relative, data in sources
        ],
    }
    manifest_path = output_dir / f"syndicatum-codex-plugin-v{version}.manifest.json"
    manifest_path.write_text(
        json.dumps(package_manifest, indent=2, ensure_ascii=True) + "\n",
        encoding="ascii",
        newline="\n",
    )
    return package_manifest


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, default=Path.cwd())
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--source-commit", required=True)
    parser.add_argument("--source-ref", required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.repository, args.output_dir, args.source_commit, args.source_ref), indent=2))


if __name__ == "__main__":
    main()
