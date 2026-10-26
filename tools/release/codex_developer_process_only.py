"""Process-only acquisition and bounded durable evidence. No deployment API.

The CLI host supplies fresh protected-owner authority and exact staged source
bytes. This entry discovers proposals; it cannot manufacture production pins or
launch the installer, worker, listener or any activation operation.
"""
import base64
import hashlib
import json
from pathlib import Path
import re
import sys
import time

SCOPE='Developer PROCESS-ONLY'
CAPTURE_ID='developer-0210-process-origin-11752-v1'
MAX_FILES=64
MAX_BYTES=16777216
MAX_RECORD=1048576


def packed(value):return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()
def sha(raw):return hashlib.sha256(raw).hexdigest()
def require(value,code):
    if not value:raise ValueError(code)


class EvidenceSink:
    """Exclusive writes, fsync/readback in native store; no overwrite or cleanup.

    Three slots/640KiB are reserved for receipts, terminal outcome and manifest. A failed
    write consumes its name and attempt even when the outcome is uncertain.
    """
    def __init__(self,store):self.store=store;self.files={};self.bytes=0;self.terminal=False

    def write(self,name,raw,*,terminal=False):
        require(type(name) is str and re.fullmatch(r'[A-Za-z0-9_.-]{1,128}',name) and
                name not in ('.','..') and name not in self.files,'capture_name_or_replay')
        require(type(raw) is bytes and len(raw)<=MAX_RECORD,'capture_record_bound')
        require((terminal or not self.terminal) and len(self.files)<(MAX_FILES if terminal else MAX_FILES-3) and
                self.bytes+len(raw)<=(MAX_BYTES if terminal else MAX_BYTES-655360),'capture_capacity')
        pin={'bytes':len(raw),'sha256':sha(raw),'state':'write_uncertain'}
        self.files[name]=pin;self.bytes+=len(raw)
        try:
            self.store.write_once(name,raw,terminal=terminal)
            require(self.store.read(name,len(raw)+1)==raw,'capture_readback')
            pin['state']='verified'
        except Exception:
            self.terminal=True
            raise

    def json(self,name,value,*,terminal=False):self.write(name,packed(value),terminal=terminal)

    def finish(self,outcome):
        self.terminal=True
        self.json('outcome.json',outcome,terminal=True)
        self.json('manifest.json',{'scope':SCOPE,'status':outcome['status'],'native_admission':False,
            'files':self.files.copy(),'payload_bytes':self.bytes},terminal=True)


def safe_failure_summary(error):
    module=sys.modules.get('codex_developer_windows_observer')
    if module is not None:return module.failure_details(error)
    return {'code':'unavailable','phase':'unavailable','api':'unavailable',
            'win32_status':None,'cleanup_failures':[],
            'evidence_capture_uncertain':getattr(error,'evidence_capture_uncertain',False) is True}

def run(packet,sources,authorize,factory,*,clock=time.monotonic):
    """One process discovery only; no CLI/layout/ref/runtime capabilities used."""
    require(packet['scope']==SCOPE and packet['capture_identity']==CAPTURE_ID,'process_scope')
    require(callable(authorize) and set(sources)==set(packet['sources']),'process_sources')
    require(sum(len(raw) for raw in sources.values())<=1048576,'process_source_total')
    for name,raw in sources.items():
        require({'sha256':sha(raw),'size':len(raw)}==packet['sources'][name],'process_source_pin')
    require(packet['shared_frozen_bytes']==171186 and packet['shared_capture_allowance_bytes']==MAX_BYTES,
            'process_shared_allowance')
    began=clock();sink=None;receipts=[];timings={}
    def checkpoint(index,label):
        require(clock()-began<120,'process_deadline')
        started=clock();receipt=authorize(index,label,max(.001,120-(clock()-began)))
        timings['authority_'+str(index)]=round((clock()-started)*1000)
        require(type(receipt) is dict,'process_receipt');receipts.append(receipt)
        require(clock()-began<120,'process_deadline')
    def within_budget():
        require(sink.bytes<=MAX_BYTES,'process_shared_capture_capacity')
    try:
        checkpoint(1,'capture_only_preflight_and_allocation')
        sink=EvidenceSink(factory.create_capture(packet))
        # Existing frozen admission data consumes the SAME allowance. Reserve
        # its bytes before any ordinary payload, preserving terminal reserve.
        sink.bytes=171186
        for name,raw in sources.items():sink.write(name,raw)
        sink.json('proposal.json',packet);factory.staged(sink.store)
        sink.json('root-provenance.json',factory.root_provenance)
        checkpoint(2,'construct_process_observer_only')
        from codex_developer_windows_observer import NativeWindowsObserver
        guard=factory.guard
        class Observer(NativeWindowsObserver):
            def gate(self):guard()
        observer=Observer()
        checkpoint(3,'one_process_origin_observation')
        started=clock()
        try:result=observer.processes(packet['known_process_image_pins'],discovery=True)
        finally:
            primary=sys.exception();timings['process_query']=round((clock()-started)*1000)
            try:
                if hasattr(observer,'last_process_evidence'):sink.json('process-raw-prefix.json',observer.last_process_evidence)
            except Exception:
                if primary is None:raise
                primary.evidence_capture_uncertain=True
                primary.add_note('process_raw_evidence_uncertain; primary preserved')
        checkpoint(4,'retain_current_origin_proposals')
        sink.json('process-origins.json',result)
        sink.json('identity-limitations.json',{'historical_child_settlement':'UNVERIFIED',
            'automatic_exclusions':[],'current_pid_name_is_not_historical_identity':True,
            'scope':'current selected process-family coverage only'})
        require(result['status']=='PROPOSED_NOT_ADMISSION' and result['snapshot']['complete'] is True,
                'process_coverage_unavailable')
        checkpoint(5,'process_evidence_only_no_successor')
        within_budget()
        checkpoint(6,'shared_capacity_and_proposal_boundary')
        checkpoint(7,'terminal_process_proposal')
        outcome={'scope':SCOPE,'status':'PROPOSED_NOT_ADMISSION','native_admission':False,
            'historical_child_settlement':'UNVERIFIED','cli_queries':0,'process_queries':1,
            'timings_ms':timings,'total_ms':round((clock()-began)*1000),
            'frozen_prior_bytes':171186,'shared_accounted_bytes_before_terminal':sink.bytes}
        sink.json('authority-receipts.json',receipts);sink.finish(outcome);return outcome
    except Exception as primary:
        if sink is not None:
            try:
                sink.json('authority-receipts.json',receipts,terminal=True)
                sink.finish({'scope':SCOPE,'status':'STOP_NO_RETRY','native_admission':False,
                    'primary_error_type':type(primary).__name__,'failure':safe_failure_summary(primary),
                    'historical_child_settlement':'UNVERIFIED',
                    'timings_ms':timings,'total_ms':round((clock()-began)*1000)})
            except Exception:
                primary.evidence_capture_uncertain=True
                primary.add_note('process_terminal_evidence_uncertain; no replay')
        raise
