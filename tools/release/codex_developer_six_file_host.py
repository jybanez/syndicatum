"""Process-only executable bootstrap for the existing seven-checkpoint parent.
Launch only after source review and a fresh, exact owner acquisition decision.
It has no B/C/D or installation branch. No invocation is authorized by this file.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import types

SCOPE='Developer SIX-EXACT-FILE-ONLY'
NAMES=frozenset(('codex_developer_admission_native.py',
 'codex_developer_six_file_host.py','codex_developer_six_file.py','codex_developer_a1_tool_host.py','codex_developer_a1_runner.py',
 'codex_developer_fixed_journal.py','codex_developer_process_ipc.py','codex_developer_install_collector.py',
 'codex_developer_metadata_producer.py','codex_developer_windows_observer.py'))
def require(value,code):
    if not value:raise ValueError(code)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def packed(value):return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()


def load(name,raw):
    module=types.ModuleType(name);exec(compile(raw,'reviewed_git_blob_'+name,'exec'),module.__dict__)
    return module


def stop_summary(error,core):
    return {'scope':SCOPE,'status':'STOP_NO_RETRY','error_type':type(error).__name__,
            'failure':core.safe_failure_summary(error),'preflight':core.public_preflight(error)}


def main(argv=None):
    parser=argparse.ArgumentParser();parser.add_argument('--six-exact-file-only',action='store_true',required=True)
    for field in ('head','proposal','proposal-sha','entry-sha','parent-sha','session'):parser.add_argument('--'+field,required=True)
    parser.add_argument('--request-id',type=int,required=True);parser.add_argument('--approval-id',type=int,required=True)
    args=parser.parse_args(argv)
    require(re.fullmatch('[a-f0-9]{40}',args.head) and re.fullmatch('[a-f0-9]{48}',args.session) and
            all(re.fullmatch('[a-f0-9]{64}',v) for v in (args.proposal_sha,args.entry_sha,args.parent_sha)),'admission_host_arguments')
    proposal=Path(args.proposal)
    require(os.name=='nt','admission_windows_host')
    import ctypes as c
    documents=c.create_unicode_buffer(32768)
    require(c.windll.shell32.SHGetFolderPathW(None,5,None,0,documents)==0,'admission_documents_lookup')
    require(proposal==Path(documents.value)/'Syndicatum-Developer-six-image-verification-11792-v1.json','admission_proposal_location')
    require(proposal.is_absolute() and proposal.stat().st_size<=65536,'admission_proposal_bound')
    raw=proposal.read_bytes();require(sha(raw)==args.proposal_sha,'admission_proposal_pin')
    packet=json.loads(raw);require(packed(packet)==raw and packet['scope']==SCOPE and packet['source_head']==args.head,
                                  'admission_proposal_scope')
    require(set(packet['sources'])==NAMES,'admission_source_closure')
    source_start=time.monotonic();source_bytes=0;git_calls=0
    def blob(path):
        nonlocal source_bytes,git_calls
        require(time.monotonic()-source_start<60 and git_calls<11,'admission_source_budget')
        git_calls+=1
        child=subprocess.Popen(['git','show',args.head+':'+path],stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,shell=False,close_fds=True,
            **({'creationflags':subprocess.CREATE_NO_WINDOW} if os.name=='nt' else {}))
        # Same bounded/no-kill local Git acquisition semantics as accepted A1.
        data,error=child.communicate(timeout=min(10,max(.001,60-(time.monotonic()-source_start))))
        require(child.returncode==0 and len(data)<=1048576 and len(error)<=65536,'admission_source_result')
        source_bytes+=len(data);require(source_bytes<=2097152 and time.monotonic()-source_start<=60,'admission_source_total')
        return data
    review=json.loads(blob('docs/codex-developer-pilot-admission-packet.json'))['six_file_verification']
    require(review['status']=='SOURCE_REVIEW_ONLY_NOT_AUTHORITY' and {r['name'] for r in review['source_files']}==NAMES,
            'admission_public_source_catalog')
    require(args.parent_sha==review['parent_sha256'] and packet['launcher_sha256']==review['launcher_source']['git_blob_bytes_sha256'],
            'admission_parent_launcher_pins')
    require(all(packet[name]['pin']['sha256']==review['retained_executable_hashes'][name]
                for name in ('python','cli','powershell')),'admission_retained_runtime_pins')
    sources={}
    for row in review['source_files']:
        data=blob(row['repository_path']);require({'sha256':sha(data),'size':len(data)}==packet['sources'][row['name']] and
            sha(data)==row['git_blob_bytes_sha256'] and len(data)==row['size'],'admission_reviewed_source_pin')
        sources[row['name']]=data
    require(sha(sources['codex_developer_six_file_host.py'])==args.entry_sha,'admission_bootstrap_pin')
    bridge=load('admission_framing',sources['codex_developer_a1_tool_host.py'])
    bridge.HEAD=args.head;bridge.PACKET_SHA=args.proposal_sha
    helpers=load('admission_helpers',sources['codex_developer_a1_runner.py'])
    core=load('admission_core',sources['codex_developer_six_file.py'])
    native=load('admission_native',sources['codex_developer_admission_native.py'])
    require(packet['capture_identity']==core.CAPTURE_ID and packet['capture_parent_sha256']==review['existing_parent_path_sha256'] and
            packet['retained_root_map_sha256']==review['retained_root_map_sha256'],'admission_root_scope')
    began=time.monotonic();last_authority=None;sequence=0
    tag=bridge.marker_tag(args.session,args.request_id,args.approval_id,'run')
    def authorize(index,label,remaining):
        nonlocal last_authority,sequence
        require(index==sequence+1 and remaining>0,'admission_checkpoint_order');sequence=index
        print('\n'+bridge.challenge_marker(tag,index),flush=True)
        bridge.DEADLINE=min(90,remaining)
        value=bridge.read_response(sys.stdin.buffer.raw,args.session,index)
        bridge.validate_authority(value,args.session,index,args.request_id,args.approval_id,args.entry_sha,args.parent_sha)
        require(SCOPE in value['request']['message']['body'] and core.CAPTURE_ID in value['request']['message']['body'] and
                packet['launcher_sha256'] in value['request']['message']['body'],
                'admission_canonical_purpose')
        last_authority=time.monotonic();return value
    def guard():
        require(last_authority is not None and time.monotonic()-began<120 and time.monotonic()-last_authority<120,
                'admission_authority_or_deadline')
    def staged(root):
        global __file__
        __file__=str(root/'codex_developer_six_file_host.py')
        for module,name in ((bridge,'codex_developer_a1_tool_host.py'),(helpers,'codex_developer_a1_runner.py'),
                            (core,'codex_developer_six_file.py'),(native,'codex_developer_admission_native.py')):
            module.__file__=str(root/name);sys.modules[module.__name__]=module
    restore=bridge.echo_control()
    try:
        factory=native.NativeFactory(helpers,guard,staged)
        factory.host_overhead={'source_git_children':git_calls,'source_git_bytes':source_bytes,
            'source_preload_ms':round((time.monotonic()-source_start)*1000),
            'tool_shells':1,'python_admission_children':1,'cli_children_max':0,
            'javascript_hash_helpers':0,'git_os_image_verified':False}
        result=core.run(packet,sources,authorize,factory)
        print('\n'+bridge.outcome_marker(tag,True),flush=True)
        print(packed({'scope':SCOPE,'status':result['status'],'native_admission':False}).decode(),flush=True)
    except Exception as error:
        print('\n'+bridge.outcome_marker(tag,False),flush=True)
        print(packed(stop_summary(error,core)).decode(),flush=True)
    finally:restore()


if __name__=='__main__':
    try:main()
    except Exception as error:
        print(packed({'scope':SCOPE,'status':'STOP_NO_RETRY','error_type':type(error).__name__}).decode(),flush=True)
        sys.exit(1)
