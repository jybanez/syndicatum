#!/usr/bin/env python3

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location(
    "build_openai_plugin", ROOT / "tools/release/build_openai_plugin.py"
)
BUILDER = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(BUILDER)


class OpenAIPluginPackageTest(unittest.TestCase):
    def test_submission_manifest_is_complete_and_bounded(self):
        manifest = json.loads((ROOT / "plugins/openai-public/plugin.json").read_text(encoding="utf-8"))
        extension = manifest["extensions"]["com.openai"]
        interface = extension["interface"]
        cases = extension["review"]["test_cases"]

        self.assertEqual(manifest["$schema"], "https://agent-plugins.org/schemas/1.0.0/plugin.schema.json")
        self.assertLessEqual(len(interface["displayName"]), 30)
        self.assertLessEqual(len(interface["shortDescription"]), 30)
        self.assertLessEqual(len(interface["longDescription"]), 4000)
        self.assertEqual(len(cases["positive"]), 5)
        self.assertEqual(len(cases["negative"]), 3)
        for case in cases["positive"]:
            self.assertTrue(case["tools_triggered"])
            self.assertTrue(case["expected_behavior"])
        for key in ("websiteURL", "supportURL", "privacyPolicyURL", "termsOfServiceURL"):
            self.assertTrue(interface[key].startswith("https://"))

        mcp = json.loads((ROOT / "plugins/openai-public/mcp.json").read_text(encoding="utf-8"))
        server = mcp["mcpServers"]["syndicatum"]
        self.assertEqual(server["type"], "streamable-http")
        self.assertEqual(server["url"], "https://syndicatum.wizaya.com/mcp")

    def test_builder_is_deterministic_and_archive_is_upload_shaped(self):
        with tempfile.TemporaryDirectory() as temporary:
            first = Path(temporary) / "first"
            second = Path(temporary) / "second"
            one = BUILDER.build(ROOT, first)
            two = BUILDER.build(ROOT, second)
            archive = one["archive"]
            self.assertEqual((first / archive).read_bytes(), (second / archive).read_bytes())
            self.assertEqual(one, two)
            with zipfile.ZipFile(first / archive) as package:
                self.assertEqual(
                    package.namelist(),
                    ["assets/syndicatum.svg", "mcp.json", "plugin.json"],
                )
                self.assertTrue(all(info.date_time == BUILDER.FIXED_TIME for info in package.infolist()))


if __name__ == "__main__":
    unittest.main()
