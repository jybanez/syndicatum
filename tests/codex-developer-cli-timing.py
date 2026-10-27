"""Inert timing-only tests; no real CLI or process observation."""
import base64,importlib.util,io,json,sys,time,unittest
from pathlib import Path
from unittest.mock import MagicMock,patch
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_cli_timing as core
import codex_developer_metadata_producer as metadata
import codex_developer_windows_observer as windows
spec=importlib.util.spec_from_file_location('fixture_tests',ROOT/'tests/codex-developer-admission.py')
f=importlib.util.module_from_spec(spec);spec.loader.exec_module(f)

class Cases(unittest.TestCase):
    def test_delayed_success_and_timeout_use60s_and_keep_production8s(self):
        self.assertEqual(metadata.NativeReadOnlyCli({}).query_policy(),('marketplaces',8,14))
        for timeout,deadline in ((False,120),(True,120),(True,5)):
            with self.subTest(timeout=timeout):
                now=[0.0];pin={'size':1,'sha256':'a'*64}
                class Timing(metadata.NativeReadOnlyCli):
                    def query_policy(self):return ('plugins',60,1)
                cli=Timing({'cli':{'path':'INERT','pin':pin},'neutral_directory':'C:/inert'})
                child=MagicMock(pid=123);child.stdout=io.BytesIO(b'{"installed":[]}');child.stderr=io.BytesIO(b'')
                def wait(**args):
                    self.assertLessEqual(args['timeout'],min(60,deadline))
                    if deadline>8:self.assertGreater(args['timeout'],8)
                    now[0]=min(60,deadline) if timeout else 20
                    if timeout:raise metadata.subprocess.TimeoutExpired('INERT',60)
                    return 0
                child.wait.side_effect=wait;cwd=MagicMock();cwd.is_dir.return_value=True
                with patch.object(cli,'gate'),patch.object(metadata,'stable_file',return_value=pin), \
                     patch.object(metadata,'check_nonreparse_chain'),patch.object(metadata,'Path',return_value=cwd), \
                     patch.object(metadata.time,'monotonic',side_effect=lambda:now[0]), \
                     patch.object(metadata.subprocess,'CREATE_NO_WINDOW',0,create=True), \
                     patch.object(metadata.subprocess,'Popen',return_value=child) as start:
                    if timeout:
                        with self.assertRaises(metadata.subprocess.TimeoutExpired):cli.read('plugins',deadline)
                    else:self.assertEqual(cli.read('plugins',deadline),{'installed':[]})
                    row=cli.results[0];self.assertEqual(row['elapsed_ms'],min(60,deadline)*1000 if timeout else 20000)
                    self.assertIn('channel_reads',row);self.assertEqual(row['root_pid'],123)
                    with self.assertRaises(Exception):cli.read('plugins',deadline)
                    self.assertEqual(start.call_count,1);child.kill.assert_not_called();child.terminate.assert_not_called()
    def test_one_query_no_process_runtime_or_inventory_successor(self):
        for failure in (False,True):
            p,s,authorize,old,calls,store=f.fixture();store.path=Path('C:/inert')
            p.update(scope=core.SCOPE,capture_identity=core.CAPTURE_ID,shared_frozen_bytes=346528,shared_capture_allowance_bytes=16777216,cli={})
            class Factory:
                root_provenance={};guard=staticmethod(lambda:None)
                def create_capture(self,p):return store
                def staged(self,s):pass
                def observers(self,*a):raise AssertionError('no observers')
            class Cli:
                def __init__(self,*a):self.results=[]
                def read(self,command,deadline):
                    calls.append(('cli',command));self_policy=self.query_policy()
                    if self_policy!=('plugins',60,1):raise AssertionError(self_policy)
                    self.results.append({'status':'failed_uncertain' if failure else 'exited','elapsed_ms':20000,
                        'stdout_prefix_base64':'','stderr_prefix_base64':'','channel_reads':{}})
                    if failure:raise TimeoutError('inert')
                    return {'installed':[]}
            with patch.object(metadata,'NativeReadOnlyCli',Cli), \
                 patch.object(windows.NativeWindowsObserver,'processes',side_effect=AssertionError('no process')), \
                 patch.object(windows.NativeWindowsObserver,'runtime',side_effect=AssertionError('no runtime')):
                if failure:
                    with self.assertRaises(TimeoutError):core.run(p,s,authorize,Factory())
                else:self.assertEqual(core.run(p,s,authorize,Factory())['cli_queries'],1)
            self.assertEqual([x for x in calls if x[0]=='cli'],[('cli','plugins')])
            self.assertIn('cli-0.json',store.files)
    def test_catalog_binds_exact_timing_closure_and_argv(self):
        import hashlib,subprocess,codex_developer_cli_timing_host as host
        a=json.loads((ROOT/'docs/codex-developer-pilot-admission-packet.json').read_text())['cli_timing']
        self.assertEqual({r['name'] for r in a['source_files']},host.NAMES)
        self.assertEqual(a['scope'],core.SCOPE);self.assertEqual(a['capture_identity'],core.CAPTURE_ID)
        self.assertEqual(a['launch']['cli_argv'],list(metadata.READ_COMMANDS['plugins']))
        for row in a['source_files']+[a['launcher_source'],a['parent_source']]:
            raw=__import__('subprocess').check_output(['git','show',':'+row['repository_path']],cwd=ROOT)
            self.assertEqual((len(raw),hashlib.sha256(raw).hexdigest()),(row['size'],row['git_blob_bytes_sha256']))

    def test_timing_sink_is_exact_accepted_sink(self):
        import inspect,codex_developer_admission as admission
        self.assertEqual(inspect.getsource(core.EvidenceSink),inspect.getsource(admission.EvidenceSink))

if __name__=='__main__':unittest.main()
