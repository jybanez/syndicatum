"""Closed native launch, implemented bounded/deadline-aware pipe exchange.

Tests attach inert child streams; no worker is launched and the launch gate is
never patched. Cooperating host supplies reviewed pins, never an untrusted plan.
"""
import ctypes
import hashlib
import json
import os
from pathlib import Path
import queue
import subprocess
import threading
import time

from codex_developer_fixed_journal import Stop, check, packed

MAX_FRAME = 2 * 1024 * 1024
MAX_STDERR = 65536


def _unique(pairs):
    result = {}
    for key, value in pairs:
        check(key not in result, "ipc_duplicate_key")
        result[key] = value
    return result


class PinnedWindowsProcess:
    def __init__(self, executable, worker, pins, deadline_seconds=30):
        check(type(deadline_seconds) in (int, float) and 0 < deadline_seconds <= 120, "ipc_deadline_bound")
        self.executable, self.worker, self.pins = executable, worker, dict(pins)
        self.deadline_seconds = deadline_seconds
        self.child, self.identity, self.terminal, self.close_attempted = None, None, False, False
        self.last_sequence, self.session = 0, None
        self.stderr, self.stderr_overflow = bytearray(), False
        self.stderr_started, self.stderr_done = False, threading.Event()

    @staticmethod
    def _launch_gate():
        raise Stop("source_only_persistent_process_launch_disabled")

    def _pin_files(self):
        worker = Path(self.worker)
        required = {self.executable, self.worker, str(worker.parent / "codex-developer-fixed-session-protocol.ps1"),
                    str(worker.parent / "codex-developer-fixed-storage.psm1")}
        check(os.name == "nt" and os.path.isabs(self.executable) and os.path.isabs(self.worker) and
              set(self.pins) == required and worker.name == "codex-developer-fixed-session-worker.ps1" and
              Path(self.executable).name.lower() == "pwsh.exe", "ipc_reviewed_file_set")
        for name, expected in self.pins.items():
            path = Path(name)
            check(path.is_absolute() and not path.is_symlink() and path.is_file() and
                  0 < path.stat().st_size <= 134217728 and
                  hashlib.sha256(path.read_bytes()).hexdigest() == expected, "ipc_executable_or_script_pin")

    def _probe(self):
        # Only the child created by this transport may be observed. Nothing is
        # searched by process name; PID + creation FILETIME prevents PID reuse.
        check(os.name == "nt" and self.child is not None and self.child.poll() is None, "ipc_child_not_running")
        from ctypes import wintypes
        api = ctypes.WinDLL("kernel32", use_last_error=True)
        api.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
        api.OpenProcess.restype = wintypes.HANDLE
        api.QueryFullProcessImageNameW.argtypes = [wintypes.HANDLE, wintypes.DWORD, wintypes.LPWSTR, ctypes.POINTER(wintypes.DWORD)]
        api.GetProcessTimes.argtypes = [wintypes.HANDLE] + [ctypes.POINTER(wintypes.FILETIME)] * 4
        api.CloseHandle.argtypes = [wintypes.HANDLE]
        handle = api.OpenProcess(0x1000, False, self.child.pid)
        check(bool(handle), "ipc_child_handle")
        try:
            size, path = wintypes.DWORD(32768), ctypes.create_unicode_buffer(32768)
            check(bool(api.QueryFullProcessImageNameW(handle, 0, path, ctypes.byref(size))), "ipc_child_image")
            times = [wintypes.FILETIME() for _ in range(4)]
            check(bool(api.GetProcessTimes(handle, *(ctypes.byref(t) for t in times))), "ipc_child_creation")
            check(os.path.normcase(path.value) == os.path.normcase(os.path.abspath(self.executable)), "ipc_child_image_changed")
            return {"pid": self.child.pid, "created_filetime": (times[0].dwHighDateTime << 32) | times[0].dwLowDateTime,
                    "image": path.value, "sha256": hashlib.sha256(Path(path.value).read_bytes()).hexdigest()}
        finally:
            check(bool(api.CloseHandle(handle)), "ipc_child_probe_close_uncertain")

    def _start(self):
        self._launch_gate()  # Unconditional refusal; no flag/environment bypass.
        self._pin_files()
        self.child = subprocess.Popen([self.executable, "-NoLogo", "-NoProfile", "-NonInteractive", "-File", self.worker],
            executable=self.executable, cwd=str(Path(self.worker).parent), stdin=subprocess.PIPE,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, shell=False, close_fds=True,
            creationflags=subprocess.CREATE_NO_WINDOW)
        self.identity = self._probe()
        check(self.identity["sha256"] == self.pins[self.executable], "ipc_child_executable_hash")
        self._pin_files()  # Refuse a changed launch source after process creation.
        self.stderr_started = True
        threading.Thread(target=self._drain_stderr, daemon=True).start()

    def _drain_stderr(self):
        try:
            while True:
                raw = self.child.stderr.read(1)
                if not raw: return
                if len(self.stderr) >= MAX_STDERR:
                    self.stderr_overflow = True
                    return
                self.stderr.extend(raw)
        except Exception:
            self.stderr_overflow = True
        finally:
            self.stderr_done.set()

    def _bounded(self, work, deadline):
        remaining = deadline - time.monotonic()
        check(remaining > 0, "ipc_deadline_expired")
        results = queue.Queue(maxsize=1)
        def run():
            try: results.put((True, work()))
            except Exception as error: results.put((False, error))
        threading.Thread(target=run, daemon=True).start()
        remaining = deadline - time.monotonic()
        check(remaining > 0, "ipc_deadline_expired")
        try: success, result = results.get(timeout=remaining)
        except queue.Empty: raise Stop("ipc_timeout_child_outcome_uncertain") from None
        if not success: raise result
        return result

    def _read_frame(self, limit=None):
        limit = MAX_FRAME if limit is None else limit
        check(type(limit) is int and 0 < limit <= MAX_FRAME, 'ipc_read_limit')
        raw = bytearray()
        while True:
            char = self.child.stdout.read(1)
            check(char != b"", "ipc_eof_child_outcome_uncertain")
            if char == b"\n": break
            check(len(raw) < limit, "ipc_frame_bound")
            raw.extend(char)
        check(bool(raw), "ipc_empty_frame")
        return json.loads(raw.decode("utf-8"), object_pairs_hook=_unique)

    def exchange(self, request):
        check(not self.terminal, "ipc_terminal_no_reconnect")
        deadline = time.monotonic() + self.deadline_seconds
        try:
            check(type(request["sequence"]) is int and request["sequence"] == self.last_sequence + 1 and
                  (self.session is None or request["session"] == self.session), "ipc_request_sequence")
            self.last_sequence, self.session = request["sequence"], request["session"]
            if self.child is None: self._start()
            check(self._probe() == self.identity and not self.stderr_overflow, "ipc_child_identity_or_stderr")
            raw = packed(request)
            check(len(raw) <= MAX_FRAME, "ipc_request_bound")
            def send():
                check(self.child.stdin.write(raw + b"\n") == len(raw) + 1, "ipc_short_write_uncertain")
                self.child.stdin.flush()
            self._bounded(send, deadline)
            result = self._bounded(self._read_frame, deadline)
            check(type(result) is dict and result.get("session") == self.session and
                  type(result.get("sequence")) is int and result["sequence"] == self.last_sequence,
                  "ipc_response_correlation")
            if request["operation"] == "close":
                self.close_attempted = True
                check(result.get("ok") is True and result.get("value") == {"closed": True}, "ipc_close_uncertain")
                check(self._bounded(lambda: self.child.wait(timeout=max(0.001, deadline-time.monotonic())), deadline) == 0,
                      "ipc_child_exit_uncertain")
                if self.stderr_started:
                    check(self.stderr_done.wait(max(0, deadline-time.monotonic())), "ipc_stderr_exit_uncertain")
                for stream in (self.child.stdin, self.child.stdout, self.child.stderr):
                    self._bounded(stream.close, deadline)
                self.terminal = True
            else:
                check(self._probe() == self.identity, "ipc_child_identity_changed")
            check(not self.stderr_overflow and time.monotonic() <= deadline, "ipc_response_late_or_stderr")
            return result
        except Exception as error:
            self.terminal = True
            error.close_uncertain = self.child is not None
            # Do not kill/restart/reconnect a child after an uncertain action.
            # Retain identity/stderr/sequence for the trusted caller's evidence.
            raise
