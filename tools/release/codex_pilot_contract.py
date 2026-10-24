"""Pure fail-closed contracts for the 0.2.10 pilot. No machine mutation adapter.

This module cannot install, stop a process, or change a registration. Its checks
are preparation for a separately reviewed Windows one-attempt implementation.
"""
from __future__ import annotations

import hashlib
import io
import json
import stat
from pathlib import PurePosixPath
import zipfile

SOURCE = "9141daa08d896f2524be30b96d01ab2a14398757"
ZIP_HASH = "331e47a6ee3cd690846ac7f2d302566658e4be195c3a734228f457f55e61feeb"
MANIFEST_HASH = "d54107333c8344ec4811d0e4d96a07c6904b69ba3747327d0c0d832f9c6c98ce"
LAUNCHER_HASH = "d44496492eb5b4f10586b0b299b83db2a9e1f87e34986c96e12c4bcfe9e94737"
CORRUPT_HASHES = {
    "b40134f5a1eb03ff2ff989fd5493047247e07d0dadaf830514c68d35d746fa73": 4761,
    "575643b90c0f71591befad5c2e4d0309b4051ecc84e556495e7dc929b26eab78": 24732,
}


class Stop(RuntimeError):
    """Unmet invariant; never implies automatic retry or rollback."""


def require(condition, reason):
    if not condition:
        raise Stop(reason)


def digest(value):
    return hashlib.sha256(value).hexdigest()


def object_pairs(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "duplicate JSON member")
        result[key] = value
    return result


def validate_entries(archive_bytes, manifest):
    """Validate in memory, never extract or follow archive paths."""
    files = manifest.get("files")
    require(isinstance(files, list) and len(files) == 48, "manifest count")
    expected = {}
    folded = set()
    for item in files:
        name = item.get("path", "")
        require(isinstance(name, str) and bool(name), "entry name")
        parts = name.split("/")
        require(not any(p in ("", ".", "..") for p in parts), "unsafe path")
        require(not any(c in name for c in ("\\", ":", "\x00")), "unsafe Windows path")
        require(not PurePosixPath(name).is_absolute(), "absolute path")
        require(all(not p.endswith((".", " ")) for p in parts), "Windows path alias")
        require(all(p.split(".")[0].upper() not in {
            "CON", "PRN", "AUX", "NUL", *[f"COM{i}" for i in range(10)],
            *[f"LPT{i}" for i in range(10)]} for p in parts), "reserved Windows path")
        require(name.casefold() not in folded, "duplicate path")
        folded.add(name.casefold())
        expected[name] = item
    with zipfile.ZipFile(io.BytesIO(archive_bytes)) as archive:
        entries = archive.infolist()
        require(len(entries) == 48, "archive count")
        require(len({e.filename.casefold() for e in entries}) == 48, "duplicate archive entry")
        require({e.filename for e in entries} == set(expected), "extra/missing archive entry")
        require(sum(e.file_size for e in entries) < 10_000_000, "oversized archive")
        for entry in entries:
            require(not entry.flag_bits & 1, "encrypted archive entry")
            require(stat.S_IFMT(entry.external_attr >> 16) == stat.S_IFREG, "non-regular archive entry")
            item = expected[entry.filename]
            require(entry.file_size == item["size"], "entry size mismatch")
            require(digest(archive.read(entry)) == item["sha256"], "entry hash mismatch")
    return tuple(expected)


def validate_artifact(archive_bytes, manifest_bytes):
    require(digest(archive_bytes) == ZIP_HASH, "archive identity mismatch")
    require(digest(manifest_bytes) == MANIFEST_HASH, "manifest identity mismatch")
    manifest = json.loads(manifest_bytes, object_pairs_hook=object_pairs)
    require(manifest.get("source_commit") == SOURCE, "source mismatch")
    require(manifest.get("version") == "0.2.10", "version mismatch")
    require(manifest.get("archive") == "syndicatum-codex-plugin-v0.2.10.zip", "archive name mismatch")
    require(manifest.get("sha256") == ZIP_HASH, "manifest archive mismatch")
    return validate_entries(archive_bytes, manifest)


def validate_run_preimage(expected, actual):
    """Private exact preimage comparison, never include values in exceptions."""
    fields = {"hive", "key", "view", "name", "kind", "raw_command", "launcher_hash", "launcher_size"}
    require(set(expected) == fields and set(actual) == fields, "registration schema mismatch")
    require(expected["hive"] == "HKCU" and expected["view"] in (32, 64), "registration boundary")
    require(all(isinstance(expected[k], str) and expected[k] for k in ("key", "name", "kind", "raw_command")), "incomplete registration")
    require(expected["launcher_hash"] == LAUNCHER_HASH and expected["launcher_size"] == 1962, "launcher mismatch")
    require(expected == actual, "registration preimage drift")


def validate_after_removal(before, after, target):
    """Snapshots are maps keyed by exact (hive,key,view,name); never normalize."""
    require(target in before, "missing target preimage")
    require(target not in after, "uncertain deletion outcome")
    require({k: v for k, v in before.items() if k != target} == after, "unrelated registration changed")


def validate_preserved_corruption(observed):
    require(observed == CORRUPT_HASHES, "malformed artifact preservation mismatch")


def require_fresh_attempt(existing_marker):
    require(existing_marker is None, "attempt already exists; preserve evidence, no replay")


def validate_quiescence(evidence):
    require(evidence == {"codex_closed": True, "task_suppressed": True,
        "approved_run_absent": True, "owned_process_absent": True,
        "background_lock_absent": True, "listener_lock_absent": True,
        "unexpected_launchers_absent": True}, "quiescence ambiguous")
