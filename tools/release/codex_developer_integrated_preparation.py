"""SOURCE ONLY: fixed-session journal + canonical receipt + preparation binding.

Closed transport defaults; inert channels only in tests. No setup/install entry.
"""
import base64
import json
import math

from codex_developer_fixed_journal import (CanonicalAuthority, FakeFixedSessionAPI, FixedJournal,
    SessionStorage, Stop, check, digest, packed, unframe)
from codex_developer_native_b import DeveloperBAdapter
from codex_developer_transport import DurableHead, WindowsSessionAPI


SCOPES = {"connector_task_run_suppression", "owned_listener_stop", "private_cutoff_capture"}
OPERATIONS = ("suppress", "stop", "cutoff")
LIFECYCLE_OPERATIONS = ("marketplace_remove", "marketplace_add", "plugin_add", "owner_activation", "acceptance_observe")


def plan_context(plan):
    # The approval event ID is known only AFTER approving the reviewed request.
    # Exclude only that ID to avoid a self-referential approval/plan digest.
    return digest(packed({key: value for key, value in plan.items() if key != "owner_authority_message_id"}))


class RetainedHead:
    """Trusted caller-owned handoff, separate from the mutable writer bytes.

    Inert object only; durable/authenticated live handoff remains an admission gap.
    """
    def __init__(self):
        self.value = None
        self.history = []

    def retain(self, core, head, expected_previous):
        check((self.value is None and expected_previous is None) or
              (self.value is not None and packed(self.value) == expected_previous), "trusted_handoff_compare_and_advance")
        value = {"head": head, "sequence": core.index, "plan_sha256": core.expected["plan_sha256"],
                 "attempt_id": core.expected["attempt_id"], "namespace": core.pin["namespace"]}
        self.value = value
        self.history.append(packed(value))

    def head(self, core):
        check(self.value is not None and self.value == {"head": core.external_head, "sequence": core.index,
              "plan_sha256": core.expected["plan_sha256"], "attempt_id": core.expected["attempt_id"],
              "namespace": core.pin["namespace"]}, "trusted_handoff_changed")
        return self.value["head"]

    def close_terminal(self):
        pass  # Inert volatile handoff owns no OS resource.


class FixedPreparationJournal:
    """Preparation journal protocol over existing fixed containers; no directories.

    Large logical observations are bounded multipart records plus commit, with a
    flushed/read-back independent head per physical record. A partial attempt is
    terminal and cannot be resumed, rebuilt or replayed.
    """
    def __init__(self, core, handoff, native_context, publication_limits=None):
        check(type(core) is FixedJournal and type(handoff) in (RetainedHead, DurableHead) and handoff.value is None,
              "new_fixed_handoff_required")
        self.core, self.handoff, self.context = core, handoff, native_context
        self.spans, self.admitted, self.pending = [], [], None
        self.terminal = False
        self.publication_limits=publication_limits
        try:
            handoff.retain(core, core.external_head, None)
        except Exception as error:
            self._abort(error)
            raise

    @property
    def retained_head(self):
        return self.handoff.head(self.core)

    def verify(self, retained_head):
        check(not self.terminal and retained_head == self.retained_head, "integrated_journal_terminal_or_handoff")
        self.core.verify(retained_head)
        for start, count, expected_sha, logical_id in self.spans:
            chunks = []
            for index in range(count):
                record = unframe(self.core.storage.read("records", start + index))
                data = record["data"]
                check(record["kind"] == "preparation_part" and data["logical_id"] == logical_id and
                      data["part"] == index and data["parts"] == count and data["sha256"] == expected_sha,
                      "integrated_part_binding")
                chunks.append(base64.b64decode(data["raw_base64"], validate=True))
            raw = b"".join(chunks)
            commit = unframe(self.core.storage.read("records", start + count))
            check(digest(raw) == expected_sha and commit["kind"] == "preparation_commit" and
                  commit["data"] == {"logical_id": logical_id, "parts": count, "sha256": expected_sha,
                                     "plan_context_sha256": self.context}, "integrated_logical_commit")
            value = json.loads(raw)
            check(packed(value) == raw and value["plan_context_sha256"] == self.context,
                  "integrated_logical_context")

    def _append(self, kind, value):
        previous = packed(self.handoff.value)
        head = self.core.append_evidence(kind, value, self.retained_head)
        self.handoff.retain(self.core, head, previous)

    def append(self, boundary, producer, data):
        try:
            self.verify(self.retained_head)
            observation = {"boundary": boundary, "producer": producer, "data": data,
                           "plan_context_sha256": self.context,
                           "canonical_evidence": self.core.authority.latest}
            raw = packed(observation)
            if self.publication_limits is not None:
                limits=self.publication_limits
                index=len(self.spans)
                if index<len(limits['lengths']):limit=limits['lengths'][index]
                else:
                    check(index<len(limits['lengths'])+limits['failure_count'] and
                          (producer in ('stopped','uncertain-dispatch-evidence','failed-command-postimage') or boundary=='failure-postimage'),
                          'unreviewed_logical_publication')
                    limit=max(limits['lengths'])
                check(len(raw)<=limit,'admitted_logical_projection_exceeded')
            check(len(raw) <= 33554432 and self.core.pin["width"] >= 2048, "integrated_evidence_bound")
            chunk_size = (self.core.pin["width"] - 1024) * 3 // 4
            count = max(1, math.ceil(len(raw) / chunk_size))
            intent = producer == "intent"
            check(self.core.index + count + 1 + int(intent) <= self.core.pin["slots"], "integrated_capacity_stop")
            if intent:
                operation = data["operation"]
                check(operation in OPERATIONS and self.pending is None, "integrated_intent_order")
                previous = packed(self.handoff.value)
                result = self.core.model_action(operation, self.retained_head, self.context)
                self.handoff.retain(self.core, result["head_sha256"], previous)
                self.pending = operation
            start, logical_id = self.core.index, len(self.spans)
            payload_sha = digest(raw)
            for index in range(count):
                self._append("preparation_part", {"logical_id": logical_id, "part": index, "parts": count,
                    "sha256": payload_sha, "raw_base64": base64.b64encode(raw[index * chunk_size:(index + 1) * chunk_size]).decode()})
            self._append("preparation_commit", {"logical_id": logical_id, "parts": count, "sha256": payload_sha,
                                                "plan_context_sha256": self.context})
            self.spans.append((start, count, payload_sha, logical_id))
            # Refresh at every completed logical publication; physical multipart
            # writes only reuse the same bounded-age validated snapshot. This
            # avoids hundreds of redundant host tool round trips per observation.
            self.core.authority.refresh()
            self.verify(self.retained_head)
            self.core._authority()  # Final time check after potentially slow verification.
            return self.retained_head
        except Exception as error:
            self._abort(error)
            raise

    def admit(self, operation, head):
        try:
            self.verify(head)
            check(len(self.admitted) < len(OPERATIONS) and operation == OPERATIONS[len(self.admitted)] and
                  self.pending == operation, "integrated_action_replay_or_order")
            # AFTER all evidence writes, immediately before the backend operation.
            self.core.authority.refresh()
            self.core._authority()
            self.verify(head)
            self.core._authority()
            self.admitted.append(operation)
            self.pending = None
        except Exception as error:
            self._abort(error)
            raise

    def _abort(self, error):
        try: self.close_terminal()
        except Exception:
            error.close_uncertain = True
            error.add_note("integrated_close_uncertain; no retry")

    def check_final_time(self):
        receipt, now = self.core.expected, self.core.now()
        check(type(now) is int and receipt["not_before_ms"] <= now < receipt["expires_ms"],
              "terminal_authority_time_window")

    def admit_lifecycle(self, operation, head):
        try:
            check(operation in LIFECYCLE_OPERATIONS and self.admitted == list(OPERATIONS), "lifecycle_after_preparation_only")
            self.verify(head)
            self.core.authority.refresh()
            self.core._authority()
            previous = packed(self.handoff.value)
            result = self.core.model_action(operation, head, self.context)
            self.handoff.retain(self.core, result["head_sha256"], previous)
            self.verify(self.retained_head)
            self.core._authority()
            return self.retained_head
        except Exception as error:
            self._abort(error)
            raise

    def close_terminal(self):
        self.terminal = True
        errors = []
        for close in (self.core._stop, self.handoff.close_terminal, self.core.authority.close_terminal):
            try: close()
            except Exception as error: errors.append(error)
        if errors:
            error = errors[0]
            error.close_uncertain = True
            for secondary in errors[1:]: error.add_note("additional_terminal_close_uncertainty: " + type(secondary).__name__)
            raise error


class IntegratedPreparation:
    """Composes preparation with inert or closed-default session/host contracts."""
    def __init__(self, native_plan_raw, authority, session_api, full_manifest_raw,
                 reviewed_fixed_plan_raw, expected_receipt_raw, now_ms, handoff):
        check(type(authority) is CanonicalAuthority and type(session_api) in (FakeFixedSessionAPI, WindowsSessionAPI),
              "source_only_integrated_transports")
        self.plan = json.loads(native_plan_raw)
        check(packed(self.plan) == native_plan_raw, "integrated_native_plan_canonical")
        self.context = plan_context(self.plan)
        check(authority.context == self.context and authority.approval_id == self.plan["owner_authority_message_id"],
              "integrated_canonical_context_binding")
        full_sha = digest(full_manifest_raw)
        check(self.plan["fixed_storage_manifest_sha256"] == full_sha, "integrated_full_manifest_binding")
        self.storage = SessionStorage(session_api, full_manifest_raw, full_sha)
        from codex_developer_host_handoff import TrustedAgentChannel
        wire = getattr(authority.timeline, "wire", None)
        if wire is not None and type(wire.channel) is TrustedAgentChannel:
            host = wire.channel
            check(self.plan.get("private_receipt_manifest_sha256") == host.manifest_sha256 and
                  host.context == self.context and host.ids == (authority.request_id, authority.approval_id),
                  "trusted_host_receipt_context_pin")
            stores = [self.storage.manifest, host.storage.manifest]
            if type(handoff) is DurableHead: stores.append(handoff.storage.manifest)
            ids = [m[k] for m in stores for k in ("record_id", "head_id")]
            check(len(ids) == len(set(ids)), "trusted_host_receipt_storage_overlap")
        if type(handoff) is DurableHead:
            check(self.plan.get("independent_handoff_manifest_sha256") == handoff.manifest_sha256 and
                  handoff.storage.manifest["namespace"] != self.storage.manifest["namespace"] and
                  not ({handoff.storage.manifest[k] for k in ("record_id", "head_id")} &
                       {self.storage.manifest[k] for k in ("record_id", "head_id")}), "independent_handoff_plan_or_overlap")
        self.authority, self.now, self.handoff = authority, now_ms, handoff
        self.fixed_plan_raw, self.receipt_raw = reviewed_fixed_plan_raw, expected_receipt_raw
        fixed_plan = json.loads(reviewed_fixed_plan_raw)
        purpose = self.plan.get("authority_purpose", "Developer-only preparation")
        allowed = OPERATIONS + LIFECYCLE_OPERATIONS if purpose == "Developer-only pilot" else OPERATIONS
        check(authority.purpose == purpose and fixed_plan["operations"] == {name: self.context for name in allowed} and
              all(fixed_plan[key] == self.plan[key] for key in ("mechanism_head", "candidate_commit", "attempt_id", "cutoff_id")),
              "integrated_reviewed_context")
        self.journal = None
        self.built = False
        self.publication_limits = None

    def verify_authority(self, approval_id, native_digest, scopes):
        check(approval_id == self.plan["owner_authority_message_id"] and scopes == SCOPES and
              native_digest == digest(packed(self.plan)), "integrated_authority_binding")
        try:
            receipt = self.authority.get(approval_id)
        except Exception as error:
            try: self.authority.close_terminal()
            except Exception: error.close_uncertain = True
            raise
        check(packed(receipt) == self.receipt_raw, "integrated_expected_receipt")
        now = self.now()
        check(type(now) is int and type(receipt["not_before_ms"]) is int and type(receipt["expires_ms"]) is int and
              receipt["not_before_ms"] <= now < receipt["expires_ms"], "integrated_authority_expired")
        return True

    def journal_factory(self, plan, native_digest):
        check(self.journal is None and packed(plan) == packed(self.plan) and native_digest == digest(packed(plan)),
              "integrated_journal_reuse")
        projected = packed(self.storage.manifest)
        core = FixedJournal(self.storage, projected, digest(projected), self.authority,
                            self.receipt_raw, None, self.fixed_plan_raw, self.now)
        self.journal = FixedPreparationJournal(core, self.handoff, self.context,self.publication_limits)
        return self.journal

    def build(self, preparation_backend, *, lifecycle=False):
        # Live backends still hit their existing unconditional gates. The tests
        # provide the existing inert preparation backend; no real transport here.
        check(not self.built, "integrated_runner_no_replay")
        check(not lifecycle or self.plan.get("authority_purpose") == "Developer-only pilot", "explicit_pilot_authority_required")
        check(not lifecycle or self.publication_limits is not None,"pilot_capacity_not_admitted")
        self.built = True
        return DeveloperBAdapter(packed(self.plan), digest(packed(self.plan)), preparation_backend,
            self.verify_authority, now_ms=self.now, journal_factory=self.journal_factory,
            action_admission=lambda operation, head: self.journal.admit(operation, head),
            close_after_boundaries=not lifecycle)
