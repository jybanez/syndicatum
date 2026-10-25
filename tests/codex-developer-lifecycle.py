"""One C/D lifetime and owner-controlled reload, inert dispatch/observations."""
import importlib.util
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_lifecycle as lifecycle
import codex_developer_host_assembly as assembly
import codex_developer_fixed_journal as fixed
specmod=importlib.util.spec_from_file_location('prep_fixture',ROOT/'tests/codex-developer-integrated-preparation.py')
fixture=importlib.util.module_from_spec(specmod);specmod.loader.exec_module(fixture)


def setup(fault=None):
    spec={'unrelated_sha256':'a'*64,'preinstall_files':{'old':'b'*64},'candidate_files':{'file'+str(i):'c'*64 for i in range(48)},
          'marketplace_preimage':{'source':'old'},'marketplace_postimage':lifecycle.MARKETPLACE,
          'archive_sha256':lifecycle.ARCHIVE_SHA,'capacity':{'logical_upper_bytes':[16384]*36,
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
    market=dict(spec['marketplace_preimage']); files=dict(spec['preinstall_files']);calls=[]
    class Dispatcher:
        def invoke(self,operation):
            nonlocal market,files
            calls.append(operation)
            if fault=='command' and operation=='marketplace_add':raise TimeoutError('inert uncertain command')
            if operation=='marketplace_remove':market=None
            else:market=dict(spec['marketplace_postimage'])
            if operation=='plugin_add':files=dict(spec['candidate_files'])
            return {'operation':operation,'exit_code':0,'argv_sha256':fixed.digest(fixed.packed(list(lifecycle.COMMANDS[operation])))}
    def observe(operation):
        value={'operation':operation,'marketplace':market,'candidate_files':files,
               'unrelated_sha256':spec['unrelated_sha256'],'observed_ms':backend.time,
               'shared_staging_entries':[],'concurrent_cli_operations':[]}
        if fault=='staging':value['shared_staging_entries']=['unrelated-entry']
        if fault=='postimage' and operation=='plugin_add':value['candidate_files']={}
        if fault=='age' and operation=='before-marketplace_add':value['observed_ms']-=120001
        return fixed.packed(value)
    return state,Dispatcher(),observe,spec,api,calls


class LifecycleFixtures(unittest.TestCase):
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
