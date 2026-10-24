"""Pure fixtures only: no registry, process, installed-root or backup adapters."""
import copy
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("pilot", Path(__file__).resolve().parents[1] / "tools/release/codex_pilot_contract.py")
pilot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pilot)


class PilotContractTest(unittest.TestCase):
    def registration(self):
        return dict(hive="HKCU", key="fixture/Run", view=64, name="fixture spaced name",
                    kind="String", raw_command="fixture-only", launcher_hash=pilot.LAUNCHER_HASH, launcher_size=1962)

    def test_every_preimage_field_is_bound(self):
        original = self.registration()
        pilot.validate_run_preimage(original, copy.deepcopy(original))
        for field in original:
            changed = {**original, field: "drift"}
            with self.subTest(field=field), self.assertRaises(pilot.Stop):
                pilot.validate_run_preimage(original, changed)

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
        pilot.validate_preserved_corruption(dict(pilot.CORRUPT_HASHES))
        with self.assertRaises(pilot.Stop):
            pilot.validate_preserved_corruption({})

    def test_quiescence_requires_every_boundary(self):
        good = {key: True for key in ("codex_closed", "task_suppressed", "approved_run_absent",
            "owned_process_absent", "background_lock_absent", "listener_lock_absent", "unexpected_launchers_absent")}
        pilot.validate_quiescence(good)
        for key in good:
            with self.subTest(key=key), self.assertRaises(pilot.Stop):
                pilot.validate_quiescence({**good, key: False})


if __name__ == "__main__":
    unittest.main()
