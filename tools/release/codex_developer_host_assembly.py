"""One Python coordinator, trusted caller-owned private ledgers, closed CLI.

The Codex host pumps tool challenges through this coordinator's private stdio.
No installer/activation dispatcher exists. CLI gate refuses before packet read.
"""
import json
from pathlib import Path
import sys
import time

from codex_developer_fixed_journal import CanonicalAuthority, check, digest, packed
from codex_developer_host_handoff import TrustedAgentChannel
from codex_developer_integrated_preparation import IntegratedPreparation
from codex_developer_native_b import WindowsBackend
from codex_developer_process_ipc import PinnedWindowsProcess
from codex_developer_transport import DurableHead, ProtectedCanonicalTimeline, WindowsSessionAPI


class AgentStdioLink:
    """Correlated private parent/coordinator channel, one pending tool result.

    Only the trusted agent owns the other end. Receipt/head storage is never
    addressed by stdin content; its manifests/capabilities come from pinned config.
    """
    def __init__(self, session, input_stream, output_stream):
        self.session, self.input, self.output = session, input_stream, output_stream
        self.closed, self.pending = False, False
        # Reuse the already tested bounded IO implementation, not its launch path.
        self.io = PinnedWindowsProcess('NO-LAUNCH', 'NO-LAUNCH', {}, 30)
        self.io.child = type('ParentStreams', (), {'stdout': input_stream})()

    def emit(self, value, deadline):
        raw = packed(value) + b'\n'
        check(len(raw) <= 65536, 'agent_assembly_frame_bound')
        def write():
            check(self.output.write(raw) == len(raw), 'agent_assembly_short_write')
            self.output.flush()
        self.io._bounded(write, deadline)

    def invoke(self, challenge):
        check(not self.closed and not self.pending, 'agent_assembly_terminal_or_pending')
        self.pending = True
        deadline = time.monotonic() + 30
        try:
            self.emit({'kind': 'tool_challenge', 'session': self.session, 'challenge': challenge}, deadline)
            response = self.io._bounded(lambda: self.io._read_frame(65536), deadline)
            check(type(response) is dict and set(response) == {'kind', 'session', 'envelope'} and
                  response['kind'] == 'tool_delivery' and response['session'] == self.session,
                  'agent_assembly_delivery_correlation')
            return response['envelope']
        except Exception:
            self.closed = True
            raise
        finally:
            self.pending = False


class TrustedHostState:
    """Trusted caller layer owns three disjoint exclusive session capabilities.

    Writer receives its primary storage and journal callbacks. Caller receipt/head
    capabilities are never constructed from a tool result or public plan body.
    This is cooperating-code ownership, not hostile-code process isolation.
    """
    def __init__(self, runner, backend, link=None):
        self.runner, self.backend, self.link = runner, backend, link
        self.adapter, self.started, self.closed = None, False, False

    def prepare(self):
        check(not self.started and not self.closed, 'host_assembly_no_replay')
        self.started = True
        self.adapter = self.runner.build(self.backend)
        result = self.adapter.prepare()
        if result['result'] == 'stopped': self.close_terminal()
        return result

    def boundary(self, name, independently_retained_head):
        check(self.started and not self.closed and self.adapter is not None, 'host_assembly_boundary_lifetime')
        try:
            result = self.adapter.verify_boundary(name, independently_retained_head)
            if self.adapter.terminal: self.close_terminal()
            return result
        except Exception as error:
            try: self.close_terminal()
            except Exception: error.add_note('host_assembly_close_uncertain; no replay')
            raise

    def close_terminal(self):
        if self.closed: return
        self.closed = True
        errors = []
        journal = self.runner.journal
        closers = ([journal.close_terminal] if journal is not None else
                   [self.runner.handoff.close_terminal, self.runner.authority.close_terminal])
        for close in closers:
            try: close()
            except Exception as error: errors.append(error)
        if self.link is not None: self.link.closed = True
        if errors:
            errors[0].close_uncertain = True
            raise errors[0]


def assemble(config, trusted_invoke, now_ms, *, session_factory=None, backend=None, link=None):
    """Actual object graph used by the closed CLI; inert factories only in tests.

    config is the trusted agent's private digest-verified packet, not message JSON.
    Live CLI must validate packet/source/runtime/ACL admission before calling this.
    """
    check(config['operation'] == 'prepare_only', 'installer_activation_dispatch_unimplemented')
    if session_factory is None:
        def session_factory(role, manifest_raw):
            process = PinnedWindowsProcess(config['powershell_executable'], config['worker_script'], config['worker_pins'])
            return WindowsSessionAPI(manifest_raw, process, config['session'] + '-' + role)
    manifests = {name: packed(config['manifests'][name]) for name in ('writer', 'head', 'receipt')}
    apis = {role: session_factory(role, raw) for role, raw in manifests.items()}
    caller_head = DurableHead(apis['head'], manifests['head'])
    request_id, approval_id = config['request_id'], config['approval_id']
    receipt_channel = TrustedAgentChannel(trusted_invoke, now_ms, apis['receipt'], manifests['receipt'],
        config['receipt_manifest_sha256'], request_id, approval_id, config['native_context_sha256'])
    authority = CanonicalAuthority(ProtectedCanonicalTimeline(receipt_channel), request_id, approval_id,
                                   config['request_material_sha256'], config['native_context_sha256'])
    runner = IntegratedPreparation(packed(config['native_plan']), authority, apis['writer'], manifests['writer'],
        packed(config['reviewed_fixed_plan']), packed(config['expected_receipt']), now_ms, caller_head)
    actual_backend = backend if backend is not None else WindowsBackend(config['native_module'], config['native_module_sha256'])
    return TrustedHostState(runner, actual_backend, link)


def main():
    raise RuntimeError('SOURCE_ONLY: actual Developer host assembly CLI disabled before packet/stdio/native IO')
    # Reviewed future entry, still unreachable. Never enable to fill draft fields.
    config_raw = Path(sys.argv[1]).read_bytes()
    check(len(config_raw) <= 1048576 and digest(config_raw) == sys.argv[2], 'private_assembly_packet_pin')
    config = json.loads(config_raw)
    check(packed(config) == config_raw, 'private_assembly_packet_canonical')
    link = AgentStdioLink(config['session'], sys.stdin.buffer, sys.stdout.buffer)
    state = assemble(config, link.invoke, lambda: time.time_ns() // 1000000, link=link)
    try:
        result = state.prepare()
        # No install/activation command can follow preparation in this source.
        state.close_terminal()
        link.emit({'kind': 'assembly_result', 'session': config['session'], 'result': result,
                   'session_closed': True, 'installer_dispatch': 'unimplemented', 'authorizes_install': False},
                  time.monotonic() + 30)
    finally:
        state.close_terminal()


if __name__ == '__main__': main()
