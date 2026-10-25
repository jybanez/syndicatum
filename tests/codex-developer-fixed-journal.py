"""Memory-only fixtures: no native calls, files, canonical access or commands."""
import copy
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools/release"))
import codex_developer_fixed_journal as fixed


def receipt():
    return {"server": "https://syndicatum.wizaya.com", "project_id": 2,
            "message_id": 99999, "sender_id": 28, "addressee_id": 36,
            "revision": 1, "body_sha256": "a" * 64, "plan_sha256": "b" * 64,
            "attempt_id": "new-synthetic", "cutoff_id": "new-synthetic-cutoff",
            "scopes": ["synthetic-first", "synthetic-second"], "revoked": False,
            "not_before_ms": 1000, "expires_ms": 2000, "mechanism_head": "c" * 40,
            "candidate_commit": "9141daa08d896f2524be30b96d01ab2a14398757", "notification_interruption": True}


def context_plan(pin):
    value = receipt()
    plan = {key: value[key] for key in ("mechanism_head", "candidate_commit", "attempt_id", "cutoff_id", "notification_interruption")}
    plan.update(operations={"synthetic-first": "reviewed-first", "synthetic-second": "reviewed-second"},
                storage_manifest_sha256=fixed.digest(pin))
    return fixed.packed(plan)


def open_model(storage, authority=None, pin=None, head=None, now=1500, changes=None):
    pin = fixed.packed(storage.manifest) if pin is None else pin
    plan = context_plan(pin)
    expected = {**receipt(), "plan_sha256": fixed.digest(plan), **(changes or {})}
    if authority is None: authority = fixed.FakeAuthority(expected)
    else: authority.message["plan_sha256"] = expected["plan_sha256"]
    model = fixed.FixedJournal(storage, pin, fixed.digest(pin), authority,
                              fixed.packed(expected), head, plan, lambda: now)
    return model


class FixedFixtures(unittest.TestCase):
    def test_reservation_precedes_intent_and_never_authorizes_mutation(self):
        store = fixed.MemoryStorage()
        model = open_model(store)
        retained = model.external_head  # Caller retains this value separately.
        self.assertEqual(fixed.unframe(store.read("records", 0))["kind"], "reservation")
        result = model.model_action("synthetic-first", retained, "reviewed-first")
        self.assertFalse(result["authorizes_mutation"])
        self.assertEqual(store.events, ["records-write", "records-flush", "heads-write", "heads-flush"] * 2)

    def test_contention_does_not_release_the_first_lease(self):
        store = fixed.MemoryStorage()
        first = open_model(store)
        with self.assertRaisesRegex(fixed.Stop, "contended"):
            open_model(store)
        self.assertTrue(store.held)
        first.model_action("synthetic-first", first.external_head, "reviewed-first")

    def test_existing_or_partial_storage_never_resumes_or_reinitializes(self):
        for kind in ("records", "heads"):
            for value in (b"REC1", b"garbage"):
                store = fixed.MemoryStorage()
                store.files[kind][:len(value)] = value
                before = copy.deepcopy(store.files)
                with self.assertRaisesRegex(fixed.Stop, "no_resume"):
                    open_model(store)
                self.assertEqual(store.files, before)
        store = fixed.MemoryStorage()
        first = open_model(store)
        first._stop()  # Simulated process exit releases handle, not reservation.
        with self.assertRaisesRegex(fixed.Stop, "no_resume"):
            open_model(store)

    def test_each_publication_crash_phase_retains_bytes_and_blocks_replay(self):
        for phase in ("records-partial", "records-write", "records-flush",
                      "heads-partial", "heads-write", "heads-flush"):
            for reservation in (True, False):
                with self.subTest(phase=phase, reservation=reservation):
                    store = fixed.MemoryStorage()
                    if reservation:
                        store.fault = phase
                        with self.assertRaises(fixed.Stop):
                            open_model(store)
                    else:
                        model = open_model(store)
                        store.fault = phase
                        with self.assertRaises(fixed.Stop):
                            model.model_action("synthetic-first", model.external_head, "reviewed-first")
                        self.assertTrue(model.closed)
                    before = copy.deepcopy(store.files)
                    store.fault = None
                    with self.assertRaises(fixed.Stop):
                        open_model(store)
                    self.assertEqual(store.files, before)

    def test_capacity_failure_is_terminal_without_overwrite(self):
        store = fixed.MemoryStorage(slots=1)
        model = open_model(store)
        before = copy.deepcopy(store.files)
        with self.assertRaisesRegex(fixed.Stop, "capacity"):
            model.model_action("synthetic-first", model.external_head, "reviewed-first")
        self.assertTrue(model.closed)
        self.assertEqual(store.files, before)
        with self.assertRaisesRegex(fixed.Stop, "slot_capacity"):
            fixed.frame({"payload": "x" * 2048}, 512)

    def test_alternate_root_namespace_identity_and_size_are_not_adopted(self):
        for key in ("root", "namespace", "record_id", "head_id"):
            store = fixed.MemoryStorage()
            pin = fixed.packed(store.manifest)
            store.manifest[key] = "replacement"
            with self.assertRaisesRegex(fixed.Stop, "identity"):
                open_model(store, pin=pin)
        store = fixed.MemoryStorage()
        model = open_model(store)
        store.files["heads"].append(0)
        with self.assertRaisesRegex(fixed.Stop, "capacity_changed"):
            model.model_action("synthetic-first", model.external_head, "reviewed-first")

    def test_changed_unavailable_revoked_authority_stops_before_intent(self):
        for key in receipt():
            store = fixed.MemoryStorage()
            transport = fixed.FakeAuthority(receipt())
            model = open_model(store, transport)
            transport.message[key] = "changed"
            before = copy.deepcopy(store.files)
            with self.assertRaises(fixed.Stop):
                model.model_action("synthetic-first", model.external_head, "reviewed-first")
            self.assertTrue(model.closed)
            self.assertEqual(store.files, before)
        store = fixed.MemoryStorage()
        transport = fixed.FakeAuthority(receipt())
        transport.available = False
        with self.assertRaisesRegex(fixed.Stop, "unavailable"):
            open_model(store, transport)
        transport.available = True
        with self.assertRaisesRegex(fixed.Stop, "no_resume"):
            open_model(store, transport)

    def test_torn_slot_bad_external_head_and_operation_replay_are_terminal(self):
        for kind in ("records", "heads", "external", "replay"):
            store = fixed.MemoryStorage()
            model = open_model(store)
            head = model.external_head
            if kind in ("records", "heads"):
                store.files[kind][45] ^= 1
            elif kind == "external":
                head = "0" * 64
            else:
                head = model.model_action("synthetic-first", head, "reviewed-first")["head_sha256"]
            with self.assertRaises(fixed.Stop):
                model.model_action("synthetic-first", head, "reviewed-first")
            self.assertTrue(model.closed)
            with self.assertRaisesRegex(fixed.Stop, "terminal"):
                model.model_action("synthetic-second", model.external_head, "reviewed-second")

    def test_authority_admission_requires_time_release_and_interruption(self):
        for changes, now in (({}, 999), ({}, 2000), ({"expires_ms": 1000}, 1500),
                             ({"not_before_ms": True}, 1500), ({"mechanism_head": "d" * 40}, 1500),
                             ({"candidate_commit": "d" * 40}, 1500),
                             ({"notification_interruption": None}, 1500)):
            with self.subTest(changes=changes, now=now), self.assertRaises(fixed.Stop):
                open_model(fixed.MemoryStorage(), now=now, changes=changes)
        store = fixed.MemoryStorage()
        model = open_model(store)
        with self.assertRaisesRegex(fixed.Stop, "operation_context"):
            model.model_action("synthetic-first", model.external_head, "caller-chosen")

    def test_non_fake_seams_and_existing_external_head_are_rejected(self):
        with self.assertRaisesRegex(fixed.Stop, "fake_seams"):
            fixed.FixedJournal(object(), b"{}", fixed.digest(b"{}"), object(), b"{}", None, b"{}", lambda: 1500)
        with self.assertRaisesRegex(fixed.Stop, "no_resume"):
            open_model(fixed.MemoryStorage(), head="a" * 64)

    def test_missing_admission_fields_and_expiry_after_reservation_fail_closed(self):
        pin = fixed.packed(fixed.MemoryStorage().manifest)
        plan = context_plan(pin)
        for field in ("expires_ms", "not_before_ms", "mechanism_head", "candidate_commit", "notification_interruption"):
            expected = {**receipt(), "plan_sha256": fixed.digest(plan)}
            del expected[field]
            with self.subTest(field=field), self.assertRaises(fixed.Stop):
                fixed.FixedJournal(fixed.MemoryStorage(), pin, fixed.digest(pin), fixed.FakeAuthority(expected),
                                   fixed.packed(expected), None, plan, lambda: 1500)
        model = open_model(fixed.MemoryStorage())
        model.now = lambda: 2000
        with self.assertRaisesRegex(fixed.Stop, "time_window"):
            model.model_action("synthetic-first", model.external_head, "reviewed-first")
        self.assertTrue(model.closed)

    def test_slow_evidence_requires_fresh_admission_and_consumes_intent(self):
        for changed in (False, True):
            store = fixed.MemoryStorage()
            transport = fixed.FakeAuthority(receipt())
            model = open_model(store, transport)
            if changed:
                original_get = transport.get
                def get(message_id):
                    if len(store.events) > 4:
                        transport.message["revision"] = 99
                    return original_get(message_id)
                transport.get = get
            else:
                model.now = lambda: 1500 if len(store.events) == 4 else 2000
            with self.assertRaises(fixed.Stop):
                model.model_action("synthetic-first", model.external_head, "reviewed-first")
            self.assertTrue(model.closed)
            self.assertEqual(fixed.unframe(store.read("records", 1))["kind"], "intent")
            with self.assertRaisesRegex(fixed.Stop, "no_resume"):
                open_model(store)

    def test_unexecuted_review_packet_pins_exact_source_and_denies_authority(self):
        root = Path(__file__).resolve().parents[1]
        import json
        packet = json.loads((root / "docs/codex-fixed-synthetic-review-packet.json").read_bytes())
        self.assertFalse(packet["may_execute"])
        self.assertIsNone(packet["owner_authority_message_id"])
        self.assertFalse(packet["source_review_complete"])
        self.assertFalse(packet["exact_head_ci_success"])
        for name in ("harness", "backend"):
            raw = (root / packet[name]).read_bytes()
            self.assertEqual(fixed.digest(raw), packet[name + "_sha256"])
            self.assertNotIn(b"\r", raw)


if __name__ == "__main__":
    unittest.main()
