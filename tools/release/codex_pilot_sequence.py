"""Source-only sequencing model. No native Windows or installation adapter.

The adapter interface is exercised only by disposable fixtures. Supplying an
adapter does not confer execution authority; no command-line entry point exists.
"""
from dataclasses import dataclass, field


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
