"""Exact-copy backup primitive, fixture-tested; no live attempt entry point.

The production adapter must supply approved quiescent roots and an exclusive,
private, fixed-volume attempt destination. This helper never stops processes or
modifies sources, and never retries or removes a failed destination.
"""
import hashlib
import json
import os
from pathlib import Path
import stat


class BackupStop(RuntimeError):
    pass


def file_identity(info):
    return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)


def regular(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or path.is_symlink() or getattr(info, "st_file_attributes", 0) & 0x400:
        raise BackupStop("nonregular_or_reparse_source")
    return info


def inventory(root):
    root = Path(root)
    info = root.lstat()
    if not stat.S_ISDIR(info.st_mode) or root.is_symlink() or getattr(info, "st_file_attributes", 0) & 0x400:
        raise BackupStop("invalid_source_root")
    files = {}
    directories = [root]
    while directories:
        directory = directories.pop()
        for path in sorted(directory.iterdir()):
            info = path.lstat()
            if path.is_symlink() or getattr(info, "st_file_attributes", 0) & 0x400:
                raise BackupStop("reparse_source")
            if stat.S_ISDIR(info.st_mode):
                directories.append(path)
            elif stat.S_ISREG(info.st_mode):
                files[path.relative_to(root).as_posix()] = file_identity(info)
            else:
                raise BackupStop("nonregular_source")
    return files


def capture(root, destination):
    root, destination = Path(root), Path(destination)
    before = inventory(root)
    source_absolute = root.resolve(strict=True)
    parent_absolute = destination.parent.resolve(strict=True)
    target_absolute = parent_absolute / destination.name
    if source_absolute == target_absolute or source_absolute in target_absolute.parents or target_absolute in source_absolute.parents:
        raise BackupStop("backup_source_overlap")
    # A preexisting/partial directory is a hard no-replay barrier.
    destination.mkdir(mode=0o700, exist_ok=False)
    records = {}
    for name, identity in sorted(before.items()):
        source, target = root / name, destination / name
        if file_identity(regular(source)) != identity:
            raise BackupStop("source_drift_before_copy")
        target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        hasher = hashlib.sha256()
        with source.open("rb") as reader, target.open("xb") as writer:
            if file_identity(os.fstat(reader.fileno())) != identity:
                raise BackupStop("source_handle_drift")
            while block := reader.read(1024 * 1024):
                hasher.update(block)
                writer.write(block)
            writer.flush()
            os.fsync(writer.fileno())
            if file_identity(os.fstat(reader.fileno())) != identity:
                raise BackupStop("source_drift_during_copy")
        if file_identity(regular(source)) != identity:
            raise BackupStop("source_drift_after_copy")
        check = hashlib.sha256()
        with target.open("rb") as reader:
            while block := reader.read(1024 * 1024):
                check.update(block)
        if check.digest() != hasher.digest() or target.stat().st_size != identity[2]:
            raise BackupStop("backup_verification_failed")
        records[name] = {"sha256": hasher.hexdigest(), "size": identity[2], "mtime_ns": identity[3]}
    if inventory(root) != before:
        raise BackupStop("source_tree_drift")
    # Metadata is detached; corrupt originals are copied verbatim, never parsed.
    evidence = destination.parent / (destination.name + ".manifest.json")
    with evidence.open("x", encoding="utf-8", newline="\n") as writer:
        json.dump(records, writer, sort_keys=True)
        writer.write("\n")
        writer.flush()
        os.fsync(writer.fileno())
    return records
