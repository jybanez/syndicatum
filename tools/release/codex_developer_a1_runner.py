"""A1-only runner; trusted Codex host supplies fresh canonical owner authorization.
No CLI main, worker, storage, lifecycle, listener or install call is present.
"""
import base64, hashlib, json, os, re, secrets, stat, subprocess, threading, time
from pathlib import Path

CAPTURE_ID='developer-a1-5eb6ed67761e450288a3da8968d93f72'

REQUIRED_SOURCES=frozenset(['codex_developer_codex_entry.mjs', 'codex_developer_agent_host.mjs', 'codex_developer_host_assembly.py', 'codex_developer_host_handoff.py', 'codex_developer_integrated_preparation.py', 'codex_developer_transport.py', 'codex_developer_process_ipc.py', 'codex_developer_fixed_journal.py', 'codex_developer_native_b.py', 'codex_developer_freshness.py', 'codex-developer-fixed-session-worker.ps1', 'codex_developer_lifecycle.py', 'codex_pilot_sequence.py', 'codex_developer_install_collector.py', 'codex_developer_metadata_producer.py', 'codex_developer_windows_observer.py', 'codex-developer-fixed-session-protocol.ps1', 'codex-developer-fixed-storage.psm1', 'codex-developer-native-b.psm1', 'codex_developer_a1_module_probe.py', 'codex_developer_a1_module_probe.mjs', 'codex-developer-a1-module-probe.ps1', 'codex_developer_a1_runner.py', 'codex-developer-a1-admission.md'])

def sha(raw):return hashlib.sha256(raw).hexdigest()
def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':')).encode()
def require(value,code):
    if not value:raise ValueError(code)

def check_nonreparse_chain(path,metadata=None,native_attributes=None):
    """Check direct Windows attributes as well as lstat for all reparse tags."""
    path=Path(path)
    get_attributes=native_attributes
    if get_attributes is None and metadata is None and os.name=='nt':
        import ctypes as c
        from ctypes import wintypes as w
        get_attributes=c.WinDLL('kernel32',use_last_error=True).GetFileAttributesW
        get_attributes.argtypes=[w.LPCWSTR];get_attributes.restype=w.DWORD
    chain=[path,*path.parents];require(len(chain)<=64,'ancestor_bound')
    for item in chain:
        info=metadata(item) if metadata else item.lstat()
        flags=getattr(info,'st_file_attributes',0)
        if get_attributes is not None:
            native=get_attributes(str(item))
            require(native!=0xFFFFFFFF,'windows_attributes_unavailable')
            flags|=native
        require(not (flags&0x400) and not stat.S_ISLNK(info.st_mode),'windows_reparse_point')


def windows_sid(path=None):
    """Read current token SID or one directory OWNER SID; close all owned handles."""
    import ctypes as c
    from ctypes import wintypes as w
    require(os.name=='nt','windows_identity_required')
    adv=c.WinDLL('advapi32',use_last_error=True);kernel=c.WinDLL('kernel32',use_last_error=True)
    adv.ConvertSidToStringSidW.argtypes=[c.c_void_p,c.POINTER(w.LPWSTR)];adv.ConvertSidToStringSidW.restype=w.BOOL
    kernel.LocalFree.argtypes=[c.c_void_p];kernel.LocalFree.restype=c.c_void_p
    descriptor=c.c_void_p();token=w.HANDLE();text=w.LPWSTR()
    try:
        if path is not None:
            adv.GetNamedSecurityInfoW.argtypes=[w.LPCWSTR,w.DWORD,w.DWORD,c.POINTER(c.c_void_p),c.c_void_p,c.c_void_p,c.c_void_p,c.POINTER(c.c_void_p)]
            adv.GetNamedSecurityInfoW.restype=w.DWORD
            owner=c.c_void_p()
            require(adv.GetNamedSecurityInfoW(str(path),1,1,c.byref(owner),None,None,None,c.byref(descriptor))==0,'parent_owner_metadata_unavailable')
            sid=owner
        else:
            kernel.GetCurrentProcess.restype=w.HANDLE
            adv.OpenProcessToken.argtypes=[w.HANDLE,w.DWORD,c.POINTER(w.HANDLE)];adv.OpenProcessToken.restype=w.BOOL
            adv.GetTokenInformation.argtypes=[w.HANDLE,w.DWORD,c.c_void_p,w.DWORD,c.POINTER(w.DWORD)];adv.GetTokenInformation.restype=w.BOOL
            kernel.CloseHandle.argtypes=[w.HANDLE];kernel.CloseHandle.restype=w.BOOL
            require(adv.OpenProcessToken(kernel.GetCurrentProcess(),8,c.byref(token)),'token_query_unavailable')
            size=w.DWORD();adv.GetTokenInformation(token,1,None,0,c.byref(size))
            require(0<size.value<=65536,'token_metadata_bound')
            buffer=c.create_string_buffer(size.value)
            require(adv.GetTokenInformation(token,1,buffer,size,c.byref(size)),'token_identity_unavailable')
            sid=c.cast(buffer,c.POINTER(c.c_void_p))[0]
        require(adv.ConvertSidToStringSidW(sid,c.byref(text)),'sid_conversion_unavailable')
        return text.value
    finally:
        if text:kernel.LocalFree(c.cast(text,c.c_void_p))
        if descriptor:kernel.LocalFree(descriptor)
        if token:kernel.CloseHandle(token)

def validate_environment(packet,identity=None,owner=None):
    """One read-only admission/execution preflight. No create, command or refresh."""
    parent=Path(packet['capture_parent'])
    require(parent.is_absolute() and parent.is_dir() and '..' not in parent.parts,'capture_parent')
    if os.name=='nt':require(len(parent.drive)==2 and parent.drive[1]==':','local_capture_drive')
    require(not str(parent).lower().startswith('c:\\wamp64\\www') and '.syndicatum' not in [x.lower() for x in parent.parts],'capture_location')
    check_nonreparse_chain(parent)
    require(packet['capture_identity']==CAPTURE_ID,'capture_identity')
    target=parent/CAPTURE_ID
    require(not target.exists() and not target.is_symlink() and not target.is_junction(),'capture_occupied')
    if os.name=='nt' or identity is not None:
        sid=(identity or windows_sid)()
        require(packet['owner_sid']==sid,'current_owner_sid_drift')
        parent_sid=(owner or windows_sid)(parent)
        require(parent_sid in (sid,'S-1-5-18'),'capture_parent_owner')
    require(set(packet['executables'])=={'cli','python','node','powershell'},'runtime_roles')
    for row in packet['executables'].values():
        path=Path(row['path'])
        require(path.is_absolute() and '..' not in path.parts,'runtime_path')
        if os.name=='nt':require(len(path.drive)==2 and path.drive[1]==':','local_runtime_drive')
        require(path.is_file(),'runtime_file')
        check_nonreparse_chain(path)
        require(path.stat().st_size<=536870912 and sha(path.read_bytes())==row['sha256'],'runtime_pin')
    return {'runtime_pins':4,'parent_constraints':True,'owner_checked':os.name=='nt' or identity is not None,'target_absent':True}

def staged_origin_matches(observed,expected,expected_bytes,uri=False):
    """Only two known staged spellings; never open a reported outside path."""
    if type(observed) is not str:return False
    expected=Path(expected)
    check_nonreparse_chain(expected)
    resolved=expected.resolve(strict=True)
    check_nonreparse_chain(resolved)
    require(expected.is_file() and resolved.is_file() and expected.samefile(resolved),'staged_origin_identity')
    require(expected.stat().st_size<=1048576 and expected.read_bytes()==expected_bytes,'staged_origin_bytes')
    allowed={expected.as_uri(),resolved.as_uri()} if uri else {str(expected),str(resolved)}
    return observed in allowed

class NativeHost:
    """Windows-only native footprint. No process on object construction."""
    def __init__(self):self.children=[]
    def create_capture(self,parent,sid):
        # CreateDirectory security is applied atomically, never an open-ACL window.
        import ctypes as c
        from ctypes import wintypes as w
        require(os.name=='nt' and re.fullmatch(r'S-1-5-21-(?:[0-9]+-){2}[0-9]+-[0-9]+',sid),'capture_sid')
        base=Path(parent)
        require(base.is_absolute() and base.is_dir(),'capture_parent')
        check_nonreparse_chain(base)
        require(not str(base).lower().startswith('c:\\wamp64\\www') and '.syndicatum' not in [x.lower() for x in base.parts],'capture_location')
        adv=c.WinDLL('advapi32',use_last_error=True);kernel=c.WinDLL('kernel32',use_last_error=True)
        adv.ConvertStringSecurityDescriptorToSecurityDescriptorW.argtypes=[w.LPCWSTR,w.DWORD,c.POINTER(c.c_void_p),c.POINTER(w.ULONG)]
        adv.ConvertStringSecurityDescriptorToSecurityDescriptorW.restype=w.BOOL
        class SA(c.Structure):_fields_=[('length',w.DWORD),('descriptor',c.c_void_p),('inherit',w.BOOL)]
        kernel.CreateDirectoryW.argtypes=[w.LPCWSTR,c.POINTER(SA)];kernel.CreateDirectoryW.restype=w.BOOL
        kernel.LocalFree.argtypes=[c.c_void_p];kernel.LocalFree.restype=c.c_void_p
        descriptor=c.c_void_p()
        sddl='O:'+sid+'D:P(A;OICI;FA;;;'+sid+')(A;OICI;FA;;;SY)'
        require(adv.ConvertStringSecurityDescriptorToSecurityDescriptorW(sddl,1,c.byref(descriptor),None),'capture_descriptor')
        try:
            target=base/CAPTURE_ID
            require(kernel.CreateDirectoryW(str(target),c.byref(SA(c.sizeof(SA),descriptor,False))),'capture_occupied_or_create_failed')
        finally:kernel.LocalFree(descriptor)
        return target
    def command(self,executable,args,cwd,stdin,limit):
        child=subprocess.Popen([executable,*args],executable=executable,cwd=cwd,
            stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
            shell=False,close_fds=True,creationflags=subprocess.CREATE_NO_WINDOW)
        identity={'pid':child.pid,'requested_executable':executable,'parent_observed_after_spawn_monotonic_ns':time.monotonic_ns(),'os_image_verified':False,'os_creation_time_verified':False}
        self.children.append(identity)
        data={'out':bytearray(),'err':bytearray()};errors=[]
        def drain(stream,key,bound):
            try:
                while True:
                    raw=stream.read(4096)
                    if not raw:break
                    if len(data[key])+len(raw)>bound:raise ValueError('output_bound')
                    data[key].extend(raw)
            except Exception as e:errors.append(e)
        threads=[threading.Thread(target=drain,args=(child.stdout,'out',limit),daemon=True),threading.Thread(target=drain,args=(child.stderr,'err',65536),daemon=True)]
        for t in threads:t.start()
        # Input is <=61 bytes; async write makes deadline cover EOF delivery too.
        def feed():
            try:child.stdin.write(stdin);child.stdin.close()
            except Exception as e:errors.append(e)
        writer=threading.Thread(target=feed,daemon=True);writer.start()
        deadline=time.monotonic()+30
        try:
            child.wait(timeout=max(0,deadline-time.monotonic()))
            for t in [writer,*threads]:t.join(max(0,deadline-time.monotonic()))
            require(not any(t.is_alive() for t in [writer,*threads]),'pipe_deadline')
            if errors:raise errors[0]
            require(child.returncode==0,'command_nonzero')
            return {'identity':identity,'stdout':bytes(data['out']),'stderr':bytes(data['err']),'exit':child.returncode}
        except Exception as error:
            error.child_identity=identity
            error.bounded_stdout=bytes(data['out']);error.bounded_stderr=bytes(data['err'])
            error.stable_postimage='unavailable_until_independently_quiescent'
            # Do not kill, retry, inspect shared artifacts or launch a successor.
            raise

def run(packet,source_bytes,authorize,host=None):
    """authorize is a trusted host capability, NEVER a packet-supplied callback.
    It must fetch exact protected-profile canonical request/owner28 positive event,
    check revision/deletion/time/scope/digest and return only on fresh authority.
    This is cooperating-host trust, not a cryptographic approval-file mechanism.
    """
    host=host or NativeHost();used=False;capture=None;records=[]
    packet_raw=canonical(packet);binding=sha(packet_raw)
    require(packet['capture_identity']==CAPTURE_ID and packet['scope']=='Developer A1 only','a1_scope')
    require(set(packet['executables'])=={'cli','python','node','powershell'},'runtime_roles')
    require(packet['native_effects_accepted'] is True,'native_effects_not_accepted')
    authorize(binding,'Developer A1 only') # Before filesystem read, create or process.
    try:
        require(not used,'a1_no_replay');used=True
        require(set(packet['sources'])==set(source_bytes)==REQUIRED_SOURCES,'complete_a1_source_set')
        for name,row in packet['sources'].items():
            require(re.fullmatch(r'[A-Za-z0-9_.-]+',name) and sha(source_bytes[name])==row['sha256'] and len(source_bytes[name])==row['size'],'source_bytes')
        require(sha(source_bytes['codex-developer-a1-admission.md'])==packet['scope_document_sha256'],'scope_document_binding')
        validate_environment(packet)
        capture=host.create_capture(packet['capture_parent'],packet['owner_sid'])
        # Verified Git blob bytes are materialized, never copied from autocrlf checkout.
        require(len(source_bytes)<=24 and sum(map(len,source_bytes.values()))<=16777216,'capture_bounds')
        for name,raw in source_bytes.items():
            with (capture/name).open('xb') as f:f.write(raw);f.flush();os.fsync(f.fileno())
            require((capture/name).read_bytes()==raw,'source_readback')
        nonce=secrets.token_hex(24);frame=canonical({'nonce':nonce})+b'\n'
        commands=[('cli',['--version']),('cli',['plugin','marketplace','list','--json']),('cli',['plugin','list','--json']),
            ('python',['-I','-B',str(capture/'codex_developer_a1_module_probe.py'),'--a1-nonce',nonce]),
            ('node',[str(capture/'codex_developer_a1_module_probe.mjs'),'--a1-nonce',nonce]),
            ('powershell',['-NoLogo','-NoProfile','-NonInteractive','-File',str(capture/'codex-developer-a1-module-probe.ps1'),'-A1Nonce',nonce])]
        for i,(role,args) in enumerate(commands):
            authorize(binding,'Developer A1 only') # Fresh immediate admission per process.
            result=host.command(packet['executables'][role]['path'],args,str(capture),frame if i>=3 else b'',1048576 if i<3 else 65536)
            record={'index':i,'role':role,'argv':args,'identity':result['identity'],'exit':result['exit'],
                'stdout_base64':base64.b64encode(result['stdout']).decode(),'stderr_base64':base64.b64encode(result['stderr']).decode()}
            records.append(record)
            raw=canonical(record)
            with (capture/('result-%d.json'%i)).open('xb') as f:f.write(raw);f.flush();os.fsync(f.fileno())
            if i==0:require(result['stdout'].decode().strip()=='codex-cli 0.164.0-alpha.11.1','cli_version')
            else:
                def unique(pairs):
                    d={}
                    for k,v in pairs:
                        require(k not in d,'duplicate_output_key');d[k]=v
                    return d
                value=json.loads(result['stdout'],object_pairs_hook=unique)
                require(type(value) is dict,'output_schema')
                if i==1:
                    require(type(value.get('marketplaces')) is list,'marketplace_schema')
                    targets=[x for x in value['marketplaces'] if x.get('name')=='syndicatum']
                    require(len(targets)==1 and type(targets[0].get('root')) is str and targets[0].get('marketplaceSource') is not None,'target_mapping_absent_or_ambiguous')
                if i==2:require(type(value.get('installed')) is list and type(value.get('available')) is list,'plugin_schema')
                if i>=3:
                    require(value.get('nonce')==nonce,'probe_nonce')
                    if i==3:
                        names=('codex_developer_host_assembly','codex_developer_install_collector','codex_developer_process_ipc')
                        expected={n:str((capture/(n+'.py')).resolve()) for n in names}
                        require(set(value)=={'nonce','modules'} and value['modules']==expected,'python_module_origins')
                    elif i==4:
                        declared=source_bytes['codex_developer_codex_entry.mjs'].decode().split('Object.freeze([',1)[1].split(']);',1)[0]
                        expected=re.findall(r"'([^']+)'",declared)
                        require(set(value)=={'nonce','source_files','entry_url'} and value['source_files']==expected and staged_origin_matches(value['entry_url'],capture/'codex_developer_codex_entry.mjs',source_bytes['codex_developer_codex_entry.mjs'],uri=True),'node_module_origin_or_sources')
                    else:
                        require(set(value)=={'nonce','module','protocol_origin'} and staged_origin_matches(value['module'],capture/'codex-developer-fixed-storage.psm1',source_bytes['codex-developer-fixed-storage.psm1']) and staged_origin_matches(value['protocol_origin'],capture/'codex-developer-fixed-session-protocol.ps1',source_bytes['codex-developer-fixed-session-protocol.ps1']),'powershell_module_origins')
        return {'packet_sha256':binding,'capture_identity':CAPTURE_ID,'records':records,'status':'observations_only_not_install_admission'}
    except Exception as primary:
        if capture is not None:
            try:
                failure={'error_type':type(primary).__name__,'error':str(primary),'child':getattr(primary,'child_identity',None),'completed':len(records),'packet_sha256':binding,'stdout_base64':base64.b64encode(getattr(primary,'bounded_stdout',b'')).decode(),'stderr_base64':base64.b64encode(getattr(primary,'bounded_stderr',b'')).decode()}
                with (capture/'STOP.json').open('xb') as f:f.write(canonical(failure));f.flush();os.fsync(f.fileno())
            except Exception:primary.add_note('stop_capture_unavailable; primary preserved')
        raise

if __name__=='__main__':raise RuntimeError('Use trusted host canonical A1 authorization; no standalone launch')
