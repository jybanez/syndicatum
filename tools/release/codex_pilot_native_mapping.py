"""Strict native-output mapping. No collection or machine mutation occurs here."""
import hashlib
import hmac
import json
import os
import re
from datetime import datetime
from pathlib import Path


class MappingStop(RuntimeError):
    pass


def require(condition, code):
    if not condition:
        raise MappingStop(code)


def exact(value, fields):
    require(type(value) is dict and set(value) == set(fields), "native_schema")
    for key, kind in fields.items():
        require(type(value[key]) is kind, "native_type")


def token(private_key, domain, value):
    require(type(private_key) is bytes and len(private_key) >= 32, "private_token_key")
    require(type(value) is str, "token_input")
    return hmac.new(private_key, (domain + "\0" + value).encode(), hashlib.sha256).hexdigest()


def map_processes(raw, private_key, expected_listener_executable):
    require(type(raw) is list, "native_process_array")
    by_pid = {}
    for item in raw:
        exact(item, {"pid": int, "parent_pid": int, "executable": str, "command_line": str, "started": str})
        require(item["pid"] > 0 and item["parent_pid"] >= 0 and item["pid"] not in by_pid, "native_process_identity")
        require(bool(item["started"]), "native_process_start_missing")
        by_pid[item["pid"]] = item
    result = []
    for item in raw:
        executable = item["executable"].replace("\\", "/")
        role = "other"
        if re.search(r"/OpenAI\.Codex_[^/]+/app/(?:ChatGPT|Codex)\.exe$", executable, re.I) or re.search(r"/codex\.exe$", executable, re.I):
            role = "codex_owner"
        if item["executable"].casefold() == expected_listener_executable.casefold():
            # Executable match is deliberately conservative: exact runtime entry
            # comparison is a separate private preimage check, not inferred here.
            role = "listener"
        parent = by_pid.get(item["parent_pid"])
        require(role == "other" or parent is not None, "relevant_parent_missing")
        result.append(dict(pid=item["pid"], parent_pid=item["parent_pid"], started=item["started"],
            parent_started=parent["started"] if parent else "", executable_token=token(private_key, "executable", item["executable"]), role=role))
    return result


def map_locks(raw):
    require(type(raw) is dict and set(raw) == {"background", "listener"}, "native_lock_set")
    result = {}
    for identity, item in raw.items():
        require(type(item) is dict and type(item.get("present")) is bool, "native_lock_schema")
        if not item["present"]:
            exact(item, {"present": bool})
            result[identity] = dict(present=False, owner_pid=0, sha256="")
        else:
            exact(item, {"present": bool, "raw": str, "size": int, "mtime": str})
            require(re.fullmatch(r"[1-9][0-9]*\r?\n?", item["raw"]) is not None and item["size"] == len(item["raw"].encode()), "native_lock_owner")
            result[identity] = dict(present=True, owner_pid=int(item["raw"].strip()), sha256=hashlib.sha256(item["raw"].encode()).hexdigest())
    return result


def map_registrations(task, run_values, private_key):
    if task is None:
        task_digest = ""
    else:
        exact(task, {"name": str, "path": str, "xml": str, "state": str, "principal_sid": str,
                     "trigger_sids": list, "current_sid": str, "actions": list})
        require(task["principal_sid"] == task["current_sid"] and bool(task["current_sid"]), "native_task_sid")
        require(len(task["trigger_sids"]) == 1 and task["trigger_sids"][0] == task["current_sid"], "native_trigger_sid")
        require(len(task["actions"]) == 1, "native_task_actions")
        exact(task["actions"][0], {"executable": str, "arguments": str, "working_directory": str})
        task_digest = hashlib.sha256(task["xml"].encode()).hexdigest()
    require(type(run_values) is list, "native_run_array")
    mapped = {}
    for item in run_values:
        exact(item, {"hive": str, "key": str, "view": str, "name": str, "kind": str, "raw_command": str})
        require(item["hive"] == "HKCU" and item["view"] in {"Registry32", "Registry64"}, "native_run_boundary")
        identity = token(private_key, "run_identity", json.dumps([item[k] for k in ("hive", "key", "view", "name")], separators=(",", ":")))
        require(identity not in mapped, "duplicate_native_run_identity")
        mapped[identity] = dict(view=32 if item["view"] == "Registry32" else 64,
            kind=item["kind"], raw_value_sha256=hashlib.sha256(item["raw_command"].encode()).hexdigest())
    return dict(task_present=task is not None, task_xml_sha256=task_digest, run_values=mapped)


def retain_private_raw(directory, identity, raw_bytes):
    """External fixture only until production ACL/root gates are reviewed."""
    require(re.fullmatch(r"[a-z0-9-]{1,80}", identity) is not None and type(raw_bytes) is bytes, "raw_record_identity")
    root = Path(directory)
    require(root.is_dir() and not root.is_symlink(), "raw_record_root")
    with (root / (identity + ".raw")).open("xb") as writer:
        writer.write(raw_bytes)
        writer.flush()
        os.fsync(writer.fileno())
    return hashlib.sha256(raw_bytes).hexdigest()


def map_health(raw):
    require(type(raw) is dict, "native_health_object")
    fields = {"pid": int, "updatedAt": str, "state": str, "role": str, "bindings": int,
              "projects": int, "unavailableBindings": int, "requestedBindings": int,
              "initializedBindings": int, "unavailableBindingReasons": list}
    if "error" in raw:
        fields["error"] = str
    exact(raw, fields)
    require(raw["pid"] > 0 and all(raw[key] >= 0 for key in ("bindings", "projects", "unavailableBindings", "requestedBindings", "initializedBindings")), "native_health_bounds")
    try:
        moment = datetime.fromisoformat(raw["updatedAt"].replace("Z", "+00:00"))
        require(moment.tzinfo is not None, "native_health_timezone")
        milliseconds = int(moment.timestamp() * 1000)
    except (ValueError, OverflowError):
        raise MappingStop("native_health_time") from None
    require(raw["state"] in {"ready", "degraded"} and "error" not in raw, "native_unhealthy_baseline")
    require(raw["role"] == "background", "native_standby_health")
    readiness = "degraded" if raw["unavailableBindings"] else "ready"
    return dict(pid=raw["pid"], timestamp_ms=milliseconds, readiness=readiness,
                unavailable_count=raw["unavailableBindings"])


def map_files(raw, private_key):
    require(type(raw) is list and bool(raw), "native_file_inventory")
    result = {}
    for item in raw:
        exact(item, {"private_identity": str, "sha256": str, "size": int, "mtime_ns": int})
        require(item["private_identity"] and re.fullmatch(r"[0-9a-f]{64}", item["sha256"]) and item["size"] >= 0 and item["mtime_ns"] >= 0, "native_file_metadata")
        identity = token(private_key, "file_identity", item["private_identity"])
        require(identity not in result, "native_file_duplicate")
        result[identity] = {key: item[key] for key in ("sha256", "size", "mtime_ns")}
    return result


def map_route_outcomes(raw, private_key):
    # Raw route observations must come from independently retained authorized
    # routing evidence; these are not inferred from aggregate health counts.
    require(type(raw) is list and bool(raw), "native_route_inventory")
    result = {}
    for item in raw:
        exact(item, {"private_route_identity": str, "reason": str, "delivered": int,
                     "acknowledged": int, "history_recovered": bool})
        require(item["private_route_identity"] and item["delivered"] >= 0 and item["acknowledged"] >= 0, "native_route_metadata")
        identity = token(private_key, "route_identity", item["private_route_identity"])
        require(identity not in result, "native_route_duplicate")
        result[identity] = {key: item[key] for key in ("reason", "delivered", "acknowledged", "history_recovered")}
    return result
