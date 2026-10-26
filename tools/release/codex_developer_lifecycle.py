"""Closed CLI dispatch + one held C/D lifetime + owner-controlled activation.

No outer retries, rollback, forced reopen, or continuation of consumed storage.
Independent postimage/acceptance evidence comes from trusted admitted collectors.
"""
import base64
import json
import math
from pathlib import Path
import subprocess
import threading
import queue
import time

from codex_developer_fixed_journal import check, digest, packed, Stop
from codex_developer_process_ipc import PinnedWindowsProcess, _unique, sha_file
from codex_pilot_sequence import verify_acceptance, bound_object
from codex_developer_install_collector import InstallObservationCollector

CANDIDATE = '9141daa08d896f2524be30b96d01ab2a14398757'
ARCHIVE_SHA = '331e47a6ee3cd690846ac7f2d302566658e4be195c3a734228f457f55e61feeb'
# CLI list exposes URL but not configured ref/sparse. HEAD/ref resolution is
# independently observed. The exact ref/sparse remain verified dispatch argv,
# never fabricated configuration observations.
MARKETPLACE = {'url':'https://github.com/jybanez/syndicatum.git','head':CANDIDATE}
COMMANDS = {
    'marketplace_remove': ('plugin','marketplace','remove','syndicatum','--json'),
    'marketplace_add': ('plugin','marketplace','add','https://github.com/jybanez/syndicatum.git',
                       '--ref',CANDIDATE,'--sparse','.agents/plugins','--sparse','plugins/codex','--json'),
    'plugin_add': ('plugin','add','codex@syndicatum','--json'),
}
BOUNDARIES = {'marketplace_remove':('before-remove','after-remove'),
              'marketplace_add':('before-add','after-add'),
              'plugin_add':('before-plugin-add','after-plugin-add')}


class NativeCliDispatcher:
    def __init__(self, executable, executable_sha256, neutral_directory):
        self.executable, self.sha, self.cwd = executable, executable_sha256, neutral_directory
        self.started, self.terminal, self.evidence = set(), False, []
        self.child=None
        self.child_identity=None

    def failure_context(self):
        if self.child is None:return {'collection_safe':True,'reason':'command_not_started','child':None}
        exited=self.child.poll() is not None
        return {'collection_safe':exited,'reason':'root_exited_requires_independent_quiescence' if exited else 'child_may_be_running',
                'child':self.child_identity or {'pid':self.child.pid}}

    @staticmethod
    def gate():
        raise RuntimeError('SOURCE_ONLY: installation command dispatch disabled')

    def invoke(self, operation):
        self.gate()  # Before executable reads, process start or mutation.
        check(not self.terminal and operation in COMMANDS and operation not in self.started, 'cli_dispatch_no_replay')
        self.started.add(operation)
        self.child=None;self.child_identity=None
        child = None
        try:
            executable, cwd = Path(self.executable), Path(self.cwd)
            check(executable.is_absolute() and cwd.is_absolute() and cwd.is_dir() and
                  not str(cwd).lower().startswith('c:\\wamp64\\www') and
                  sha_file(executable) == self.sha, 'cli_pin_or_neutral_directory')
            child = subprocess.Popen([self.executable,*COMMANDS[operation]],executable=self.executable,
                cwd=self.cwd,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
                shell=False,close_fds=True,creationflags=subprocess.CREATE_NO_WINDOW)
            self.child=child
            # Fresh child only; reuse bounded reads/deadlines, never a reconnect.
            helper= PinnedWindowsProcess(self.executable,'NO-WORKER',{},30)
            helper.child=child
            identity=helper._probe()
            self.child_identity=identity
            check(identity['sha256']==self.sha,'cli_child_pin')
            deadline=time.monotonic()+30
            def bounded_read(stream,limit):
                result=bytearray()
                while True:
                    raw=stream.read(4096)
                    if not raw:return bytes(result)
                    result.extend(raw)
                    check(len(result)<=limit,'cli_output_bound_uncertain')
            # Both pipes drain concurrently; neither can deadlock the other.
            results=queue.Queue(maxsize=2)
            def drain(name,stream,limit):
                try:results.put((name,True,bounded_read(stream,limit)))
                except Exception as error:results.put((name,False,error))
            for name,stream,limit in (('out',child.stdout,2097152),('err',child.stderr,65536)):
                threading.Thread(target=drain,args=(name,stream,limit),daemon=True).start()
            code=helper._bounded(lambda:child.wait(timeout=max(.001,deadline-time.monotonic())),deadline)
            captured={}
            for _ in range(2):
                name,ok,value=results.get(timeout=max(.001,deadline-time.monotonic()))
                if not ok:raise value
                captured[name]=value
            out,err=captured['out'],captured['err']
            for stream in (child.stdout,child.stderr):helper._bounded(stream.close,deadline)
            value={'operation':operation,'argv_sha256':digest(packed(list(COMMANDS[operation]))),
                   'exit_code':code,'child':identity,'stdout_base64':base64.b64encode(out).decode(),
                   'stderr_base64':base64.b64encode(err).decode()}
            self.evidence.append(value)
            check(code==0,'cli_nonzero_partial_outcome')
            return value
        except Exception as error:
            self.terminal=True
            error.close_uncertain=child is not None
            if child is not None and not any(x.get('operation')==operation for x in self.evidence):
                self.evidence.append({'operation':operation,'pid':child.pid,'outcome':'uncertain',
                                      'argv_sha256':digest(packed(list(COMMANDS[operation])))})
            # Never kill/retry a potentially partial marketplace command.
            raise


class DeveloperLifecycle:
    """Trusted host lifetime. Only a new explicit pilot grant can reach D/E.

    observe_install is a trusted independent collector, NOT plan-provided facts.
    Its current schema/provenance and binding must be admitted before execution.
    """
    def __init__(self, host_state, dispatcher, observe_install, install_spec):
        self.state,self.dispatcher,self.observe,self.spec=host_state,dispatcher,observe_install,install_spec
        self.started,self.terminal=False,False
        self.active_command=None
        self.failure_assessment=None
        self.last_observation={}
        check(type(observe_install) is InstallObservationCollector and
              digest(packed(observe_install.binding))==install_spec['collector_binding_sha256'] and
              observe_install.context==host_state.runner.context,'independent_install_collector_required')

    def _postimage(self, operation, raw):
        value=json.loads(raw,object_pairs_hook=_unique)
        check(packed(value)==raw and set(value)=={'operation','marketplace','candidate_files','unrelated_sha256','observed_ms','shared_staging_entries','concurrent_cli_operations','collector_provenance'},
              'installation_postimage_schema')
        self._quiet(value)
        check(value['operation']==operation and value['unrelated_sha256']==self.spec['unrelated_sha256'],
              'installation_unrelated_drift')
        now=self.state.runner.now()
        check(type(value['observed_ms']) is int and 0<=now-value['observed_ms']<=120000,'installation_postimage_stale')
        if operation=='marketplace_remove':
            check(value['marketplace'] is None and value['candidate_files']==self.spec['preinstall_files'],
                  'marketplace_remove_postimage')
        else:
            check(value['marketplace']==self.spec['marketplace_postimage'],'exact_marketplace_source_postimage')
            expected=self.spec['candidate_files'] if operation=='plugin_add' else self.spec['preinstall_files']
            check(value['candidate_files']==expected,'installed_candidate_postimage')
        return value

    def _preimage(self, operation):
        raw=self.observe('before-'+operation)
        value=json.loads(raw,object_pairs_hook=_unique)
        check(packed(value)==raw,'installation_preimage_canonical')
        check(set(value)=={'operation','marketplace','candidate_files','unrelated_sha256','observed_ms','shared_staging_entries','concurrent_cli_operations','collector_provenance'} and
              value['operation']=='before-'+operation and value['unrelated_sha256']==self.spec['unrelated_sha256'] and
              value['candidate_files']==self.spec['preinstall_files'],'installation_preimage')
        expected={'marketplace_remove':self.spec['marketplace_preimage'],'marketplace_add':None,
                  'plugin_add':self.spec['marketplace_postimage']}[operation]
        check(value['marketplace']==expected,'marketplace_preimage_drift')
        self._quiet(value)
        self._fresh(value)
        return value

    def _fresh(self, value):
        check(type(value['observed_ms']) is int and 0<=self.state.runner.now()-value['observed_ms']<=120000,
              'installation_observation_expired')

    def _quiet(self,value):
        check(type(value['shared_staging_entries']) is list and value['shared_staging_entries']==[] and
              type(value['concurrent_cli_operations']) is list and value['concurrent_cli_operations']==[],
              'shared_cli_staging_or_concurrent_operation')

    def _capacity(self):
        data=self.spec['capacity']
        lengths=data['logical_upper_bytes']
        check(type(lengths) is list and len(lengths)==36 and all(type(n) is int and 0<n<=33554432 for n in lengths),
              'pilot_publication_projection')
        check(type(data['projected_total_ms']) is int and 0<data['projected_total_ms']<=120000 and
              type(data['worst_observation_ms']) is int and 0<data['worst_observation_ms']<=120000 and
              type(data['failure_publications']) is int and 3<=data['failure_publications']<=8,
              'pilot_time_or_failure_projection')
        manifest=self.state.runner.storage.manifest
        chunk=(manifest['width']-1024)*3//4
        check(chunk>0,'pilot_chunk_width')
        required=8+sum(math.ceil(n/chunk)+1 for n in lengths)+data['failure_publications']*(math.ceil(max(lengths)/chunk)+1)
        head=self.state.runner.handoff
        head_slots=head.storage.manifest['slots'] if hasattr(head,'storage') else manifest['slots']
        wire=getattr(self.state.runner.authority.timeline,'wire',None)
        receipt_slots=wire.channel.storage.manifest['slots'] if wire is not None and hasattr(wire.channel,'storage') else 128
        receipts=2*(1+36+7+data['failure_publications'])
        check(required<=manifest['slots'] and required<=head_slots and receipts<=receipt_slots,
              'pilot_capacity_before_preparation')
        self.state.runner.publication_limits={'lengths':lengths,'failure_count':data['failure_publications']}

    def run(self):
        check(not self.started and not self.terminal,'lifecycle_no_replay')
        self.started=True
        try:
            # Full spec is approved in the native context, not a fresh caller list.
            check(digest(packed(self.spec))==self.state.runner.plan.get('installation_spec_sha256'),
                  'installation_spec_context_pin')
            check(self.spec['archive_sha256']==ARCHIVE_SHA and self.spec['marketplace_postimage']==MARKETPLACE and
                  0<len(self.spec['candidate_files'])<=512 and all(type(value) is str and len(value)==64 and
                  all(c in '0123456789abcdef' for c in value) for value in self.spec['candidate_files'].values()),
                  'exact_install_artifact_spec')
            self._capacity()
            self._preimage('marketplace_remove')
            prepared=self.state.prepare(lifecycle=True)
            check(prepared['result']=='cutoff_prepared_only','lifecycle_preparation_stopped')
            journal=self.state.runner.journal
            head=prepared['head_sha256']
            for operation in COMMANDS:
                before,after=BOUNDARIES[operation]
                head=self.state.boundary(before,head)['head_sha256']
                preimage=self._preimage(operation)
                journal.append(operation,'independent-install-preimage',preimage)
                journal.append(operation,'command-intent',{'argv_sha256':digest(packed(list(COMMANDS[operation])))})
                head=journal.admit_lifecycle(operation,journal.retained_head)
                self.state.adapter._fresh(self.state.adapter.last_clock)
                self._fresh(preimage)
                self.active_command=operation
                result=self.dispatcher.invoke(operation)
                check(result['operation']==operation and result['exit_code']==0 and
                      result['argv_sha256']==digest(packed(list(COMMANDS[operation]))),'command_result_uncertain')
                journal.append(operation,'command-result',result)
                head=self.state.boundary(after,journal.retained_head)['head_sha256']
                raw=self.observe(operation,command_context={'collection_safe':True,'child':result.get('child')})
                self.last_observation[operation]=raw
                postimage=self._postimage(operation,raw)
                journal.append(operation,'independent-install-postimage',postimage)
                head=journal.retained_head
                self.active_command=None
            # Activation dispatch is deliberately owner-controlled normal reload:
            # never restart the old plugin, launch a second MCP server or reopen UI.
            journal.append('activation','owner-intent',{'policy':'owner_normal_close_reopen','forced_reopen':False})
            head=journal.admit_lifecycle('owner_activation',journal.retained_head)
            self.state.adapter._fresh(self.state.adapter.last_clock)
            journal.check_final_time()
            self.state.close_terminal()
            journal.check_final_time()
            self.state.adapter._fresh(self.state.adapter.last_clock)
            self.terminal=True
            return {'result':'installed_awaiting_owner_normal_reload','installed_commit':CANDIDATE,
                    'activation':'awaiting_owner','acceptance':'not_observed','head_sha256':head,
                    'session_closed':True,'authorizes_resume':False,
                    'owner_instruction':'When ready, close Codex and open it normally. Do not force reopen.'}
        except Exception as error:
            self.terminal=True
            if self.active_command is not None:
                self._failed_postimage(error)
            if self.state.runner.journal is not None:
                try:self.state.runner.journal.append('lifecycle','uncertain-dispatch-evidence',
                    {'evidence':getattr(self.dispatcher,'evidence',[]),'code':type(error).__name__})
                except Exception:error.add_note('failure_evidence_publication_unavailable')
            try:self.state.close_terminal()
            except Exception:error.add_note('lifecycle_close_uncertain; no replay')
            raise

    def _failed_postimage(self, primary):
        operation=self.active_command
        context={'collection_safe':False,'reason':'command_process_state_unavailable','child':None}
        try:
            probe=getattr(self.dispatcher,'failure_context',None)
            if probe:context=probe()
            if context.get('collection_safe') is not True:
                assessment={'operation':operation,'status':'unavailable','reason':context.get('reason','quiescence_not_proven'),
                            'child':context.get('child')}
            else:
                raw=self.last_observation.get(operation)
                if raw is None:
                    collect=getattr(self.observe,'failed',self.observe)
                    raw=collect(operation,command_context=context)
                check(type(raw) is bytes and len(raw)<=32768,'failed_postimage_bound')
                value=json.loads(raw,object_pairs_hook=_unique)
                check(packed(value)==raw and value['operation']==operation,'failed_postimage_context')
                check(type(value['concurrent_cli_operations']) is list and not value['concurrent_cli_operations'],
                      'independent_command_quiescence_unavailable')
                self._fresh(value)
                # Failed/partial commands need observations, NOT success-state
                # assertions or inference that the preimage stayed unchanged.
                assessment={'operation':operation,'status':'retained','child':context.get('child'),
                            'completeness':'bounded_partial_observation_not_success',
                            'raw_base64':base64.b64encode(raw).decode(),'sha256':digest(raw)}
        except Exception as secondary:
            code=getattr(secondary,'code',None)
            if code is None and isinstance(secondary,Stop) and str(secondary).replace('_','').isalnum():code=str(secondary)
            assessment={'operation':operation,'status':'unavailable','reason':code or 'independent_collection_failed',
                        'error_type':type(secondary).__name__,'child':context.get('child')}
            primary.add_note('independent_failed_postimage_unavailable; primary preserved')
        self.failure_assessment=assessment
        primary.failed_command_postimage=assessment
        try:self.state.runner.journal.append('lifecycle','failed-command-postimage',assessment)
        except Exception:primary.add_note('failed_postimage_publication_unavailable')


def verify_after_owner_reload(plan_raw,plan_sha,evidence_raw,evidence_sha):
    """New read-only acceptance context, never resumes C/D or starts a listener.

    Actual observations are separately retained and bounded by the existing
    acceptance contract. Passing this function does not create those observations.
    """
    plan=bound_object(plan_raw,plan_sha)
    evidence=bound_object(evidence_raw,evidence_sha)
    check(evidence['plan_sha256']==plan_sha and evidence['attempt_id']==plan['attempt_id'], 'reload_acceptance_context')
    expected=plan['plan'].pop('candidate_runtime_sha256')
    actual=evidence['observed'].pop('running_runtime_sha256')
    check(type(expected) is str and len(expected)==64 and all(c in '0123456789abcdef' for c in expected) and
          actual==expected,'running_candidate_runtime_mismatch')
    # The original independent anchors were checked above. Derive a structural
    # view for the existing lifecycle verifier without replacing those anchors.
    structural_plan=packed(plan)
    structural_sha=digest(structural_plan)
    structural_evidence=packed({**evidence,'plan_sha256':structural_sha})
    return verify_acceptance(structural_plan,structural_sha,structural_evidence,digest(structural_evidence))
