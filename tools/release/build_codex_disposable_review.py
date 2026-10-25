"""Build a source-only review bundle. Never invokes the bundled PowerShell runner."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile

FILES = (
    "tools/release/codex-disposable-marketplace-test.ps1",
    "docs/codex-disposable-marketplace-test-plan.md",
    "docs/codex-disposable-marketplace-handoff.md",
)


def build(root, destination):
    root = Path(root)
    revision = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
    payload = {}
    for name in FILES:
        # Compare through Git's clean filters: Windows worktrees can use CRLF.
        # Archive the committed bytes, never the filtered working-tree bytes.
        subprocess.run(["git", "diff", "--quiet", "HEAD", "--", name], cwd=root, check=True)
        committed = subprocess.check_output(["git", "show", f"{revision}:{name}"], cwd=root)
        payload[name] = committed
    manifest = {
        "schema": 1, "source_revision": revision,
        "scope": "source-review-only", "execution_authorized": False,
        "files": {name: {"sha256": hashlib.sha256(data).hexdigest(), "size": len(data)}
                  for name, data in sorted(payload.items())},
    }
    payload["review-manifest.json"] = (json.dumps(manifest, sort_keys=True, indent=2) + "\n").encode()
    # Exclusive output is a replay barrier; never replace or clean up a prior bundle.
    with zipfile.ZipFile(destination, "x", compression=zipfile.ZIP_STORED) as archive:
        for name, data in sorted(payload.items()):
            entry = zipfile.ZipInfo(name, (1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, data)
    return hashlib.sha256(Path(destination).read_bytes()).hexdigest()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("destination", type=Path)
    args = parser.parse_args()
    print(build(Path(__file__).resolve().parents[2], args.destination))
