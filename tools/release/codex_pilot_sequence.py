"""Source-only sequencing model. No native Windows or installation adapter.

The adapter interface is exercised only by disposable fixtures. Supplying an
adapter does not confer execution authority; no command-line entry point exists.
"""
from dataclasses import dataclass, field
import hashlib
import json


class SequenceStop(RuntimeError):
    pass


@dataclass
class Outcome:
    stage: str = "not_started"
    result: str = "not_started"
    failure_code: str | None = None
    secondary_errors: list[str] = field(default_factory=list)


def require_fact(condition, code):
    if not condition:
        raise SequenceStop(code)


def verify_quiescent_facts(plan, task, run_values, processes, locks, launchers):
    """Separate snapshots, no caller-provided summary booleans.

    Native collectors still require review: snapshots must be complete, fresh,
    from the same machine/user and bracketed against concurrent change.
    """
    require_fact(task is None, "task_not_suppressed")
    require_fact(plan["run_identity"] not in run_values, "run_not_suppressed")
    require_fact(run_values == plan["unrelated_run_values"], "unrelated_run_drift")
    require_fact(not launchers, "unexpected_launcher")
    require_fact(set(locks) == {"background", "listener"}, "lock_inventory_incomplete")
    require_fact(all(value is None for value in locks.values()), "lock_still_owned")
    for process in processes:
        require_fact(set(process) == {"pid", "started", "executable", "parent_pid", "parent_started", "role"}, "process_schema")
        require_fact(process["role"] not in {"codex_owner", "listener", "legacy_launcher"}, "live_owner_or_listener")
        require_fact(process["executable"] not in plan["listener_executables"], "listener_executable_still_live")
        require_fact(process["pid"] != plan["listener_identity"]["pid"], "listener_pid_reuse_or_live")


def verify_listener_preimage(expected, actual, parent, locks):
    fields = {"pid", "started", "executable", "entry", "parent_pid", "parent_started"}
    require_fact(set(expected) == fields and set(actual) == fields, "listener_schema")
    require_fact(expected == actual, "listener_identity_drift")
    require_fact(parent == {"pid": expected["parent_pid"], "started": expected["parent_started"]}, "parent_identity_drift")
    require_fact(locks == {"background": expected["pid"], "listener": expected["pid"]}, "listener_lock_mismatch")


def strict_object(value, fields):
    require_fact(type(value) is dict and set(value) == set(fields), "acceptance_evidence_schema")
    for key, kind in fields.items():
        require_fact(type(value[key]) is kind, "acceptance_evidence_type")


def unique_pairs(pairs):
    value = {}
    for key, item in pairs:
        require_fact(key not in value, "duplicate_evidence_key")
        value[key] = item
    return value


def bound_object(raw, expected_hash):
    require_fact(type(raw) is bytes and len(raw) <= 2_000_000, "evidence_bounds")
    require_fact(type(expected_hash) is str and len(expected_hash) == 64 and all(c in "0123456789abcdef" for c in expected_hash), "evidence_anchor_format")
    require_fact(hashlib.sha256(raw).hexdigest() == expected_hash, "evidence_anchor_drift")
    return json.loads(raw, object_pairs_hook=unique_pairs)


def verify_acceptance(plan_bytes, reviewed_plan_digest, evidence_bytes, retained_evidence_digest):
    """Anchors must be retained independently; never refreshed from live evidence."""
    try:
        plan_envelope = bound_object(plan_bytes, reviewed_plan_digest)
        strict_object(plan_envelope, {"attempt_id": str, "plan": dict})
        envelope = bound_object(evidence_bytes, retained_evidence_digest)
        strict_object(envelope, {"attempt_id": str, "plan_sha256": str, "observed": dict})
        require_fact(bool(plan_envelope["attempt_id"]) and envelope["attempt_id"] == plan_envelope["attempt_id"] and envelope["plan_sha256"] == reviewed_plan_digest, "acceptance_attempt_mismatch")
        plan, observed = plan_envelope["plan"], envelope["observed"]
        strict_object(plan, {"candidate_files": dict, "unrelated_run_values": dict,
            "malformed_artifacts": dict, "protected_identity_hashes": dict,
            "malformed_route_tokens": list, "max_health_age_ms": int, "shutdown_bound_ms": int})
        strict_object(observed, {"installed_files": dict, "version": str, "lifecycle": str,
            "task": type(None), "run_values": dict, "malformed_artifacts": dict,
            "protected_identity_hashes": dict, "unavailable_routes": dict, "health_samples": list,
            "listener_pid": int, "observed_at_ms": int, "locks": dict, "owner_phases": list,
            "healthy_route_deliveries": int, "acknowledged_replays": int})
        require_fact(plan["max_health_age_ms"] > 0 and 0 < plan["shutdown_bound_ms"] <= 2000 and observed["listener_pid"] > 0, "acceptance_plan_bounds")
        require_fact(len(plan["malformed_route_tokens"]) <= 512 and all(type(v) is str and v for v in plan["malformed_route_tokens"]) and len(set(plan["malformed_route_tokens"])) == len(plan["malformed_route_tokens"]), "acceptance_route_schema")
        for item in observed["unavailable_routes"].values():
            strict_object(item, {"reason": str, "delivered": int, "acknowledged": int, "history_recovered": bool})
        strict_object(observed["locks"], {"background": int, "listener": int})
        for item in observed["health_samples"]:
            strict_object(item, {"pid": int, "timestamp_ms": int, "readiness": str, "unavailable_count": int})
        phases = observed["owner_phases"]
        require_fact(len(phases) == 4, "owner_phase_count")
        base = {"phase": str, "owner_tokens": list, "listener_pids": list}
        strict_object(phases[0], base)
        strict_object(phases[1], base)
        strict_object(phases[2], {**base, "stop_observed_ms": int, "last_owner_exit_ms": int,
            "reopen_events": int, "delivery_count": int, "message_acknowledged": bool, "test_message_id": str})
        strict_object(phases[3], {**base, "test_message_id": str, "delivery_count": int})
        for phase in phases:
            require_fact(all(type(v) is str and v for v in phase["owner_tokens"]) and all(type(v) is int and v > 0 for v in phase["listener_pids"]), "owner_identity_schema")
        return _verify_acceptance(plan, observed)
    except SequenceStop:
        raise
    except (KeyError, TypeError, ValueError, AttributeError):
        raise SequenceStop("acceptance_evidence_schema") from None


def _verify_acceptance(plan, observed):
    """Compare independently collected post-start evidence to reviewed preimages.

    This verifier does not gather observations or generate test messages. The
    native collection/physical operator harness remains a separate review gate.
    """
    require_fact(observed["installed_files"] == plan["candidate_files"], "installed_bytes_mismatch")
    require_fact(observed["version"] == "0.2.10", "installed_version_mismatch")
    require_fact(observed["lifecycle"] == "codex_session", "wrong_lifecycle")
    require_fact(observed["task"] is None and observed["run_values"] == plan["unrelated_run_values"], "autostart_or_registration_drift")
    require_fact(observed["malformed_artifacts"] == plan["malformed_artifacts"], "malformed_state_changed")
    require_fact(observed["protected_identity_hashes"] == plan["protected_identity_hashes"], "protected_identity_changed")
    unavailable = observed["unavailable_routes"]
    require_fact(set(unavailable) == set(plan["malformed_route_tokens"]), "degraded_route_identity_mismatch")
    require_fact(all(value == {"reason": "binding_initialization_failed", "delivered": 0,
                              "acknowledged": 0, "history_recovered": False}
                     for value in unavailable.values()), "degraded_route_advanced")
    health = observed["health_samples"]
    require_fact(len(health) == 2, "health_sample_count")
    first, second = health
    require_fact(first["pid"] == second["pid"] == observed["listener_pid"], "health_process_mismatch")
    require_fact(first["timestamp_ms"] < second["timestamp_ms"] <= observed["observed_at_ms"], "health_not_advancing")
    require_fact(0 <= observed["observed_at_ms"] - second["timestamp_ms"] <= plan["max_health_age_ms"], "health_stale")
    unavailable_count=len(plan["malformed_route_tokens"])
    readiness='degraded' if unavailable_count else 'ready'
    require_fact(all(item["readiness"] == readiness and item["unavailable_count"] == unavailable_count for item in health), "degraded_health_not_explicit")
    require_fact(observed["locks"] == {"background": observed["listener_pid"], "listener": observed["listener_pid"]}, "acceptance_lock_mismatch")
    phases = observed["owner_phases"]
    require_fact([item["phase"] for item in phases] == ["two_open", "one_open", "last_closed", "reopened"], "owner_phase_sequence")
    two, one, closed, reopened = phases
    require_fact(len(set(two["owner_tokens"])) == 2 and two["listener_pids"] == [observed["listener_pid"]], "shared_listener_failed")
    require_fact(len(one["owner_tokens"]) == 1 and set(one["owner_tokens"]) < set(two["owner_tokens"]) and one["listener_pids"] == two["listener_pids"], "one_owner_shutdown_failed")
    require_fact(not closed["owner_tokens"] and not closed["listener_pids"], "last_owner_did_not_stop")
    require_fact(0 <= closed["stop_observed_ms"] - closed["last_owner_exit_ms"] <= plan["shutdown_bound_ms"], "shutdown_bound_exceeded")
    require_fact(closed["reopen_events"] == 0 and closed["delivery_count"] == 0 and closed["message_acknowledged"] is False, "closed_session_activated")
    require_fact(closed["test_message_id"] == reopened["test_message_id"] and bool(closed["test_message_id"]), "recovery_message_mismatch")
    require_fact(len(reopened["owner_tokens"]) >= 1 and len(reopened["listener_pids"]) == 1 and reopened["delivery_count"] == 1, "pending_recovery_not_once")
    require_fact(observed["healthy_route_deliveries"] == 1 and observed["acknowledged_replays"] == 0, "healthy_route_or_replay_failure")
    routing="ready" if not unavailable_count else ("degraded_two_routes_not_recovered" if unavailable_count==2 else "degraded_routes_not_recovered")
    return {"scope": "device_pilot_only", "routing": routing}


def sequence(adapter):
    """No retries, rollback, cleanup or restart on any failure.

    Adapter methods must preserve exact private evidence; this model journals only
    bounded stage/error classifications, never exception text or protected values.
    """
    outcome = Outcome()
    try:
        for stage in (
            "validate_reviewed_plan_and_artifact",
            "validate_external_volume_acl_capacity",
            "reserve_durable_attempt",
            "collect_complete_preimages",
            "capture_initial_external_backup",
            "verify_initial_backup",
            "revalidate_registration_and_listener_preimages",
            "suppress_exact_owned_task",
            "remove_exact_approved_run_value",
            "verify_suppression_and_unrelated_entries",
            "revalidate_live_process_and_locks",
            "stop_exact_owned_process",
            "collect_independent_quiescence_facts",
            "verify_quiescence",
            "capture_quiescent_external_backup",
            "verify_quiescent_backup_and_corrupt_artifacts",
            "stage_validated_artifact_outside_installed_roots",
            "revalidate_target_preimages_and_quiescence",
            "verify_marketplace_owner_approval_and_disposable_semantics",
            "revalidate_exact_managed_marketplace_preimage",
            "remove_exact_marketplace_via_reviewed_adapter",
            "verify_exact_marketplace_removal_postimage",
            "verify_unrelated_marketplace_and_plugin_preservation",
            "add_exact_local_marketplace_via_reviewed_adapter",
            "install_exact_staged_artifact",
            "verify_installed_bytes_before_owner_open",
            "await_authorized_owner_normal_open",
            "collect_post_start_acceptance",
            "verify_post_start_acceptance",
        ):
            outcome.stage = stage
            outcome.result = "in_progress"
            # Failure to retain intent prevents the mutation from being called.
            adapter.retain_stage(stage, "intent")
            getattr(adapter, stage)()
            adapter.retain_stage(stage, "confirmed")
        outcome.result = "passed"
    except Exception as error:
        outcome.result = "stopped"
        # Do not serialize raw errors: registry/command/path contents may occur.
        outcome.failure_code = str(error) if isinstance(error, SequenceStop) and str(error).replace("_", "").isalnum() else "adapter_failure"
        try:
            adapter.retain_failure(outcome.stage, outcome.failure_code)
        except Exception:
            outcome.secondary_errors.append("failure_evidence_write_failed")
    return outcome
