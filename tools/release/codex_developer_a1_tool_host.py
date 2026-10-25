"""Trusted Codex tool-host bridge, separate from accepted A1 runner/packet.
PTY transports bounded ASCII chunks. Parent invokes exact protected tools; received
bytes are cooperating-host evidence, never packet-supplied authority. Inert mode
never loads runner/probes, reads proposal, creates capture or launches a child.
"""
import argparse,base64,ctypes,hashlib,json,os,queue,re,secrets,subprocess,sys,threading,time,types
from pathlib import Path
PROFILE='1f397680dd1cddc0.2.33'
HEAD='8bc1e5ac51fa8ef0323e0a9432ccf8627ed2cbb5'
PACKET_SHA='51a21324b2d7e701aff04bac049fa5022fd7cbfed1084902977ccea0d6218845'
CHUNK=768;MAX_RAW=65536;MAX_LINE=1024;DEADLINE=90

def canonical(v):return json.dumps(v,sort_keys=True,separators=(',',':')).encode()
def digest(v):return hashlib.sha256(v).hexdigest()
def check(v,code):
    if not v:raise ValueError(code)
def unique(pairs):
    out={}
    for k,v in pairs:check(k not in out,'duplicate_key');out[k]=v
    return out

def encode_response(value,session,sequence):
    check(re.fullmatch('[a-f0-9]{48}',session) and type(sequence) is int and sequence>0,'frame_identity')
    raw=canonical(value);check(len(raw)<=MAX_RAW,'response_bound')
    encoded=base64.b64encode(raw).decode();parts=[encoded[i:i+CHUNK] for i in range(0,len(encoded),CHUNK)]
    lines=['A1R %s %d %d %d %s'%(session,sequence,i+1,len(parts),p) for i,p in enumerate(parts)]
    lines.append('A1E %s %d %s'%(session,sequence,digest(raw)))
    return ('\n'.join(lines)+'\n').encode()

def read_response(stream,session,sequence):
    # One absolute deadline covers ALL chunks + end marker. No reconnect/retry.
    result=queue.Queue(maxsize=1)
    def consume():
        try:
            parts=[];total=None
            for index in range(1,129):
                line=stream.readline(MAX_LINE+1)
                check(line and len(line)<=MAX_LINE and line.endswith(b'\n'),'response_eof_or_line_bound')
                text=line.removesuffix(b'\n').removesuffix(b'\r').decode('ascii')
                fields=text.split(' ')
                check(len(fields)==6 and fields[:2]==['A1R',session] and fields[2]==str(sequence) and fields[3]==str(index),'response_chunk_context')
                n=int(fields[4]);check(1<=n<=128 and (total is None or total==n),'response_chunk_count');total=n
                check(re.fullmatch('[A-Za-z0-9+/=]{1,768}',fields[5]),'response_chunk_encoding');parts.append(fields[5])
                if index==total:break
            line=stream.readline(MAX_LINE+1)
            check(line and len(line)<=MAX_LINE and line.endswith(b'\n'),'response_end_eof')
            end=line.removesuffix(b'\n').removesuffix(b'\r').decode('ascii').split(' ')
            raw=base64.b64decode(''.join(parts),validate=True)
            check(len(raw)<=MAX_RAW and end==['A1E',session,str(sequence),digest(raw)],'response_end_digest')
            value=json.loads(raw,object_pairs_hook=unique);check(canonical(value)==raw,'response_canonical')
            result.put((True,value))
        except Exception as error:result.put((False,error))
    threading.Thread(target=consume,daemon=True).start()
    try:ok,value=result.get(timeout=DEADLINE)
    except queue.Empty:raise TimeoutError('authorization_transport_deadline')
    if not ok:raise value
    return value

def parse_request_line(line,session,sequence):
    # Parent ignores every other line, including echoed A1R/A1E and ANSI noise.
    # Never strip controls into a valid marker. Marker must begin a clean line.
    if not line.startswith('A1Q '):return None
    value=json.loads(line[4:],object_pairs_hook=unique)
    check(value['session']==session and value['sequence']==sequence and value['operation']=='protected_authority','request_identity')
    return value

def existing_proposal(folder_lookup=None):
    if folder_lookup is None:
        check(os.name=='nt','windows_documents_required')
        def folder_lookup():
            buffer=ctypes.create_unicode_buffer(32768)
            check(ctypes.windll.shell32.SHGetFolderPathW(None,5,None,0,buffer)==0,'documents_resolution')
            return buffer.value
    parent=Path(folder_lookup());check(parent.is_absolute() and parent.is_dir(),'documents_parent')
    return parent/'Syndicatum-A1-proposed-owner-packet-140ab028ea4e4f00821e07a46b8f8260.json'

def echo_control():
    # Best effort console echo suppression. Framing remains safe with echo ON.
    if os.name!='nt':return lambda:None
    import msvcrt
    from ctypes import wintypes as w
    kernel=ctypes.WinDLL('kernel32',use_last_error=True);mode=w.DWORD()
    kernel.GetConsoleMode.argtypes=[w.HANDLE,ctypes.POINTER(w.DWORD)];kernel.SetConsoleMode.argtypes=[w.HANDLE,w.DWORD]
    handle=msvcrt.get_osfhandle(sys.stdin.fileno())
    if not kernel.GetConsoleMode(handle,ctypes.byref(mode)):return lambda:None
    if not kernel.SetConsoleMode(handle,mode.value & ~4):return lambda:None
    return lambda:kernel.SetConsoleMode(handle,mode.value)

def validate_authority(value,session,sequence,request_id,approval_id,wrapper_sha,parent_sha):
    check(value['session']==session and value['sequence']==sequence,'authority_context')
    check(0<=time.time_ns()//1000000-value['host_observed_ms']<=120000,'authority_freshness')
    req=value['request'];approval=value['approval']
    check(req['profile']['profile_id']==approval['profile']['profile_id']==PROFILE,'authority_profile')
    r=req['message'];e=approval['message']
    check(r['id']==request_id and r['project_id']==2 and r['deleted_at'] is None and r['revision_count']==0 and r['sender']['participant_id']==36,'canonical_request')
    check(r['action_requested'] is True and r['action_request_type']=='approval' and all(x in r['body'] for x in (HEAD,PACKET_SHA,wrapper_sha,parent_sha)),'request_scope_pins')
    check(any(x['participant_id']==28 for x in r['addressees']),'owner_addressee')
    check(e['id']==approval_id and e['project_id']==2 and e['deleted_at'] is None and e['revision_count']==0 and e['sender']['participant_id']==28,'canonical_owner')
    check(e['system_event']['type']=='responsibility.approval_approved','canonical_positive_event')
    d=e['system_event']['data'];check(d['request_message_id']==request_id and d['actor_participant_id']==28 and d['kind']=='approval_approved','owner_request_binding')

class GitBlobReader:
    """At most23 source reads:10s each and60s aggregate, no replay/kill."""
    def __init__(self,clock=time.monotonic):
        self.clock=clock;self.deadline=clock()+60;self.stopped=False;self.calls=0;self.bytes=0
    def read(self,path):
        check(not self.stopped,'source_reader_terminal')
        child=None
        try:
            remaining=self.deadline-self.clock()
            check(remaining>0 and self.calls<23,'source_acquisition_deadline_or_count')
            self.calls+=1
            child=subprocess.Popen(['git','show',HEAD+':'+path],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,shell=False,close_fds=True,**({'creationflags':subprocess.CREATE_NO_WINDOW} if os.name=='nt' else {}))
            # communicate(timeout) does NOT kill or reattach a timed-out child.
            raw,stderr=child.communicate(timeout=min(10,remaining))
            check(child.returncode==0,'source_acquisition_nonzero')
            check(len(raw)<=1048576 and len(stderr)<=65536,'source_acquisition_output_bound')
            self.bytes+=len(raw);check(self.bytes<=16777216 and self.clock()<=self.deadline,'source_acquisition_total_bound')
            return raw
        except Exception as primary:
            self.stopped=True
            if child is not None:primary.host_git_pid=child.pid
            # Preserve original TimeoutExpired/IO error; no kill, retry, successor.
            raise

def marker_tag(session,request_id,approval_id,mode):
    return digest(('%s|%s|%s|%s'%(session,request_id,approval_id,mode)).encode())[:12]
def challenge_marker(tag,sequence):return '!Q%s:%d!'%(tag,sequence)
def outcome_marker(tag,ok):return '!O%s:%s!'%(tag,'OK' if ok else 'STOP')

def main(argv=None):
    parser=argparse.ArgumentParser();parser.add_argument('--mode',choices=('inert','run'),required=True);parser.add_argument('--session',required=True);parser.add_argument('--request-id',type=int);parser.add_argument('--approval-id',type=int);parser.add_argument('--wrapper-sha');parser.add_argument('--parent-sha');parser.add_argument('--inert-count',type=int,default=1);parser.add_argument('--inert-short-at',type=int,default=0);args=parser.parse_args(argv)
    check(re.fullmatch('[a-f0-9]{48}',args.session),'host_session')
    restore=echo_control()
    try:
        tag=marker_tag(args.session,args.request_id,args.approval_id,args.mode)
        if args.mode=='inert':
            check(1<=args.inert_count<=7 and 0<=args.inert_short_at<=7,'inert_checkpoint_bounds')
            completed=0
            try:
                for sequence in range(1,args.inert_count+1):
                    # Long inert display noise deliberately wraps in a narrow PTY.
                    print('inert-display-no-authority:'+('x'*150),flush=True)
                    print('\n'+challenge_marker(tag,sequence),flush=True)
                    global DEADLINE
                    DEADLINE=2 if sequence==args.inert_short_at else 90
                    value=read_response(sys.stdin.buffer.raw,args.session,sequence)
                    check(value['session']==args.session and value['sequence']==sequence and type(value.get('request')) is dict and type(value.get('approval')) is dict,'inert_actual_shape')
                    completed+=1
                print('\n'+outcome_marker(tag,True),flush=True)
                print(canonical({'mode':'inert','status':'transport_ok','completed':completed}).decode(),flush=True)
            except Exception as error:
                print('\n'+outcome_marker(tag,False),flush=True)
                print(canonical({'mode':'inert','status':'STOP_NO_RETRY','completed':completed,'error_type':type(error).__name__}).decode(),flush=True)
            return
        check(args.request_id and args.approval_id and re.fullmatch('[a-f0-9]{64}',args.wrapper_sha or '') and re.fullmatch('[a-f0-9]{64}',args.parent_sha or ''),'new_owner_binding_required')
        check(args.request_id not in (11683,11697,11704) and args.approval_id not in (11684,11698,11705),'stopped_authority_no_replay')
        reader=GitBlobReader();blob=reader.read
        raw=existing_proposal().read_bytes();check(len(raw)==3718 and digest(raw)==PACKET_SHA,'existing_proposal_drift')
        packet=json.loads(raw);check(canonical(packet)==raw,'existing_proposal_canonical')
        review=json.loads(blob('docs/codex-developer-pilot-admission-packet.json'))['next_A1_decision_draft'];source={}
        for row in review['complete_sources_and_scope']:
            b=blob(row['repository_path']);name=Path(row['repository_path']).name
            check(row['git_blob_bytes_sha256']==digest(b) and row['size']==len(b) and packet['sources'][name]=={'sha256':digest(b),'size':len(b)},'reviewed_source_pin');source[name]=b
        target=Path(packet['capture_parent'])/packet['capture_identity'];check(not target.exists() and not target.is_symlink() and not target.is_junction(),'capture_occupied')
        module=types.ModuleType('reviewed_a1_runner');exec(compile(source['codex_developer_a1_runner.py'],'reviewed_git_blob_a1_runner','exec'),module.__dict__)
        sequence=0;receipts=[]
        def authorize(binding,scope):
            nonlocal sequence
            check(binding==PACKET_SHA and scope=='Developer A1 only','host_scope');sequence+=1
            print('\n'+challenge_marker(tag,sequence),flush=True)
            value=read_response(sys.stdin.buffer.raw,args.session,sequence)
            validate_authority(value,args.session,sequence,args.request_id,args.approval_id,args.wrapper_sha,args.parent_sha);receipts.append(value)
        outcome={}
        try:
            result=module.run(packet,source,authorize)
            outcome={'status':result['status'],'completed_commands':len(result['records'])}
        except Exception as error:
            outcome={'status':'STOP_NO_RETRY','error_type':type(error).__name__,'authorization_checks':sequence}
            if isinstance(error,ValueError) and str(error).replace('_','').isalnum():outcome['reason']=str(error)
        finally:
            if target.is_dir():
                try:
                    with (target/'owner-authority-and-outcome.json').open('xb') as f:
                        f.write(canonical({'receipts':receipts,'outcome':outcome,'wrapper_sha256':args.wrapper_sha,'parent_sha256':args.parent_sha}));f.flush();os.fsync(f.fileno())
                except Exception:outcome['authority_capture']='unavailable_no_retry'
            print('\n'+outcome_marker(tag,outcome.get('status')=='observations_only_not_install_admission'),flush=True)
            print(canonical({'mode':'run','outcome':outcome}).decode(),flush=True)
    finally:restore()
if __name__=='__main__':main()
