"""Pure fixtures only: no registry, process, installed-root or backup adapters."""
import copy
import json
import io
import stat
import subprocess
import tarfile
import tempfile
import zipfile
import struct
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("pilot", Path(__file__).resolve().parents[1] / "tools/release/codex_pilot_contract.py")
pilot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pilot)
sequence_spec = importlib.util.spec_from_file_location("pilot_sequence", Path(__file__).resolve().parents[1] / "tools/release/codex_pilot_sequence.py")
sequence_module = importlib.util.module_from_spec(sequence_spec)
import sys
sys.modules[sequence_spec.name] = sequence_module
sequence_spec.loader.exec_module(sequence_module)
backup_spec = importlib.util.spec_from_file_location("pilot_backup", Path(__file__).resolve().parents[1] / "tools/release/codex_pilot_backup.py")
backup_module = importlib.util.module_from_spec(backup_spec)
backup_spec.loader.exec_module(backup_module)
journal_spec = importlib.util.spec_from_file_location("pilot_journal", Path(__file__).resolve().parents[1] / "tools/release/codex_pilot_journal.py")
journal_module = importlib.util.module_from_spec(journal_spec)
journal_spec.loader.exec_module(journal_module)
mapping_spec = importlib.util.spec_from_file_location("pilot_native_mapping", Path(__file__).resolve().parents[1] / "tools/release/codex_pilot_native_mapping.py")
mapping_module = importlib.util.module_from_spec(mapping_spec)
mapping_spec.loader.exec_module(mapping_module)


class PilotContractTest(unittest.TestCase):
    def test_marketplace_identity_drift_is_a_separate_gate(self):
        original = dict(pilot.MARKETPLACE_PREIMAGE)
        pilot.validate_marketplace_preimage(original)
        for key in original:
            with self.subTest(key=key), self.assertRaises(pilot.Stop):
                pilot.validate_marketplace_preimage({**original, key: "drift"})
        with self.assertRaises(pilot.Stop):
            pilot.validate_marketplace_preimage({**original, "enabled": 1})
    def test_remaining_native_mapping_classes(self):
        key = b"fixture-only-private-key-32bytes!!"
        health = dict(pid=101, updatedAt="2026-10-25T00:00:00Z", state="degraded", role="background",
            bindings=2, projects=1, unavailableBindings=2, requestedBindings=4, initializedBindings=2, unavailableBindingReasons=[])
        mapped = mapping_module.map_health(health)
        journal_module.validate_payload("health", mapped)
        self.assertEqual(mapped["readiness"], "degraded")
        for value in ({**health, "extra": 1}, {**health, "pid": True}, {**health, "updatedAt": "bad"}, {**health, "state": "error"}):
            with self.assertRaises(mapping_module.MappingStop):
                mapping_module.map_health(value)
        files = [dict(private_identity="private-file", sha256="a" * 64, size=4, mtime_ns=100)]
        mapped = mapping_module.map_files(files, key)
        journal_module.validate_payload("installed_files", mapped)
        self.assertNotIn("private-file", json.dumps(mapped))
        with self.assertRaises(mapping_module.MappingStop):
            mapping_module.map_files(files * 2, key)
        route = dict(private_route_identity="private-route", reason="binding_initialization_failed", delivered=0, acknowledged=0, history_recovered=False)
        mapped = mapping_module.map_route_outcomes([route], key)
        journal_module.validate_payload("routes", mapped)
        with self.assertRaises(mapping_module.MappingStop):
            mapping_module.map_route_outcomes([{**route, "delivered": True}], key)
    def test_native_mapping_rejects_extra_partial_and_native_type_drift(self):
        key = b"fixture-only-private-key-32bytes!!"
        locks = {k: dict(present=True, raw="101\n", size=4, mtime="fixture-time") for k in ("background", "listener")}
        typed = mapping_module.map_locks(locks)
        journal_module.validate_payload("locks", typed)
        for bad in ({}, {**locks, "extra": {}}, {**locks, "listener": {"present": "false"}},
                    {**locks, "listener": dict(present=True, raw="101 junk", size=8, mtime="fixture-time")}):
            with self.assertRaises(mapping_module.MappingStop):
                mapping_module.map_locks(bad)
        raw = [dict(pid=100, parent_pid=1, executable="fixture-shell", command_line="private", started="parent-time"),
               dict(pid=101, parent_pid=100, executable="fixture-node", command_line="private", started="start-time")]
        mapped = mapping_module.map_processes(raw, key, "fixture-node")
        journal_module.validate_payload("processes", mapped)
        self.assertEqual(mapped[1]["role"], "listener")
        self.assertNotIn("private", json.dumps(mapped))
        with self.assertRaises(mapping_module.MappingStop):
            mapping_module.map_processes([raw[1]], key, "fixture-node")
        with self.assertRaises(mapping_module.MappingStop):
            mapping_module.map_processes([{**raw[0], "extra": True}], key, "fixture-node")
        run = dict(hive="HKCU", key="fixture", view="Registry64", name="private name", kind="String", raw_command="private command")
        mapped = mapping_module.map_registrations(None, [run], key)
        journal_module.validate_payload("registrations", mapped)
        self.assertNotIn("private command", json.dumps(mapped))
        with self.assertRaises(mapping_module.MappingStop):
            mapping_module.map_registrations(None, [run, run], key)

    def test_retained_head_chain_and_partial_head_failure(self):
        with tempfile.TemporaryDirectory() as temporary:
            journal = journal_module.Journal(temporary, "fixture-attempt", "a" * 64)
            journal.append("health", journal_module.PRODUCERS["health"], dict(pid=101, timestamp_ms=100, readiness="degraded", unavailable_count=2), 100)
            head = Path(temporary) / "journal-heads/000000.json"
            anchor = pilot.digest(head.read_bytes())
            self.assertEqual(len(journal_module.verify_retained_chain(temporary, "fixture-attempt", "a" * 64, 0, anchor, 101, 10)), 64)
            with self.assertRaises(journal_module.EvidenceStop):
                journal_module.verify_retained_chain(temporary, "fixture-attempt", "a" * 64, 0, "b" * 64, 101, 10)
            # Simulate a failed next head creation, retaining the previous head.
            (Path(temporary) / "journal-heads/000001.json").mkdir()
            with self.assertRaises(OSError):
                journal.append("health", journal_module.PRODUCERS["health"], dict(pid=101, timestamp_ms=101, readiness="degraded", unavailable_count=2), 101)
            with self.assertRaises(FileExistsError):
                journal_module.Journal(temporary, "fixture-attempt", "a" * 64)
            self.assertEqual(pilot.digest(head.read_bytes()), anchor)
            with self.assertRaises(journal_module.EvidenceStop):
                journal_module.verify_retained_chain(temporary, "fixture-attempt", "a" * 64, 0, anchor, 101, 10)
    def test_evidence_journal_rejects_stale_mixed_and_reused_collectors(self):
        with tempfile.TemporaryDirectory() as temporary:
            journal = journal_module.Journal(temporary, "fixture-attempt", "a" * 64)
            item = dict(pid=101, timestamp_ms=100, readiness="degraded", unavailable_count=2)
            record = journal.append("health", journal_module.PRODUCERS["health"], item, 100)
            self.assertEqual(len(journal_module.verify_record(record, "fixture-attempt", "a" * 64, "0" * 64, 0, 101, 10)), 64)
            for args in (("other-attempt", "a" * 64, 101), ("fixture-attempt", "b" * 64, 101), ("fixture-attempt", "a" * 64, 1000)):
                with self.assertRaises(journal_module.EvidenceStop):
                    journal_module.verify_record(record, args[0], args[1], "0" * 64, 0, args[2], 10)
            callback = lambda: item
            with self.assertRaisesRegex(journal_module.EvidenceStop, "collector_reuse"):
                journal_module.collect_independent(journal, {k: callback for k in journal_module.PRODUCERS}, 100)
            with self.assertRaises(FileExistsError):
                journal_module.Journal(temporary, "fixture-attempt", "a" * 64)
            self.assertEqual(len(list((Path(temporary) / "journal").iterdir())), 1)

    def test_collector_and_journal_failures_are_distinct(self):
        values = {
            "installed_files": {"fixture": dict(sha256="a" * 64, size=1, mtime_ns=1)},
            "registrations": dict(task_present=False, task_xml_sha256="", run_values={}),
            "processes": [],
            "locks": {k: dict(present=False, owner_pid=0, sha256="") for k in ("background", "listener")},
            "health": dict(pid=101, timestamp_ms=100, readiness="degraded", unavailable_count=2),
            "routes": {"A": dict(reason="binding_initialization_failed", delivered=0, acknowledged=0, history_recovered=False)},
        }
        collectors = {k: (lambda value=value: value) for k, value in values.items()}
        class BrokenJournal:
            def append(self, *args):
                raise OSError("private failure detail must not escape")
        with self.assertRaisesRegex(journal_module.EvidenceStop, "journal_write_failed_installed_files"):
            journal_module.collect_independent(BrokenJournal(), collectors, 100)
        def failed():
            raise OSError("private collector detail must not escape")
        with self.assertRaisesRegex(journal_module.EvidenceStop, "collector_failed_installed_files"):
            journal_module.collect_independent(BrokenJournal(), {**collectors, "installed_files": failed}, 100)
        with tempfile.TemporaryDirectory() as temporary:
            journal = journal_module.Journal(temporary, "fixture-attempt", "a" * 64)
            journal_module.collect_independent(journal, collectors, 100)
            self.assertEqual(journal.sequence, 6)
    def test_post_start_evidence_requires_degraded_preservation_and_lifecycle(self):
        def verify(plan, evidence):
            raw_plan = json.dumps({"attempt_id": "fixture-attempt", "plan": plan}).encode()
            plan_hash = pilot.digest(raw_plan)
            raw_evidence = json.dumps({"attempt_id": "fixture-attempt", "plan_sha256": plan_hash, "observed": evidence}).encode()
            return sequence_module.verify_acceptance(raw_plan, plan_hash, raw_evidence, pilot.digest(raw_evidence))
        plan = dict(candidate_files={"fixture": "hash"}, unrelated_run_values={"other": "hash"},
            malformed_artifacts={"A": "hash-A", "B": "hash-B"}, protected_identity_hashes={"identity": "hash"},
            malformed_route_tokens=["A", "B"], max_health_age_ms=1000, shutdown_bound_ms=2000)
        evidence = dict(installed_files=plan["candidate_files"], version="0.2.10", lifecycle="codex_session",
            task=None, run_values=plan["unrelated_run_values"], malformed_artifacts=plan["malformed_artifacts"],
            protected_identity_hashes=plan["protected_identity_hashes"],
            unavailable_routes={key: dict(reason="binding_initialization_failed", delivered=0, acknowledged=0, history_recovered=False) for key in ("A", "B")},
            health_samples=[dict(pid=101, timestamp_ms=t, readiness="degraded", unavailable_count=2) for t in (1000, 1500)],
            listener_pid=101, observed_at_ms=1600, locks=dict(background=101, listener=101),
            owner_phases=[dict(phase="two_open", owner_tokens=["one", "two"], listener_pids=[101]),
                dict(phase="one_open", owner_tokens=["two"], listener_pids=[101]),
                dict(phase="last_closed", owner_tokens=[], listener_pids=[], stop_observed_ms=3000, last_owner_exit_ms=2000,
                     reopen_events=0, delivery_count=0, message_acknowledged=False, test_message_id="fixture-message"),
                dict(phase="reopened", owner_tokens=["three"], listener_pids=[102], test_message_id="fixture-message", delivery_count=1)],
            healthy_route_deliveries=1, acknowledged_replays=0)
        self.assertEqual(verify(plan, evidence)["scope"], "device_pilot_only")
        for field, value in (("version", "0.2.6"), ("task", "still-present"), ("lifecycle", "autostart"),
                             ("malformed_artifacts", {}), ("installed_files", {}), ("observed_at_ms", 5000),
                             ("healthy_route_deliveries", 0), ("acknowledged_replays", 1)):
            with self.subTest(field=field), self.assertRaises(sequence_module.SequenceStop):
                verify(plan, {**evidence, field: value})
        bad = copy.deepcopy(evidence)
        bad["unavailable_routes"]["A"]["history_recovered"] = True
        with self.assertRaises(sequence_module.SequenceStop):
            verify(plan, bad)
        bad = copy.deepcopy(evidence)
        bad["owner_phases"][2]["reopen_events"] = 1
        with self.assertRaises(sequence_module.SequenceStop):
            verify(plan, bad)
        for changed in ({**evidence, "extra": True}, {**evidence, "listener_pid": True}, {**evidence, "locks": {"background": True, "listener": 101}}):
            with self.assertRaises(sequence_module.SequenceStop):
                verify(plan, changed)
        raw_plan = json.dumps({"attempt_id": "fixture-attempt", "plan": plan}).encode()
        plan_hash = pilot.digest(raw_plan)
        raw_evidence = json.dumps({"attempt_id": "different-attempt", "plan_sha256": plan_hash, "observed": evidence}).encode()
        with self.assertRaises(sequence_module.SequenceStop):
            sequence_module.verify_acceptance(raw_plan, plan_hash, raw_evidence, pilot.digest(raw_evidence))
        with self.assertRaises(sequence_module.SequenceStop):
            sequence_module.verify_acceptance(raw_plan + b" ", plan_hash, raw_evidence, pilot.digest(raw_evidence))

    def test_backup_keeps_malformed_bytes_and_refuses_replay(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "fixture-source"
            source.mkdir()
            original = b'{"fixture":true} trailing-invalid'
            (source / "bad-state").write_bytes(original)
            before = (source / "bad-state").stat().st_mtime_ns
            records = backup_module.capture(source, root / "backup")
            self.assertEqual((root / "backup/bad-state").read_bytes(), original)
            self.assertEqual((source / "bad-state").read_bytes(), original)
            self.assertEqual(records["bad-state"]["mtime_ns"], before)
            self.assertEqual(records["bad-state"]["sha256"], pilot.digest(original))
            self.assertTrue((root / "backup.manifest.json").is_file())
            with self.assertRaises(FileExistsError):
                backup_module.capture(source, root / "backup")
            with self.assertRaises(backup_module.BackupStop):
                backup_module.capture(source, source / "nested-backup")
    def test_sequence_orders_backups_and_never_replays_after_failure(self):
        class Fixture:
            def __init__(self, fail=None, fail_evidence=False):
                self.calls = []
                self.fail = fail
                self.fail_evidence = fail_evidence
            def retain_stage(self, stage, state):
                self.calls.append((stage, state))
            def retain_failure(self, stage, code):
                self.failure = (stage, code)
                if self.fail_evidence:
                    raise OSError("fixture secondary failure")
            def __getattr__(self, stage):
                def action():
                    self.calls.append((stage, "action"))
                    if self.fail == stage:
                        raise sequence_module.SequenceStop("fixture_failure")
                return action
        good = Fixture()
        self.assertEqual(sequence_module.sequence(good).result, "passed")
        stages = [stage for stage, kind in good.calls if kind == "action"]
        self.assertLess(stages.index("verify_initial_backup"), stages.index("suppress_exact_owned_task"))
        self.assertLess(stages.index("verify_suppression_and_unrelated_entries"), stages.index("stop_exact_owned_process"))
        self.assertLess(stages.index("verify_quiescent_backup_and_corrupt_artifacts"), stages.index("install_exact_staged_artifact"))
        for failed_stage in stages:
            fixture = Fixture(failed_stage, fail_evidence=True)
            result = sequence_module.sequence(fixture)
            actual = [stage for stage, kind in fixture.calls if kind == "action"]
            self.assertEqual(actual, stages[:stages.index(failed_stage) + 1])
            self.assertEqual(result.stage, failed_stage)
            self.assertEqual(result.failure_code, "fixture_failure")
            self.assertEqual(result.secondary_errors, ["failure_evidence_write_failed"])
            self.assertFalse(any("rollback" in stage or "restart" in stage for stage in actual))

    def test_process_identity_and_locks_independently_bound(self):
        identity = dict(pid=101, started="start", executable="fixture-node", entry="fixture-runtime", parent_pid=100, parent_started="parent-start")
        parent = dict(pid=100, started="parent-start")
        locks = dict(background=101, listener=101)
        sequence_module.verify_listener_preimage(identity, dict(identity), parent, locks)
        for key in identity:
            with self.subTest(key=key), self.assertRaises(sequence_module.SequenceStop):
                sequence_module.verify_listener_preimage(identity, {**identity, key: "drift"}, parent, locks)
        with self.assertRaises(sequence_module.SequenceStop):
            sequence_module.verify_listener_preimage(identity, identity, parent, {**locks, "listener": 99})

    def test_independent_quiescence_snapshots_fail_closed(self):
        plan = dict(run_identity="private-run-token", unrelated_run_values={"other": "digest"}, listener_executables=["fixture-node"], listener_identity={"pid": 101})
        locks = dict(background=None, listener=None)
        sequence_module.verify_quiescent_facts(plan, None, {"other": "digest"}, [], locks, [])
        for facts in (({}, {"other": "digest"}, [], locks, []),
                      (None, {}, [], locks, []),
                      (None, {"other": "digest"}, [], dict(background=101, listener=None), []),
                      (None, {"other": "digest"}, [], locks, ["unexpected"])):
            with self.assertRaises(sequence_module.SequenceStop):
                sequence_module.verify_quiescent_facts(plan, *facts)
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

    def test_encryption_compression_and_malformed_metadata(self):
        good, manifest = self.fixture()
        encrypted = bytearray(good)
        for signature, offset in ((b"PK\x03\x04", 6), (b"PK\x01\x02", 8)):
            position = encrypted.find(signature)
            struct.pack_into("<H", encrypted, position + offset, 1)
        with self.assertRaises(pilot.Stop):
            pilot.validate_entries(bytes(encrypted), manifest)
        compressed = io.BytesIO()
        with zipfile.ZipFile(io.BytesIO(good)) as source, zipfile.ZipFile(compressed, "w", compression=zipfile.ZIP_DEFLATED) as target:
            for item in source.infolist():
                content = source.read(item.filename)
                item = copy.copy(item)
                item.compress_type = zipfile.ZIP_DEFLATED
                target.writestr(item, content)
        with self.assertRaises(pilot.Stop):
            pilot.validate_entries(compressed.getvalue(), manifest)
        for bad in (good[:-30], b"not ZIP", good[:40]):
            with self.assertRaises(pilot.Stop):
                pilot.validate_entries(bad, manifest)
        wrong_crc = bytearray(good)
        central = wrong_crc.find(b"PK\x01\x02")
        struct.pack_into("<I", wrong_crc, central + 16, 0)
        with self.assertRaises(pilot.Stop):
            pilot.validate_entries(bytes(wrong_crc), manifest)

    def test_atomic_attempt_marker_is_hard_replay_barrier(self):
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            installed = parent / "installed"
            installed.mkdir()
            marker = pilot.create_attempt_marker(parent / "attempt", [installed], "a" * 64, "fixture-attempt-0001")
            self.assertTrue(marker.is_file())
            with self.assertRaises(pilot.Stop):
                pilot.create_attempt_marker(parent / "attempt", [installed], "a" * 64, "fixture-attempt-0001")
            with self.assertRaises(pilot.Stop):
                pilot.create_attempt_marker(installed / "attempt", [installed], "a" * 64, "fixture-attempt-0002")
            self.assertFalse((installed / "attempt").exists())
            # An incomplete reservation also forbids retry.
            (parent / "failed-attempt").mkdir()
            with self.assertRaises(pilot.Stop):
                pilot.create_attempt_marker(parent / "failed-attempt", [installed], "a" * 64, "fixture-attempt-0003")

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
