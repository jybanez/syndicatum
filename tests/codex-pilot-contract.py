"""Pure fixtures only: no registry, process, installed-root or backup adapters."""
import copy
import json
import io
import stat
import subprocess
import tarfile
import tempfile
import zipfile
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("pilot", Path(__file__).resolve().parents[1] / "tools/release/codex_pilot_contract.py")
pilot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pilot)


class PilotContractTest(unittest.TestCase):
    def test_exact_accepted_source_build_passes(self):
        root = Path(__file__).resolve().parents[1]
        archive = subprocess.run(["git", "archive", pilot.SOURCE], cwd=root, check=True, capture_output=True).stdout
        with tempfile.TemporaryDirectory() as temporary:
            source = Path(temporary) / "source"
            source.mkdir()
            with tarfile.open(fileobj=io.BytesIO(archive)) as tree:
                tree.extractall(source, filter="data")
            output = Path(temporary) / "output"
            subprocess.run(["python", str(source / "tools/release/build_codex_plugin.py"),
                "--repository", str(source), "--output-dir", str(output),
                "--source-commit", pilot.SOURCE, "--source-ref", "codex-v0.2.10"], check=True, capture_output=True)
            self.assertEqual(len(pilot.validate_artifact(
                (output / "syndicatum-codex-plugin-v0.2.10.zip").read_bytes(),
                (output / "syndicatum-codex-plugin-v0.2.10.manifest.json").read_bytes())), 48)

    def fixture(self, name="safe/0", mode=stat.S_IFREG | 0o644, alter=None):
        records = [{"path": f"safe/{i}", "size": 1, "sha256": pilot.digest(b"x")} for i in range(48)]
        records[0]["path"] = name
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, "w") as archive:
            for i, record in enumerate(records):
                entry = zipfile.ZipInfo(record["path"])
                entry.create_system = 3
                entry.external_attr = (mode if i == 0 else stat.S_IFREG | 0o644) << 16
                archive.writestr(entry, b"x")
        if alter:
            alter(records)
        return stream.getvalue(), {"files": records}

    def test_adversarial_paths_modes_and_inventory(self):
        good, manifest = self.fixture()
        self.assertEqual(len(pilot.validate_entries(good, manifest)), 48)
        for name in ("safe/1", "SAFE/1", "./x", "../x", "a/../x", "a\\x", "a:x", "/x", "a/", "a.", "a ", "CON", "nul.txt", "com1.log"):
            with self.subTest(name=name), self.assertRaises(pilot.Stop):
                pilot.validate_entries(*self.fixture(name))
        for mode in (stat.S_IFLNK | 0o777, stat.S_IFDIR | 0o755, stat.S_IFIFO | 0o644):
            with self.subTest(mode=mode), self.assertRaises(pilot.Stop):
                pilot.validate_entries(*self.fixture(mode=mode))
        for alter in (lambda r: r.pop(), lambda r: r.append(dict(r[0])),
                      lambda r: r[0].update(size=2), lambda r: r[0].update(sha256="0" * 64),
                      lambda r: r[0].update(path="extra/name"), lambda r: r[0].update(size=True)):
            with self.assertRaises(pilot.Stop):
                pilot.validate_entries(*self.fixture(alter=alter))

    def registration(self):
        return dict(hive="HKCU", key="fixture/Run", view=64, name="fixture spaced name",
                    kind="String", raw_command="fixture-only", launcher_hash=pilot.LAUNCHER_HASH, launcher_size=1962)

    def test_every_preimage_field_is_bound(self):
        original = self.registration()
        raw = json.dumps(original).encode()
        anchor = pilot.digest(raw)
        pilot.validate_run_preimage(raw, anchor, copy.deepcopy(original))
        for field in original:
            changed = {**original, field: "drift"}
            with self.subTest(field=field), self.assertRaises(pilot.Stop):
                pilot.validate_run_preimage(raw, anchor, changed)
            with self.subTest(anchor_field=field), self.assertRaises(pilot.Stop):
                pilot.validate_run_preimage(json.dumps(changed).encode(), anchor, changed)

    def test_unrelated_changes_and_uncertain_deletion_stop(self):
        before = {("HKCU", "Run", 64, "target"): "one", ("HKCU", "Run", 64, "other"): "two"}
        target = next(iter(before))
        pilot.validate_after_removal(before, {list(before)[1]: "two"}, target)
        for after in (before, {}, {list(before)[1]: "changed"}):
            with self.assertRaises(pilot.Stop):
                pilot.validate_after_removal(before, after, target)

    def test_artifact_mismatch_never_reaches_extraction(self):
        with self.assertRaises(pilot.Stop):
            pilot.validate_artifact(b"not approved", b"{}")

    def test_replay_forbidden_even_after_failure(self):
        pilot.require_fresh_attempt(None)
        for marker in ({}, {"failed": True}, {"completed": True}):
            with self.assertRaises(pilot.Stop):
                pilot.require_fresh_attempt(marker)

    def test_both_corrupt_files_are_required(self):
        expected = {f"private-artifact-{i}": {"sha256": h, "size": size, "mtime": "fixture-time", "route_token": f"route-{i}"}
                    for i, (h, size) in enumerate(pilot.CORRUPT_HASHES.items())}
        raw = json.dumps(expected).encode()
        anchor = pilot.digest(raw)
        pilot.validate_preserved_corruption(raw, anchor, copy.deepcopy(expected))
        with self.assertRaises(pilot.Stop):
            pilot.validate_preserved_corruption(raw, anchor, {})
        changed = copy.deepcopy(expected)
        changed["private-artifact-0"]["route_token"] = "other-route"
        with self.assertRaises(pilot.Stop):
            pilot.validate_preserved_corruption(raw, anchor, changed)
        changed = copy.deepcopy(expected)
        changed["private-artifact-0"]["mtime"] = "other-time"
        with self.assertRaises(pilot.Stop):
            pilot.validate_preserved_corruption(raw, anchor, changed)

    def test_malformed_schema_is_bounded_stop(self):
        for manifest in (None, [], {"files": [None] * 48}, {"files": [{}] * 48}, {"files": ["bad"] * 48}):
            with self.subTest(manifest=type(manifest).__name__), self.assertRaises(pilot.Stop):
                pilot.validate_entries(b"", manifest)
        for raw in (b"{", b"[]", b'{"duplicate":1,"duplicate":2}'):
            with self.assertRaises(pilot.Stop):
                pilot.anchored_json(raw, pilot.digest(raw))

    def test_quiescence_requires_every_boundary(self):
        good = {key: True for key in ("codex_closed", "task_suppressed", "approved_run_absent",
            "owned_process_absent", "background_lock_absent", "listener_lock_absent", "unexpected_launchers_absent")}
        pilot.validate_quiescence(good)
        for key in good:
            with self.subTest(key=key), self.assertRaises(pilot.Stop):
                pilot.validate_quiescence({**good, key: False})


if __name__ == "__main__":
    unittest.main()
