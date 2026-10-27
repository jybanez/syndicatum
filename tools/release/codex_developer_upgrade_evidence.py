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

SCOPE='Developer UPGRADE-EVIDENCE-ONLY'
CAPTURE_ID='developer-0210-upgrade-evidence-11784-v1'
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


def retained_observers(packet,factory,sink):
    """Two exact historical inputs; no CLI object or query is constructed."""
    from codex_developer_install_collector import NativeInstallReader
    from codex_developer_metadata_producer import retained_root_map
    from codex_developer_windows_observer import NativeWindowsObserver
    guard=factory.guard;guard();rows=packet['registration_inputs']
    expected=[('developer-a1-5eb6ed67761e450288a3da8968d93f72','result-1.json',1324,
               'fdb41dfe306df541c8db84a8773d58b31a445bc9cfaab0f2c35f39079ea066b9'),
              ('developer-0210-cli-timing-11775-v1','cli-0-stdout.bin',10189,
               'b6d117949715e5b6b4e9a144fbc19fa5bf301ab88b425aa17526b0e2ac83eafd')]
    require(len(rows)==2,'retained_input_count');values=[]
    for index,(row,(leaf,name,size,pin)) in enumerate(zip(rows,expected)):
        path=Path(packet['capture_parent'])/leaf/name
        require(row['path']==str(path) and row['bytes']==size and row['sha256']==pin,'retained_input_scope')
        factory.helpers.check_nonreparse_chain(path);before=path.stat()
        require(before.st_size==size,'retained_input_size')
        with path.open('rb') as stream:raw=stream.read(size+1)
        factory.helpers.check_nonreparse_chain(path);after=path.stat()
        require(len(raw)==size and sha(raw)==pin and
                (before.st_size,before.st_mtime_ns,before.st_ino)==(after.st_size,after.st_mtime_ns,after.st_ino),
                'retained_input_drift')
        sink.write('retained-input-'+str(index)+'.bin',raw)
        sink.json('retained-input-'+str(index)+'.json',row)
        value=json.loads(raw)
        if index==0:
            require(value['exit']==0,'retained_marketplace_exit')
            value=json.loads(base64.b64decode(value['stdout_base64'],validate=True))
        values.append(value)
    roots=retained_root_map(*values)
    require(sha(packed(roots))==packet['retained_root_map_sha256'],'retained_root_map_pin')
    class Reader(NativeInstallReader):
        def gate(self):guard()
    class Windows(NativeWindowsObserver):
        def gate(self):guard()
    reader=Reader(roots,retain_ref_bytes=True);factory.reader=reader
    binding={'roots':reader.roots,'managed_root_role':'managed'}
    sink.json('historical-input-boundary.json',{'current_mutation_preimages':False,
        'roots':'source_derived_until_independently_read','original_observations':[r['observation'] for r in rows]})
    return reader,Windows(),binding,values[1]


def run(packet,sources,authorize,factory,*,clock=time.monotonic):
    require(packet['scope']==SCOPE and packet['capture_identity']==CAPTURE_ID,'upgrade_evidence_scope')
    require(callable(authorize) and set(sources)==set(packet['sources']),'upgrade_evidence_sources')
    require(sum(len(raw) for raw in sources.values())<=1048576,'upgrade_evidence_source_total')
    for name,raw in sources.items():
        require({'sha256':sha(raw),'size':len(raw)}==packet['sources'][name],'upgrade_evidence_source_pin')
    require(packet['shared_frozen_bytes']==572262 and packet['shared_capture_allowance_bytes']==MAX_BYTES,
            'upgrade_evidence_shared_allowance')
    began=clock();sink=None;receipts=[];timings={};cardinality={}
    def checkpoint(index,label):
        require(clock()-began<120,'upgrade_evidence_deadline');started=clock()
        receipt=authorize(index,label,max(.001,120-(clock()-began)))
        timings['authority_'+str(index)]=round((clock()-started)*1000)
        require(type(receipt) is dict,'upgrade_evidence_receipt');receipts.append(receipt)
        require(clock()-began<120,'upgrade_evidence_deadline')
    def phase(label,operation):
        start=clock()
        try:return operation()
        finally:timings[label]=round((clock()-start)*1000)
    try:
        checkpoint(1,'capture_staging_and_historical_registration_inputs')
        sink=EvidenceSink(factory.create_capture(packet));sink.bytes=572262
        for name,raw in sources.items():sink.write(name,raw)
        sink.json('proposal.json',packet);factory.staged(sink.store)
        sink.json('root-provenance.json',factory.root_provenance)
        reader,windows,binding,plugins=retained_observers(packet,factory,sink)
        checkpoint(2,'actual_target_and_sibling_inventories')
        roles=['installed_027','installed_0210',*factory.sibling_roles(plugins,binding)]
        for index,role in enumerate(roles):
            value=phase('layout_'+str(index),lambda role=role:discover_layout(reader,role))
            sink.json('layout-'+str(index)+'.json',value);cardinality[role]=len(value['files'])
        checkpoint(3,'managed_refs_and_staging')
        try:sink.json('managed-state.json',phase('managed_refs',lambda:reader.managed_state('managed')))
        finally:
            primary=sys.exception()
            try:
                for index,record in enumerate(reader.ref_reads):
                    sink.write('managed-ref-'+str(index)+'.bin',base64.b64decode(record['raw_base64'],validate=True))
                    sink.json('managed-ref-'+str(index)+'.json',{k:v for k,v in record.items() if k!='raw_base64'})
            except Exception:
                if primary is None:raise
                primary.evidence_capture_uncertain=True
                primary.add_note('upgrade_ref_evidence_uncertain; primary preserved')
        sink.json('staging-metadata.json',phase('staging',lambda:factory.staging(binding)))
        checkpoint(4,'loaded_runtime_closure_proposals')
        try:runtime=phase('runtime',lambda:windows.runtime(None,discovery_roots=packet['runtime_discovery_roots']))
        finally:
            primary=sys.exception()
            try:
                for attr,name in [('last_runtime_evidence','runtime-raw-prefix.json'),
                                  ('runtime_raw_passes','runtime-origin-passes.json'),
                                  ('runtime_proposed_pins','runtime-proposed-pins.json')]:
                    if hasattr(windows,attr):sink.json(name,getattr(windows,attr))
            except Exception:
                if primary is None:raise
                primary.evidence_capture_uncertain=True
                primary.add_note('upgrade_runtime_evidence_uncertain; primary preserved')
        sink.json('runtime-proposal.json',runtime)
        checkpoint(5,'one_process_origin_coverage_observation')
        try:processes=phase('process_query',lambda:windows.processes(packet['known_process_image_pins'],discovery=True))
        finally:
            primary=sys.exception()
            try:
                if hasattr(windows,'last_process_evidence'):sink.json('process-raw-prefix.json',windows.last_process_evidence)
            except Exception:
                if primary is None:raise
                primary.evidence_capture_uncertain=True
                primary.add_note('upgrade_process_evidence_uncertain; primary preserved')
        sink.json('process-proposal.json',processes)
        require(processes['status']=='PROPOSED_NOT_ADMISSION' and processes['snapshot']['complete'] is True,
                'upgrade_process_coverage_unavailable')
        checkpoint(6,'proposals_only_no_mutation_or_successor')
        checkpoint(7,'terminal_upgrade_evidence')
        outcome={'scope':SCOPE,'status':'PROPOSED_NOT_ADMISSION','native_admission':False,
            'cli_queries':0,'process_queries':1,'current_mutation_preimages':False,
            'timings_ms':timings,'layout_cardinality':cardinality,'total_ms':round((clock()-began)*1000),
            'frozen_prior_bytes':572262,'historical_child_settlement':'UNVERIFIED'}
        sink.json('authority-receipts.json',receipts);sink.finish(outcome);return outcome
    except Exception as primary:
        if sink is not None:
            try:
                sink.json('authority-receipts.json',receipts,terminal=True)
                sink.finish({'scope':SCOPE,'status':'STOP_NO_RETRY','native_admission':False,
                    'primary_error_type':type(primary).__name__,'failure':safe_failure_summary(primary),
                    'timings_ms':timings,'layout_cardinality':cardinality,'total_ms':round((clock()-began)*1000)})
            except Exception:
                primary.evidence_capture_uncertain=True;primary.add_note('upgrade_terminal_evidence_uncertain; no replay')
        raise
