"""Admission orchestration/sink tests with no native queries or capture allocation."""
import base64
import importlib.util
import json
from pathlib import Path
import sys
import unittest
import subprocess

ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_admission as admission


class Store:
    def __init__(self):self.files={};self.writes=[];self.fail=None;self.bad_read=False
    def write_once(self,name,raw,*,terminal=False):
        self.writes.append((name,terminal))
        if name==self.fail:raise OSError('inert_write_uncertain')
        if name in self.files:raise AssertionError('overwrite')
        self.files[name]=raw
    def read(self,name,limit):return b'wrong' if self.bad_read else self.files[name][:limit]


def fixture(*,deny=0,cli_failure=False,gap=False):
    sources={'reviewed.py':b'reviewed-source'}
    packet={'scope':admission.SCOPE,'capture_identity':admission.CAPTURE_ID,
            'sources':{name:{'sha256':admission.sha(raw),'size':len(raw)} for name,raw in sources.items()},
            'runtime_discovery_roots':['C:/inert'],'known_process_image_pins':{}}
    calls=[];store=Store()
    class Cli:
        def __init__(self):self.results=[]
        def read(self,command,deadline):
            calls.append(('cli',command))
            if cli_failure and command=='plugins':
                self.results.append({'command':command,'status':'failed_uncertain','stdout_prefix_base64':base64.b64encode(b'partial').decode()})
                raise TimeoutError('primary_cli_timeout')
            raw=b'{"inert":true}'
            self.results.append({'command':command,'status':'exited','stdout_base64':base64.b64encode(raw).decode(),'stderr_base64':''})
            return {'installed':[],'available':[]} if command=='plugins' else {'marketplaces':[]}
    class Reader:
        ref_reads=[{'role':'managed','relative':'HEAD','raw_base64':base64.b64encode(b'ref: refs/heads/main\n').decode()}]
        def layout(self,description,partial=False):
            calls.append(('layout',description['root_role'],partial))
            if description['root_role']=='installed_0210':return {'!root':'unavailable:root_absent'}
            return {'plugin.json':'unread:metadata:inert' if partial else 'a'*64}
        def managed_state(self,role):calls.append(('head',));return {'present':True,'head':'b'*40}
    class Windows:
        def runtime(self,pins,**kwargs):
            calls.append(('runtime',));return {'status':'PROPOSED_NOT_ADMISSION','pins':{'inert':'raw-runtime-origin'},'runtime':{'files':1}}
        def processes(self,pins,**kwargs):
            calls.append(('processes',));return {'status':'PROPOSED_NOT_ADMISSION','snapshot':{'complete':not gap,
                'observer_pid':11,'processes':[{'pid':11,'created_filetime':22}]},'unadmitted_image_origins':{'inert':'C:/private/unknown.exe'} if gap else {}}
    class Factory:
        root_provenance={'scope':'inert-private-root'}
        def create_capture(self,p):calls.append(('create',));return store
        def staged(self,s):calls.append(('stage',))
        def observers(self,p):calls.append(('construct',));return Reader(),Windows(),Cli(),{}
        def check_cli_projection(self,*args):calls.append(('projection',))
        def sibling_roles(self,*args):return []
        def staging(self,*args):return []
    def authorize(index,label,remaining):
        calls.append(('authorize',index));
        if index==deny:raise ValueError('owner_revoked')
        return {'inert_receipt':index}
    return packet,sources,authorize,Factory(),calls,store


class Cases(unittest.TestCase):
    def test_public_catalog_pins_the_complete_executable_source_closure(self):
        from codex_developer_admission_host import NAMES
        packet=json.loads((ROOT/'docs/codex-developer-pilot-admission-packet.json').read_text())['admission_acquisition']
        self.assertEqual({row['name'] for row in packet['source_files']},NAMES)
        self.assertEqual(packet['status'],'SOURCE_REVIEW_ONLY_NOT_AUTHORITY')
        rows=[*packet['source_files'],packet['parent_source'],packet['launcher_source']]
        for row in rows:
            raw=subprocess.check_output(['git','show',':'+row['repository_path']],cwd=ROOT)
            self.assertEqual(admission.sha(raw),row['git_blob_bytes_sha256'])
            self.assertEqual(len(raw),row['size'])
        self.assertFalse(packet['ready_for_owner_execution'])
        self.assertEqual(packet['launch']['local_git_children'],len(NAMES)+1)

    def test_seven_checkpoints_raw_bytes_layouts_and_proposed_only_manifest(self):
        packet,sources,authorize,factory,calls,store=fixture()
        result=admission.run(packet,sources,authorize,factory)
        self.assertEqual(result['status'],'PROPOSED_NOT_ADMISSION')
        self.assertFalse(result['native_admission']);self.assertFalse(result['installed_or_activated'])
        self.assertEqual([c[1] for c in calls if c[0]=='authorize'],list(range(1,8)))
        self.assertEqual(store.files['cli-0-stdout.bin'],b'{"inert":true}')
        self.assertEqual(store.files['managed-ref-0.bin'],b'ref: refs/heads/main\n')
        self.assertIn('runtime-proposal.json',store.files);self.assertIn('process-origins.json',store.files)
        self.assertEqual(json.loads(store.files['exclusion-review.json'])['automatic_exclusions'],[])
        manifest=json.loads(store.files['manifest.json'])
        self.assertEqual(manifest['status'],'PROPOSED_NOT_ADMISSION')
        for name,pin in manifest['files'].items():
            self.assertEqual(admission.sha(store.files[name]),pin['sha256'])

    def test_no_authority_no_capture_and_revocation_has_no_successor(self):
        for deny in (1,4):
            packet,sources,authorize,factory,calls,store=fixture(deny=deny)
            with self.subTest(deny=deny),self.assertRaisesRegex(ValueError,'owner_revoked'):
                admission.run(packet,sources,authorize,factory)
            if deny==1:self.assertEqual(store.files,{});self.assertNotIn(('create',),calls)
            else:
                self.assertFalse(any(c[0]=='layout' for c in calls))
                self.assertEqual(json.loads(store.files['outcome.json'])['status'],'STOP_NO_RETRY')
                self.assertEqual(json.loads(store.files['manifest.json'])['status'],'STOP_NO_RETRY')

    def test_cli_failure_retains_raw_prefix_and_does_not_run_runtime_or_process_probe(self):
        packet,sources,authorize,factory,calls,store=fixture(cli_failure=True)
        with self.assertRaisesRegex(TimeoutError,'primary_cli_timeout'):admission.run(packet,sources,authorize,factory)
        self.assertEqual(store.files['cli-1-stdout.bin'],b'partial')
        self.assertNotIn(('runtime',),calls);self.assertNotIn(('processes',),calls)
        self.assertEqual(json.loads(store.files['outcome.json'])['status'],'STOP_NO_RETRY')

    def test_raw_sink_failure_preserves_original_cli_error(self):
        packet,sources,authorize,factory,calls,store=fixture(cli_failure=True);store.fail='cli-0.json'
        with self.assertRaisesRegex(TimeoutError,'primary_cli_timeout') as caught:
            admission.run(packet,sources,authorize,factory)
        self.assertIn('raw_cli_evidence_capture_uncertain; primary preserved',caught.exception.__notes__)
        self.assertNotIn(('runtime',),calls)

    def test_coverage_gap_is_durable_stop_never_quiescence_or_automatic_exclusion(self):
        packet,sources,authorize,factory,calls,store=fixture(gap=True)
        with self.assertRaisesRegex(ValueError,'process_coverage_unavailable'):admission.run(packet,sources,authorize,factory)
        self.assertIn('runtime-proposal.json',store.files);self.assertIn('layout-installed_027.json',store.files)
        self.assertFalse(json.loads(store.files['process-origins.json'])['snapshot']['complete'])
        self.assertFalse(any(c==('authorize',7) for c in calls))
        self.assertEqual(json.loads(store.files['outcome.json'])['status'],'STOP_NO_RETRY')

    def test_sink_refuses_overwrite_bounds_and_uncertain_readback_without_cleanup(self):
        store=Store();sink=admission.EvidenceSink(store)
        sink.write('one.bin',b'raw')
        with self.assertRaises(ValueError):sink.write('one.bin',b'raw')
        for name in ('../outside','C:\\outside','/outside'):
            with self.assertRaises(ValueError):sink.write(name,b'raw')
        with self.assertRaises(ValueError):sink.write('oversize.bin',b'x'*(admission.MAX_RECORD+1))
        store.bad_read=True
        with self.assertRaisesRegex(ValueError,'capture_readback'):sink.write('uncertain.bin',b'preserve')
        self.assertEqual(store.files['uncertain.bin'],b'preserve')
        with self.assertRaises(ValueError):sink.write('successor.bin',b'no')

    def test_sink_reserves_capacity_for_three_terminal_records(self):
        store=Store();sink=admission.EvidenceSink(store)
        for i in range(admission.MAX_FILES-3):sink.write('empty-'+str(i),b'')
        with self.assertRaises(ValueError):sink.write('too-many',b'')
        sink.json('authority-receipts.json',[],terminal=True)
        sink.finish({'status':'STOP_NO_RETRY'})
        self.assertEqual(len(store.files),admission.MAX_FILES)

    def test_tampered_source_rejected_before_authority_or_factory(self):
        packet,sources,authorize,factory,calls,store=fixture();sources['reviewed.py']=b'drift'
        with self.assertRaisesRegex(ValueError,'admission_source_pin'):admission.run(packet,sources,authorize,factory)
        self.assertEqual(calls,[]);self.assertEqual(store.files,{})


if __name__=='__main__':unittest.main()
