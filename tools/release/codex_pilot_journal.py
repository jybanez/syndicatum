"""Immutable-record evidence harness; no native collectors are invoked here.

Fixture tests use only fresh temporary directories and injected inert collectors.
Production root security, native collector bindings and Windows durability remain
separately reviewed integration gates.
"""
import hashlib
import json
import os
from pathlib import Path


class EvidenceStop(RuntimeError):
    pass


PRODUCERS = {
    "installed_files": "native-file-hash-inventory",
    "registrations": "native-task-and-registry-snapshot",
    "processes": "native-process-ancestry-snapshot",
    "locks": "native-lock-file-snapshot",
    "health": "native-health-file-snapshot",
    "routes": "authorized-route-observation",
}


def encode(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()


def require(condition, code):
    if not condition:
        raise EvidenceStop(code)


def exact(value, fields):
    require(type(value) is dict and set(value) == set(fields), "record_schema")
    for key, kind in fields.items():
        require(type(value[key]) is kind, "record_type")


def validate_payload(kind, data):
    if kind == "installed_files":
        require(type(data) is dict and bool(data), "file_inventory_schema")
        for identity, item in data.items():
            require(type(identity) is str and bool(identity), "private_file_identity")
            exact(item, {"sha256": str, "size": int, "mtime_ns": int})
            require(len(item["sha256"]) == 64 and all(c in "0123456789abcdef" for c in item["sha256"]) and item["size"] >= 0 and item["mtime_ns"] >= 0, "file_metadata_schema")
    elif kind == "health":
        exact(data, {"pid": int, "timestamp_ms": int, "readiness": str, "unavailable_count": int})
        require(data["pid"] > 0 and data["timestamp_ms"] >= 0 and data["unavailable_count"] >= 0, "health_bounds")
    elif kind == "locks":
        exact(data, {"background": dict, "listener": dict})
        for item in data.values():
            exact(item, {"present": bool, "owner_pid": int, "sha256": str})
            require((item["present"] and item["owner_pid"] > 0 and len(item["sha256"]) == 64) or
                    (not item["present"] and item["owner_pid"] == 0 and item["sha256"] == ""), "lock_presence_schema")
    elif kind == "processes":
        require(type(data) is list, "process_inventory_schema")
        for item in data:
            exact(item, {"pid": int, "parent_pid": int, "started": str, "parent_started": str, "executable_token": str, "role": str})
            require(item["pid"] > 0 and item["started"] and item["role"] in {"codex_owner", "listener", "legacy_launcher", "other"}, "process_identity_schema")
    elif kind == "registrations":
        exact(data, {"task_present": bool, "task_xml_sha256": str, "run_values": dict})
        require((data["task_present"] and len(data["task_xml_sha256"]) == 64) or
                (not data["task_present"] and data["task_xml_sha256"] == ""), "task_presence_schema")
        for token, item in data["run_values"].items():
            require(type(token) is str and bool(token), "private_run_identity")
            exact(item, {"view": int, "kind": str, "raw_value_sha256": str})
            require(item["view"] in (32, 64) and len(item["raw_value_sha256"]) == 64, "run_metadata_schema")
    elif kind == "routes":
        require(type(data) is dict and bool(data), "route_inventory_schema")
        for token, item in data.items():
            require(type(token) is str and bool(token), "route_identity_schema")
            exact(item, {"reason": str, "delivered": int, "acknowledged": int, "history_recovered": bool})
            require(item["delivered"] >= 0 and item["acknowledged"] >= 0, "route_counter_bounds")
    else:
        raise EvidenceStop("unknown_collector_kind")


class Journal:
    def __init__(self, root, attempt_id, plan_digest):
        self.root = Path(root)
        # Root must be pre-reserved by the reviewed attempt mechanism. Never
        # implicitly create a missing root or resume an existing journal.
        require(self.root.is_dir(), "missing_attempt_root")
        self.directory = self.root / "journal"
        self.directory.mkdir(mode=0o700, exist_ok=False)
        self.heads = self.root / "journal-heads"
        self.heads.mkdir(mode=0o700, exist_ok=False)
        self.attempt_id, self.plan_digest = attempt_id, plan_digest
        require(type(attempt_id) is str and bool(attempt_id) and len(plan_digest) == 64, "journal_anchor")
        self.previous = "0" * 64
        self.sequence = 0

    def append(self, kind, producer, data, observed_ms):
        require(PRODUCERS.get(kind) == producer, "collector_identity_mismatch")
        validate_payload(kind, data)
        require(type(observed_ms) is int and observed_ms >= 0, "observation_time")
        value = dict(attempt_id=self.attempt_id, plan_sha256=self.plan_digest,
                     sequence=self.sequence, previous_sha256=self.previous,
                     kind=kind, producer=producer, observed_ms=observed_ms, data=data)
        raw = encode(value)
        target = self.directory / f"{self.sequence:06d}.json"
        with target.open("xb") as writer:
            writer.write(raw)
            writer.flush()
            os.fsync(writer.fileno())
        record_hash = hashlib.sha256(raw).hexdigest()
        # Write a new immutable head reference, never overwrite HEAD in place.
        # Failure leaves the record/attempt reserved; do not resume or erase it.
        head = encode(dict(attempt_id=self.attempt_id, plan_sha256=self.plan_digest,
                           sequence=self.sequence, record_sha256=record_hash))
        with (self.heads / f"{self.sequence:06d}.json").open("xb") as writer:
            writer.write(head)
            writer.flush()
            os.fsync(writer.fileno())
        self.previous = record_hash
        self.sequence += 1
        return value


def collect_independent(journal, collectors, observed_ms):
    require(set(collectors) == set(PRODUCERS), "collector_set_incomplete")
    require(len({id(callback) for callback in collectors.values()}) == len(PRODUCERS), "collector_reuse")
    for kind, producer in PRODUCERS.items():
        try:
            data = collectors[kind]()
            validate_payload(kind, data)
        except Exception:
            raise EvidenceStop("collector_failed_" + kind) from None
        try:
            journal.append(kind, producer, data, observed_ms)
        except Exception:
            raise EvidenceStop("journal_write_failed_" + kind) from None


def verify_record(record, attempt_id, plan_digest, previous_hash, sequence, now_ms, max_age_ms):
    require(type(record) is dict and type(now_ms) is int and type(max_age_ms) is int and max_age_ms >= 0, "journal_verification_schema")
    exact(record, {"attempt_id": str, "plan_sha256": str, "sequence": int, "previous_sha256": str,
                   "kind": str, "producer": str, "observed_ms": int, "data": (dict if record.get("kind") != "processes" else list)})
    require(record["attempt_id"] == attempt_id and record["plan_sha256"] == plan_digest, "mixed_attempt")
    require(record["sequence"] == sequence and record["previous_sha256"] == previous_hash, "journal_chain")
    require(0 <= now_ms - record["observed_ms"] <= max_age_ms, "stale_record")
    require(PRODUCERS.get(record["kind"]) == record["producer"], "producer_mismatch")
    validate_payload(record["kind"], record["data"])
    return hashlib.sha256(encode(record)).hexdigest()


def verify_retained_chain(root, attempt_id, plan_digest, final_sequence, retained_head_digest, now_ms, max_age_ms):
    """Final head digest must be retained independently of this journal tree."""
    require(type(final_sequence) is int and 0 <= final_sequence < 10000, "journal_sequence_bounds")
    root = Path(root)
    names = {f"{i:06d}.json" for i in range(final_sequence + 1)}
    require({p.name for p in (root / "journal").iterdir()} == names and
            {p.name for p in (root / "journal-heads").iterdir()} == names, "partial_or_extra_journal")
    previous = "0" * 64
    for index in range(final_sequence + 1):
        raw = (root / "journal" / f"{index:06d}.json").read_bytes()
        record = json.loads(raw)
        previous = verify_record(record, attempt_id, plan_digest, previous, index, now_ms, max_age_ms)
        head_bytes = (root / "journal-heads" / f"{index:06d}.json").read_bytes()
        head = json.loads(head_bytes)
        require(head == dict(attempt_id=attempt_id, plan_sha256=plan_digest, sequence=index, record_sha256=previous), "head_reference_mismatch")
    require(hashlib.sha256(head_bytes).hexdigest() == retained_head_digest, "retained_head_drift")
    return previous
