"""Read-only policy classifiers. No capture, refresh, install or cutover adapter."""
import hashlib
import json


class FreshnessStop(RuntimeError):
    pass


def require(condition, code):
    if not condition:
        raise FreshnessStop(code)


def state(raw):
    require(type(raw) is bytes and len(raw) <= 4 * 1024 * 1024, "state_byte_bounds")

    def unique(pairs):
        result = {}
        for key, value in pairs:
            require(key not in result, "duplicate_state_key")
            result[key] = value
        return result

    def nonfinite(_):
        raise FreshnessStop("nonfinite_state")

    try:
        value = json.loads(raw, object_pairs_hook=unique, parse_constant=nonfinite)
    except (ValueError, UnicodeError):
        raise FreshnessStop("invalid_state_json") from None
    require(type(value) is dict and set(value) ==
            {"processedMessageIds", "pendingNotifications", "activeWake", "lastSequence"}, "state_schema")
    require(type(value["lastSequence"]) is int and value["lastSequence"] >= 0, "state_sequence_type")
    require(type(value["processedMessageIds"]) is list and
            all(type(item) is str and item for item in value["processedMessageIds"]), "state_history_type")
    require(type(value["pendingNotifications"]) is list and
            all(type(item) is dict for item in value["pendingNotifications"]), "state_queue_type")
    require(value["activeWake"] is None or type(value["activeWake"]) is dict, "state_wake_type")
    return value


def classify_state_change(before_raw, after_raw):
    """Classify observations; never infer actor, legitimacy or permission."""
    before, after = state(before_raw), state(after_raw)
    if before_raw == after_raw:
        kind = "exact_bytes"
    elif after["lastSequence"] < before["lastSequence"]:
        kind = "sequence_regression"
    elif any(json.dumps(before[key], sort_keys=True) != json.dumps(after[key], sort_keys=True)
             for key in before if key != "lastSequence"):
        kind = "queue_history_or_wake_changed"
    elif after["lastSequence"] > before["lastSequence"]:
        kind = "sequence_only_advance"
    else:
        kind = "serialization_only_change"
    return {"classification": kind, "before_sha256": hashlib.sha256(before_raw).hexdigest(),
            "after_sha256": hashlib.sha256(after_raw).hexdigest(), "authorizes_mutation": False}


def require_fresh_window(captured_ms, observed_ms, maximum_age_ms):
    require(all(type(x) is int for x in (captured_ms, observed_ms, maximum_age_ms)), "window_type")
    require(captured_ms >= 0 and 0 < maximum_age_ms <= 120000, "window_bounds")
    require(0 <= observed_ms - captured_ms <= maximum_age_ms, "expired_or_future_cutoff")


def require_new_cutoff(previous_attempt_id, new_attempt_id, previous_terminal):
    require(previous_terminal == "STOPPED_BEFORE_MUTATION", "previous_attempt_not_closed")
    require(type(previous_attempt_id) is str and bool(previous_attempt_id) and
            type(new_attempt_id) is str and bool(new_attempt_id) and
            new_attempt_id != previous_attempt_id, "attempt_reuse")
    # This only prevents fixture ID reuse; approval and durable reservation are
    # separate required inputs to a future reviewed native adapter.


def require_frozen_observations(before, after):
    """After an approved quiescent cutoff, no route/Task byte exception exists.

    Keys are opaque private identities; values are independently retained bytes.
    A new cutoff chooses current observations, not a restoration of old state.
    """
    require(type(before) is dict and type(after) is dict and bool(before), "frozen_inventory_type")
    require(set(before) == set(after), "frozen_inventory_identity_drift")
    for identity in before:
        require(type(identity) is str and bool(identity) and
                type(before[identity]) is bytes and type(after[identity]) is bytes,
                "frozen_observation_type")
        require(before[identity] == after[identity], "frozen_observation_bytes_drift")


QUIESCENT_PREPARATION_PHASES = (
    "verify_separate_exact_cutover_authority",
    "reserve_new_attempt_without_reusing_11545",
    "verify_immutable_install_intent_and_exact_connector_ownership",
    "retain_pre_suppression_observations",
    "suppress_only_reviewed_connector_task_and_run",
    "verify_exact_suppression_and_unrelated_preservation",
    "revalidate_owned_listener_start_executable_entry_and_locks",
    "stop_only_exact_owned_listener",
    "independently_verify_quiescence_without_lock_cleanup",
    "capture_new_private_route_and_task_cutoff",
    "verify_cutoff_copy_bytes_and_source_stability",
    "verify_fresh_cutoff_and_immutable_install_intent",
    "retain_prepared_cutoff_result_without_install_or_activation",
)


def model_quiescent_preparation(adapter):
    """Fixture orchestration only; there is no native adapter or CLI entry point.

    The first phase must obtain new exact authority. Source tests inject inert
    methods. This model never itself grants permission or resumes an attempt.
    """
    current = "not_started"
    try:
        for current in QUIESCENT_PREPARATION_PHASES:
            adapter.retain_intent(current)
            getattr(adapter, current)()
            adapter.retain_confirmed(current)
        return {"result": "prepared_model_only", "stage": current, "authorizes_install": False}
    except Exception:
        # No retry, rollback, cleanup, later phase, or raw private exception text.
        return {"result": "stopped", "stage": current, "authorizes_install": False}
