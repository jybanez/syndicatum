"""Inert process-only entry tests: no native process or CLI invocation."""
import importlib.util
from pathlib import Path
import sys,unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_process_only as core
import codex_developer_windows_observer as windows
import codex_developer_metadata_producer as metadata
spec=importlib.util.spec_from_file_location('admission_tests',ROOT/'tests/codex-developer-admission.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)

class Cases(unittest.TestCase):
    def exercise(self,fault=None):
        p,s,authorize,old,calls,store=fixture.fixture()
        p.update(scope=core.SCOPE,capture_identity=core.CAPTURE_ID,shared_frozen_bytes=346528,
                 shared_capture_allowance_bytes=16777216)
        class Factory:
            root_provenance={'scope':'inert'}
            guard=staticmethod(lambda:None)
            def create_capture(self,packet):return store
            def staged(self,store):pass
            def observers(self,*a):raise AssertionError('no CLI/layout/ref factory')
        class Observer:
            def processes(self,pins,discovery=False):
                calls.append(('process',discovery));self.last_process_evidence={'complete':fault is None}
                if fault in ('query','query_sink'):raise TimeoutError('primary')
                return {'status':'PROPOSED_NOT_ADMISSION','snapshot':{'complete':fault is None}}
        if fault in ('sink','query_sink'):store.fail='process-raw-prefix.json'
        with patch.object(windows,'NativeWindowsObserver',Observer), \
             patch.object(metadata.NativeReadOnlyCli,'read',side_effect=AssertionError('no CLI')):
            if fault:
                with self.assertRaises(Exception) as caught:core.run(p,s,authorize,Factory())
                if fault=='query_sink':
                    self.assertIsInstance(caught.exception,TimeoutError)
                    self.assertTrue(core.safe_failure_summary(caught.exception)['evidence_capture_uncertain'])
                self.assertEqual(__import__('json').loads(store.files['outcome.json'])['status'],'STOP_NO_RETRY')
            else:
                out=core.run(p,s,authorize,Factory());self.assertEqual(out['cli_queries'],0)
                self.assertEqual(out['historical_child_settlement'],'UNVERIFIED')
                self.assertEqual(out['status'],'PROPOSED_NOT_ADMISSION')
        self.assertEqual([c for c in calls if c[0]=='process'],[('process',True)])
        return store,calls
    def test_one_process_query_and_no_other_acquisition(self):
        store,calls=self.exercise();self.assertEqual(len([c for c in calls if c[0]=='authorize']),7)
    def test_query_coverage_and_sink_failures_never_query_again(self):
        for fault in ('query','gap','sink','query_sink'):
            with self.subTest(fault=fault):self.exercise(fault)
    def test_sink_reuses_the_exact_accepted_mechanism(self):
        import inspect,codex_developer_admission as admission
        self.assertEqual(inspect.getsource(core.EvidenceSink),inspect.getsource(admission.EvidenceSink))
    def test_native_failure_codes_survive_raw_terminal_and_host_without_second_query(self):
        import ctypes,json
        from unittest.mock import MagicMock
        import codex_developer_process_host as host
        cases={'open':('process_identity_unavailable','OpenProcess'),
               'image':('process_image_unavailable','QueryFullProcessImageNameW'),
               'creation':('process_creation_unavailable','GetProcessTimes'),
               'enumeration':('process_snapshot_incomplete','Process32NextW'),
               'close':('process_handle_close_uncertain','CloseHandle'),
               'primary_and_cleanup':('process_image_unavailable','QueryFullProcessImageNameW')}
        for fault,(code,api) in cases.items():
            with self.subTest(fault=fault):
                p,s,authorize,old,calls,store=fixture.fixture()
                p.update(scope=core.SCOPE,capture_identity=core.CAPTURE_ID,shared_frozen_bytes=346528,
                         shared_capture_allowance_bytes=16777216,known_process_image_pins={})
                class Factory:
                    root_provenance={};guard=staticmethod(lambda:None)
                    def create_capture(self,p):return store
                    def staged(self,s):pass
                kernel=MagicMock();kernel.CreateToolhelp32Snapshot.return_value=100
                kernel.OpenProcess.return_value=0 if fault=='open' else 101
                def first(handle,pointer):
                    e=pointer._obj;e.pid=123;e.parent=1;e.name='python.exe';return True
                kernel.Process32FirstW.side_effect=first;kernel.Process32NextW.return_value=False
                def query(handle,flags,buffer,size):
                    buffer.value=r'C:\private\unknown.exe';size._obj.value=len(buffer.value)
                    return fault not in ('image','primary_and_cleanup')
                kernel.QueryFullProcessImageNameW.side_effect=query
                kernel.GetProcessTimes.return_value=fault!='creation'
                kernel.CloseHandle.side_effect=lambda h:not(h==101 and fault in ('close','primary_and_cleanup'))
                status=5 if fault!='enumeration' else 87
                with patch.object(windows.NativeWindowsObserver,'gate'),patch.object(windows.os,'name','nt'), \
                     patch.object(ctypes,'WinDLL',return_value=kernel,create=True), \
                     patch.object(ctypes,'get_last_error',return_value=status,create=True), \
                     patch.object(metadata.NativeReadOnlyCli,'read',side_effect=AssertionError('no CLI')):
                    with self.assertRaises(windows.ObservationUnavailable) as caught:
                        core.run(p,s,authorize,Factory())
                raw=json.loads(store.files['process-raw-prefix.json'])
                terminal=json.loads(store.files['outcome.json']);summary=host.stop_summary(caught.exception,core)
                for detail in (raw['failure'],terminal['failure'],summary['failure']):
                    self.assertEqual(detail['code'],code);self.assertEqual(detail['api'],api)
                    self.assertEqual(detail['win32_status'],status)
                    if fault=='primary_and_cleanup':
                        self.assertEqual(detail['cleanup_failures'][0]['code'],'process_handle_close_uncertain')
                self.assertEqual(raw['current_query']['pid'],123)
                self.assertEqual(kernel.CreateToolhelp32Snapshot.call_count,1)
                self.assertEqual(kernel.OpenProcess.call_count,1)
                self.assertNotIn('private',json.dumps(summary));self.assertNotIn('unknown.exe',json.dumps(summary))

    def test_unavailable_reason_is_not_inferred_or_raw_text_published(self):
        error=windows.ObservationUnavailable(r'C:\private\raw error',win32_status=True)
        detail=core.safe_failure_summary(error)
        self.assertEqual(detail['code'],'unavailable');self.assertIsNone(detail['win32_status'])
        self.assertNotIn('private',str(detail))

    def test_catalog_pins_the_exact_process_entry_closure(self):
        import json,subprocess,hashlib,codex_developer_process_host as host
        a=json.loads((ROOT/'docs/codex-developer-pilot-admission-packet.json').read_text())['process_observation']
        self.assertEqual({r['name'] for r in a['source_files']},host.NAMES)
        self.assertEqual(a['scope'],core.SCOPE);self.assertEqual(a['capture_identity'],core.CAPTURE_ID)
        self.assertEqual(a['launch']['fixed_readonly_cli_children'],0)
        for row in a['source_files']+[a['launcher_source'],a['parent_source']]:
            raw=subprocess.check_output(['git','show',':'+row['repository_path']],cwd=ROOT)
            self.assertEqual((len(raw),hashlib.sha256(raw).hexdigest()),(row['size'],row['git_blob_bytes_sha256']))

    def test_shared_frozen_bytes_reduce_available_capacity(self):
        sink=core.EvidenceSink(fixture.Store());sink.bytes=16777216-655360
        with self.assertRaises(ValueError):sink.write('no-room.bin',b'x')

if __name__=='__main__':unittest.main()
