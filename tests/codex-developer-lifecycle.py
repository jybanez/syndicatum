"""One C/D lifetime and owner-controlled reload, inert dispatch/observations."""
import importlib.util
import base64
import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch, MagicMock

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_lifecycle as lifecycle
import codex_developer_host_assembly as assembly
import codex_developer_fixed_journal as fixed
import codex_developer_install_collector as collector_module
specmod=importlib.util.spec_from_file_location('prep_fixture',ROOT/'tests/codex-developer-integrated-preparation.py')
fixture=importlib.util.module_from_spec(specmod);specmod.loader.exec_module(fixture)


def setup(fault=None):
    before_files={'old.mjs':b'old-source'}
    after_files={'new'+str(i)+'.mjs':('source-'+str(i)).encode() for i in range(47)}
    binding={'collector_source_sha256':fixed.digest(Path(collector_module.__file__).read_bytes()),
             'metadata_producer_source_sha256':'f'*64,'managed_root_role':'managed',
             'installed_before':{'root_role':'old','files':list(before_files)},
             'installed_after':{'root_role':'new','files':list(after_files)}}
    spec={'unrelated_sha256':'a'*64,'preinstall_files':{k:fixed.digest(v) for k,v in before_files.items()},
          'candidate_files':{k:fixed.digest(v) for k,v in after_files.items()},'collector_binding_sha256':fixed.digest(fixed.packed(binding)),
          'marketplace_preimage':{'source':'old','head':'b'*40},'marketplace_postimage':lifecycle.MARKETPLACE,
          'archive_sha256':lifecycle.ARCHIVE_SHA,'capacity':{'logical_upper_bytes':[32768]*36,
          'failure_publications':3,'projected_total_ms':10000,'worst_observation_ms':1000}}
    if fault=='capacity':spec['capacity']['projected_total_ms']=120001
    runner,backend,api,_,_=fixture.setup(lifecycle=True,installation_spec_sha256=fixed.digest(fixed.packed(spec)))
    if fault=='publish-age':
        factory=runner.journal_factory
        def slow_factory(*args):
            journal=factory(*args);append=journal.append
            def slow(boundary,producer,data):
                result=append(boundary,producer,data)
                if boundary=='marketplace_add' and producer=='command-intent':backend.time+=120001
                return result
            journal.append=slow
            return journal
        runner.journal_factory=slow_factory
    state=assembly.TrustedHostState(runner,backend)
    market=dict(spec['marketplace_preimage']);roots={'old':dict(before_files),'new':{}};calls=[]
    class Reader:
        def __init__(self):self.calls=[]
        def layout(self,description,partial=False):
            self.calls.append((description['root_role'],partial))
            if fault=='collection-fail' and partial:raise ValueError('inert secondary')
            data=roots[description['root_role']]
            if not partial:fixed.check(sorted(data)==sorted(description['files']),'inert_layout_inventory')
            return {k:fixed.digest(v) for k,v in data.items()}
        def git_head(self,role):return 'b'*40 if market and market.get('source')=='old' else lifecycle.CANDIDATE
    reader=Reader()
    class Dispatcher:
        def invoke(self,operation):
            nonlocal market
            calls.append(operation)
            if fault in ('guard','nonzero','writer-live','collection-fail','timeout','partial-result') and operation=='marketplace_remove':
                if fault!='guard':market=None
                if fault=='partial-result':return {'operation':operation,'exit_code':0,'argv_sha256':'0'*64}
                raise RuntimeError('primary_command_failure')
            if fault=='command' and operation=='marketplace_add':raise TimeoutError('inert uncertain command')
            if operation=='marketplace_remove':market=None
            else:market=dict(spec['marketplace_postimage'])
            if operation=='plugin_add':
                roots['new']=dict(after_files)
                if fault=='postimage':roots['new'].pop(next(iter(roots['new'])))
            return {'operation':operation,'exit_code':0,'argv_sha256':fixed.digest(fixed.packed(list(lifecycle.COMMANDS[operation])))}
        def failure_context(self):
            return {'collection_safe':fault not in ('command','timeout'),'reason':'command_not_started' if fault=='guard' else ('child_may_be_running' if fault in ('command','timeout') else 'root_exited_requires_independent_quiescence'),
                    'child':None if fault=='guard' else {'pid':123,'created_filetime':456}}
    metadata_calls=[]
    def metadata(challenge):
        operation=challenge['operation'];metadata_calls.append(operation)
        value={'marketplace':market,'unrelated_sha256':spec['unrelated_sha256'],'observed_ms':backend.time,
               'shared_staging_entries':[],'concurrent_cli_operations':[],'writer_processes':[]}
        if fault=='staging':value['shared_staging_entries']=['unrelated-entry']
        if fault=='age' and operation=='before-marketplace_add':value['observed_ms']-=120001
        if fault=='writer-live' and operation=='marketplace_remove':value['writer_processes']=[{'pid':789,'parent_pid':123}]
        return fixed.packed({'challenge':challenge,'producer_source_sha256':'f'*64,'facts':value})
    observe=collector_module.InstallObservationCollector(metadata,reader,runner.now,binding,spec['collector_binding_sha256'],runner.context)
    observe.metadata_calls=metadata_calls;observe.reader_fixture=reader
    return state,Dispatcher(),observe,spec,api,calls


class LifecycleFixtures(unittest.TestCase):
    def test_native_reader_full_reparse_guard_precedes_artifact_and_ref_reads(self):
        # Pure mocks: no native collector, filesystem inventory or process probe.
        reader=collector_module.NativeInstallReader({'old':'bound-private-root'})
        root=MagicMock();root.is_absolute.return_value=True
        root.drive='C:';root.parts=('C:\\','artifact')
        for mode in ('layout','git'):
            with self.subTest(mode=mode),patch.object(reader,'gate'), \
                 patch.object(collector_module,'Path',return_value=root), \
                 patch.object(collector_module,'check_nonreparse_chain',side_effect=ValueError('windows_reparse_point')) as guard:
                with self.assertRaisesRegex(ValueError,'windows_reparse_point'):
                    if mode=='layout':reader.layout({'root_role':'old','files':[]})
                    else:reader.git_head('old')
                guard.assert_called_once_with(root.parent if mode=='layout' else root)
                root.is_dir.assert_not_called()
                root.open.assert_not_called()

    def test_partial_missing_leaf_does_not_treat_dangling_reparse_as_absent(self):
        reader=collector_module.NativeInstallReader({'old':'bound-private-root'})
        root=MagicMock();root.is_absolute.return_value=True
        root.drive='C:';root.parts=('C:\\','artifact')
        def guard(path):
            if path is root:raise ValueError('windows_reparse_point')
        with patch.object(reader,'gate'),patch.object(collector_module,'Path',return_value=root), \
             patch.object(collector_module,'check_nonreparse_chain',side_effect=guard):
            with self.assertRaisesRegex(ValueError,'windows_reparse_point'):
                reader.layout({'root_role':'old','files':[]},partial=True)
            root.lstat.assert_called_once()
            root.exists.assert_not_called()

    def test_native_git_ref_bounded_read_and_postread_drift(self):
        reader=collector_module.NativeInstallReader({'managed':'bound-private-root'})
        for fault in ('oversize','drift',None):
            with self.subTest(fault=fault):
                root=MagicMock();root.is_absolute.return_value=True
                root.drive='C:';root.parts=('C:\\','artifact')
                git=MagicMock();head=MagicMock()
                root.__truediv__.return_value=git;git.__truediv__.return_value=head
                before=MagicMock(st_size=40,st_mtime_ns=1,st_ino=2)
                after=MagicMock(st_size=40,st_mtime_ns=3 if fault=='drift' else 1,st_ino=2)
                head.stat.side_effect=[before,after]
                head.open.return_value=io.BytesIO(b'a'*(1048577 if fault=='oversize' else 40))
                with patch.object(reader,'gate'),patch.object(collector_module,'Path',return_value=root), \
                     patch.object(collector_module,'check_nonreparse_chain') as guard:
                    if fault:
                        with self.assertRaisesRegex(fixed.Stop,'managed_ref_bound' if fault=='oversize' else 'managed_ref_read_drift'):
                            reader.git_head('managed')
                    else:self.assertEqual(reader.git_head('managed'),'a'*40)
                    self.assertIn(((head,),{}),guard.call_args_list)

    def test_native_git_ref_rejects_windows_path_and_traversal_syntax(self):
        reader=collector_module.NativeInstallReader({'managed':'bound-private-root'})
        for name in ('refs/heads/C:\\outside','refs/heads/../../outside','refs/heads/..\\outside','refs/heads//main'):
            with self.subTest(name=name):
                root=MagicMock();root.is_absolute.return_value=True
                root.drive='C:';root.parts=('C:\\','artifact')
                git=MagicMock();head=MagicMock();raw=('ref: '+name).encode('ascii')
                root.__truediv__.return_value=git;git.__truediv__.return_value=head
                metadata=MagicMock(st_size=len(raw),st_mtime_ns=1,st_ino=2)
                head.stat.return_value=metadata;head.open.return_value=io.BytesIO(raw)
                with patch.object(reader,'gate'),patch.object(collector_module,'Path',return_value=root), \
                     patch.object(collector_module,'check_nonreparse_chain'):
                    with self.assertRaisesRegex(fixed.Stop,'managed_ref_path'):reader.git_head('managed')
                    git.__truediv__.assert_called_once_with('HEAD')
                    git.joinpath.assert_not_called()

    def test_ref_drift_bound_and_decode_failure_retain_bounded_unverified_prefix(self):
        for fault in ('drift','bound','decode'):
            reader=collector_module.NativeInstallReader({'managed':'bound-private-root'},retain_ref_bytes=True)
            root=MagicMock();root.is_absolute.return_value=True;root.drive='C:';root.parts=('C:\\','artifact')
            git=MagicMock();head=MagicMock();root.__truediv__.return_value=git;git.__truediv__.return_value=head
            head.relative_to.return_value.as_posix.return_value='HEAD'
            before=MagicMock(st_size=40,st_mtime_ns=1,st_ino=2)
            after=MagicMock(st_size=40,st_mtime_ns=2 if fault=='drift' else 1,st_ino=2)
            head.stat.side_effect=[before,after]
            raw=b'a'*1048577 if fault=='bound' else b'\xff'*40 if fault=='decode' else b'a'*40
            head.open.return_value=io.BytesIO(raw)
            with self.subTest(fault=fault),patch.object(reader,'gate'),patch.object(collector_module,'Path',return_value=root), \
                 patch.object(collector_module,'check_nonreparse_chain'):
                with self.assertRaises(Exception):reader.git_head('managed')
            self.assertEqual(len(reader.ref_reads),1)
            record=reader.ref_reads[0];prefix=base64.b64decode(record['raw_base64'])
            self.assertEqual(prefix,raw[:1048576]);self.assertEqual(record['status'],'read_failed')
            self.assertEqual(record['bytes_returned'],len(raw));self.assertLessEqual(len(prefix),1048576)
            self.assertEqual(record['prefix_truncated'],fault=='bound')
            self.assertEqual(head.open.call_count,1)

    def test_failed_command_collects_independent_postimage_or_precise_unavailability(self):
        for mode in ('nonzero','guard','partial-result','timeout','writer-live','collection-fail'):
            with self.subTest(mode=mode):
                state,dispatch,observe,spec,api,calls=setup(mode)
                controller=lifecycle.DeveloperLifecycle(state,dispatch,observe,spec)
                with self.assertRaises(Exception) as caught:controller.run()
                result=controller.failure_assessment
                self.assertEqual(calls,['marketplace_remove'])
                self.assertEqual(result['operation'],'marketplace_remove')
                if mode=='timeout':
                    self.assertEqual(result['status'],'unavailable')
                    self.assertEqual(result['reason'],'child_may_be_running')
                    self.assertEqual(result['child']['pid'],123)
                    self.assertNotIn('marketplace_remove',observe.metadata_calls)
                elif mode in ('collection-fail','writer-live'):
                    self.assertEqual(str(caught.exception),'primary_command_failure')
                    self.assertEqual(result['status'],'unavailable')
                    self.assertEqual(result['reason'],'independent_writer_quiescence_unavailable' if mode=='writer-live' else 'independent_collection_failed')
                else:
                    self.assertEqual(result['status'],'retained')
                    import base64
                    raw=base64.b64decode(result['raw_base64'],validate=True)
                    self.assertEqual(fixed.digest(raw),result['sha256'])
                    value=json.loads(raw)
                    self.assertIn('collector_provenance',value)
                    self.assertTrue(any(partial for _,partial in observe.reader_fixture.calls))
                self.assertTrue(state.closed)
                self.assertFalse(api.held)
                before=list(calls)
                with self.assertRaises(fixed.Stop):controller.run()
                self.assertEqual(calls,before)

    def test_collector_source_context_and_quiescence_are_not_caller_fact_maps(self):
        state,dispatch,observe,spec,api,calls=setup()
        original=observe.metadata
        def wrong_context(challenge):
            value=json.loads(original(challenge));value['challenge']['context_sha256']='0'*64
            return fixed.packed(value)
        observe.metadata=wrong_context
        with self.assertRaises(fixed.Stop):lifecycle.DeveloperLifecycle(state,dispatch,observe,spec).run()
        self.assertEqual(observe.reader_fixture.calls,[])
        self.assertEqual(calls,[])
        state,dispatch,observe,spec,api,calls=setup()
        original=observe.metadata
        def writer_present(challenge):
            value=json.loads(original(challenge));value['facts']['writer_processes']=[{'pid':123}]
            return fixed.packed(value)
        observe.metadata=writer_present
        with self.assertRaises(collector_module.ObservationUnavailable):
            lifecycle.DeveloperLifecycle(state,dispatch,observe,spec).run()
        self.assertEqual(observe.reader_fixture.calls,[])
        with patch.object(collector_module.Path,'exists') as read:
            with self.assertRaisesRegex(RuntimeError,'SOURCE_ONLY'):
                collector_module.NativeInstallReader({'old':'NO-READ'}).layout({'root_role':'old','files':[]})
            read.assert_not_called()

    def test_new_readonly_reload_acceptance_checks_runtime_and_never_resumes_install(self):
        plan={'candidate_files':{'new':'c'*64},'unrelated_run_values':{},'malformed_artifacts':{},
              'protected_identity_hashes':{},'malformed_route_tokens':[],'max_health_age_ms':1000,
              'shutdown_bound_ms':2000,'candidate_runtime_sha256':'d'*64}
        phases=[{'phase':'two_open','owner_tokens':['one','two'],'listener_pids':[101]},
                {'phase':'one_open','owner_tokens':['two'],'listener_pids':[101]},
                {'phase':'last_closed','owner_tokens':[],'listener_pids':[],'stop_observed_ms':3000,
                 'last_owner_exit_ms':2000,'reopen_events':0,'delivery_count':0,'message_acknowledged':False,'test_message_id':'inert'},
                {'phase':'reopened','owner_tokens':['three'],'listener_pids':[102],'test_message_id':'inert','delivery_count':1}]
        observed={'installed_files':plan['candidate_files'],'version':'0.2.10','lifecycle':'codex_session','task':None,
            'run_values':{},'malformed_artifacts':{},'protected_identity_hashes':{},'unavailable_routes':{},
            'health_samples':[{'pid':101,'timestamp_ms':t,'readiness':'ready','unavailable_count':0} for t in (1000,1500)],
            'listener_pid':101,'observed_at_ms':1600,'locks':{'background':101,'listener':101},'owner_phases':phases,
            'healthy_route_deliveries':1,'acknowledged_replays':0,'running_runtime_sha256':'d'*64}
        raw=fixed.packed({'attempt_id':'inert','plan':plan});sha=fixed.digest(raw)
        def verify(value):
            data=fixed.packed({'attempt_id':'inert','plan_sha256':sha,'observed':value})
            return lifecycle.verify_after_owner_reload(raw,sha,data,fixed.digest(data))
        self.assertEqual(verify(observed)['routing'],'ready')
        with self.assertRaises(fixed.Stop):verify({**observed,'running_runtime_sha256':'e'*64})
        changed=json.loads(json.dumps(observed));changed['owner_phases'][2]['reopen_events']=1
        with self.assertRaises(Exception):verify(changed)

    def test_full_lifetime_does_not_close_between_C_D_and_waits_for_owner(self):
        state,dispatch,observe,spec,api,calls=setup()
        controller=lifecycle.DeveloperLifecycle(state,dispatch,observe,spec)
        result=controller.run()
        self.assertEqual(calls,list(lifecycle.COMMANDS))
        self.assertEqual(len(spec['candidate_files']),47) # archive48 != installed-layout47
        self.assertEqual(result['result'],'installed_awaiting_owner_normal_reload')
        self.assertEqual(result['activation'],'awaiting_owner')
        self.assertFalse(result['authorizes_resume'])
        self.assertTrue(state.closed)
        self.assertFalse(api.held)
        self.assertEqual(api.calls.count(('open_pair',)),1)
        self.assertEqual(api.calls.count(('close_pair',)),1)
        self.assertEqual(len(state.runner.journal.spans),36)
        with self.assertRaises(fixed.Stop):controller.run()
        self.assertEqual(calls,list(lifecycle.COMMANDS))

    def test_uncertain_postimage_or_stale_phase_stops_without_replay(self):
        for fault in ('command','postimage','age','publish-age','staging','capacity'):
            with self.subTest(fault=fault):
                state,dispatch,observe,spec,api,calls=setup(fault)
                controller=lifecycle.DeveloperLifecycle(state,dispatch,observe,spec)
                with self.assertRaises(Exception):controller.run()
                before=list(calls)
                self.assertTrue(state.closed)
                self.assertFalse(api.held)
                with self.assertRaises(fixed.Stop):controller.run()
                self.assertEqual(calls,before)
                self.assertNotIn('activate',calls)
                if fault in ('staging','capacity'):self.assertEqual(state.backend.calls,[])

    def test_native_dispatch_refuses_before_file_read_or_process_start(self):
        runner,backend,_,_,_=fixture.setup()
        with self.assertRaisesRegex(fixed.Stop,'explicit_pilot_authority_required'):runner.build(backend,lifecycle=True)
        self.assertEqual(backend.calls,[])
        with patch.object(lifecycle,'sha_file') as read,patch.object(lifecycle.subprocess,'Popen') as start:
            with self.assertRaisesRegex(RuntimeError,'SOURCE_ONLY'):
                lifecycle.NativeCliDispatcher('INERT','0'*64,'INERT').invoke('marketplace_remove')
            read.assert_not_called();start.assert_not_called()


if __name__=='__main__':unittest.main()
