"""Inert combined upgrade evidence; never queries native APIs or CLI."""
import base64,hashlib,importlib.util,json,subprocess,sys,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_upgrade_evidence as core
import codex_developer_metadata_producer as metadata
spec=importlib.util.spec_from_file_location('fixture_tests',ROOT/'tests/codex-developer-admission.py')
f=importlib.util.module_from_spec(spec);spec.loader.exec_module(f)

class Cases(unittest.TestCase):
    def exercise(self,fault=None):
        p,s,authorize,old,calls,store=f.fixture()
        p.update(scope=core.SCOPE,capture_identity=core.CAPTURE_ID,shared_frozen_bytes=572262,
                 shared_capture_allowance_bytes=16777216,runtime_discovery_roots=[],known_process_image_pins={})
        class Reader:
            ref_reads=[{'raw_base64':base64.b64encode(b'head').decode(),'status':'read_verified'}]
            def layout(self,desc,partial=False):calls.append(('layout',desc['root_role']));return {'file':'hash'}
            def managed_state(self,role):calls.append(('refs',));return {'present':True}
        class Windows:
            def runtime(self,*a,**k):
                calls.append(('runtime',));self.last_runtime_evidence={'complete':fault!='runtime'}
                if fault=='runtime':raise RuntimeError('inert runtime')
                return {'status':'PROPOSED_NOT_ADMISSION'}
            def processes(self,*a,**k):
                calls.append(('process',));self.last_process_evidence={'complete':fault not in ('process','gap')}
                if fault=='process':raise RuntimeError('inert process')
                return {'status':'PROPOSED_NOT_ADMISSION','snapshot':{'complete':fault!='gap'}}
        class Factory:
            root_provenance={};guard=staticmethod(lambda:None)
            def create_capture(self,p):return store
            def staged(self,s):pass
            def sibling_roles(self,*a):return ['sibling_0']
            def staging(self,*a):calls.append(('staging',));return []
            def observers(self,*a):raise AssertionError('no old CLI observers')
        def retained(*a):calls.append(('retained',));return Reader(),Windows(),{},{}
        with patch.object(core,'retained_observers',side_effect=retained), \
             patch.object(metadata.NativeReadOnlyCli,'read',side_effect=AssertionError('no CLI')):
            if fault:
                with self.assertRaises(Exception):core.run(p,s,authorize,Factory())
            else:self.assertEqual(core.run(p,s,authorize,Factory())['cli_queries'],0)
        self.assertIn('layout-0.json',store.files);self.assertIn('managed-state.json',store.files)
        if fault in ('process','gap'):
            self.assertIn('runtime-proposal.json',store.files);self.assertIn('process-raw-prefix.json',store.files)
        self.assertEqual(len([c for c in calls if c[0]=='process']),0 if fault=='runtime' else 1)
        return calls,store
    def test_success_has_one_combined_sequence_and_no_cli(self):
        calls,store=self.exercise();self.assertLess(calls.index(('staging',)),calls.index(('runtime',)))
        self.assertLess(calls.index(('runtime',)),calls.index(('process',)))
    def test_late_failure_preserves_inventory_and_runtime_without_successor(self):
        for fault in ('runtime','process','gap'):
            with self.subTest(fault=fault):self.exercise(fault)
    def test_sink_is_exact_accepted_component(self):
        import inspect,codex_developer_admission as a
        self.assertEqual(inspect.getsource(core.EvidenceSink),inspect.getsource(a.EvidenceSink))
    def test_catalog_exact_source_closure(self):
        import codex_developer_upgrade_evidence_host as host
        a=json.loads((ROOT/'docs/codex-developer-pilot-admission-packet.json').read_text())['upgrade_evidence']
        self.assertEqual({r['name'] for r in a['source_files']},host.NAMES)
        self.assertEqual(a['scope'],core.SCOPE);self.assertEqual(a['capture_identity'],core.CAPTURE_ID)
        for r in a['source_files']+[a['launcher_source'],a['parent_source']]:
            raw=subprocess.check_output(['git','show',':'+r['repository_path']],cwd=ROOT)
            self.assertEqual((len(raw),hashlib.sha256(raw).hexdigest()),(r['size'],r['git_blob_bytes_sha256']))

if __name__=='__main__':unittest.main()
