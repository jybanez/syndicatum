#!/usr/bin/env python3
"""Contract tests for the deterministic canonical release producer."""

from __future__ import annotations

import copy
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("canonical_release", ROOT / "tools/release/canonical_release.py")
producer = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(producer)


class CanonicalReleaseProducerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.commit = os.environ.get("CANONICAL_TEST_COMMIT") or producer.git_text(ROOT, "rev-parse", "HEAD")
        cls.policy = json.loads(producer.blob(ROOT, cls.commit, "release/canonical-release-policy-v1.json"))
        cls.tree = producer.parse_tree(ROOT, cls.commit)

    def test_policy_is_exact_and_closed(self) -> None:
        shipped = producer.validate_policy(self.policy, self.tree)
        self.assertGreater(len(shipped), 100)
        destinations = [entry["path"] for entry in shipped]
        self.assertEqual(destinations, sorted(destinations, key=lambda value: value.encode("ascii")))
        self.assertFalse(any(path.startswith("app/schema/mysql84/") for path in destinations))
        self.assertIn("schema/baselines/mysql84/schema.sql", destinations)
        self.assertIn("schema/legacy-upgrade/plan.json", destinations)
        legacy_migrations = [path for path in destinations if path.startswith("schema/legacy-upgrade/migrations/")]
        self.assertEqual(len(legacy_migrations), 30)
        self.assertIn("metadata/runtime/Dockerfile", destinations)
        forbidden = ("/.env", "/tests/", "/docs/", "/runtime/avatars/")
        self.assertFalse(any(any(token in "/" + path for token in forbidden) for path in destinations))
        self.assertFalse(any(path.startswith("migrations/") for path in destinations))

    def test_missing_disposition_is_rejected(self) -> None:
        policy = copy.deepcopy(self.policy)
        policy["dispositions"].pop()
        with self.assertRaises(producer.ContractError):
            producer.validate_policy(policy, self.tree)

    def test_unknown_top_level_is_rejected(self) -> None:
        tree = dict(self.tree)
        tree["surprise/file.php"] = ("100644", "blob", "0" * 40)
        with self.assertRaises(producer.ContractError):
            producer.validate_policy(self.policy, tree)

    def test_unclassified_path_under_known_top_level_is_rejected(self) -> None:
        tree = dict(self.tree)
        tree["release/surprise.txt"] = ("100644", "blob", "0" * 40)
        with self.assertRaises(producer.ContractError):
            producer.validate_policy(self.policy, tree)

    def test_symlink_candidate_is_rejected(self) -> None:
        policy = copy.deepcopy(self.policy)
        source = policy["dispositions"][0]["source"]
        tree = dict(self.tree)
        tree[source] = ("120000", "blob", tree[source][2])
        with self.assertRaises(producer.ContractError):
            producer.validate_policy(policy, tree)

    def test_namespace_role_mismatch_is_rejected(self) -> None:
        policy = copy.deepcopy(self.policy)
        shipped = next(item for item in policy["dispositions"] if item["action"] == "ship")
        shipped["role"] = "notice"
        with self.assertRaises(producer.ContractError):
            producer.validate_policy(policy, self.tree)

    def test_casefold_collision_is_rejected(self) -> None:
        policy = copy.deepcopy(self.policy)
        shipped = [item for item in policy["dispositions"] if item["action"] == "ship"]
        shipped[1]["path"] = shipped[0]["path"].upper()
        with self.assertRaises(producer.ContractError):
            producer.validate_policy(policy, self.tree)

    def test_unsafe_destination_is_rejected(self) -> None:
        policy = copy.deepcopy(self.policy)
        shipped = next(item for item in policy["dispositions"] if item["action"] == "ship")
        shipped["path"] = "app/CON.txt"
        with self.assertRaises(producer.ContractError):
            producer.validate_policy(policy, self.tree)

    def test_build_is_byte_identical_and_zip_metadata_is_normalized(self) -> None:
        with tempfile.TemporaryDirectory() as first, tempfile.TemporaryDirectory() as second:
            base = {
                "repository": str(ROOT), "commit": self.commit, "tag": "candidate-v1.0.0",
                "policy": "release/canonical-release-policy-v1.json", "canonical": False,
            }
            one = producer.build(type("Args", (), {**base, "output_dir": first})())
            two = producer.build(type("Args", (), {**base, "output_dir": second})())
            for name in ("archive", "manifest", "checksum", "provenance"):
                self.assertEqual(Path(one[name]).read_bytes(), Path(two[name]).read_bytes())
            manifest = json.loads(Path(one["manifest"]).read_text(encoding="ascii"))
            packaged = {entry["path"]: entry for entry in manifest["files"]}
            self.assertIn("schema/legacy-upgrade/plan.json", packaged)
            self.assertEqual(len([path for path in packaged if path.startswith("schema/legacy-upgrade/migrations/")]), 30)
            self.assertFalse(manifest["contains_data"])
            self.assertFalse(manifest["contains_persistent_assets"])
            self.assertEqual(manifest["source_commit"], self.commit)
            with zipfile.ZipFile(one["archive"], "r") as archive:
                plan_path = "schema/legacy-upgrade/plan.json"
                plan_blob = producer.blob(ROOT, self.commit, "schema/mysql84/legacy-upgrade-plan.json")
                self.assertEqual(archive.read(plan_path), plan_blob)
                self.assertEqual(packaged[plan_path]["sha256"], producer.sha256(plan_blob))
                infos = archive.infolist()
                self.assertEqual([item.filename for item in infos], [item["path"] for item in manifest["files"]])
                for info in infos:
                    self.assertEqual(info.date_time, (1980, 1, 1, 0, 0, 0))
                    self.assertEqual(info.create_system, 3)
                    self.assertEqual(info.compress_type, zipfile.ZIP_STORED)
                    self.assertEqual(info.extra, b"")
                    self.assertEqual(info.comment, b"")


if __name__ == "__main__":
    unittest.main()
