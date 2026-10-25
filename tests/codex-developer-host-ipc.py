"""Host-mediated ingress + bounded process exchange; inert streams only."""
import copy
import importlib.util
import io
import json
from pathlib import Path
import sys
import time
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/release'))
import codex_developer_fixed_journal as fixed
import codex_developer_integrated_preparation as integrated
import codex_developer_transport as transport
import codex_developer_host_handoff as host
import codex_developer_process_ipc as ipc

spec = importlib.util.spec_from_file_location('wire_fixture', ROOT / 'tests/codex-developer-transport.py')
wire_fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wire_fixture)


def setup_host(fault=None):
    prior, _, api, head_api, _, _, _ = wire_fixture.setup_transport()
    receipt_manifest = copy.deepcopy(api.manifest)
    receipt_manifest.update(root='INERT-TRUSTED-RECEIPTS', namespace='trusted-agent-receipts', width=49152)
    for name, pin in receipt_manifest['files'].items():
        pin.update(path='INERT-TRUSTED-RECEIPTS/' + name, final_path='receipt-normalized:' + name,
                   identity='receipt-volume:' + name, length=128 * 49152)
    raw = fixed.packed(receipt_manifest)
    old, backend, api, timeline, _ = wire_fixture.fixture.setup(prior.handoff.manifest_sha256, fixed.digest(raw))
    if fault == 'stale': backend.time = 200000
    receipt_api = fixed.FakeFixedSessionAPI(receipt_manifest)
    receipt_channel = wire_fixture.storage_channel(receipt_api)
    invocations = []
    def invoke(invocation):
        invocations.append(copy.deepcopy(invocation))
        value = timeline.get_message(invocation['arguments']['message_id'])
        if fault == 'context' and invocation['arguments']['message_id'] == 99998:
            body = json.loads(value['message']['body'])
            body['plan_context_sha256'] = '0' * 64
            value['message']['body'] = fixed.packed(body).decode()
        delivered = copy.deepcopy(invocation)
        if fault == 'substituted': delivered['invocation_id'] = 'wrong'
        started = backend.time - (120001 if fault == 'stale' else 0)
        import base64
        return {'invocation': delivered, 'raw_base64': base64.b64encode(json.dumps(value, indent=2).encode()).decode(),
                'started_ms': started, 'finished_ms': started}
    channel = host.TrustedAgentChannel(invoke, lambda: backend.time + 1,
        transport.WindowsSessionAPI(raw, receipt_channel, 'private-receipts'), raw, fixed.digest(raw),
        99998, 99999, old.context)
    authority = fixed.CanonicalAuthority(transport.ProtectedCanonicalTimeline(channel), 99998, 99999,
                                         old.authority.request_digest, old.context)
    runner = integrated.IntegratedPreparation(fixed.packed(old.plan), authority,
        transport.WindowsSessionAPI(fixed.packed(api.manifest), wire_fixture.storage_channel(api)),
        fixed.packed(api.manifest), old.fixed_plan_raw, old.receipt_raw, old.now, prior.handoff)
    return runner, backend, api, head_api, receipt_api, channel, invocations


class HostIPCFixtures(unittest.TestCase):
    def test_trusted_host_tool_receipts_compose_with_all_preparation_boundaries(self):
        runner, backend, api, head_api, receipt_api, channel, invocations = setup_host()
        adapter = runner.build(backend)
        result = adapter.prepare()
        self.assertEqual(result['result'], 'cutoff_prepared_only', result)
        head = result['head_sha256']
        for boundary in ('before-remove', 'after-remove', 'before-add', 'after-add', 'before-plugin-add', 'after-plugin-add'):
            head = adapter.verify_boundary(boundary, head)['head_sha256']
        self.assertFalse(api.held or head_api.held or receipt_api.held)
        self.assertTrue(channel.closed)
        self.assertGreater(len(invocations), 6)
        self.assertLess(len(invocations), 128)
        self.assertEqual(len({x['invocation_id'] for x in invocations}), len(invocations))
        self.assertTrue(all(x['tool'] == host.TOOL and x['arguments']['profile_id'] == host.PROFILE for x in invocations))
        receipt = fixed.unframe(bytes(receipt_api.files['records'][:receipt_api.manifest['width']]))
        import base64
        exact = base64.b64decode(receipt['raw_base64'], validate=True)
        self.assertEqual(fixed.digest(exact), receipt['raw_sha256'])
        self.assertIn(b'\n  ', exact)  # Original formatting retained, not reserialized.
        self.assertNotIn('install', backend.calls)

    def test_stale_substituted_context_handoffs_stop_without_action_or_replay(self):
        for fault in ('stale', 'substituted', 'context', 'publication-age'):
            with self.subTest(fault=fault):
                runner, backend, api, head_api, receipts, channel, invocations = setup_host(fault)
                if fault == 'publication-age':
                    original = receipts.flush
                    def slow_flush(name):
                        original(name)
                        backend.time += 120001
                    receipts.flush = slow_flush
                adapter = runner.build(backend)
                result = adapter.prepare()
                self.assertEqual(result['result'], 'stopped', result)
                self.assertNotIn('suppress', backend.calls)
                self.assertFalse(api.held or head_api.held or receipts.held)
                calls = len(invocations)
                self.assertEqual(adapter.prepare()['code'], 'attempt_object_reuse')
                self.assertEqual(len(invocations), calls)

    def test_bounded_ipc_success_eof_timeout_correlation_and_gate(self):
        class Child:
            pid = 999
            def __init__(self, raw):
                self.stdin, self.stdout, self.stderr = io.BytesIO(), io.BytesIO(raw), io.BytesIO()
            def poll(self): return None
            def wait(self, timeout): return 0
        request = dict(session='inert-child', sequence=1, operation='inspect', arguments={})
        response = dict(session='inert-child', sequence=1, ok=True, value={})
        for mode in ('success', 'eof', 'timeout', 'sequence', 'oversize', 'identity', 'close'):
            with self.subTest(mode=mode):
                value = dict(response)
                if mode == 'sequence': value['sequence'] = 2
                if mode == 'close': value['value'] = {'closed': True}
                raw = fixed.packed(value) + b'\n'
                if mode == 'eof': raw = b''
                if mode == 'oversize': raw = b'x' * 257
                child = Child(raw)
                process = ipc.PinnedWindowsProcess('INERT-pwsh.exe', 'INERT-worker', {}, 0.05)
                process.child, process.identity = child, {'fixture': 'same-child'}
                probes = []
                def probe():
                    probes.append(1)
                    return {'fixture': 'changed' if mode == 'identity' and len(probes) > 1 else 'same-child'}
                process._probe = probe  # Inert identity seam; launch gate untouched.
                if mode == 'timeout':
                    class Slow:
                        def read(self, count):
                            time.sleep(0.2)
                            return b''
                    child.stdout = Slow()
                call = dict(request)
                if mode == 'close': call['operation'] = 'close'
                with patch.object(ipc, 'MAX_FRAME', 256):
                    if mode in ('success', 'close'):
                        self.assertEqual(process.exchange(call), value)
                    else:
                        with self.assertRaises(Exception): process.exchange(call)
                        with self.assertRaises(fixed.Stop): process.exchange(call)
                        self.assertTrue(process.terminal)
        process = ipc.PinnedWindowsProcess('INERT-pwsh.exe', 'INERT-worker', {})
        with patch.object(ipc.subprocess, 'Popen') as launch:
            with self.assertRaisesRegex(fixed.Stop, 'source_only_persistent_process_launch_disabled'):
                process.exchange(request)
            launch.assert_not_called()


if __name__ == '__main__': unittest.main()
