"""SOURCE ONLY: fixed-container journal and sealed fake session/canonical seams.

Integrated preparation consumes this protocol; no native/API/storage setup here.
"""
import copy
import hashlib
import json
import re
import struct


def packed(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


class Stop(RuntimeError):
    pass


def check(value, reason):
    if not value:
        raise Stop(reason)


class MemoryStorage:
    """Synthetic pre-established files. Never opens a filesystem path."""
    def __init__(self, slots=16, width=2048):
        self.manifest = {"namespace": "synthetic-developer-authority", "root": "fixture-root",
                         "record_id": "fixture-volume:file-1", "head_id": "fixture-volume:file-2",
                         "slots": slots, "width": width}
        self.files = {key: bytearray(slots * width) for key in ("records", "heads")}
        self.held = False
        self.fault = None
        self.events = []

    def acquire(self):
        check(not self.held, "storage_contended")
        self.held = True

    def release(self):
        self.held = False

    def observe(self):
        return copy.deepcopy(self.manifest)

    def read(self, kind, index):
        width = self.manifest["width"]
        return bytes(self.files[kind][index * width:(index + 1) * width])

    def write(self, kind, index, raw):
        check(self.held, "exclusive_handle_required")
        width = self.manifest["width"]
        check(len(raw) == width, "fixed_width_required")
        check(self.read(kind, index) == bytes(width), "occupied_slot")
        start = index * width
        if self.fault == kind + "-partial":
            self.files[kind][start:start + 7] = raw[:7]
            raise Stop("partial_write")
        self.files[kind][start:start + width] = raw
        self.events.append(kind + "-write")
        if self.fault == kind + "-write":
            raise Stop("uncertain_write")

    def flush(self, kind):
        self.events.append(kind + "-flush")
        check(self.fault != kind + "-flush", "uncertain_flush")

    def sizes(self):
        return {name: len(raw) for name, raw in self.files.items()}

    def pristine(self):
        return all(not any(raw) for raw in self.files.values())


class FakeAuthority:
    """Test fixture transport; not a canonical owner-message retrieval bridge."""
    def __init__(self, message):
        self.message = copy.deepcopy(message)
        self.available = True

    def get(self, message_id):
        check(self.available, "authority_unavailable")
        check(self.message["message_id"] == message_id, "authority_id")
        return copy.deepcopy(self.message)


class FakeCanonicalTimeline:
    """Existing canonical message shape, INERT responses only; no API access."""
    def __init__(self, messages):
        self.messages, self.available = copy.deepcopy(messages), True
        self.profile = {"profile_id": "1f397680dd1cddc0.2.33", "project_id": 2,
                        "participant_id": 36, "agent_id": 33,
                        "syndicatum_url": "https://syndicatum.wizaya.com"}

    def get_message(self, identity):
        check(self.available and identity in self.messages, "canonical_message_unavailable")
        return {"profile": copy.deepcopy(self.profile), "message": copy.deepcopy(self.messages[identity])}


class CanonicalAuthority:
    """Canonical approval/request comparison through a sealed fake transport.

    Retrieval/authentication is deliberately not inferred from the message body.
    A real protected-tool transport remains closed/unimplemented here.
    """
    @staticmethod
    def material(message):
        value = {key: message[key] for key in ("id", "project_id", "body", "action_requested",
                                               "action_request_type", "edited", "revision_count", "deleted_at")}
        value["sender_id"] = message["sender"]["participant_id"]
        value["addressees"] = sorted((item["participant_id"], item["reason"]) for item in message["addressees"])
        return value

    def __init__(self, timeline, request_id, approval_id, trusted_request_sha256, plan_context_sha256):
        check(type(timeline) is FakeCanonicalTimeline, "source_only_canonical_transport")
        self.timeline, self.request_id, self.approval_id = timeline, request_id, approval_id
        self.request_digest, self.context = trusted_request_sha256, plan_context_sha256
        self.latest = None

    def get(self, message_id):
        check(message_id == self.approval_id, "canonical_approval_id")
        request = self.timeline.get_message(self.request_id)
        self.latest = {"request": request, "approval": None}
        approval = self.timeline.get_message(self.approval_id)
        self.latest["approval"] = approval  # Retain available rejected input too.
        expected_profile = {"profile_id": "1f397680dd1cddc0.2.33", "project_id": 2,
                            "participant_id": 36, "agent_id": 33,
                            "syndicatum_url": "https://syndicatum.wizaya.com"}
        check(all({key: response["profile"][key] for key in expected_profile} == expected_profile
                  for response in (request, approval)), "canonical_identity")
        r, a = request["message"], approval["message"]
        check(digest(packed(self.material(r))) == self.request_digest, "canonical_request_changed")
        check(r["id"] == self.request_id and r["project_id"] == a["project_id"] == 2 and
              r["sender"]["participant_id"] == 36 and r["action_requested"] is True and
              r["action_request_type"] == "approval" and r["edited"] is False and r["revision_count"] == 0 and
              r["deleted_at"] is None and a["deleted_at"] is None and
              28 in [item["participant_id"] for item in r["addressees"]], "canonical_request_scope")
        event = a["system_event"]
        check(a["id"] == self.approval_id and a["sender"]["participant_id"] == 28 and
              a["message_kind"] == "system" and a["edited"] is False and a["revision_count"] == 0 and
              a["reply_to_message_id"] == self.request_id and
              36 in [item["participant_id"] for item in a["addressees"]] and
              event["type"] == "responsibility.approval_approved" and
              event["data"]["request_message_id"] == self.request_id and
              event["data"]["actor_participant_id"] == event["data"]["initial_responder_participant_id"] == 28 and
              event["data"]["kind"] == "approval_approved", "canonical_owner_approval")
        body = json.loads(r["body"])
        check(packed(body).decode() == r["body"] and set(body) == {"purpose", "plan_context_sha256", "receipt"}
              and body["purpose"] == "Developer-only preparation" and body["plan_context_sha256"] == self.context,
              "canonical_plan_context")
        return {**body["receipt"], "server": expected_profile["syndicatum_url"], "project_id": 2,
                "message_id": a["id"], "sender_id": 28, "addressee_id": 36, "revision": 0,
                "body_sha256": digest(r["body"].encode()), "revoked": False}


class FakeFixedSessionAPI:
    """Low-level existing-file session contract matching the Windows callbacks."""
    def __init__(self, manifest):
        self.manifest, self.held, self.fault = copy.deepcopy(manifest), False, None
        self.files = {name: bytearray(pin["length"]) for name, pin in manifest["files"].items()}
        self.calls = []

    def open_existing_pair(self):
        check(not self.held, "session_contended")
        self.held = True
        self.calls.append(("open_pair",))

    def inspect(self):
        check(self.held, "session_not_held")
        value = copy.deepcopy(self.manifest)
        for name in value["files"]:
            value["files"][name]["length"] = len(self.files[name])
        return value

    def read(self, name, offset, count):
        check(self.held, "session_not_held")
        return bytes(self.files[name][offset:offset + count])

    def write(self, name, offset, raw):
        check(self.held, "session_not_held")
        self.calls.append(("write", name, offset))
        if self.fault == "partial":
            self.files[name][offset:offset + 7] = raw[:7]
            raise Stop("session_partial_write")
        self.files[name][offset:offset + len(raw)] = raw

    def flush(self, name):
        self.calls.append(("file_flush", name))
        check(self.fault != "flush", "session_flush_uncertain")

    def close(self):
        if self.held:
            self.calls.append(("close_pair",))
        self.held = False


class SessionStorage:
    """Pinned projection plus FULL native-manifest checks at every file operation."""
    def __init__(self, api, full_manifest_raw, full_manifest_sha256):
        check(type(api) is FakeFixedSessionAPI, "source_only_storage_transport")
        check(digest(full_manifest_raw) == full_manifest_sha256, "full_storage_manifest_anchor")
        self.full = json.loads(full_manifest_raw)
        check(packed(self.full) == full_manifest_raw and set(self.full["files"]) == {"records", "heads"}, "full_manifest_schema")
        self.api = api
        m = self.full
        for pin in m["files"].values():
            check(set(pin) == {"path", "identity", "length", "acl", "final_path"} and
                  pin["length"] == m["slots"] * m["width"] and all(type(pin[k]) is str and pin[k]
                  for k in ("path", "identity", "acl", "final_path")), "full_file_pin")
        self.manifest = {"namespace": m["namespace"], "root": m["root"], "slots": m["slots"], "width": m["width"],
                         "record_id": m["files"]["records"]["identity"], "head_id": m["files"]["heads"]["identity"]}

    def acquire(self):
        self.api.open_existing_pair()

    def release(self):
        self.api.close()

    def observe(self):
        check(self.api.inspect() == self.full, "full_storage_identity_acl_path_drift")
        return copy.deepcopy(self.manifest)

    def sizes(self):
        self.observe()
        return {name: pin["length"] for name, pin in self.full["files"].items()}

    def pristine(self):
        return all(not any(self.read(name, index)) for name in ("records", "heads")
                   for index in range(self.manifest["slots"]))

    def read(self, kind, index):
        self.observe()
        width = self.manifest["width"]
        check(0 <= index < self.manifest["slots"], "session_read_bound")
        raw = self.api.read(kind, index * width, width)
        check(len(raw) == width, "session_short_read")
        return raw

    def write(self, kind, index, raw):
        check(len(raw) == self.manifest["width"] and not any(self.read(kind, index)), "session_occupied_slot")
        self.api.write(kind, index * self.manifest["width"], raw)
        self.observe()

    def flush(self, kind):
        self.observe()
        self.api.flush(kind)
        self.observe()


def frame(value, width):
    raw = packed(value)
    check(len(raw) + 44 <= width, "slot_capacity")
    return (b"REC1" + struct.pack(">I", len(raw)) + hashlib.sha256(raw).digest() + raw
            + b"DONE" + bytes(width - len(raw) - 44))


def unframe(raw):
    check(len(raw) >= 44 and raw[:4] == b"REC1", "partial_or_torn_slot")
    length = struct.unpack(">I", raw[4:8])[0]
    check(0 < length <= len(raw) - 44, "slot_length")
    body = raw[40:40 + length]
    check(hashlib.sha256(body).digest() == raw[8:40] and raw[40 + length:44 + length] == b"DONE"
          and not any(raw[44 + length:]), "partial_or_torn_slot")
    value = json.loads(body)
    check(packed(value) == body, "noncanonical_slot")
    return value


class FixedJournal:
    """One pinned authority/attempt namespace. Existing bytes are never resumed."""
    def __init__(self, storage, pinned_manifest_raw, pinned_manifest_sha256,
                 authority, expected_message_raw, externally_retained_head, reviewed_plan_raw, now_ms):
        self.storage, self.authority = storage, authority
        self.closed, self.owned = False, False
        self.index, self.previous = 0, "0" * 64
        self.external_head = externally_retained_head
        try:
            check(type(storage) in (MemoryStorage, SessionStorage) and
                  type(authority) in (FakeAuthority, CanonicalAuthority), "source_only_fake_seams")
            check(type(pinned_manifest_raw) is bytes and digest(pinned_manifest_raw) == pinned_manifest_sha256,
                  "manifest_anchor")
            self.pin = json.loads(pinned_manifest_raw)
            check(packed(self.pin) == pinned_manifest_raw, "manifest_canonical")
            check(set(self.pin) == {"namespace", "root", "record_id", "head_id", "slots", "width"}
                  and all(type(self.pin[k]) is str and self.pin[k] for k in ("namespace", "root", "record_id", "head_id"))
                  and self.pin["record_id"] != self.pin["head_id"]
                  and type(self.pin["slots"]) is int and 1 <= self.pin["slots"] <= 512
                  and type(self.pin["width"]) is int and 512 <= self.pin["width"] <= 65536, "manifest_schema")
            self.expected = json.loads(expected_message_raw)
            check(packed(self.expected) == expected_message_raw, "receipt_canonical")
            check(set(self.expected) == {"server", "project_id", "message_id", "sender_id", "addressee_id",
                  "revision", "body_sha256", "plan_sha256", "attempt_id", "cutoff_id", "scopes", "revoked",
                  "not_before_ms", "expires_ms", "mechanism_head", "candidate_commit", "notification_interruption"}
                  and self.expected["server"] == "https://syndicatum.wizaya.com"
                  and self.expected["project_id"] == 2 and self.expected["sender_id"] == 28
                  and self.expected["addressee_id"] == 36 and type(self.expected["message_id"]) is int
                  and self.expected["message_id"] > 11562 and self.expected["attempt_id"] != "11545"
                  and self.expected["revoked"] is False, "receipt_scope")
            check(all(type(self.expected[key]) is int for key in
                      ("project_id", "sender_id", "addressee_id", "revision"))
                  and self.expected["revision"] >= 0
                  and all(type(self.expected[key]) is str and
                          re.fullmatch(r"[0-9a-f]{64}", self.expected[key])
                          for key in ("body_sha256", "plan_sha256"))
                  and all(type(self.expected[key]) is str and bool(self.expected[key])
                          for key in ("attempt_id", "cutoff_id"))
                  and type(self.expected["scopes"]) is list and bool(self.expected["scopes"])
                  and all(type(scope) is str and bool(scope) for scope in self.expected["scopes"])
                  and len(set(self.expected["scopes"])) == len(self.expected["scopes"]), "receipt_schema")
            self.now = now_ms  # Trusted injected clock; no plan-controlled time.
            self.reviewed_plan = json.loads(reviewed_plan_raw)
            check(packed(self.reviewed_plan) == reviewed_plan_raw and
                  digest(reviewed_plan_raw) == self.expected["plan_sha256"], "reviewed_plan_digest")
            check(set(self.reviewed_plan) == {"mechanism_head", "candidate_commit", "attempt_id", "cutoff_id",
                  "notification_interruption", "operations", "storage_manifest_sha256"} and
                  self.reviewed_plan["storage_manifest_sha256"] == pinned_manifest_sha256, "reviewed_plan_schema")
            for key in ("mechanism_head", "candidate_commit", "attempt_id", "cutoff_id", "notification_interruption"):
                check(self.expected[key] == self.reviewed_plan[key], "reviewed_plan_binding")
            check(type(self.expected["mechanism_head"]) is str and
                  re.fullmatch(r"[0-9a-f]{40}", self.expected["mechanism_head"]) and
                  self.expected["candidate_commit"] == "9141daa08d896f2524be30b96d01ab2a14398757" and
                  self.expected["notification_interruption"] is True, "reviewed_release_or_interruption")
            operations = self.reviewed_plan["operations"]
            check(type(operations) is dict and bool(operations) and all(
                  type(name) is str and bool(name) and type(context) is str and bool(context)
                  for name, context in operations.items()) and
                  sorted(operations) == sorted(self.expected["scopes"]), "reviewed_operation_scope")
            # Null means approved empty established storage, not permission to
            # rebuild erased storage. Establishment/identity proof is external.
            check(externally_retained_head is None, "existing_external_head_no_resume")
            storage.acquire()
            self.owned = True
            self._pin()
            check(storage.pristine(), "occupied_or_partial_attempt_no_resume")
            self._append("reservation", {"authority": self.expected})
            self._authority()
        except Exception:
            self._stop()
            raise

    def _stop(self):
        self.closed = True
        if self.owned:
            self.storage.release()
            self.owned = False

    def _pin(self):
        check(self.storage.observe() == self.pin, "storage_identity_or_namespace_changed")
        expected_size = self.pin["slots"] * self.pin["width"]
        sizes = self.storage.sizes()
        check(set(sizes) == {"records", "heads"} and
              all(size == expected_size for size in sizes.values()), "storage_capacity_changed")

    def _authority(self):
        current = self.authority.get(self.expected["message_id"])
        check(packed(current) == packed(self.expected), "canonical_authority_changed")
        start, end, now = current["not_before_ms"], current["expires_ms"], self.now()
        check(type(start) is int and type(end) is int and type(now) is int and
              0 <= start <= now < end, "authority_time_window")

    def verify(self, independent_head):
        try:
            self._verify(independent_head)
        except Exception:
            self._stop()
            raise

    def _verify(self, independent_head):
        check(not self.closed, "terminal_no_replay")
        self._pin()
        check(independent_head == self.external_head, "independent_head_changed")
        previous = "0" * 64
        for index in range(self.pin["slots"]):
            record, head = self.storage.read("records", index), self.storage.read("heads", index)
            if index >= self.index:
                check(not any(record) and not any(head), "occupied_or_partial_future_slot")
                continue
            value, reference = unframe(record), unframe(head)
            check(value["sequence"] == index and value["previous"] == previous and
                  value["namespace"] == self.pin["namespace"] and value["authority_id"] == self.expected["message_id"]
                  and value["attempt_id"] == self.expected["attempt_id"] and
                  value["plan_sha256"] == self.expected["plan_sha256"], "record_binding")
            previous = digest(record)
            check(reference == {"sequence": index, "record_sha256": previous,
                                "namespace": self.pin["namespace"]}, "head_binding")
        check(previous == self.previous, "chain_changed")
        if self.index:
            check(digest(self.storage.read("heads", self.index - 1)) == independent_head, "retained_head_mismatch")

    def _append(self, kind, data):
        self.verify(self.external_head)
        check(self.index < self.pin["slots"], "journal_capacity")
        value = {"sequence": self.index, "previous": self.previous, "namespace": self.pin["namespace"],
                 "authority_id": self.expected["message_id"], "attempt_id": self.expected["attempt_id"],
                 "plan_sha256": self.expected["plan_sha256"], "kind": kind, "data": data}
        record = frame(value, self.pin["width"])
        head = frame({"sequence": self.index, "record_sha256": digest(record),
                      "namespace": self.pin["namespace"]}, self.pin["width"])
        for name, raw in (("records", record), ("heads", head)):
            self.storage.write(name, self.index, raw)
            self.storage.flush(name)
            check(self.storage.read(name, self.index) == raw, "write_readback_changed")
            unframe(raw)
            self._pin()
        self.previous = digest(record)
        self.external_head = digest(head)
        self.index += 1
        self.verify(self.external_head)
        return self.external_head

    def model_action(self, name, independent_head, operation_context=None):
        """Records synthetic intent only. No callback, process or mutation API."""
        try:
            self.verify(independent_head)
            self._authority()
            check(name in self.expected["scopes"], "action_scope")
            check(operation_context == self.reviewed_plan["operations"][name], "reviewed_operation_context")
            for index in range(self.index):
                prior = unframe(self.storage.read("records", index))
                check(not (prior["kind"] == "intent" and prior["data"]["operation"] == name), "operation_replay")
            head = self._append("intent", {"operation": name, "reviewed_context": operation_context})
            # Evidence writes/flushes may be slow. A prior time check is not
            # action admission; changed/expired authority consumes the intent.
            self._authority()
            return {"head_sha256": head, "synthetic_intent_only": True, "authorizes_mutation": False}
        except Exception:
            self._stop()
            raise

    def append_evidence(self, kind, data, independent_head):
        """Private evidence only; never grants a command or installation capability."""
        try:
            self.verify(independent_head)
            return self._append(kind, data)
        except Exception:
            self._stop()
            raise
