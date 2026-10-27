"""Inert six-file verification; only temporary fixture bytes, no native query."""
import hashlib,importlib.util,json,sys,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_six_file as core
import codex_developer_six_file_host as host
spec=importlib.util.spec_from_file_location('fixture_tests',ROOT/'tests/codex-developer-admission.py')
f=importlib.util.module_from_spec(spec);spec.loader.exec_module(f)
class Cases(unittest.TestCase):
    def exercise(self,cap=181804680,drift=False):
        with tempfile.TemporaryDirectory() as folder:
            rows=[]
            for i in range(6):
                path=Path(folder)/('fixture-'+str(i));path.write_bytes(b'a'*100)
                rows.append({'path':str(path),'path_sha256':core.sha(str(path).encode())})
            p,s,authorize,old,calls,store=f.fixture()
            p.update(scope=core.SCOPE,capture_identity=core.CAPTURE_ID,six_files=rows,
                known_image_pin_bytes=355066232,shared_frozen_bytes=905422,shared_capture_allowance_bytes=16777216)
            class Helpers:
                @staticmethod
                def check_nonreparse_chain(path):calls.append(('metadata_path',))
            class Factory:
                root_provenance={};helpers=Helpers();guard=staticmethod(lambda:None)
                def create_capture(self,p):calls.append(('capture',));return store
                def staged(self,s):
                    if drift:(Path(folder)/'fixture-0').write_bytes(b'drift')
            with patch.object(core,'EXPECTED_PATH_HASHES',frozenset(r['path_sha256'] for r in rows)), \
                 patch.object(core,'MAX_EXTRA',cap),patch.object(core,'hash_exact',wraps=core.hash_exact) as read:
                if cap<600 or drift:
                    with self.assertRaises(ValueError) as caught:core.run(p,s,authorize,Factory())
                    if cap<600:
                        detail=host.stop_summary(caught.exception,core)['preflight']
                        self.assertEqual(detail['status'],'SHORTFALL');self.assertEqual(detail['files'],6)
                        self.assertEqual(detail['aggregate_shortfall_bytes'],600-cap)
                        self.assertEqual(len(detail['observed_sizes']),6)
                        self.assertNotIn(('capture',),calls);read.assert_not_called()
                    else:self.assertEqual(read.call_count,1)
                else:
                    out=core.run(p,s,authorize,Factory());self.assertEqual(out['files_hashed'],6)
                    self.assertEqual(out['writer_exclusions'],0);self.assertEqual(read.call_count,6)
                    for i in range(6):
                        r=json.loads(store.files['image-'+str(i)+'.json'])
                        self.assertEqual(r['sha256'],hashlib.sha256(b'a'*100).hexdigest())
                        self.assertFalse(r['writer_excluded']);self.assertLessEqual(len(store.files['image-'+str(i)+'-prefix.bin']),65536)
            return calls
    def test_all_six_size_preflight_stops_before_capture_or_content(self):self.exercise(cap=500)
    def test_whole_scope_fit_then_exact_hashes_and_no_exclusion(self):self.exercise()
    def test_metadata_drift_stops_before_successor_file(self):self.exercise(drift=True)
    def test_exact_scope_hashes_cannot_be_replaced(self):
        with self.assertRaises(ValueError):core.metadata_preflight({'six_files':[]},None,lambda:None)
    def test_full_hash_with_bounded_prefix_and_postread_drift(self):
        for drift in (False,True):
            with self.subTest(drift=drift),tempfile.TemporaryDirectory() as folder:
                path=Path(folder)/'inert';raw=b'a'*131072;path.write_bytes(raw);st=path.stat()
                row={'path':str(path),'path_sha256':core.sha(str(path).encode())}
                meta={'size':st.st_size,'mtime_ns':st.st_mtime_ns,'device':st.st_dev,'inode':st.st_ino}
                calls=[]
                class Helpers:
                    @staticmethod
                    def check_nonreparse_chain(p):
                        calls.append(1)
                        if drift and len(calls)==2:path.write_bytes(b'drift')
                record={}
                if drift:
                    with self.assertRaises(ValueError):core.hash_exact(row,meta,Helpers(),lambda:None,record)
                    self.assertEqual(record['status'],'read_failed')
                else:
                    core.hash_exact(row,meta,Helpers(),lambda:None,record)
                    self.assertEqual(record['sha256'],hashlib.sha256(raw).hexdigest())
                self.assertEqual(len(__import__('base64').b64decode(record['prefix_base64'])),65536)
                self.assertEqual(record['received_bytes'],len(raw))

    def test_catalog_pins_exact_six_file_closure(self):
        import subprocess
        a=json.loads((ROOT/'docs/codex-developer-pilot-admission-packet.json').read_text())['six_file_verification']
        self.assertEqual({r['name'] for r in a['source_files']},host.NAMES)
        self.assertEqual(a['scope'],core.SCOPE);self.assertEqual(a['capture_identity'],core.CAPTURE_ID)
        for r in a['source_files']+[a['launcher_source'],a['parent_source']]:
            raw=subprocess.check_output(['git','show',':'+r['repository_path']],cwd=ROOT)
            self.assertEqual((len(raw),hashlib.sha256(raw).hexdigest()),(r['size'],r['git_blob_bytes_sha256']))

    def test_sink_exact_accepted_component(self):
        import inspect,codex_developer_admission as admission
        self.assertEqual(inspect.getsource(core.EvidenceSink),inspect.getsource(admission.EvidenceSink))
if __name__=='__main__':unittest.main()
