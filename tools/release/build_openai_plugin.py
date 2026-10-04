#!/usr/bin/env python3
"""Build the deterministic Syndicatum public OpenAI plugin review archive."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import zipfile

PACKAGE_ROOT = Path("plugins/openai-public")
PACKAGE_FILES = (
    Path("plugin.json"),
    Path("mcp.json"),
    Path("assets/syndicatum.svg"),
)
FIXED_TIME = (2000, 1, 1, 0, 0, 0)


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def build(repository: Path, output_dir: Path) -> dict[str, object]:
    repository = repository.resolve()
    package_root = repository / PACKAGE_ROOT
    manifest = json.loads((package_root / "plugin.json").read_text(encoding="utf-8"))
    version = manifest.get("version")
    if not isinstance(version, str) or not version:
        raise ValueError("plugin.json requires a non-empty version")

    sources: list[tuple[Path, bytes]] = []
    for relative in PACKAGE_FILES:
        source = package_root / relative
        if not source.is_file():
            raise FileNotFoundError(f"Missing package file: {source}")
        data = source.read_bytes()
        if source.suffix.lower() in {".json", ".svg"}:
            data = data.replace(b"\r\n", b"\n").replace(b"\r", b"\n")
        sources.append((relative, data))

    output_dir.mkdir(parents=True, exist_ok=True)
    archive_name = f"syndicatum-openai-plugin-v{version}.zip"
    archive_path = output_dir / archive_name
    with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_STORED) as archive:
        for relative, data in sorted(sources, key=lambda item: item[0].as_posix()):
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
        "package": manifest["name"],
        "version": version,
        "archive": archive_name,
        "sha256": digest,
        "files": [
            {"path": relative.as_posix(), "size": len(data), "sha256": sha256(data)}
            for relative, data in sorted(sources, key=lambda item: item[0].as_posix())
        ],
    }
    package_manifest_path = output_dir / f"syndicatum-openai-plugin-v{version}.manifest.json"
    package_manifest_path.write_text(
        json.dumps(package_manifest, indent=2, ensure_ascii=True) + "\n",
        encoding="ascii",
        newline="\n",
    )
    return package_manifest


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", type=Path, default=Path.cwd())
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.repository, args.output_dir), indent=2))


if __name__ == "__main__":
    main()
