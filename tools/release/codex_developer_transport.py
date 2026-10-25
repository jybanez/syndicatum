"""Closed-gate persistent transport and protected-host contracts. No CLI entry.

Native process launch and protected-tool dispatch unconditionally refuse. Inert
channels exercise the SAME framing/session/receipt/handoff code without native IO.
"""
import base64
import copy
import json

from codex_developer_fixed_journal import Stop, check, digest, packed, frame, unframe

PROFILE = "1f397680dd1cddc0.2.33"
MAX_WIRE = 2 * 1024 * 1024


class ClosedWindowsProcess:
    """One future process, one stdin/stdout session, never spawn per request.

    There is deliberately no launch escape hatch or caller-supplied executable.
    Full procedure review must precede implementing/opening process admission.
    """
    def exchange(self, request):
        raise Stop("source_only_persistent_windows_gate")


class ClosedProtectedHost:
    """Agent-host boundary; Python cannot directly invoke a protected MCP tool.

    The installed tool has get_message(profile_id, message_id), but no opaque
    authenticated receipt or durable compare-and-advance handoff operation. A
    pasted JSON response is not a trusted host dispatch. No HTTP/token fallback.
    """
    def exchange(self, request):
        raise Stop("protected_host_dispatch_and_durable_handoff_unavailable")


class InertChannel:
    """Sealed test adapter; callback outputs carry NO live provenance claim."""
    def __init__(self, handler):
        self.handler, self.requests = handler, []

    def exchange(self, request):
        self.requests.append(copy.deepcopy(request))
        return self.handler(copy.deepcopy(request))


class CorrelatedChannel:
    def __init__(self, channel, session):
        check(type(channel) in (InertChannel, ClosedWindowsProcess, ClosedProtectedHost), "unreviewed_transport")
        check(type(session) is str and bool(session), "transport_session")
        self.channel, self.session, self.sequence, self.terminal = channel, session, 0, False

    def call(self, operation, arguments):
        check(not self.terminal, "transport_terminal_no_replay")
        self.sequence += 1
        request = {"session": self.session, "sequence": self.sequence, "operation": operation, "arguments": arguments}
        try:
            check(len(packed(request)) <= MAX_WIRE, "transport_request_bound")
            response = self.channel.exchange(request)
            check(type(response) is dict and len(packed(response)) <= MAX_WIRE and
                  set(response) == {"session", "sequence", "ok", "value"} and
                  response["session"] == self.session and type(response["sequence"]) is int and
                  response["sequence"] == self.sequence and response["ok"] is True,
                  "transport_uncertain_response")
            return response["value"]
        except Exception:
            self.terminal = True
            raise


class WindowsSessionAPI:
    """One continuously held existing pair via correlated bounded request frames.

    A timeout/EOF/unknown reply terminally poisons the session. No reconnect or
    automatic replay. An explicit close may be attempted once on a healthy wire;
    transport uncertainty instead records close uncertainty, never reconnects.
    """
    def __init__(self, manifest_raw, channel=None, session="developer-fixed-session"):
        self.manifest = json.loads(manifest_raw)
        check(packed(self.manifest) == manifest_raw, "transport_manifest_canonical")
        self.wire = CorrelatedChannel(channel or ClosedWindowsProcess(), session)
        self.held, self.open_attempted, self.close_attempted, self.close_uncertain = False, False, False, False

    def open_existing_pair(self):
        check(not self.open_attempted, "persistent_session_no_reopen")
        self.open_attempted = True
        raw = packed(self.manifest)
        try:
            result = self.wire.call("open", {"manifest_base64": base64.b64encode(raw).decode(), "sha256": digest(raw)})
            check(result == self.manifest, "persistent_session_manifest")
            self.held = True
        except Exception as error:
            try: self.close()
            except Exception:
                error.close_uncertain = True
                error.add_note("open_outcome_and_close_uncertain; no reconnect/replay")
            raise

    def inspect(self):
        check(self.held, "persistent_session_not_held")
        return self.wire.call("inspect", {})

    def _slot(self, name, offset, length):
        width = self.manifest["width"]
        check(self.held and name in ("records", "heads") and type(offset) is int and
              length == width and offset % width == 0 and 0 <= offset // width < self.manifest["slots"],
              "persistent_session_slot_bounds")
        return {"name": name, "index": offset // width}

    def read(self, name, offset, length):
        value = self.wire.call("read", self._slot(name, offset, length))
        raw = base64.b64decode(value, validate=True)
        check(len(raw) == length, "persistent_session_short_read")
        return raw

    def write(self, name, offset, raw):
        args = self._slot(name, offset, len(raw))
        args["base64"] = base64.b64encode(raw).decode()
        check(self.wire.call("write", args) == {"written": True}, "persistent_session_write_uncertain")

    def flush(self, name):
        check(self.held and name in ("records", "heads"), "persistent_session_flush_scope")
        check(self.wire.call("flush", {"name": name}) == {"flushed": True}, "persistent_session_flush_uncertain")

    def close(self):
        if self.close_attempted or not self.open_attempted:
            return
        self.close_attempted = True
        try:
            check(self.wire.call("close", {}) == {"closed": True}, "persistent_session_close_uncertain")
            self.held = False
        except Exception:
            self.close_uncertain = True
            raise


class ProtectedCanonicalTimeline:
    """Protected host dispatch contract, never caller-supplied canonical JSON.

    Both request/approval are fetched afresh through exact profile-bound calls.
    Only a trusted host can supply their provenance; InertChannel proves framing,
    not authentication. Production dispatch is closed with a precise blocker.
    """
    def __init__(self, channel=None, session="developer-protected-host"):
        self.wire = CorrelatedChannel(channel or ClosedProtectedHost(), session)

    def get_message(self, identity):
        check(type(identity) is int and identity > 0, "protected_message_id")
        return self.wire.call("protected_get_message", {"tool": "syndicatum_get_message",
                              "profile_id": PROFILE, "message_id": identity})


class DurableHead:
    """Caller-owned independent fixed-session head ledger; no writer access.

    Requires a separately pinned, already-existing pair owned by the trusted host,
    not the preparation writer. This class never creates a container. The host's
    independent ownership/provenance is an explicit unresolved live admission.
    """
    def __init__(self, api, manifest_raw):
        check(type(api) is WindowsSessionAPI, "independent_head_transport")
        from codex_developer_fixed_journal import SessionStorage
        self.storage = SessionStorage(api, manifest_raw, digest(manifest_raw))
        self.manifest_sha256 = digest(manifest_raw)
        self.value, self.history, self.closed, self.index = None, [], False, 0
        self.started = False

    def retain(self, core, head, expected_previous):
        check(not self.closed, "durable_handoff_terminal")
        try:
            if not self.started:
                self.started = True
                self.storage.acquire()
                check(self.storage.pristine(), "durable_handoff_existing_attempt_no_replay")
            check((self.value is None and expected_previous is None) or
                  (self.value is not None and packed(self.value) == expected_previous), "durable_handoff_compare_and_advance")
            self._verify_history()
            value = {"head": head, "sequence": core.index, "plan_sha256": core.expected["plan_sha256"],
                     "attempt_id": core.expected["attempt_id"], "namespace": core.pin["namespace"],
                     "receipt": copy.deepcopy(core.expected)}
            check(self.index < self.storage.manifest["slots"], "durable_handoff_capacity")
            raw = frame({"previous": digest(self.history[-1]) if self.history else None, "value": value},
                        self.storage.manifest["width"])
            for name in ("records", "heads"):
                self.storage.write(name, self.index, raw)
                self.storage.flush(name)
                check(self.storage.read(name, self.index) == raw, "durable_handoff_readback")
            self.value = value
            self.history.append(packed(value))
            self.index += 1
        except Exception as error:
            try: self.close_terminal()
            except Exception:
                error.close_uncertain = True
                error.add_note("durable_handoff_close_uncertain; no retry")
            raise

    def head(self, core):
        check(not self.closed and self.value == {"head": core.external_head, "sequence": core.index,
              "plan_sha256": core.expected["plan_sha256"], "attempt_id": core.expected["attempt_id"],
              "namespace": core.pin["namespace"], "receipt": core.expected}, "durable_handoff_changed")
        self._verify_history()
        return self.value["head"]

    def _verify_history(self):
        for index, raw in enumerate(self.history):
            expected = {"previous": digest(self.history[index - 1]) if index else None, "value": json.loads(raw)}
            for name in ("records", "heads"):
                check(unframe(self.storage.read(name, index)) == expected, "durable_handoff_persisted_drift")

    def close_terminal(self):
        if not self.closed:
            self.closed = True
            if self.started:
                self.storage.release()
