#!/usr/bin/env python3

import importlib.util
import json
from pathlib import Path
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
