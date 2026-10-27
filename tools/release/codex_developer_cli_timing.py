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

SCOPE='Developer CLI-TIMING-ONLY'
CAPTURE_ID='developer-0210-cli-timing-11775-v1'
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
    """One plugin list --json timing query; production8s policy unchanged."""
    require(packet['scope']==SCOPE and packet['capture_identity']==CAPTURE_ID,'timing_scope')
    require(callable(authorize) and set(sources)==set(packet['sources']),'timing_sources')
    require(sum(len(raw) for raw in sources.values())<=1048576,'timing_source_total')
    for name,raw in sources.items():
        require({'sha256':sha(raw),'size':len(raw)}==packet['sources'][name],'timing_source_pin')
    require(packet['shared_frozen_bytes']==346528 and packet['shared_capture_allowance_bytes']==MAX_BYTES,
            'timing_shared_allowance')
    began=clock();sink=None;receipts=[];timings={};cli=None
    def checkpoint(index,label):
        require(clock()-began<120,'timing_deadline');started=clock()
        receipt=authorize(index,label,max(.001,120-(clock()-began)))
        timings['authority_'+str(index)]=round((clock()-started)*1000)
        require(type(receipt) is dict,'timing_receipt');receipts.append(receipt)
        require(clock()-began<120,'timing_deadline')
    try:
        checkpoint(1,'timing_capture_only_preflight')
        sink=EvidenceSink(factory.create_capture(packet));sink.bytes=346528
        for name,raw in sources.items():sink.write(name,raw)
        sink.json('proposal.json',packet);factory.staged(sink.store)
        sink.json('root-provenance.json',factory.root_provenance)
        checkpoint(2,'construct_cli_timing_recorder_only')
        from codex_developer_metadata_producer import NativeReadOnlyCli
        guard=factory.guard
        class TimingCli(NativeReadOnlyCli):
            def gate(self):guard()
            def query_policy(self):return ('plugins',60,1)
        cli=TimingCli({'cli':packet['cli'],'neutral_directory':str(sink.store.path)})
        checkpoint(3,'one_plugin_list_json_max60s')
        started=clock()
        try:
            value=cli.read('plugins',began+120)
        finally:
            primary=sys.exception();timings['plugin_list_query']=round((clock()-started)*1000)
            try:
                for index,record in enumerate(cli.results):
                    sink.json('cli-'+str(index)+'.json',record)
                    for channel in ('stdout','stderr'):
                        raw=record.get(channel+'_base64',record.get(channel+'_prefix_base64'))
                        if raw is not None:sink.write('cli-'+str(index)+'-'+channel+'.bin',base64.b64decode(raw,validate=True))
            except Exception:
                if primary is None:raise
                primary.evidence_capture_uncertain=True
                primary.add_note('timing_raw_evidence_uncertain; primary preserved')
        checkpoint(4,'retained_raw_query_and_elapsed')
        # Parsed output is descriptive evidence only, never production admission.
        sink.json('timing-summary.json',{'query':'plugin list --json','query_count':1,
            'elapsed_ms':timings['plugin_list_query'],'top_level_type':type(value).__name__,
            'production_query_seconds':8,'diagnostic_query_seconds':60,
            'historical_child_settlement':'UNVERIFIED','status':'PROPOSED_NOT_ADMISSION'})
        checkpoint(5,'no_successor_or_inventory_probe')
        checkpoint(6,'shared_capacity_boundary')
        checkpoint(7,'terminal_timing_evidence')
        outcome={'scope':SCOPE,'status':'PROPOSED_NOT_ADMISSION','native_admission':False,
            'cli_queries':1,'process_queries':0,'timings_ms':timings,
            'total_ms':round((clock()-began)*1000),'frozen_prior_bytes':346528}
        sink.json('authority-receipts.json',receipts);sink.finish(outcome);return outcome
    except Exception as primary:
        if sink is not None:
            try:
                sink.json('authority-receipts.json',receipts,terminal=True)
                sink.finish({'scope':SCOPE,'status':'STOP_NO_RETRY','native_admission':False,
                    'primary_error_type':type(primary).__name__,'failure':safe_failure_summary(primary),
                    'timings_ms':timings,'total_ms':round((clock()-began)*1000)})
            except Exception:
                primary.evidence_capture_uncertain=True
                primary.add_note('timing_terminal_evidence_uncertain; no replay')
        raise
