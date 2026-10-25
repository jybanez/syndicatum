"""Trusted cooperating-agent ingress, not a cryptographic MCP attestation.

Only the trusted host constructs this object and its invocation callback. A plan
cannot supply a receipt path, callback, time source or canonical response body.
Native IO remains behind WindowsSessionAPI's closed process default.
"""
import base64
import copy
import json
import secrets

from codex_developer_fixed_journal import SessionStorage, check, digest, frame, packed, unframe

PROFILE = "1f397680dd1cddc0.2.33"
TOOL = "mcp__syndicatum_codex__syndicatum_get_message"


class TrustedAgentChannel:
    """One host invocation -> one pinned, read-back receipt -> one delivery.

    trusted_invoke calls the exact protected tool and returns its ORIGINAL UTF-8
    result bytes with the invocation ID/arguments and host-observed start/finish
    times. This callback is part of the trusted Codex host, never plan input.
    Inert callbacks test protocol properties, not live provenance or file ACLs.
    """
    def __init__(self, trusted_invoke, now_ms, receipt_api, manifest_raw,
                 trusted_manifest_sha256, request_id, approval_id, context_sha256):
        check(callable(trusted_invoke) and callable(now_ms), "trusted_host_callbacks")
        check(digest(manifest_raw) == trusted_manifest_sha256, "private_receipt_manifest_pin")
        self.storage = SessionStorage(receipt_api, manifest_raw, trusted_manifest_sha256)
        check(self.storage.manifest["width"] >= 49152, "private_receipt_slot_capacity")
        self.invoke, self.now = trusted_invoke, now_ms
        self.ids, self.context = (request_id, approval_id), context_sha256
        self.manifest_sha256 = trusted_manifest_sha256
        self.index, self.previous, self.started, self.closed = 0, None, False, False
        self.sequence, self.session, self.history = 0, None, []
        self.deliveries = []

    def check_cached_freshness(self, started):
        check(not self.closed, "trusted_receipt_terminal")
        self._fresh(started, started)

    def _fresh(self, started, finished):
        now = self.now()
        check(all(type(v) is int for v in (started, finished, now)) and
              0 <= started <= finished <= now <= started + 120000, "trusted_receipt_stale_or_clock")

    def exchange(self, request):
        try:
            args = request["arguments"]
            check(not self.closed and request["operation"] == "protected_get_message" and
                  args == {"tool": "syndicatum_get_message", "profile_id": PROFILE, "message_id": args["message_id"]} and
                  type(args["message_id"]) is int and args["message_id"] in self.ids and
                  type(request["sequence"]) is int and request["sequence"] == self.sequence + 1 and
                  (self.session is None or request["session"] == self.session), "trusted_receipt_invocation_scope")
            self.sequence, self.session = request["sequence"], request["session"]
            # Challenge is issued by the trusted caller, never derived from a plan.
            invocation = {"invocation_id": secrets.token_hex(24), "tool": TOOL,
                          "arguments": {"profile_id": PROFILE, "message_id": args["message_id"]},
                          "request_id": self.ids[0], "approval_id": self.ids[1], "context_sha256": self.context,
                          "session": self.session, "sequence": self.sequence}
            result = self.invoke(copy.deepcopy(invocation))
            if type(result) is dict and set(result) == {"invocation", "raw_base64", "started_ms", "finished_ms"}:
                check(type(result["raw_base64"]) is str and len(result["raw_base64"]) <= 43692, "host_delivery_base64_bound")
                result = {**result, "raw_utf8": base64.b64decode(result["raw_base64"], validate=True)}
                del result["raw_base64"]
            check(type(result) is dict and set(result) == {"invocation", "raw_utf8", "started_ms", "finished_ms"} and
                  result["invocation"] == invocation and type(result["raw_utf8"]) is bytes and
                  0 < len(result["raw_utf8"]) <= 32768, "trusted_receipt_substitution_or_bound")
            self._fresh(result["started_ms"], result["finished_ms"])
            if not self.started:
                self.started = True
                self.storage.acquire()
                check(self.storage.pristine(), "private_receipt_occupied_no_resume")
            check(self.index < self.storage.manifest["slots"], "private_receipt_capacity")
            # Retain exact bytes, not a reserialized body or normalized message.
            receipt = {"invocation": invocation, "started_ms": result["started_ms"], "finished_ms": result["finished_ms"],
                       "raw_sha256": digest(result["raw_utf8"]), "raw_base64": base64.b64encode(result["raw_utf8"]).decode(),
                       "previous": self.previous}
            self._verify_history()
            raw = frame(receipt, self.storage.manifest["width"])
            for name in ("records", "heads"):
                self.storage.write(name, self.index, raw)
                self.storage.flush(name)
                check(self.storage.read(name, self.index) == raw, "private_receipt_readback")
            self.history.append(packed(receipt))
            self.previous = digest(packed(receipt))
            self.index += 1
            self.deliveries.append({"started_ms": result["started_ms"], "finished_ms": result["finished_ms"],
                                    "sha256": digest(result["raw_utf8"])})
            self._fresh(result["started_ms"], result["finished_ms"])
            value = json.loads(result["raw_utf8"])
            check(value["profile"]["profile_id"] == PROFILE and value["message"]["id"] == args["message_id"],
                  "trusted_receipt_response_identity")
            # Actor/event/request/context validation stays in CanonicalAuthority.
            return {"session": self.session, "sequence": self.sequence, "ok": True, "value": value}
        except Exception as error:
            try: self.close_terminal()
            except Exception:
                error.close_uncertain = True
                error.add_note("trusted_receipt_close_uncertain; no replay")
            raise

    def _verify_history(self):
        for index, raw in enumerate(self.history):
            for name in ("records", "heads"):
                check(packed(unframe(self.storage.read(name, index))) == raw, "private_receipt_history_drift")

    def close_terminal(self):
        if not self.closed:
            self.closed = True
            if self.started: self.storage.release()
