"""Actual assembly object graph + private stdio, with inert tools/files only."""
import importlib.util
import io
import json
import hashlib
import re
import subprocess
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_host_assembly as assembly
import codex_developer_fixed_journal as fixed
import codex_developer_transport as transport

spec=importlib.util.spec_from_file_location('host_fixture',ROOT/'tests/codex-developer-host-ipc.py')
fixture=importlib.util.module_from_spec(spec)
spec.loader.exec_module(fixture)


class AssemblyFixtures(unittest.TestCase):
    def test_packet_complete_source_pins_and_uncaptured_phase_boundaries(self):
        packet=json.loads((ROOT/'docs/codex-developer-pilot-admission-packet.json').read_text())
        source=(ROOT/'tools/release/codex_developer_codex_entry.mjs').read_text()
        declared=re.findall(r"'([^']+)'",source.split('Object.freeze([',1)[1].split(']);',1)[0])
        self.assertEqual(sorted(declared),sorted(item['name'] for item in packet['source_files']))
        for item in packet['source_files']:
            raw=subprocess.check_output(['git','show',':'+item['repository_path']],cwd=ROOT)
            self.assertEqual(hashlib.sha256(raw).hexdigest(),item['git_blob_bytes_sha256'])
        self.assertFalse(packet['owner_authority']['requested_now'])
        self.assertTrue(all(tree['status']!='captured' for tree in packet['runtime_trees'].values()))
        self.assertEqual(packet['entrypoints']['installer_dispatch'],'IMPLEMENTED_CLOSED')
        self.assertEqual(packet['entrypoints']['activation_dispatch'],'IMPLEMENTED_OWNER_CONTROLLED_RELOAD')
        self.assertEqual([phase['id'] for phase in packet['phases']],list('ABCDEF'))
        capacity=packet['capacity']
        self.assertEqual(sum(2*capacity[role]['slots']*capacity[role]['width'] for role in
            ('writer','independent_head','private_receipt')),capacity['container_bytes_upper_bound'])

    def test_actual_builder_challenge_receipt_parsing_journal_actions_and_terminal_close(self):
        for fault in (None,'substituted','context'):
            with self.subTest(fault=fault):
                old,backend,writer,head,receipt,channel,calls=fixture.setup_host(fault)
                manifests={'writer':writer.manifest,'head':head.manifest,'receipt':receipt.manifest}
                config={'operation':'prepare_only','session':'inert-assembly','manifests':manifests,
                    'request_id':99998,'approval_id':99999,'receipt_manifest_sha256':channel.manifest_sha256,
                    'native_context_sha256':old.context,'request_material_sha256':old.authority.request_digest,
                    'native_plan':old.plan,'reviewed_fixed_plan':json.loads(old.fixed_plan_raw),
                    'expected_receipt':json.loads(old.receipt_raw)}
                apis={'writer':writer,'head':head,'receipt':receipt}
                def factory(role,raw):
                    return transport.WindowsSessionAPI(raw,fixture.wire_fixture.storage_channel(apis[role]),'assembly-'+role)
                state=assembly.assemble(config,channel.invoke,old.now,session_factory=factory,backend=backend)
                result=state.prepare()
                if fault:
                    self.assertEqual(result['result'],'stopped',result)
                    self.assertNotIn('suppress',backend.calls)
                else:
                    self.assertEqual(result['result'],'cutoff_prepared_only',result)
                    token=result['head_sha256']
                    for name in ('before-remove','after-remove','before-add','after-add','before-plugin-add','after-plugin-add'):
                        token=state.boundary(name,token)['head_sha256']
                    self.assertGreater(len(calls),6)
                    self.assertEqual(state.runner.handoff.value['head'],token)
                self.assertTrue(state.closed)
                self.assertFalse(any(api.held for api in apis.values()))
                with self.assertRaises(fixed.Stop):state.prepare()

    def test_private_stdio_delivery_correlation_and_closed_cli(self):
        challenge={'invocation_id':'inert-one','arguments':{'profile_id':'1f397680dd1cddc0.2.33','message_id':99998}}
        envelope={'invocation':challenge,'raw_base64':'e30=','started_ms':1000,'finished_ms':1001}
        for session in ('private','wrong'):
            inbound=io.BytesIO(fixed.packed({'kind':'tool_delivery','session':session,'envelope':envelope})+b'\n')
            outbound=io.BytesIO()
            link=assembly.AgentStdioLink('private',inbound,outbound)
            if session=='private':self.assertEqual(link.invoke(challenge),envelope)
            else:
                with self.assertRaises(fixed.Stop):link.invoke(challenge)
                self.assertTrue(link.closed)
            self.assertEqual(json.loads(outbound.getvalue())['challenge'],challenge)
        with patch.object(assembly.Path,'read_bytes') as read:
            with self.assertRaisesRegex(RuntimeError,'SOURCE_ONLY'):assembly.main()
            read.assert_not_called()


if __name__=='__main__':unittest.main()
