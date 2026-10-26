"""Admission-only acquisition and bounded durable evidence. No deployment API.

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

SCOPE='Developer ADMISSION-ONLY'
CAPTURE_ID='developer-0210-admission-11736-v1'
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


def discover_layout(reader,role):
    # First pass reads only unknown-file metadata, then the exact discovered
    # file set is hashed independently. Nothing is auto-admitted by this list.
    initial=reader.layout({'root_role':role,'files':[]},partial=True)
    if initial=={'!root':'unavailable:root_absent'}:
        return {'status':'PROPOSED_NOT_ADMISSION','role':role,'present':False,'files':{}}
    require(not any(name.startswith('!') for name in initial),'layout_discovery_unavailable')
    files=reader.layout({'root_role':role,'files':sorted(initial)})
    return {'status':'PROPOSED_NOT_ADMISSION','role':role,'present':True,'files':files,
            'metadata_pass_sha256':sha(packed(initial))}


def run(packet,sources,authorize,factory,*,clock=time.monotonic):
    """Exactly seven existing-bridge authority checkpoints, no automatic retry.

    authorize/factory are trusted host capabilities, never JSON callbacks. The
    factory creates only this new capture and query-only reader/CLI/observer
    instances. No B setup roots or production binding can be created here.
    """
    require(packet['scope']==SCOPE and packet['capture_identity']==CAPTURE_ID,'admission_scope')
    require(callable(authorize) and set(sources)==set(packet['sources']),'admission_sources')
    require(sum(len(raw) for raw in sources.values())<=1048576,'admission_source_total')
    for name,raw in sources.items():
        require({'sha256':sha(raw),'size':len(raw)}==packet['sources'][name],'admission_source_pin')
    began=clock();sink=None;receipts=[];timings={};results={};cli=None
    def checkpoint(index,label):
        require(clock()-began<120,'admission_total_deadline')
        started=clock();receipt=authorize(index,label,max(.001,120-(clock()-began)))
        timings['authority_'+str(index)]=round((clock()-started)*1000)
        require(type(receipt) is dict,'admission_authority_receipt')
        receipts.append(receipt)
        require(clock()-began<120,'admission_total_deadline')
    def phase(label,operation):
        start=clock()
        try:return operation()
        finally:timings[label]=round((clock()-start)*1000)
    try:
        checkpoint(1,'capture_and_reviewed_source_staging')
        sink=EvidenceSink(phase('capture_preflight_create_acl',lambda:factory.create_capture(packet)))
        staged_at=clock()
        for name,raw in sources.items():sink.write(name,raw)
        sink.json('proposal.json',packet)
        factory.staged(sink.store)
        timings['source_materialization']=round((clock()-staged_at)*1000)
        reader,windows,cli,binding=factory.observers(packet)
        sink.json('root-provenance.json',factory.root_provenance)
        checkpoint(2,'two_fixed_cli_queries')
        try:
            deadline=clock()+min(16,120-(clock()-began))
            markets=phase('cli_marketplaces',lambda:cli.read('marketplaces',deadline))
            plugins=phase('cli_plugins',lambda:cli.read('plugins',deadline))
        finally:
            # Persist raw and partial output even if the second query fails.
            primary=sys.exception()
            try:
                for index,record in enumerate(cli.results):
                    sink.json('cli-'+str(index)+'.json',record)
                    for channel in ('stdout','stderr'):
                        value=record.get(channel+'_base64',record.get(channel+'_prefix_base64'))
                        if value is not None:sink.write('cli-'+str(index)+'-'+channel+'.bin',base64.b64decode(value,validate=True))
            except Exception:
                if primary is None:raise
                primary.add_note('raw_cli_evidence_capture_uncertain; primary preserved')
        factory.check_cli_projection(markets,plugins,binding)
        checkpoint(3,'proposed_loaded_runtime_closure')
        try:runtime=phase('runtime_discovery',lambda:windows.runtime(None,discovery_roots=packet['runtime_discovery_roots']))
        finally:
            primary=sys.exception()
            try:
                if hasattr(windows,'last_runtime_evidence'):
                    sink.json('runtime-raw-prefix.json',getattr(windows,'runtime_raw_passes',[windows.last_runtime_evidence]))
                if hasattr(windows,'runtime_proposed_pins'):
                    sink.json('runtime-proposed-partial.json',{'status':'PROPOSED_NOT_ADMISSION','pins':windows.runtime_proposed_pins})
            except Exception:
                if primary is None:raise
                primary.add_note('raw_runtime_evidence_capture_uncertain')
        require(runtime['status']=='PROPOSED_NOT_ADMISSION','runtime_discovery_not_proposal')
        sink.json('runtime-proposal.json',runtime)
        checkpoint(4,'actual_installed_cache_layouts')
        for role in ('installed_027','installed_0210'):
            result=phase(role,lambda role=role:discover_layout(reader,role))
            results[role]=result;sink.json('layout-'+role+'.json',result)
        checkpoint(5,'affected_sibling_layouts_and_managed_refs')
        siblings=factory.sibling_roles(plugins,binding)
        require(len(siblings)<=16,'admission_sibling_bound')
        for index,role in enumerate(siblings):
            result=phase('sibling_'+str(index),lambda role=role:discover_layout(reader,role))
            sink.json('sibling-'+str(index)+'.json',result)
        managed=phase('managed_refs',lambda:reader.managed_state('managed'))
        sink.json('managed-state.json',managed)
        for index,record in enumerate(getattr(reader,'ref_reads',[])):
            raw=base64.b64decode(record['raw_base64'],validate=True)
            sink.write('managed-ref-'+str(index)+'.bin',raw)
            sink.json('managed-ref-'+str(index)+'.json',{k:v for k,v in record.items() if k!='raw_base64'})
        sink.json('staging-metadata.json',phase('staging_metadata',lambda:factory.staging(binding)))
        checkpoint(6,'process_origins_no_auto_exclusions')
        try:processes=phase('process_origins',lambda:windows.processes(packet['known_process_image_pins'],discovery=True))
        finally:
            primary=sys.exception()
            try:
                if hasattr(windows,'last_process_evidence'):sink.json('process-raw-prefix.json',windows.last_process_evidence)
            except Exception:
                if primary is None:raise
                primary.add_note('raw_process_evidence_capture_uncertain')
        sink.json('process-origins.json',processes)
        sink.json('exclusion-review.json',{'status':'PROPOSED_NOT_ADMISSION','automatic_exclusions':[],
            'roles':[{'pid':row['pid'],'role':('admission_observer_self' if row['pid']==processes['snapshot']['observer_pid'] else 'unclassified_possible_writer'),
                      'rationale':('Capture-only observer identity; not a future pilot identity' if row['pid']==processes['snapshot']['observer_pid'] else
                                   'No exclusion justified; requires source and role review')} for row in processes['snapshot']['processes']]})
        require(processes['status']=='PROPOSED_NOT_ADMISSION','process_discovery_not_proposal')
        # Unknown hashes/origins remain raw proposals for review. Never label an
        # incomplete snapshot as quiescence or proceed after a coverage failure.
        require(processes['snapshot']['complete'] is True,'process_coverage_unavailable')
        checkpoint(7,'terminal_proposal_and_raw_evidence_manifest')
        outcome={'status':'PROPOSED_NOT_ADMISSION','scope':SCOPE,'native_admission':False,
            'installed_or_activated':False,'timings_ms':timings,'total_ms':round((clock()-began)*1000),
            'layout_cardinality':{k:len(v['files']) for k,v in results.items()},'source_files':len(sources),
            'captured_files_before_terminal':len(sink.files),'captured_bytes_before_terminal':sink.bytes}
        sink.json('authority-receipts.json',receipts)
        sink.finish(outcome)
        return outcome
    except Exception as primary:
        if sink is not None:
            try:
                sink.json('authority-receipts.json',receipts,terminal=True)
                sink.finish({'status':'STOP_NO_RETRY','scope':SCOPE,'native_admission':False,
                    'primary_error_type':type(primary).__name__,'timings_ms':timings,
                    'layout_cardinality':{k:len(v['files']) for k,v in results.items()},
                    'captured_files_before_terminal':len(sink.files),'captured_bytes_before_terminal':sink.bytes,
                    'total_ms':round((clock()-began)*1000)})
            except Exception:primary.add_note('admission_terminal_evidence_uncertain; preserve capture, no replay')
        raise
