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

SCOPE='Developer SIX-EXACT-FILE-ONLY'
CAPTURE_ID='developer-0210-six-image-verification-11792-v1'
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

EXPECTED_PATH_HASHES=frozenset((
 '0081d95a88c78e11a01f1da46542f8ee7df73588338e1d5e5737f0a1129307ef',
 '07442caeec7e9ea8cff838f97a11f3c721e8b344d288c4848a8bbaa7470fb229',
 '53dadc40bb276161fbb4864750877d9641ebe8a5dee5ce338d2554171eebc6d1',
 '61d668c179a189c16f04dcbcbd1c68b4bead013a568e43af55b2d12a6582fea3',
 '9fc98d629f2c0874ab3eb8d89d492417a2a0b86a41510a7bb810f62ffca18290',
 'de1367d8be37a3d3d16817f55cfaa4d17547b4956ea7823172570fb696c48dd2'))
MAX_FILE=134217728
MAX_EXTRA=181804680

def public_preflight(error):
    value=getattr(error,'six_preflight',None)
    if type(value) is not dict:return {'status':'UNAVAILABLE'}
    return value # constructed solely from bounded code/numeric/hash fields below

def metadata_preflight(packet,helpers,guard):
    rows=packet['six_files'];require(len(rows)==6 and
        {r['path_sha256'] for r in rows}==EXPECTED_PATH_HASHES,'six_exact_scope')
    require(packet['known_image_pin_bytes']==355066232,'six_known_image_budget')
    observations=[];identities=set()
    try:
        for row in rows:
            guard();path=Path(row['path']);require(sha(str(path).encode())==row['path_sha256'],'six_path_binding')
            helpers.check_nonreparse_chain(path);st=path.stat()
            import stat
            require(stat.S_ISREG(st.st_mode) and st.st_size>=0,'six_regular_file')
            require((st.st_dev,st.st_ino) not in identities,'six_alias_identity')
            identities.add((st.st_dev,st.st_ino))
            observations.append({'path_sha256':row['path_sha256'],'size':st.st_size,'mtime_ns':st.st_mtime_ns,
                                 'device':st.st_dev,'inode':st.st_ino})
        total=sum(r['size'] for r in observations)
        summary={'status':'FIT' if total<=MAX_EXTRA and all(r['size']<=MAX_FILE for r in observations) else 'SHORTFALL',
            'files':6,'additional_bytes':total,'additional_cap':MAX_EXTRA,
            'aggregate_shortfall_bytes':max(0,total-MAX_EXTRA),'per_file_excess':[
                {'path_sha256':r['path_sha256'],'excess_bytes':r['size']-MAX_FILE} for r in observations if r['size']>MAX_FILE],
            'observed_sizes':[{'path_sha256':r['path_sha256'],'bytes':r['size']} for r in observations],
            'image_content_hashes_started':0}
        if summary['status']!='FIT':
            error=ValueError('six_metadata_capacity_shortfall');error.six_preflight=summary;raise error
        return observations,summary
    except Exception as error:
        if not hasattr(error,'six_preflight'):
            error.six_preflight={'status':'METADATA_UNAVAILABLE','files_completed':len(observations),
                'observed_sizes':[{'path_sha256':r['path_sha256'],'bytes':r['size']} for r in observations],
                'image_content_hashes_started':0}
        raise


def hash_exact(row,meta,helpers,guard,record):
    import os
    path=Path(row['path']);guard();helpers.check_nonreparse_chain(path);before=path.stat()
    require((before.st_size,before.st_mtime_ns,before.st_dev,before.st_ino)==
            (meta['size'],meta['mtime_ns'],meta['device'],meta['inode']),'six_metadata_drift')
    digest=hashlib.sha256();count=0;prefix=bytearray();record.update(status='read_started',received_bytes=0)
    try:
        with path.open('rb') as stream:
            opened=os.fstat(stream.fileno())
            require((opened.st_dev,opened.st_ino)==(before.st_dev,before.st_ino),'six_open_identity')
            while True:
                guard();raw=stream.read(min(65536,meta['size']+1-count))
                if not raw:break
                count+=len(raw);digest.update(raw)
                prefix.extend(raw[:max(0,65536-len(prefix))])
                record.update(received_bytes=count,prefix_base64=base64.b64encode(prefix).decode(),status='bytes_unverified')
                require(count<=meta['size'],'six_read_bound')
        helpers.check_nonreparse_chain(path);after=path.stat()
        require(count==meta['size'] and (before.st_size,before.st_mtime_ns,before.st_dev,before.st_ino)==
                (after.st_size,after.st_mtime_ns,after.st_dev,after.st_ino),'six_postread_drift')
        record.update(status='PROPOSED_NOT_ADMISSION',sha256=digest.hexdigest(),size=count,
                      vendor='UNAVAILABLE; header evidence only, no vendor trust assertion',writer_excluded=False)
    except Exception as error:
        record.update(status='read_failed',error_type=type(error).__name__);raise

def run(packet,sources,authorize,factory,*,clock=time.monotonic):
    require(packet['scope']==SCOPE and packet['capture_identity']==CAPTURE_ID,'six_scope')
    require(callable(authorize) and set(sources)==set(packet['sources']),'six_sources')
    require(sum(len(raw) for raw in sources.values())<=1048576,'six_source_total')
    for name,raw in sources.items():require({'sha256':sha(raw),'size':len(raw)}==packet['sources'][name],'six_source_pin')
    require(packet['shared_frozen_bytes']==905422 and packet['shared_capture_allowance_bytes']==MAX_BYTES,'six_shared_budget')
    began=clock();sink=None;receipts=[];timings={};summary={'status':'UNAVAILABLE'}
    def checkpoint(index,label):
        require(clock()-began<120,'six_deadline');started=clock()
        receipt=authorize(index,label,max(.001,120-(clock()-began)));require(type(receipt) is dict,'six_receipt')
        receipts.append(receipt);timings['authority_'+str(index)]=round((clock()-started)*1000)
        require(clock()-began<120,'six_deadline')
    try:
        checkpoint(1,'all_six_metadata_before_any_image_hash')
        # No capture factory/runtime image hashing or target content read until
        # ALL SIX metadata sizes/identities and aggregate budget have passed.
        metadata,summary=metadata_preflight(packet,factory.helpers,factory.guard)
        checkpoint(2,'capture_only_after_whole_scope_fits')
        sink=EvidenceSink(factory.create_capture(packet));sink.bytes=905422
        for name,raw in sources.items():sink.write(name,raw)
        sink.json('proposal.json',packet);factory.staged(sink.store)
        sink.json('root-provenance.json',factory.root_provenance)
        sink.json('six-metadata.json',{'observations':metadata,'summary':summary})
        for index,row in enumerate(packet['six_files']):
            if index%2==0:checkpoint(3+index//2,'exact_image_pair_'+str(index//2))
            record={'path_sha256':row['path_sha256'],'status':'not_started','prefix_base64':''};started=clock()
            try:hash_exact(row,metadata[index],factory.helpers,factory.guard,record)
            finally:
                primary=sys.exception();timings['image_'+str(index)]=round((clock()-started)*1000)
                try:
                    sink.write('image-'+str(index)+'-prefix.bin',base64.b64decode(record.pop('prefix_base64'),validate=True))
                    sink.json('image-'+str(index)+'.json',record)
                except Exception:
                    if primary is None:raise
                    primary.evidence_capture_uncertain=True;primary.add_note('six_raw_capture_uncertain; primary preserved')
        checkpoint(6,'hash_proposals_are_not_writer_exclusions')
        checkpoint(7,'terminal_six_file_proposal')
        outcome={'scope':SCOPE,'status':'PROPOSED_NOT_ADMISSION','native_admission':False,'files_hashed':6,
            'writer_exclusions':0,'process_queries':0,'cli_queries':0,'preflight':summary,
            'timings_ms':timings,'total_ms':round((clock()-began)*1000),'frozen_prior_bytes':905422}
        sink.json('authority-receipts.json',receipts);sink.finish(outcome);return outcome
    except Exception as primary:
        if hasattr(primary,'six_preflight'):primary.six_preflight['acquisition_elapsed_ms']=round((clock()-began)*1000)
        if sink is not None:
            try:
                sink.json('authority-receipts.json',receipts,terminal=True)
                sink.finish({'scope':SCOPE,'status':'STOP_NO_RETRY','native_admission':False,
                    'primary_error_type':type(primary).__name__,'failure':safe_failure_summary(primary),
                    'preflight':summary,'timings_ms':timings,'total_ms':round((clock()-began)*1000)})
            except Exception:
                primary.evidence_capture_uncertain=True;primary.add_note('six_terminal_capture_uncertain; no replay')
        raise
