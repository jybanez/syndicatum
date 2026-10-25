"""Integrated wire/host/handoff inert tests; no process, native or MCP calls."""
import base64
import copy
import importlib.util
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools/release'))
import codex_developer_fixed_journal as fixed
import codex_developer_integrated_preparation as integrated
import codex_developer_transport as transport

spec = importlib.util.spec_from_file_location('integrated_fixture', ROOT / 'tests/codex-developer-integrated-preparation.py')
fixture = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


def storage_channel(api):
    def handle(request):
        op, args = request['operation'], request['arguments']
        if op == 'open':
            raw = base64.b64decode(args['manifest_base64'], validate=True)
            fixed.check(fixed.digest(raw) == args['sha256'] and fixed.packed(api.manifest) == raw, 'fixture_manifest')
            api.open_existing_pair()
            value = api.inspect()
        elif op == 'inspect': value = api.inspect()
        elif op == 'read': value = base64.b64encode(api.read(args['name'], args['index'] * api.manifest['width'], api.manifest['width'])).decode()
        elif op == 'write':
            api.write(args['name'], args['index'] * api.manifest['width'], base64.b64decode(args['base64'], validate=True))
            value = {'written': True}
        elif op == 'flush':
            api.flush(args['name'])
            value = {'flushed': True}
        elif op == 'close':
            api.close()
            value = {'closed': True}
        else: raise AssertionError(op)
        return dict(session=request['session'], sequence=request['sequence'], ok=True, value=value)
    return transport.InertChannel(handle)


def setup_transport():
    old, backend, api, timeline, _ = fixture.setup()
    independent = copy.deepcopy(api.manifest)
    independent['root'], independent['namespace'] = 'INERT-INDEPENDENT-HOST', 'independent-head-attempt'
    for name, pin in independent['files'].items():
        pin.update(path='INERT-INDEPENDENT-HOST/' + name, identity='independent-volume:' + name,
                   final_path='independent-normalized:' + name)
    head_raw = fixed.packed(independent)
    old, backend, api, timeline, _ = fixture.setup(fixed.digest(head_raw))
    def protected(request):
        args = request['arguments']
        fixed.check(request['operation'] == 'protected_get_message' and args['profile_id'] == transport.PROFILE and
                    args['tool'] == 'syndicatum_get_message', 'fixture_protected_tool_scope')
        return dict(session=request['session'], sequence=request['sequence'], ok=True,
                    value=timeline.get_message(args['message_id']))
    canonical_channel = transport.InertChannel(protected)
    canonical = transport.ProtectedCanonicalTimeline(canonical_channel)
    authority = fixed.CanonicalAuthority(canonical, 99998, 99999, old.authority.request_digest, old.context)
    primary_channel = storage_channel(api)
    manifest_raw = fixed.packed(api.manifest)
    primary = transport.WindowsSessionAPI(manifest_raw, primary_channel)
    independent_api = fixed.FakeFixedSessionAPI(independent)
    head_channel = storage_channel(independent_api)
    handoff = transport.DurableHead(transport.WindowsSessionAPI(head_raw, head_channel, 'independent-head-session'), head_raw)
    runner = integrated.IntegratedPreparation(fixed.packed(old.plan), authority, primary, manifest_raw,
        old.fixed_plan_raw, old.receipt_raw, old.now, handoff)
    return runner, backend, api, independent_api, primary_channel, head_channel, canonical_channel


class TransportFixtures(unittest.TestCase):
    def test_same_state_machine_with_persistent_wires_protected_receipt_and_fixed_handoff(self):
        runner, backend, api, head_api, primary, head, canonical = setup_transport()
        adapter = runner.build(backend)
        result = adapter.prepare()
        self.assertEqual(result['result'], 'cutoff_prepared_only', result)
        self.assertTrue(api.held and head_api.held)
        token = result['head_sha256']
        for boundary in ('before-remove', 'after-remove', 'before-add', 'after-add', 'before-plugin-add', 'after-plugin-add'):
            token = adapter.verify_boundary(boundary, token)['head_sha256']
        self.assertFalse(api.held or head_api.held)
        self.assertEqual(runner.handoff.value['head'], token)
        for channel in (primary, head):
            self.assertEqual(sum(r['operation'] == 'open' for r in channel.requests), 1)
            self.assertEqual(sum(r['operation'] == 'close' for r in channel.requests), 1)
            self.assertEqual([r['sequence'] for r in channel.requests], list(range(1, len(channel.requests) + 1)))
        self.assertGreater(len(canonical.requests), 2)
        self.assertTrue(any(head_api.files['records']))
        self.assertEqual(backend.calls.count('suppress'), 1)
        self.assertNotIn('install', backend.calls)

    def test_uncertain_or_replayed_wire_is_terminal_without_reconnect(self):
        for fault in ('sequence', 'eof', 'oversized'):
            with self.subTest(fault=fault):
                calls = []
                def broken(request):
                    calls.append(request)
                    if fault == 'eof': raise EOFError('inert EOF')
                    return dict(session=request['session'], sequence=request['sequence'] + (fault == 'sequence'),
                                ok=True, value=('x' * (transport.MAX_WIRE + 1) if fault == 'oversized' else None))
                wire = transport.CorrelatedChannel(transport.InertChannel(broken), 'test')
                with self.assertRaises(Exception): wire.call('open', {})
                with self.assertRaises(fixed.Stop): wire.call('open', {})
                self.assertEqual(len(calls), 1)

    def test_independent_handoff_tamper_stops_next_action_and_closes_both(self):
        runner, backend, api, head_api, _, _, _ = setup_transport()
        flush = api.flush
        changed = False
        def corrupt(name):
            nonlocal changed
            flush(name)
            if not changed and runner.journal is not None and runner.journal.pending == 'stop':
                changed = True
                head_api.files['heads'][0] ^= 1
                # Current record as well: the independently retained tip must fail.
                index = runner.handoff.index - 1
                head_api.files['heads'][index * head_api.manifest['width']] ^= 1
        api.flush = corrupt
        result = runner.build(backend).prepare()
        self.assertEqual(result['result'], 'stopped')
        self.assertNotIn('stop', backend.calls)
        self.assertFalse(api.held or head_api.held)

    def test_production_defaults_refuse_without_dispatch_or_process(self):
        runner, _, api, _, _, _, _ = setup_transport()
        windows = transport.WindowsSessionAPI(fixed.packed(api.manifest))
        with self.assertRaisesRegex(fixed.Stop, 'source_only_persistent_windows_gate'): windows.open_existing_pair()
        with self.assertRaisesRegex(fixed.Stop, 'protected_host_dispatch_and_durable_handoff_unavailable'):
            transport.ProtectedCanonicalTimeline().get_message(99999)

    def test_open_pin_mismatch_and_handoff_flush_uncertainty_do_not_leak_or_replay(self):
        runner, backend, api, head_api, primary, _, _ = setup_transport()
        handler = primary.handler
        def wrong_pin(request):
            response = handler(request)
            if request['operation'] == 'open': response['value']['namespace'] = 'wrong'
            return response
        primary.handler = wrong_pin
        result = runner.build(backend).prepare()
        self.assertEqual(result['result'], 'stopped')
        self.assertFalse(api.held or head_api.held)
        self.assertNotIn('suppress', backend.calls)
        self.assertEqual(sum(r['operation'] == 'close' for r in primary.requests), 1)
        runner, backend, api, head_api, _, head, _ = setup_transport()
        head_api.fault = 'flush'
        result = runner.build(backend).prepare()
        self.assertEqual(result['result'], 'stopped')
        self.assertFalse(api.held)
        self.assertNotIn('suppress', backend.calls)
        self.assertTrue(runner.handoff.closed)
        self.assertTrue(runner.handoff.storage.api.close_uncertain)
        self.assertIn('failure_session_close_uncertain', result['secondary_errors'])
        # Poisoned wire is not reconnected even to retry close. The fixture's
        # still-held handle models unresolved child ownership, not cleanup success.
        self.assertTrue(head_api.held)
        self.assertEqual(sum(r['operation'] == 'open' for r in head.requests), 1)
        self.assertEqual(sum(r['operation'] == 'flush' for r in head.requests), 1)


if __name__ == '__main__': unittest.main()
