"""Inert source/producer/consumer proof. No native process query or live walk."""
import copy
import ctypes
from ctypes import wintypes
import io
import json
from pathlib import Path
import sys
import time
import unittest
from types import SimpleNamespace
from unittest.mock import patch,MagicMock

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_metadata_producer as producer
import codex_developer_install_collector as collector
import codex_developer_windows_observer as windows
from codex_developer_fixed_journal import digest,packed,Stop


def fixture(sibling=False):
    market={'name':'syndicatum','root':r'C:\inert\.codex\.tmp\marketplaces\syndicatum',
            'marketplaceSource':{'sourceType':'git','source':'https://github.com/jybanez/syndicatum.git'}}
    plugin={'pluginId':'codex@syndicatum','name':'codex','marketplaceName':'syndicatum','version':'0.2.7',
            'installed':True,'source':{'source':'local','path':market['root']+r'\plugins\codex'}}
    markets={'marketplaces':[market]};plugins={'installed':[plugin],'available':[]}
    if sibling:plugins['installed'].append({**copy.deepcopy(plugin),'pluginId':'other@syndicatum','name':'other'})
    roots=producer.retained_root_map(markets,plugins)
    row={'pid':11,'parent_pid':1,'created_filetime':22,'image_sha256':'a'*64,'image_path_sha256':'b'*64}
    pins={'files':{r'C:\inert\runtime.dll':{'size':10,'sha256':'c'*64}},'builtin_frozen':[['sys','built-in']],
          'absent_bytecode':[]}
    closure={'closure_sha256':digest(packed({'files':{digest(p.encode('utf-8')):v for p,v in pins['files'].items()},
                                        'builtin_frozen':pins['builtin_frozen']})),
             'files':1,'bytes':10,'boundary':'loaded_python_files_and_psapi_modules'}
    snapshot={'complete':True,'families':sorted(windows.NativeWindowsObserver.FAMILIES),'scanned':100,'observer_pid':11,'processes':[row]}
    binding={'roots':roots,'managed_root_role':'managed','metadata_schema':2,'context_sha256':'d'*64,
        'retained_root_map_sha256':digest(packed(roots)),
        'collector_source_sha256':digest(Path(collector.__file__).read_bytes()),'metadata_producer_source_sha256':'f'*64,
        'installed_before':{'root_role':'installed_027','files':['old']},
        'installed_after':{'root_role':'installed_0210','files':['new']},'unrelated_layouts':[],
        'runtime_closure':pins,'process_image_pins':pins['files'],'process_exclusions':[row],
        'staging_scopes':[{'root_role':'marketplace_staging','prefixes':None},
                          {'root_role':'cache_marketplace','prefixes':['plugin-install-','plugin-backup-']}]}
    calls=[]
    class Reader:
        def layout(self,description,partial=False):
            calls.append(('layout',description['root_role'],partial))
            return {name:'e'*64 for name in description['files']}
        def git_head(self,role):calls.append(('head',role));return '9'*40
        def managed_state(self,role):return {'present':True,'head':self.git_head(role)}
    class Windows:
        snapshots=[snapshot,snapshot]
        def runtime(self,pins):calls.append(('runtime',));return copy.deepcopy(closure)
        def processes(self,pins):
            calls.append(('processes',));return copy.deepcopy(self.snapshots.pop(0))
    class Cli:
        def __init__(self):self.results=[]
        def read(self,command,deadline):
            calls.append(('cli',command));self.results.append({'command':command,'inert':True})
            return copy.deepcopy(markets if command=='marketplaces' else plugins)
    native=producer.NativeMetadataProducer(binding,Reader(),lambda:1000,windows=Windows(),cli=Cli())
    observe=collector.InstallObservationCollector(native,native.reader,lambda:1000,binding,digest(packed(binding)),binding['context_sha256'])
    return native,observe,binding,calls,snapshot,markets,plugins


class Cases(unittest.TestCase):
    def run_observation(self,native,observe):
        with patch.object(native,'gate'),patch.object(native,'staging',return_value=[]), \
             patch.object(producer,'stable_file',return_value={'sha256':'f'*64,'size':10}):
            return json.loads(observe('before-marketplace_remove'))

    def test_actual_producer_brackets_independent_target_reads_and_binds_evidence(self):
        native,observe,binding,calls,*_=fixture()
        result=self.run_observation(native,observe)
        self.assertEqual(result['marketplace'],{'url':'https://github.com/jybanez/syndicatum.git','head':'9'*40})
        self.assertEqual(calls,[('runtime',),('processes',),('cli','marketplaces'),('cli','plugins'),
                                ('layout','installed_027',False),('head','managed'),('processes',),('runtime',)])
        self.assertEqual(result['candidate_files'],{'old':'e'*64})
        self.assertEqual(len(native.private_evidence),1)
        import base64
        envelope=json.loads(base64.b64decode(result['collector_provenance']['metadata_raw_base64']))
        self.assertIn('artifact_inventory_sha256',envelope['observation_evidence'])
        self.assertEqual(envelope['observation_evidence']['writer_before']['scanned'],100)

    def test_missing_coverage_unavailable_never_empty_default_or_retry(self):
        for bad in ({},None,{'complete':True},'inert'):
            native,observe,binding,calls,*_=fixture()
            native.windows.snapshots=[bad]
            with self.subTest(bad=bad),self.assertRaises(windows.ObservationUnavailable):
                self.run_observation(native,observe)
            self.assertFalse(any(c[0]=='cli' for c in calls))
            with self.assertRaises(Stop):self.run_observation(native,observe)
        native,observe,binding,calls,snapshot,*_=fixture()
        snapshot=copy.deepcopy(snapshot);snapshot['processes']=[]
        native.windows.snapshots=[snapshot]
        with self.assertRaises(windows.ObservationUnavailable):self.run_observation(native,observe)

    def test_cli_failures_preserve_bounded_evidence_and_never_start_successor(self):
        for fault in ('nonzero','stderr','oversize','invalid','timeout'):
            with self.subTest(fault=fault):
                pin={'sha256':'a'*64,'size':1}
                cli=producer.NativeReadOnlyCli({'cli':{'path':'INERT.exe','pin':pin},'neutral_directory':'C:/inert'})
                child=MagicMock(pid=123)
                child.stdout=io.BytesIO(b'x'*262145 if fault=='oversize' else b'{' if fault=='invalid' else b'{"marketplaces":[]}')
                child.stderr=io.BytesIO(b'warning' if fault=='stderr' else b'')
                child.wait.return_value=1 if fault=='nonzero' else 0
                if fault=='timeout':child.wait.side_effect=producer.subprocess.TimeoutExpired('INERT',8)
                cwd=MagicMock();cwd.is_dir.return_value=True
                with patch.object(cli,'gate'),patch.object(producer,'stable_file',return_value=pin), \
                     patch.object(producer,'check_nonreparse_chain'),patch.object(producer,'Path',return_value=cwd), \
                     patch.object(producer.subprocess,'CREATE_NO_WINDOW',0,create=True), \
                     patch.object(producer.subprocess,'Popen',return_value=child) as start:
                    with self.assertRaises(Exception):cli.read('marketplaces',time.monotonic()+30)
                    self.assertTrue(cli.terminal)
                    self.assertEqual(cli.results[0]['status'],'failed_uncertain')
                    self.assertEqual(cli.results[0]['root_pid'],123)
                    self.assertFalse(cli.results[0]['os_image_verified'])
                    for name,limit in (('stdout',262144),('stderr',65536)):
                        import base64
                        raw=base64.b64decode(cli.results[0].get(name+'_prefix_base64',''))
                        self.assertLessEqual(len(raw),limit)
                    with self.assertRaises(Stop):cli.read('plugins',time.monotonic()+30)
                    self.assertEqual(start.call_count,1)
                    child.kill.assert_not_called();child.terminate.assert_not_called()

    def test_short_pipe_prefix_survives_timeout_and_zero_is_not_unavailable(self):
        import threading,base64
        for fault in ('short','zero','unavailable'):
            with self.subTest(fault=fault):
                entered=threading.Event();release=threading.Event();threads=[]
                thread_type=threading.Thread
                def thread_factory(*args,**kwargs):
                    t=thread_type(*args,**kwargs);threads.append(t);return t
                class Pipe:
                    def __init__(self):self.calls=0
                    def read(self,*args):raise AssertionError('blocking buffered read forbidden')
                    def read1(self,count):
                        self.calls+=1
                        if fault=='unavailable':
                            entered.set();raise OSError('inert unavailable')
                        if fault=='short' and self.calls==1:return b'short'
                        entered.set();release.wait(2);return b''
                pin={'sha256':'a'*64,'size':1}
                cli=producer.NativeReadOnlyCli({'cli':{'path':'INERT.exe','pin':pin},'neutral_directory':'C:/inert'})
                child=MagicMock(pid=123);child.stdout=Pipe();child.stderr=io.BytesIO(b'')
                def timeout(**kwargs):
                    self.assertTrue(entered.wait(1))
                    if fault=='unavailable':threads[0].join(1);self.assertFalse(threads[0].is_alive())
                    raise producer.subprocess.TimeoutExpired('INERT',8)
                child.wait.side_effect=timeout
                cwd=MagicMock();cwd.is_dir.return_value=True
                try:
                    with patch.object(producer.threading,'Thread',side_effect=thread_factory), \
                         patch.object(cli,'gate'),patch.object(producer,'stable_file',return_value=pin), \
                         patch.object(producer,'check_nonreparse_chain'),patch.object(producer,'Path',return_value=cwd), \
                         patch.object(producer.subprocess,'CREATE_NO_WINDOW',0,create=True), \
                         patch.object(producer.subprocess,'Popen',return_value=child) as start:
                        with self.assertRaises(producer.subprocess.TimeoutExpired):cli.read('marketplaces',time.monotonic()+30)
                        row=cli.results[0];state=row['channel_reads']['stdout']
                        self.assertEqual(base64.b64decode(row['stdout_prefix_base64']),b'short' if fault=='short' else b'')
                        self.assertEqual(state['received_bytes'],5 if fault=='short' else 0)
                        self.assertEqual(state['read_state'],'read_unavailable' if fault=='unavailable' else 'read_inflight')
                        self.assertEqual(row['root_pid'],123);self.assertIsNone(row['exit'])
                        with self.assertRaises(Stop):cli.read('plugins',time.monotonic()+30)
                        self.assertEqual(start.call_count,1);child.kill.assert_not_called();child.terminate.assert_not_called()
                finally:release.set()

    def test_handle_close_failure_keeps_original_observation_error(self):
        kernel=MagicMock();kernel.CloseHandle.return_value=False
        try:
            try:raise ValueError('primary')
            finally:windows.close_observation_handle(kernel,1,'close_uncertain')
        except ValueError as error:
            self.assertEqual(str(error),'primary');self.assertIn('close_uncertain',error.__notes__)
        with self.assertRaises(windows.ObservationUnavailable):windows.close_observation_handle(kernel,1,'close_uncertain')

    def test_unknown_writer_or_reused_pid_stops_before_cli(self):
        for change in ('pid','created_filetime','image_sha256','parent_pid'):
            native,observe,binding,calls,snapshot,*_=fixture()
            snapshot=copy.deepcopy(snapshot)
            snapshot['processes'][0][change]=44 if change!='image_sha256' else '0'*64
            native.windows.snapshots=[snapshot]
            with self.subTest(change=change),self.assertRaises(windows.ObservationUnavailable):
                self.run_observation(native,observe)
            self.assertFalse(any(c[0]=='cli' for c in calls))

    def test_runtime_drift_and_live_writer_after_artifact_read_are_rejected(self):
        native,observe,binding,calls,snapshot,*_=fixture()
        bad=copy.deepcopy(snapshot);bad['processes'].append({**bad['processes'][0],'pid':55})
        native.windows.snapshots=[snapshot,bad]
        with self.assertRaises(windows.ObservationUnavailable):self.run_observation(native,observe)
        self.assertIn(('layout','installed_027',False),calls)
        self.assertTrue(native.terminal)
        native,observe,binding,calls,*_=fixture()
        original=native.windows.runtime;count=0
        def drift(pins):
            nonlocal count
            count+=1;value=original(pins)
            if count==2:value['closure_sha256']='0'*64
            return value
        native.windows.runtime=drift
        with self.assertRaises(Stop):self.run_observation(native,observe)

    def test_source_and_cache_paths_are_distinct_and_unreported_config_not_invented(self):
        native,observe,binding,calls,snapshot,markets,plugins=fixture()
        self.assertNotEqual(binding['roots']['plugin_source'],binding['roots']['installed_027'])
        self.assertTrue(binding['roots']['installed_027'].endswith(r'plugins\cache\syndicatum\codex\0.2.7'))
        target,_=producer.project_cli_facts(markets,plugins,binding)
        self.assertEqual(set(target),{'url'})
        plugins['installed'][0]['source']['path']=binding['roots']['installed_027']
        with self.assertRaises(Stop):producer.project_cli_facts(markets,plugins,binding)

    def test_native_methods_refuse_before_dll_process_or_filesystem_observation(self):
        native,observe,binding,*_=fixture()
        with patch.object(windows,'stable_file') as read,patch.object(producer.subprocess,'Popen') as start:
            for call in (lambda:windows.NativeWindowsObserver().processes({}),
                         lambda:windows.NativeWindowsObserver().runtime({}),
                         lambda:windows.NativeWindowsObserver().runtime(None,discovery_roots=[]),
                         lambda:windows.NativeWindowsObserver().processes({},discovery=True),
                         lambda:producer.NativeReadOnlyCli({}).read('marketplaces',0),
                         lambda:native.collect({},lambda market:None)):
                with self.assertRaisesRegex(RuntimeError,'SOURCE_ONLY'):call()
            read.assert_not_called();start.assert_not_called()

    def test_native_process_adapter_with_stubbed_win32_calls_and_unknown_image_discovery(self):
        # Executes adapter logic against stubbed APIs; no Windows process query.
        image=r'C:\inert\python.exe';pin={'sha256':'a'*64,'size':1}
        for mode in ('known','unknown','denied'):
            kernel=MagicMock();kernel.CreateToolhelp32Snapshot.return_value=100
            kernel.OpenProcess.return_value=101;kernel.CloseHandle.return_value=True
            def first(handle,pointer):
                entry=pointer._obj;entry.pid=windows.os.getpid();entry.parent=1;entry.name='python.exe'
                return True
            def query(handle,flags,buffer,size):
                buffer.value=image;size._obj.value=len(image);return mode!='denied'
            def times(handle,created,exited,kernel_time,user_time):
                created._obj.dwLowDateTime=22;created._obj.dwHighDateTime=0;return True
            kernel.Process32FirstW.side_effect=first;kernel.Process32NextW.return_value=False
            kernel.QueryFullProcessImageNameW.side_effect=query;kernel.GetProcessTimes.side_effect=times
            observer=windows.NativeWindowsObserver()
            with self.subTest(mode=mode),patch.object(observer,'gate'),patch.object(windows.os,'name','nt'), \
                 patch.object(ctypes,'WinDLL',return_value=kernel,create=True), \
                 patch.object(ctypes,'get_last_error',return_value=18,create=True), \
                 patch.object(windows,'stable_file',return_value=pin) as read:
                if mode=='denied':
                    with self.assertRaises(windows.ObservationUnavailable):observer.processes({image:pin})
                elif mode=='unknown':
                    value=observer.processes({},discovery=True)
                    self.assertEqual(value['status'],'PROPOSED_NOT_ADMISSION')
                    self.assertFalse(value['snapshot']['complete']);read.assert_not_called()
                    self.assertFalse(observer.last_process_evidence['complete'])
                    self.assertTrue(observer.last_process_evidence['query_finished'])
                    with self.assertRaises(windows.ObservationUnavailable):windows.verify_process_observation(value,[])
                else:
                    value=observer.processes({image:pin})
                    self.assertTrue(value['complete'])
                    self.assertEqual(value['processes'][0]['created_filetime'],22)
                    read.assert_called_once_with(image,1)
                self.assertEqual(kernel.CloseHandle.call_count,2)

    def test_runtime_adapter_and_discovery_use_only_loaded_origins_with_stubbed_api(self):
        image=r'C:\inert\python.dll';source=r'C:\inert\source.py';pin={'sha256':'a'*64,'size':1}
        class Modules(dict):
            def items(self):return [('sys',sys),('inert_source',SimpleNamespace(__file__=source,__spec__=None))]
        kernel=MagicMock();kernel.GetCurrentProcess.return_value=999
        def enumerate_modules(process,handles,size,needed,flags):
            handles[0]=42;needed._obj.value=ctypes.sizeof(wintypes.HMODULE);return True
        def module_path(handle,buffer,size):buffer.value=image;return len(image)
        kernel.K32EnumProcessModulesEx.side_effect=enumerate_modules
        kernel.GetModuleFileNameW.side_effect=module_path
        observer=windows.NativeWindowsObserver()
        pins={'files':{image:pin,source:pin},'builtin_frozen':[['sys','built-in']],'absent_bytecode':[]}
        with patch.object(observer,'gate'),patch.object(windows.os,'name','nt'), \
             patch.object(ctypes,'WinDLL',return_value=kernel,create=True), \
             patch.object(windows.sys,'modules',Modules(sys.modules)), \
             patch.object(windows,'stable_file',return_value=pin) as read:
            actual=observer.runtime(pins)
            self.assertEqual(actual['files'],2);self.assertEqual(read.call_count,2)
            proposed=observer.runtime(None,discovery_roots=[r'C:\inert'])
            self.assertEqual(proposed['status'],'PROPOSED_NOT_ADMISSION')
            self.assertEqual(proposed['pins'],pins)
            self.assertEqual(proposed['runtime'],actual)
            read.reset_mock()
            with self.assertRaises(Stop):observer.runtime(None,discovery_roots=[r'C:\outside'])
            read.assert_not_called()
            changed=copy.deepcopy(pins);del changed['files'][image]
            with self.assertRaises(windows.ObservationUnavailable):observer.runtime(changed)
            read.assert_not_called()

    def test_failed_native_observation_requires_new_writer_evidence(self):
        native,observe,binding,calls,snapshot,*_=fixture()
        native.windows.snapshots=[copy.deepcopy(snapshot) for _ in range(4)]
        self.run_observation(native,observe)
        with patch.object(native,'gate'),patch.object(native,'staging',return_value=[]), \
             patch.object(producer,'stable_file',return_value={'sha256':'f'*64,'size':10}):
            observe.failed('before-marketplace_remove',{'collection_safe':True})
        self.assertEqual(sum(c[0]=='processes' for c in calls),4)
        self.assertEqual(sum(c[0]=='cli' for c in calls),4)
        self.assertIn(('layout','installed_0210',True),calls)

    def test_observation_limit_is_checked_before_any_native_acquisition(self):
        native,observe,binding,calls,*_=fixture()
        native.private_evidence=[{}]*7
        with self.assertRaises(Stop):self.run_observation(native,observe)
        self.assertEqual(calls,[])

    def test_missing_prerequisites_and_substituted_cache_mapping_cannot_be_admitted(self):
        for change in ('runtime','images','exclusions','mapping','inventory'):
            native,observe,binding,calls,*_=fixture()
            if change=='runtime':binding['runtime_closure']['files']={}
            if change=='images':binding['process_image_pins']={}
            if change=='exclusions':binding['process_exclusions']=[]
            if change=='mapping':binding['roots']['installed_027']=binding['roots']['plugin_source']
            if change=='inventory':binding['installed_before']['files']=[]
            with self.subTest(change=change),self.assertRaises(Stop):producer.validate_native_admission(binding)
            self.assertEqual(calls,[])

    def test_caller_binding_mutation_cannot_change_the_sealed_source_scope(self):
        native,observe,binding,calls,*_=fixture()
        binding['roots']['installed_027']='C:/outside'
        self.assertNotEqual(observe.binding['roots']['installed_027'],'C:/outside')
        self.assertNotEqual(native.binding['roots']['installed_027'],'C:/outside')
        observe.binding['roots']['installed_027']='C:/outside'
        with self.assertRaises(Stop):self.run_observation(native,observe)
        self.assertEqual(calls,[])

    def test_unrelated_sibling_layout_cannot_be_omitted_from_the_affected_region(self):
        native,observe,binding,calls,*_=fixture(sibling=True)
        with self.assertRaisesRegex(Stop,'metadata_unrelated_layout_coverage'):self.run_observation(native,observe)
        self.assertFalse(any(c[0]=='layout' for c in calls))
        self.assertTrue(native.terminal)


if __name__=='__main__':unittest.main()
