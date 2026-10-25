"""Disposable Git index/checkout only; never invokes the synthetic native harness."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PACKET = "docs/codex-fixed-synthetic-review-packet.json"
RULE = (PACKET + " text eol=lf\n").encode()


def git(*args, cwd=ROOT):
    env = {key: value for key, value in os.environ.items()
           if not key.startswith("GIT_")}
    env.update(GIT_CONFIG_NOSYSTEM="1", GIT_CONFIG_GLOBAL=os.devnull,
               GIT_TERMINAL_PROMPT="0")
    return subprocess.check_output(["git", *args], cwd=cwd, env=env, stderr=subprocess.PIPE)


class CheckoutFixtures(unittest.TestCase):
    def materialize(self, omit_packet_rule=False):
        revision = git("rev-parse", "HEAD").decode().strip()
        blob = git("show", revision + ":" + PACKET)
        packet = json.loads(blob)
        paths = (PACKET, packet["harness"], packet["backend"])
        blobs = {path: git("show", revision + ":" + path) for path in paths}
        attributes = git("show", revision + ":.gitattributes")
        self.assertIn(RULE, attributes)
        if omit_packet_rule:
            attributes = attributes.replace(RULE, b"")
        with tempfile.TemporaryDirectory(prefix="syndicatum-source-checkout-") as temporary:
            repository = Path(temporary) / "index-fixture"
            output = Path(temporary) / "materialized"
            repository.mkdir()
            output.mkdir()
            git("init", "--quiet", cwd=repository)
            git("config", "core.autocrlf", "true", cwd=repository)
            git("config", "core.safecrlf", "false", cwd=repository)
            self.assertEqual(git("config", "--get", "core.autocrlf", cwd=repository).strip(), b"true")
            (repository / ".gitattributes").write_bytes(attributes)
            for path, raw in blobs.items():
                target = repository / path
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(raw)
            git("add", "--", ".gitattributes", *paths, cwd=repository)
            # This performs Git checkout conversion, unlike archive or raw copies.
            git("checkout-index", "--all", "--prefix=" + output.as_posix() + "/", cwd=repository)
            actual = {path: (output / path).read_bytes() for path in paths}
            self.assertEqual(git("status", "--porcelain", cwd=repository).count(b"A  "), 4)
        return packet, blobs, actual

    def test_autocrlf_true_materializes_all_reviewed_bytes_exactly(self):
        packet, blobs, actual = self.materialize()
        for path in blobs:
            self.assertEqual(actual[path], blobs[path], path)
            self.assertNotIn(b"\r", actual[path], path)
        checked_packet = json.loads(actual[PACKET])
        for kind in ("harness", "backend"):
            self.assertEqual(hashlib.sha256(actual[packet[kind]]).hexdigest(), checked_packet[kind + "_sha256"])
        print("PASS: core.autocrlf=true checkout materialized packet/harness/backend equals exact Git blobs and packet pins.")

    def test_negative_control_without_packet_rule_reproduces_crlf_mismatch(self):
        packet, blobs, actual = self.materialize(omit_packet_rule=True)
        self.assertNotEqual(actual[PACKET], blobs[PACKET])
        self.assertIn(b"\r\n", actual[PACKET])
        self.assertEqual(actual[PACKET].replace(b"\r\n", b"\n"), blobs[PACKET])
        for kind in ("harness", "backend"):
            self.assertEqual(actual[packet[kind]], blobs[packet[kind]])
        print("PASS: removing ONLY the packet LF rule reproduces the exact class of preflight failure.")


if __name__ == "__main__":
    print(git("--version").decode().strip())
    unittest.main()
