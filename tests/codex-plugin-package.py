#!/usr/bin/env python3

import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "build_codex_plugin", ROOT / "tools/release/build_codex_plugin.py"
)
BUILDER = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(BUILDER)


class CodexPluginPackageTest(unittest.TestCase):
    def test_marketplace_checkout_preserves_release_bytes(self):
        sources = BUILDER.package_sources(ROOT)
        attribute_input = "".join(f"{path.as_posix()}\n" for path, _ in sources)
        result = subprocess.run(
            ["git", "check-attr", "--stdin", "text", "eol"],
            cwd=ROOT,
            input=attribute_input.encode("utf-8"),
            capture_output=True,
            check=True,
        )
        attributes: dict[str, dict[str, str]] = {}
        for line in result.stdout.decode("utf-8").splitlines():
            path, attribute, value = line.split(": ", 2)
            attributes.setdefault(path, {})[attribute] = value

        for path, _ in sources:
            name = path.as_posix()
            values = attributes[name]
            if path.suffix.lower() in BUILDER.TEXT_SUFFIXES:
                self.assertEqual(values["text"], "set", name)
                self.assertEqual(values["eol"], "lf", name)
            else:
                self.assertEqual(values["text"], "unset", name)

        with tempfile.TemporaryDirectory() as temporary:
            fixture = Path(temporary) / "fixture"
            checkout = Path(temporary) / "checkout"
            fixture.mkdir()
            shutil.copyfile(ROOT / ".gitattributes", fixture / ".gitattributes")
            for path, data in sources:
                destination = fixture / path
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(data)
            for command in (
                ["git", "init", "--quiet"],
                ["git", "config", "user.email", "tests@syndicatum.invalid"],
                ["git", "config", "user.name", "Syndicatum Tests"],
                ["git", "config", "core.autocrlf", "true"],
                ["git", "add", "."],
                ["git", "commit", "--quiet", "-m", "fixture"],
            ):
                subprocess.run(command, cwd=fixture, check=True, capture_output=True)
            subprocess.run(
                ["git", "-c", "core.autocrlf=true", "clone", "--quiet", str(fixture), str(checkout)],
                check=True,
                capture_output=True,
            )
            for path, data in sources:
                self.assertEqual((checkout / path).read_bytes(), data, path.as_posix())

    def test_builder_is_deterministic_and_records_stable_identity(self):
        plugin = json.loads(
            (ROOT / "plugins/codex/.codex-plugin/plugin.json").read_text(encoding="utf-8")
        )
        stable_ref = f"codex-v{plugin['version']}"
        commit = "0123456789abcdef0123456789abcdef01234567"
        with tempfile.TemporaryDirectory() as temporary:
            first = Path(temporary) / "first"
            second = Path(temporary) / "second"
            one = BUILDER.build(ROOT, first, commit, stable_ref)
            two = BUILDER.build(ROOT, second, commit, stable_ref)
            archive = one["archive"]
            self.assertEqual((first / archive).read_bytes(), (second / archive).read_bytes())
            self.assertEqual(one, two)
            self.assertEqual(one["package"], "codex@syndicatum")
            self.assertEqual(one["stable_ref"], stable_ref)
            self.assertEqual(one["source_commit"], commit)
            with zipfile.ZipFile(first / archive) as package:
                names = package.namelist()
                self.assertEqual(names, sorted(names))
                self.assertIn(".agents/plugins/marketplace.json", names)
                self.assertIn("plugins/codex/.codex-plugin/plugin.json", names)
                self.assertIn("plugins/codex/.mcp.json", names)
                self.assertIn("plugins/codex/skills/syndicatum-timeline/SKILL.md", names)
                self.assertTrue(all(info.date_time == BUILDER.FIXED_TIME for info in package.infolist()))

    def test_builder_rejects_mutable_or_mismatched_refs(self):
        commit = "0123456789abcdef0123456789abcdef01234567"
        with tempfile.TemporaryDirectory() as temporary:
            for source_ref in ("main", "codex-v9.9.9"):
                with self.subTest(source_ref=source_ref):
                    with self.assertRaises(ValueError):
                        BUILDER.build(ROOT, Path(temporary), commit, source_ref)


if __name__ == "__main__":
    unittest.main()
