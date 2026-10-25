"""End-to-end inert preparation. No files, native APIs, CLI installs or live tools."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools/release"))
import codex_developer_fixed_journal as fixed
import codex_developer_integrated_preparation as integrated
import codex_developer_native_b as native

spec = importlib.util.spec_from_file_location("native_fixture", ROOT / "tests/codex-developer-native-b.py")
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


def setup(handoff_manifest_sha256=None):
    plan = fixture.plan_for(Path("INERT-NOT-A-FILESYSTEM-ROOT"))
    slots, width = 128, 8192
    manifest = {"root": "INERT-FIXED-ROOT", "namespace": "new-integrated-attempt", "slots": slots, "width": width,
                "files": {name: {"path": "INERT-FIXED-ROOT/" + name, "identity": "fixture-volume:" + name,
                    "length": slots * width, "acl": "private-fixture-acl", "final_path": "fixture-normalized:" + name}
                    for name in ("records", "heads")}}
    manifest_raw = fixed.packed(manifest)
    plan["fixed_storage_manifest_sha256"] = fixed.digest(manifest_raw)
    if handoff_manifest_sha256 is not None:
        plan["independent_handoff_manifest_sha256"] = handoff_manifest_sha256
    context = integrated.plan_context(plan)
    api = fixed.FakeFixedSessionAPI(manifest)
    projection = fixed.SessionStorage(api, manifest_raw, fixed.digest(manifest_raw)).manifest
    reviewed = {key: plan[key] for key in ("mechanism_head", "candidate_commit", "attempt_id", "cutoff_id")}
    reviewed.update(notification_interruption=True, operations={name: context for name in integrated.OPERATIONS},
                    storage_manifest_sha256=fixed.digest(fixed.packed(projection)))
    reviewed_raw = fixed.packed(reviewed)
    receipt = {key: reviewed[key] for key in ("mechanism_head", "candidate_commit", "attempt_id", "cutoff_id", "notification_interruption")}
    receipt.update(not_before_ms=500, expires_ms=999999, scopes=list(integrated.OPERATIONS), plan_sha256=fixed.digest(reviewed_raw))
    body = fixed.packed({"purpose": "Developer-only preparation", "plan_context_sha256": context, "receipt": receipt}).decode()
    request = {"id": 99998, "project_id": 2, "sender": {"participant_id": 36}, "body": body,
        "addressees": [{"participant_id": 28, "reason": "direct", "seen_at": None}], "action_requested": True,
        "action_request_type": "approval", "edited": False, "revision_count": 0, "deleted_at": None}
    approval = {"id": plan["owner_authority_message_id"], "project_id": 2, "sender": {"participant_id": 28},
        "message_kind": "system", "edited": False, "revision_count": 0, "deleted_at": None, "reply_to_message_id": 99998,
        "addressees": [{"participant_id": 36}], "system_event": {"type": "responsibility.approval_approved",
        "data": {"request_message_id": 99998, "actor_participant_id": 28, "initial_responder_participant_id": 28,
                 "kind": "approval_approved"}}}
    timeline = fixed.FakeCanonicalTimeline({99998: request, approval["id"]: approval})
    authority = fixed.CanonicalAuthority(timeline, 99998, approval["id"], fixed.digest(fixed.packed(fixed.CanonicalAuthority.material(request))), context)
    expected = fixed.packed(authority.get(approval["id"]))
    backend = fixture.InertBackend(plan)
    handoff = integrated.RetainedHead()
    runner = integrated.IntegratedPreparation(fixed.packed(plan), authority, api, manifest_raw, reviewed_raw,
                                             expected, lambda: backend.time + 1, handoff)
    return runner, backend, api, timeline, handoff


class IntegratedPreparationFixtures(unittest.TestCase):
    def test_complete_receipt_fixed_session_journal_preparation_and_boundaries(self):
        runner, backend, api, timeline, handoff = setup()
        # Directory journal and directory flush are forbidden in this path.
        with patch.object(native, "CutoverJournal", side_effect=AssertionError("unsupported_directory_path")):
            adapter = runner.build(backend)
            result = adapter.prepare()
            self.assertEqual(result["result"], "cutoff_prepared_only", result)
            self.assertFalse(result["authorizes_install"])
            self.assertEqual(runner.journal.admitted, ["suppress", "stop", "cutoff"])
            self.assertNotIn("flush", backend.calls)
            self.assertEqual(backend.calls.count("suppress"), 1)
            self.assertEqual(backend.calls.count("stop"), 1)
            self.assertTrue(api.held)  # One continuous existing-file session.
            self.assertEqual(api.calls.count(("open_pair",)), 1)
            self.assertNotIn(("close_pair",), api.calls)
            head = result["head_sha256"]
            # Receipt/notification observation changes do not change approval scope.
            timeline.messages[99998]["addressees"][0]["seen_at"] = "fixture-observed"
            self.assertEqual(fixed.packed(runner.authority.get(99999)), runner.receipt_raw)
            for boundary in ("before-remove", "after-remove", "before-add", "after-add", "before-plugin-add", "after-plugin-add"):
                checked = adapter.verify_boundary(boundary, head)
                self.assertFalse(checked["authorizes_install"])
                head = checked["head_sha256"]
            self.assertEqual(handoff.value["head"], head)
            self.assertTrue(any(call[0] == "file_flush" for call in api.calls))
            self.assertFalse(any(name in backend.calls for name in ("install", "activate", "cleanup")))
            self.assertFalse(api.held)
            self.assertTrue(adapter.session_close_attempted)
            self.assertEqual(api.calls.count(("close_pair",)), 1)

    def test_observation_ages_during_intent_publication_before_each_action(self):
        for operation in integrated.OPERATIONS:
            with self.subTest(operation=operation):
                runner, backend, api, _, _ = setup()
                flush, delayed = api.flush, False
                def slow_flush(name):
                    nonlocal delayed
                    flush(name)
                    if not delayed and runner.journal is not None and runner.journal.pending == operation:
                        backend.time += 120001
                        delayed = True
                api.flush = slow_flush
                adapter = runner.build(backend)
                result = adapter.prepare()
                self.assertTrue(delayed)
                self.assertEqual(result["result"], "stopped", result)
                self.assertNotIn(operation, backend.calls)
                self.assertLess(backend.time, 999999)  # Authority remains valid.
                self.assertFalse(api.held)
                calls = list(backend.calls)
                self.assertEqual(adapter.prepare()["code"], "attempt_object_reuse")
                self.assertEqual(backend.calls, calls)

    def test_boundary_publication_age_and_close_uncertainty_are_terminal(self):
        for mode in ("age", "close", "failure-close"):
            with self.subTest(mode=mode):
                runner, backend, api, _, _ = setup()
                adapter = runner.build(backend)
                head = adapter.prepare()["head_sha256"]
                if mode == "age":
                    append = runner.journal.append
                    def slow_append(boundary, producer, data):
                        result = append(boundary, producer, data)
                        if producer == "native-frozen-boundary-confirmed": backend.time += 120001
                        return result
                    runner.journal.append = slow_append
                    with self.assertRaises(Exception): adapter.verify_boundary("before-remove", head)
                    self.assertFalse(api.held)
                    self.assertLess(backend.time, 999999)
                else:
                    closes = []
                    def uncertain_close():
                        closes.append(1)
                        raise fixed.Stop("fixture_close_uncertain")
                    api.close = uncertain_close
                    if mode == "failure-close":
                        with self.assertRaises(native.NativeBStop) as caught:
                            adapter.verify_boundary("after-remove", head)
                        self.assertEqual(str(caught.exception), "missing_or_out_of_order_boundary")
                        self.assertIn("failure_session_close_uncertain; no retry", caught.exception.__notes__)
                    else:
                        for boundary in ("before-remove", "after-remove", "before-add", "after-add", "before-plugin-add"):
                            head = adapter.verify_boundary(boundary, head)["head_sha256"]
                        with self.assertRaises(fixed.Stop): adapter.verify_boundary("after-plugin-add", head)
                    self.assertTrue(adapter.session_close_uncertain)
                    self.assertEqual(closes, [1])
                    runner.journal.close_terminal()
                    self.assertEqual(closes, [1])
                self.assertTrue(adapter.terminal)
                calls = list(backend.calls)
                with self.assertRaises(native.NativeBStop): adapter.verify_boundary("before-remove", head)
                self.assertEqual(backend.calls, calls)

    def test_cutoff_publication_and_boundary_preinvoke_delay_stop(self):
        for mode in ("cutoff-publication", "boundary-preinvoke", "boundary-raw"):
            with self.subTest(mode=mode):
                runner, backend, api, _, _ = setup()
                adapter = runner.build(backend)
                if mode == "cutoff-publication":
                    factory = adapter.journal_factory
                    def slow_factory(*args):
                        journal = factory(*args)
                        original = journal.append
                        def slow_append(boundary, producer, data):
                            result = original(boundary, producer, data)
                            if producer == "native-handle-state-and-task": backend.time += 120001
                            return result
                        journal.append = slow_append
                        return journal
                    adapter.journal_factory = slow_factory
                    self.assertEqual(adapter.prepare()["result"], "stopped")
                else:
                    head = adapter.prepare()["head_sha256"]
                    calls = backend.calls.count("cutoff")
                    if mode == "boundary-preinvoke":
                        original = runner.journal.verify
                        def slow_verify(value):
                            original(value)
                            backend.time += 120001
                        runner.journal.verify = slow_verify
                    else:
                        original = runner.journal.append
                        def slow_append(boundary, producer, data):
                            result = original(boundary, producer, data)
                            if producer == "native-boundary-raw": backend.time += 120001
                            return result
                        runner.journal.append = slow_append
                    with self.assertRaises(Exception): adapter.verify_boundary("before-remove", head)
                    if mode == "boundary-preinvoke": self.assertEqual(backend.calls.count("cutoff"), calls)
                self.assertTrue(adapter.terminal)
                self.assertFalse(api.held)
                self.assertLess(backend.time, 999999)

    def test_changed_canonical_authority_after_evidence_stops_before_stop_no_replay(self):
        for mode in ("changed", "expired", "handoff", "acl"):
            with self.subTest(mode=mode):
                runner, backend, api, timeline, handoff = setup()
                flush = api.flush
                def change_after_evidence(name):
                    flush(name)
                    if runner.journal is not None and runner.journal.pending == "stop":
                        if mode == "changed": timeline.messages[99999]["system_event"]["type"] = "responsibility.approval_rejected"
                        if mode == "expired": backend.time = 1000000
                        if mode == "handoff": handoff.value["plan_sha256"] = "0" * 64
                        if mode == "acl": api.manifest["files"]["heads"]["acl"] = "changed"
                api.flush = change_after_evidence
                adapter = runner.build(backend)
                result = adapter.prepare()
                self.assertEqual(result["result"], "stopped")
                self.assertNotIn("stop", backend.calls)
                self.assertEqual(backend.calls.count("suppress"), 1)
                self.assertNotIn("flush", backend.calls)
                self.assertFalse(api.held)
                before = len(backend.calls)
                self.assertEqual(adapter.prepare()["code"], "attempt_object_reuse")
                with self.assertRaises(fixed.Stop): runner.build(backend)
                self.assertEqual(len(backend.calls), before)
                self.assertTrue(any(api.files["records"]))

    def test_storage_uncertainty_and_wrong_authority_fail_before_preparation_mutation(self):
        for fault in ("flush", "partial", "wrong-owner", "wrong-context", "unavailable", "wrong-profile", "full-pin"):
            with self.subTest(fault=fault):
                runner, backend, api, timeline, _ = setup()
                if fault in ("flush", "partial"): api.fault = fault
                if fault == "wrong-owner": timeline.messages[99999]["sender"]["participant_id"] = 35
                if fault == "wrong-context": timeline.messages[99998]["body"] += " "
                if fault == "unavailable": timeline.available = False
                if fault == "wrong-profile": timeline.profile["profile_id"] = "1f397680dd1cddc0.2.73"
                if fault == "full-pin": api.manifest["files"]["records"]["final_path"] = "changed"
                result = runner.build(backend).prepare()
                self.assertEqual(result["result"], "stopped", result)
                self.assertNotIn("suppress", backend.calls)
                self.assertNotIn("stop", backend.calls)
                self.assertNotIn("flush", backend.calls)
                self.assertFalse(api.held)
        runner, backend, api, _, _ = setup()
        backend.fault = "transition-task-3"
        result = runner.build(backend).prepare()
        self.assertEqual(result["result"], "stopped")
        self.assertNotIn("stop", backend.calls)
        self.assertFalse(api.held)

    def test_missing_integration_refuses_before_backend_and_existing_storage_cannot_resume(self):
        runner, backend, api, timeline, handoff = setup()
        raw = native.encode(runner.plan)
        adapter = native.DeveloperBAdapter(raw, native.sha(raw), backend, lambda *args: True)
        self.assertEqual(adapter.prepare()["code"], "fixed_preparation_integration_required")
        self.assertEqual(backend.calls, [])
        gated, _, gated_api, _, _ = setup()
        outcome = gated.build(native.WindowsBackend("INERT-MISSING-SOURCE", "0" * 64)).prepare()
        self.assertEqual(outcome["code"], "source_only_native_gate")
        self.assertFalse(gated_api.held)
        self.assertFalse(any(gated_api.files["records"]))
        self.assertEqual(runner.build(backend).prepare()["result"], "cutoff_prepared_only")
        runner.journal.core._stop()  # Simulated process exit releases handles only.
        before = {name: bytes(raw) for name, raw in api.files.items()}
        # Construct another adapter against the SAME pinned existing files.
        repeat = integrated.IntegratedPreparation(native.encode(runner.plan), runner.authority, api,
            fixed.packed(api.manifest), runner.fixed_plan_raw, runner.receipt_raw, runner.now, integrated.RetainedHead())
        second_backend = fixture.InertBackend(runner.plan)
        self.assertEqual(repeat.build(second_backend).prepare()["result"], "stopped")
        self.assertNotIn("suppress", second_backend.calls)
        self.assertEqual({name: bytes(raw) for name, raw in api.files.items()}, before)


if __name__ == "__main__":
    unittest.main()
