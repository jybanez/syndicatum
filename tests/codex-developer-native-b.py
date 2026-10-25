"""Inert backend and temporary evidence roots only. Never invokes native B."""
import base64
import copy
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools/release"))
import codex_developer_native_b as native


def b64(raw):
    return base64.b64encode(raw).decode()


class InertBackend:
    def __init__(self, plan, fault=None):
        self.plan, self.fault, self.calls, self.time, self.snapshots = plan, fault, [], 1000, 0
        self.tasks = {native.TASK: b64(b'<Task>owned-fixture</Task>'), "\\Other": b64(b'<Interval>PT5M</Interval>')}
        self.runs = copy.deepcopy(plan["run_preimage"])
        self.processes = [dict(plan["listener"])]
        self.locks = {name: {"present": True, "pid": 12, "live": True,
                             "witness": {"sha256": digest}} for name, digest in plan["lock_sha256"].items()}
        raw = native.encode({"processedMessageIds": [], "pendingNotifications": [], "activeWake": None, "lastSequence": 4})
        self.states = {"state.json.participant-36": self.witness(raw)}

    @staticmethod
    def witness(raw):
        return {"raw_base64": b64(raw), "sha256": native.sha(raw), "size": len(raw),
                "handle_identity": "fixture-volume:file-1", "path_identity": "fixture-volume:file-1",
                "before_metadata": "fixture-metadata", "after_metadata": "fixture-metadata"}

    def clock(self):
        self.time += 1
        return {"utc_ms": self.time, "ticks": self.time, "frequency": 1000, "boot_utc": "fixture-boot"}

    def snapshot(self):
        self.snapshots += 1
        if self.fault == "pid-reuse" and self.snapshots == 3:
            self.processes[0]["started"] = "reused-pid-start"
        value = {"tasks": self.tasks, "runs": self.runs, "processes": self.processes,
                 "locks": self.locks, "static_files": self.plan["static_files"], "clock": self.clock()}
        if self.fault == "clock-jump" and self.snapshots == 1:
            value["clock"]["utc_ms"] += 5000
        return copy.deepcopy(value)

    def invoke(self, operation, plan):
        self.calls.append(operation)
        if operation == "admission": return {"private_roots_checked": True, "clock": self.clock()}
        if operation == "flush": return {"directory_flush_confirmed": self.fault != "directory-flush"}
        if operation == "snapshot": return self.snapshot()
        if operation == "suppress":
            del self.tasks[native.TASK]
            if self.fault == "partial-suppression": raise native.NativeBStop("native_nonzero_uncertain")
            self.runs = {key: value for key, value in self.runs.items() if not native.RUN.fullmatch(key)}
            if self.fault == "unrelated-task": self.tasks["\\Other"] = b64(b'<Interval>PT10M</Interval>')
            return {"operation": "exact_startup_suppression", "completed": True}
        if operation == "stop":
            self.processes = []
            for item in self.locks.values(): item["live"] = self.fault == "live-lock"
            if self.fault == "other-writer": self.processes = [{**plan["listener"], "pid": 99}]
            return {"operation": "exact_owned_listener_stop", "completed": True}
        if operation == "cutoff":
            result = {"before": self.snapshot(), "states": copy.deepcopy(self.states),
                      "after": self.snapshot(), "clock": self.clock()}
            witness = result["states"]["state.json.participant-36"]
            if self.fault == "path-swap": witness["path_identity"] = "different-file"
            if self.fault == "bad-raw-hash": witness["sha256"] = "0" * 64
            if self.fault == "new-route": result["states"]["state.json.participant-99"] = dict(witness)
            if self.fault == "malformed-state":
                result["states"]["state.json.participant-36"] = self.witness(b'{"lastSequence":1,"lastSequence":2}')
            return result
        raise AssertionError("unexpected inert operation")


def plan_for(root):
    return {"attempt_id": "new-fixture-attempt", "cutoff_id": "new-fixture-cutoff", "previous_attempt_id": "11545",
            "previous_cutoff_id": "old-cutoff", "mechanism_head": "a" * 40,
            "candidate_commit": "9141daa08d896f2524be30b96d01ab2a14398757", "owner_authority_message_id": 99999,
            "evidence_root": str(root / "new-attempt"), "head_root": str(root / "independent-head"),
            "transport_root": str(root), "data_root": "INERT-NOT-A-LIVE-PATH", "service_entry": "inert-entry",
            "task_xml_sha256": native.sha(b'<Task>owned-fixture</Task>'),
            "run_preimage": {"Registry64:SyndicatumCodexConnector": {"kind": "String", "value": "inert-owned"},
                             "Registry64:Other": {"kind": "String", "value": "inert-unrelated"}},
            "listener": {"pid": 12, "started": "fixture-start", "executable": "inert-node",
                         "command_line": "inert-entry", "image_sha256": "d" * 64},
            "lock_sha256": {"background.lock": "e" * 64, "listener.lock": "f" * 64},
            "route_names": ["state.json.participant-36"], "static_files": {"background.json": "c" * 64}}


class NativeBFixtures(unittest.TestCase):
    def adapter(self, root, fault=None, authority=True, plan_changes=None):
        plan = {**plan_for(root), **(plan_changes or {})}
        backend = InertBackend(plan, fault)
        raw = native.encode(plan)
        adapter = native.DeveloperBAdapter(raw, native.sha(raw), backend,
            lambda message, digest, scope: authority, now_ms=lambda: backend.time + 1)
        return adapter, backend

    def test_closed_native_gate_before_any_source_file_or_process_access(self):
        backend = native.WindowsBackend("does-not-exist", "0" * 64)
        with self.assertRaisesRegex(native.NativeBStop, "source_only_native_gate"):
            backend.invoke("suppress", {})

    def test_inert_preparation_and_each_boundary_never_authorize_install(self):
        with tempfile.TemporaryDirectory() as temporary:
            adapter, backend = self.adapter(Path(temporary))
            result = adapter.prepare()
            self.assertEqual(result["result"], "cutoff_prepared_only", result)
            self.assertIs(result["authorizes_install"], False)
            head = result["head_sha256"]
            for boundary in ("before-remove", "after-remove", "before-add", "after-add", "before-plugin-add", "after-plugin-add"):
                checked = adapter.verify_boundary(boundary, head)
                self.assertIs(checked["authorizes_install"], False)
                head = checked["head_sha256"]
            self.assertEqual(backend.calls.count("suppress"), 1)
            self.assertEqual(backend.calls.count("stop"), 1)
            self.assertFalse(any(op in backend.calls for op in ("install", "activate", "cleanup")))

    def test_bad_authority_or_old_ids_prevent_even_native_admission(self):
        for changes in ({"attempt_id": "11545"}, {"cutoff_id": "old-cutoff"}, {"owner_authority_message_id": 11545}):
            with tempfile.TemporaryDirectory() as temporary:
                adapter, backend = self.adapter(Path(temporary), plan_changes=changes)
                self.assertEqual(adapter.prepare()["result"], "stopped")
                self.assertEqual(backend.calls, [])
        with tempfile.TemporaryDirectory() as temporary:
            adapter, backend = self.adapter(Path(temporary), authority=False)
            self.assertEqual(adapter.prepare()["result"], "stopped")
            self.assertEqual(backend.calls, [])

    def test_adversarial_native_facts_stop_without_retry_or_later_mutation(self):
        for fault in ("directory-flush", "clock-jump", "partial-suppression", "unrelated-task", "pid-reuse",
                      "other-writer", "live-lock", "path-swap", "bad-raw-hash", "new-route", "malformed-state"):
            with self.subTest(fault=fault), tempfile.TemporaryDirectory() as temporary:
                adapter, backend = self.adapter(Path(temporary), fault)
                result = adapter.prepare()
                self.assertEqual(result["result"], "stopped", result)
                self.assertLessEqual(backend.calls.count("suppress"), 1)
                self.assertLessEqual(backend.calls.count("stop"), 1)
                if fault in {"directory-flush", "clock-jump"}: self.assertNotIn("suppress", backend.calls)
                if fault in {"partial-suppression", "unrelated-task", "pid-reuse"}: self.assertNotIn("stop", backend.calls)

    def test_frozen_cutoff_rejects_sequence_and_task_changes(self):
        for kind in ("sequence", "pending", "task"):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as temporary:
                adapter, backend = self.adapter(Path(temporary))
                head = adapter.prepare()["head_sha256"]
                if kind == "task": backend.tasks["\\Other"] = b64(b'<StartBoundary>changed</StartBoundary>')
                else:
                    value = {"processedMessageIds": [], "pendingNotifications": [], "activeWake": None, "lastSequence": 5}
                    if kind == "pending": value["pendingNotifications"] = [{"id": "new"}]
                    backend.states["state.json.participant-36"] = backend.witness(native.encode(value))
                with self.assertRaises(Exception): adapter.verify_boundary("before-remove", head)

    def test_tampered_record_or_wrong_external_head_stops_before_new_capture(self):
        for tamper in (True, False):
            with tempfile.TemporaryDirectory() as temporary:
                adapter, backend = self.adapter(Path(temporary))
                head = adapter.prepare()["head_sha256"]
                if tamper:
                    record = adapter.journal.records / "000000.json"
                    record.write_bytes(record.read_bytes() + b'\n')
                else: head = "0" * 64
                calls = len(backend.calls)
                with self.assertRaises(native.NativeBStop): adapter.verify_boundary("before-remove", head)
                self.assertEqual(len(backend.calls), calls)

    def test_existing_attempt_cannot_resume(self):
        with tempfile.TemporaryDirectory() as temporary:
            first, _ = self.adapter(Path(temporary))
            self.assertEqual(first.prepare()["result"], "cutoff_prepared_only")
            second, backend = self.adapter(Path(temporary))
            self.assertEqual(second.prepare()["result"], "stopped")
            self.assertNotIn("suppress", backend.calls)

    def test_boundary_failure_or_bad_order_is_terminal_not_a_refresh_opportunity(self):
        with tempfile.TemporaryDirectory() as temporary:
            adapter, backend = self.adapter(Path(temporary))
            head = adapter.prepare()["head_sha256"]
            calls = len(backend.calls)
            with self.assertRaisesRegex(native.NativeBStop, "out_of_order"):
                adapter.verify_boundary("before-add", head)
            with self.assertRaisesRegex(native.NativeBStop, "terminal_attempt"):
                adapter.verify_boundary("before-remove", head)
            self.assertEqual(len(backend.calls), calls)
            self.assertEqual(adapter.prepare()["code"], "attempt_object_reuse")
            self.assertEqual(len(backend.calls), calls)


if __name__ == "__main__":
    unittest.main()
