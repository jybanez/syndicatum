#!/usr/bin/env python3
"""Independent golden-byte verification for canonical inventory fixtures."""

import hashlib
import json
from pathlib import Path


FIXTURES = Path(__file__).with_name("fixtures")


def verify_fixture(name: str, expected_sha256: str, expected_keys: list[str]) -> None:
    raw = (FIXTURES / name).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf"), f"{name} must not contain a UTF-8 BOM"
    assert raw.endswith(b"\n"), f"{name} must end with LF"
    assert b"\r" not in raw, f"{name} must use LF-only line endings"
    assert hashlib.sha256(raw).hexdigest() == expected_sha256, f"{name} digest changed"

    rebuilt = bytearray()
    for line in raw.splitlines():
        record = json.loads(line.decode("utf-8"), object_pairs_hook=dict)
        assert list(record) == expected_keys, f"{name} key order changed"
        rebuilt.extend(json.dumps(record, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))
        rebuilt.extend(b"\n")
    assert bytes(rebuilt) == raw, f"{name} is not reproducible with independent canonical JSON"


verify_fixture(
    "canonical-inventory-helper-reference.jsonl",
    "e0bfea661be042a603f8e396580022e4c0923998bc53f32c6c4e4769c9cead30",
    ["path", "type", "mode", "size", "sha256"],
)
verify_fixture(
    "canonical-inventory-v1.jsonl",
    "1439d469f535dfeadd0351a2f4b64ee7b626dad81f3cc6a1f8e776834ed7d7cd",
    ["path", "type", "role", "mode", "size", "sha256"],
)
verify_fixture(
    "canonical-inventory-helper-role-reference.jsonl",
    "0360cff4f62a664df3c2a552e0d200cdf684586bf65a3895fe930872bed8dd42",
    ["path", "type", "role", "mode", "size", "sha256"],
)
verify_fixture(
    "canonical-inventory-v1-single.jsonl",
    "a3451d1a2752e46c566116e83ad2caee65d6ccc9f7d828ce73f6a2fe9ac09723",
    ["path", "type", "role", "mode", "size", "sha256"],
)

print("Independent canonical inventory golden fixtures passed")
