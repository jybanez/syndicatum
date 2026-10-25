"""Concrete B preparation adapter, SOURCE ONLY. Native execution is closed twice.

Tests inject an inert backend. No main, live packet creation or plugin activation.
"""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time

from codex_developer_freshness import require_frozen_observations, require_fresh_window, state

NATIVE_B_ENABLED = False
TASK = "\\Syndicatum Codex Connector"
RUN = re.compile(r"^Registry(?:32|64):SyndicatumCodexConnector$")


class NativeBStop(RuntimeError):
    pass


def require(condition, code):
    if not condition:
        raise NativeBStop(code)


def encode(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def anchored(raw, digest):
    require(type(raw) is bytes and len(raw) <= 1048576 and sha(raw) == digest, "plan_anchor")
    value = json.loads(raw)
    require(encode(value) == raw, "noncanonical_plan")
    return value


class WindowsBackend:
    """Not callable on production at this source revision, even for capture."""
    def __init__(self, module, module_sha256):
        self.module, self.module_sha256, self.counter = Path(module), module_sha256, 0

    @staticmethod
    def _wait(child):
        try:
            return child.wait(timeout=30)
        except subprocess.TimeoutExpired:
            failure = NativeBStop("native_timeout_uncertain")
            failure.pid = child.pid
            raise failure from None

    def invoke(self, operation, plan):
        require(NATIVE_B_ENABLED, "source_only_native_gate")
        require(operation in {"admission", "snapshot", "suppress", "stop", "cutoff", "flush"}, "native_operation")
        require(self.module_sha256 == plan["native_module_sha256"] and
                sha(self.module.read_bytes()) == self.module_sha256, "native_source_drift")
        powershell = Path(plan["powershell_executable"])
        require(powershell.is_absolute() and powershell.name.lower() == "pwsh.exe" and
                sha(powershell.read_bytes()) == plan["powershell_sha256"], "powershell_runtime_drift")
        # Validate the private transport/evidence parents BEFORE writing a plan
        # or redirecting potentially private output. Bootstrap contains paths and
        # fixed service identity only, never Run values or protected-state bytes.
        reduced = {key: plan[key] for key in ("data_root", "evidence_root", "head_root", "transport_root", "service_entry")}
        reduced.update(listener={key: plan["listener"][key] for key in ("executable", "command_line")}, static_files={})
        bootstrap = ("$ErrorActionPreference='Stop'; Import-Module -Name $env:SYNDICATUM_B_MODULE -Force; "
                     "$p=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:SYNDICATUM_B_BOOTSTRAP)) | ConvertFrom-Json -AsHashtable; "
                     "Invoke-DeveloperNative -Operation admission -Plan $p | ConvertTo-Json -Depth 8 -Compress")
        bootstrap_env = dict(os.environ, SYNDICATUM_B_MODULE=str(self.module),
                             SYNDICATUM_B_BOOTSTRAP=base64.b64encode(encode(reduced)).decode())
        probe = subprocess.Popen([str(powershell), "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", bootstrap],
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=bootstrap_env,
                                 cwd=str(powershell.parent), creationflags=0x08000000)
        try:
            output, error = probe.communicate(timeout=30)
        except subprocess.TimeoutExpired:
            failure = NativeBStop("native_admission_timeout_uncertain")
            failure.pid = probe.pid
            raise failure from None  # Read-only child is not killed automatically.
        require(probe.returncode == 0 and len(output) <= 65536 and len(error) <= 65536, "external_admission_failed")
        admitted = json.loads(output)
        require(admitted.get("private_roots_checked") is True, "external_admission_not_confirmed")
        if operation == "admission":
            return admitted
        # Static PowerShell code; no plan text/path is interpolated as shell code.
        script = ("$ErrorActionPreference='Stop'; Import-Module -Name $env:SYNDICATUM_B_MODULE -Force; "
                  "$p=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:SYNDICATUM_B_PLAN)) | ConvertFrom-Json -AsHashtable; "
                  "Invoke-DeveloperNative -Operation $env:SYNDICATUM_B_OPERATION -Plan $p | ConvertTo-Json -Depth 30 -Compress")
        prefix = Path(plan["transport_root"]) / f"native-{self.counter:06d}-{operation}"
        self.counter += 1
        plan_path = prefix.with_suffix(".plan.private.json")
        with plan_path.open("xb") as writer:
            writer.write(encode(plan)); writer.flush(); os.fsync(writer.fileno())
        script = script.replace("[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:SYNDICATUM_B_PLAN))",
                                "(Get-Content -LiteralPath $env:SYNDICATUM_B_PLAN_PATH -Raw)")
        env = dict(os.environ, SYNDICATUM_B_MODULE=str(self.module), SYNDICATUM_B_OPERATION=operation,
                   SYNDICATUM_B_PLAN_PATH=str(plan_path))
        with prefix.with_suffix(".stdout.private").open("xb") as out, prefix.with_suffix(".stderr.private").open("xb") as err:
            child = subprocess.Popen([str(powershell), "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script],
                                     stdout=out, stderr=err, env=env, cwd=plan["transport_root"], creationflags=0x08000000)
            try:
                code = self._wait(child)
            except NativeBStop:
                # Do not kill/retry; child may have partially applied the operation.
                with prefix.with_suffix(".uncertain.json").open("xb") as record:
                    record.write(encode({"pid": child.pid, "operation": operation, "outcome": "timeout_no_retry"}))
                failure = NativeBStop("native_timeout_uncertain")
                failure.pid = child.pid
                raise failure from None
        require(code == 0, "native_nonzero_uncertain")
        raw = prefix.with_suffix(".stdout.private").read_bytes()
        require(len(raw) <= 67108864, "native_output_bound")
        return json.loads(raw)


class CutoverJournal:
    """Historical directory fixture only; never selected by preparation defaults.

    The proposed integration injects FixedPreparationJournal instead. Retained
    solely for old component regressions; not a production durability mechanism.
    """
    def __init__(self, plan, plan_digest, barrier):
        self.root, self.head_root = Path(plan["evidence_root"]), Path(plan["head_root"])
        require(self.root.resolve() != self.head_root.resolve() and
                self.root.resolve() not in self.head_root.resolve().parents and
                self.head_root.resolve() not in self.root.resolve().parents, "journal_head_overlap")
        self.root.mkdir(exist_ok=False)
        self.records = self.root / "records"
        self.records.mkdir()
        self.head_root.mkdir(exist_ok=False)
        self.plan, self.plan_digest, self.barrier = plan, plan_digest, barrier
        self.previous, self.sequence = "0" * 64, 0
        self.retained_head = None
        self.barrier()  # Unsupported Windows directory durability stops before mutation.

    def verify(self, retained_head):
        names = {f"{index:06d}.json" for index in range(self.sequence)}
        require({p.name for p in self.records.iterdir()} == names and
                {p.name for p in self.head_root.iterdir()} == names, "partial_or_extra_native_journal")
        previous = "0" * 64
        for index in range(self.sequence):
            path, head_path = self.records / f"{index:06d}.json", self.head_root / f"{index:06d}.json"
            for item in (path, head_path):
                require(not item.is_symlink() and not getattr(item.lstat(), "st_file_attributes", 0) & 0x400,
                        "native_journal_reparse")
            raw, head_raw = path.read_bytes(), head_path.read_bytes()
            value, head = json.loads(raw), json.loads(head_raw)
            require(encode(value) == raw and encode(head) == head_raw, "native_journal_byte_drift")
            require(value["attempt_id"] == self.plan["attempt_id"] and value["cutoff_id"] == self.plan["cutoff_id"] and
                    value["plan_sha256"] == self.plan_digest and value["sequence"] == index and
                    value["previous_sha256"] == previous, "native_journal_chain_or_plan")
            previous = sha(raw)
            require(head == {"attempt_id": self.plan["attempt_id"], "cutoff_id": self.plan["cutoff_id"],
                             "plan_sha256": self.plan_digest, "sequence": index, "record_sha256": previous},
                    "native_journal_head_reference")
        require(previous == self.previous, "native_journal_memory_anchor")
        require((self.sequence == 0 and retained_head is None) or
                (self.sequence > 0 and sha(head_raw) == retained_head), "external_head_drift")

    def close_terminal(self):
        # Historical fixture has no continuously held file handles.
        pass

    def check_final_time(self):
        pass  # Historical directory fixture; never selected by preparation default.

    def append(self, boundary, producer, data):
        self.verify(self.retained_head)
        record = {"attempt_id": self.plan["attempt_id"], "cutoff_id": self.plan["cutoff_id"],
                  "plan_sha256": self.plan_digest, "boundary": boundary, "producer": producer,
                  "sequence": self.sequence, "previous_sha256": self.previous, "data": data}
        raw = encode(record)
        target = self.records / f"{self.sequence:06d}.json"
        with target.open("xb") as writer:
            writer.write(raw); writer.flush(); os.fsync(writer.fileno())
        self.barrier()
        digest = sha(raw)
        head = encode({"attempt_id": self.plan["attempt_id"], "cutoff_id": self.plan["cutoff_id"],
                       "plan_sha256": self.plan_digest, "sequence": self.sequence, "record_sha256": digest})
        with (self.head_root / f"{self.sequence:06d}.json").open("xb") as writer:
            writer.write(head); writer.flush(); os.fsync(writer.fileno())
        self.barrier()
        self.previous, self.sequence = digest, self.sequence + 1
        self.retained_head = sha(head)
        self.verify(self.retained_head)
        return self.retained_head


class DeveloperBAdapter:
    """Native operation binding with fail-closed postimages; never installs."""
    def __init__(self, plan_raw, plan_digest, backend, authority_verifier, now_ms=None,
                 journal_factory=None, action_admission=None):
        self.plan, self.digest = anchored(plan_raw, plan_digest), plan_digest
        self.backend, self.authority_verifier = backend, authority_verifier
        self.now = now_ms or (lambda: time.time_ns() // 1000000)
        self.journal_factory, self.action_admission = journal_factory, action_admission
        self.journal, self.last_clock, self.cutoff, self.cutoff_raw = None, None, None, None
        self.stage, self.mutations = "admission", []
        self.started, self.terminal, self.boundary_index = False, False, 0
        self.session_close_attempted, self.session_close_uncertain = False, False

    def _fresh(self, *clocks):
        now = self.now()
        for clock in clocks:
            require_fresh_window(clock["utc_ms"], now, 120000)

    def _close_terminal(self):
        self.terminal = True
        if self.journal is not None and not self.session_close_attempted:
            self.session_close_attempted = True
            try:
                self.journal.close_terminal()
            except Exception:
                self.session_close_uncertain = True
                raise

    def _clock(self, value):
        require(type(value) is dict and set(value) == {"utc_ms", "ticks", "frequency", "boot_utc"}, "native_clock_schema")
        require(all(type(value[k]) is int for k in ("utc_ms", "ticks", "frequency")) and
                value["ticks"] >= 0 and value["frequency"] > 0 and type(value["boot_utc"]) is str,
                "native_clock_type")
        require_fresh_window(value["utc_ms"], self.now(), 120000)
        if self.last_clock is not None:
            prior = self.last_clock
            require(value["boot_utc"] == prior["boot_utc"] and value["frequency"] == prior["frequency"], "native_clock_source_changed")
            wall = value["utc_ms"] - prior["utc_ms"]
            monotonic = (value["ticks"] - prior["ticks"]) * 1000 / value["frequency"]
            require(wall >= 0 and monotonic >= 0 and abs(wall - monotonic) <= 50, "native_clock_discontinuity")
        self.last_clock = value

    def _snapshot(self, boundary):
        value = self.backend.invoke("snapshot", self.plan)
        self.journal.append(boundary, "native-task-run-process-lock-clock", value)
        self._clock(value["clock"])
        require(value["static_files"] == self.plan["static_files"], "static_install_identity_drift")
        require(type(value["processes"]) is list and all(type(item["pid"]) is int for item in value["processes"]), "native_process_types")
        return value

    def _quiescent(self, value):
        require(value["processes"] == [], "listener_or_other_writer_present")
        require(set(value["locks"]) == {"background.lock", "listener.lock"}, "lock_identity_set")
        for lock in value["locks"].values():
            require(type(lock["live"]) is bool and not lock["live"], "live_lock_owner")
            require(type(lock["present"]) is bool and type(lock["pid"]) is int and
                    ((not lock["present"] and lock["pid"] == 0) or
                     (lock["present"] and lock["pid"] == self.plan["listener"]["pid"])), "unknown_stale_lock")
        for name, lock in value["locks"].items():
            if lock["present"]:
                require(lock["witness"]["sha256"] == self.plan["lock_sha256"][name], "stale_lock_bytes_drift")
        require(value["static_files"] == self.plan["static_files"], "quiescent_static_drift")
        require(TASK not in value["tasks"] and not any(RUN.fullmatch(key) for key in value["runs"]), "legacy_autostart_returned")

    @staticmethod
    def _quiescent_startup(value):
        require(TASK not in value["tasks"] and not any(RUN.fullmatch(key) for key in value["runs"]), "legacy_autostart_returned")

    @staticmethod
    def _preserved(before, after):
        require_frozen_observations({k: base64.b64decode(v, validate=True) for k, v in before["tasks"].items() if k != TASK},
                                    {k: base64.b64decode(v, validate=True) for k, v in after["tasks"].items() if k != TASK})
        require(encode({k: v for k, v in before["runs"].items() if not RUN.fullmatch(k)}) ==
                encode({k: v for k, v in after["runs"].items() if not RUN.fullmatch(k)}), "unrelated_run_drift")

    def _mutate(self, operation, preimage):
        self.stage = operation
        require_fresh_window(self.last_clock["utc_ms"], self.now(), 120000)
        self.journal.append(operation, "intent", {"operation": operation})
        self.journal.verify(self.journal.retained_head)
        require(callable(self.action_admission), "fixed_action_admission_required")
        self.action_admission(operation, self.journal.retained_head)
        self.mutations.append(operation)  # Intent is retained before invoking native code.
        operation_plan = dict(self.plan)
        if operation == "stop":
            operation_plan["retained_stop_anchor"] = self.preservation_anchor
        self._fresh(preimage["clock"])
        native_result = self.backend.invoke(operation, operation_plan)
        expected = {"suppress": "exact_startup_suppression", "stop": "exact_owned_listener_stop"}[operation]
        require(type(native_result) is dict and native_result.get("completed") is True and
                native_result.get("operation") == expected, "native_result_uncertain")
        postimage = self._snapshot(operation + "-postimage")
        self._preserved(preimage, postimage)
        self.journal.append(operation, "confirmed", {"operation": operation})
        return postimage

    def _cutoff_states(self, value):
        require(set(value["states"]) == set(self.plan["route_names"]), "route_identity_set_drift")
        result = {}
        for identity, item in value["states"].items():
            raw = base64.b64decode(item["raw_base64"], validate=True)
            require(type(item["size"]) is int and len(raw) == item["size"] and sha(raw) == item["sha256"], "raw_state_digest")
            require(item["handle_identity"] == item["path_identity"] and bool(item["handle_identity"]) and
                    item["before_metadata"] == item["after_metadata"], "state_handle_path_or_write_drift")
            state(raw)  # Strict duplicate/type validation; never normalize or save it.
            result[identity] = raw
        return result

    def prepare(self):
        if self.started:
            return {"result": "stopped", "stage": "already_started", "code": "attempt_object_reuse", "authorizes_install": False}
        self.started = True
        try:
            p = self.plan
            require(type(p["attempt_id"]) is str and bool(p["attempt_id"]) and p["attempt_id"] != "11545" and
                    p["previous_attempt_id"] == "11545" and p["attempt_id"] != p["previous_attempt_id"] and
                    type(p["cutoff_id"]) is str and bool(p["cutoff_id"]) and p["cutoff_id"] != p["previous_cutoff_id"],
                    "exhausted_attempt_reuse")
            require(type(p["owner_authority_message_id"]) is int and p["owner_authority_message_id"] > 11562,
                    "new_owner_authority_required")
            require(self.authority_verifier(p["owner_authority_message_id"], self.digest,
                                           {"connector_task_run_suppression", "owned_listener_stop", "private_cutoff_capture"}) is True,
                    "expanded_authority_not_verified")
            require(len(p["route_names"]) == len(set(p["route_names"])) and all(
                re.fullmatch(r"state\.json\.participant-[0-9]+", name) for name in p["route_names"]), "route_name_schema")
            require(bool(p["route_names"]) and len(p["route_names"]) <= 512 and
                    re.fullmatch(r"[0-9a-f]{40}", p["mechanism_head"]) and
                    p["candidate_commit"] == "9141daa08d896f2524be30b96d01ab2a14398757", "mechanism_or_candidate_binding")
            require(type(p["listener"]["pid"]) is int and p["listener"]["pid"] > 0 and
                    set(p["lock_sha256"]) == {"background.lock", "listener.lock"} and
                    "background.json" in p["static_files"], "listener_or_static_plan")
            require(callable(self.journal_factory) and callable(self.action_admission), "fixed_preparation_integration_required")
            admitted = self.backend.invoke("admission", p)
            self._clock(admitted["clock"])
            require(admitted["private_roots_checked"] is True, "external_boundary_not_checked")
            # Integrated preparation requires a fixed-file journal; no directory flush.
            self.journal = self.journal_factory(p, self.digest)
            before = self._snapshot("pre-suppression")
            require(TASK in before["tasks"] and sha(base64.b64decode(before["tasks"][TASK], validate=True)) == p["task_xml_sha256"], "task_preimage_drift")
            require(encode(before["runs"]) == encode(p["run_preimage"]), "run_preimage_drift")
            require(before["processes"] == [p["listener"]], "owned_listener_preimage_drift")
            suppressed = self._mutate("suppress", before)
            require(TASK not in suppressed["tasks"] and not any(RUN.fullmatch(key) for key in suppressed["runs"]), "suppression_not_observed")
            self.preservation_anchor = {key: suppressed[key] for key in ("tasks", "runs", "static_files")}
            revalidated = self._snapshot("before-stop")
            self._preserved(suppressed, revalidated)
            self._quiescent_startup(revalidated)
            require([entry for entry in revalidated["processes"] if entry["pid"] == p["listener"]["pid"]] == [p["listener"]], "owned_listener_changed_before_stop")
            stopped = self._mutate("stop", revalidated)
            self._quiescent(stopped)
            independent = self._snapshot("independent-quiescence")
            self._quiescent(independent)
            self._preserved(stopped, independent)
            self.stage = "capture-cutoff"
            self.journal.append("capture-cutoff", "intent", {"operation": "cutoff"})
            self.action_admission("cutoff", self.journal.retained_head)
            self._fresh(independent["clock"])
            cutoff = self.backend.invoke("cutoff", p)
            for capture in (cutoff["before"], cutoff["after"]):
                self._clock(capture["clock"]); self._quiescent(capture)
                self._preserved(independent, capture)
            self._clock(cutoff["clock"])
            self._cutoff_states(cutoff)
            head = self.journal.append("cutoff", "native-handle-state-and-task", cutoff)
            self._fresh(cutoff["before"]["clock"], cutoff["after"]["clock"], cutoff["clock"])
            self.cutoff = cutoff
            self.cutoff_raw = encode(cutoff)
            return {"result": "cutoff_prepared_only", "head_sha256": head, "authorizes_install": False}
        except Exception as error:
            self.terminal = True
            code = str(error) if isinstance(error, NativeBStop) and str(error).replace("_", "").isalnum() else "evidence_or_native_failure"
            secondary = []
            if getattr(error, "close_uncertain", False):
                self.session_close_uncertain = True
                secondary.append("failure_session_close_uncertain")
            if self.journal is not None:
                if self.mutations and code != "native_timeout_uncertain":
                    try: self._snapshot("failure-postimage")
                    except Exception: secondary.append("failure_postimage_unavailable")
                try: self.journal.append(self.stage, "stopped", {"code": code})
                except Exception: secondary.append("failure_journal_unavailable")
                try: self._close_terminal()
                except Exception: secondary.append("failure_session_close_uncertain")
            return {"result": "stopped", "stage": self.stage, "code": code, "secondary_errors": secondary,
                    "uncertain_pid": getattr(error, "pid", None), "authorizes_install": False}

    def verify_boundary(self, boundary, independently_retained_head):
        require(not self.terminal, "terminal_attempt_no_replay")
        order = ("before-remove", "after-remove", "before-add", "after-add", "before-plugin-add", "after-plugin-add")
        try:
            require(self.cutoff is not None and self.boundary_index < len(order) and
                    boundary == order[self.boundary_index], "missing_or_out_of_order_boundary")
            self.journal.verify(independently_retained_head)
            baseline = json.loads(self.cutoff_raw)
            self._fresh(self.last_clock)
            current = self.backend.invoke("cutoff", self.plan)
            self.journal.append(boundary, "native-boundary-raw", current)
            for capture in (current["before"], current["after"]):
                self._clock(capture["clock"]); self._quiescent(capture)
                self._preserved(baseline["after"], capture)
            self._clock(current["clock"])
            require_frozen_observations(self._cutoff_states(baseline), self._cutoff_states(current))
            head = self.journal.append(boundary, "native-frozen-boundary-confirmed", {"verified": True})
            self._fresh(current["before"]["clock"], current["after"]["clock"], current["clock"])
            self.boundary_index += 1
            if self.boundary_index == len(order):
                self._close_terminal()
                self.journal.check_final_time()
                self._fresh(current["before"]["clock"], current["after"]["clock"], current["clock"])
            return {"result": "boundary_verified_only", "head_sha256": head, "authorizes_install": False}
        except Exception as error:
            try: self._close_terminal()
            except Exception: error.add_note("failure_session_close_uncertain; no retry")
            raise
