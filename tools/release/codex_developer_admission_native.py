"""Capture-only capability owned by the protected-authority admission host.
No worker, installer, listener or activation module is imported.
"""
import ctypes as c
from ctypes import wintypes as w
import hashlib
import json
import os
from pathlib import Path, PureWindowsPath
import sys

def require(value,code):
    if not value:raise ValueError(code)
def sha(raw):return hashlib.sha256(raw).hexdigest()


class NativeCapture:
    def __init__(self,path,helpers,guard,owner):
        self.path=Path(path);self.helpers=helpers;self.guard=guard;self.owner=owner
        st=self.path.stat();self.identity=(st.st_dev,st.st_ino)
    def check_root(self):
        self.helpers.check_nonreparse_chain(self.path);st=self.path.stat()
        require((st.st_dev,st.st_ino)==self.identity and self.helpers.windows_sid(self.path)==self.owner,
                'capture_root_identity_drift')
    def write_once(self,name,raw,*,terminal=False):
        if terminal:require(name in ('authority-receipts.json','outcome.json','manifest.json'),'capture_terminal_scope')
        else:self.guard()
        self.check_root()
        with (self.path/name).open('xb') as stream:
            stream.write(raw);stream.flush();os.fsync(stream.fileno())
    def read(self,name,limit):
        self.check_root();path=self.path/name;self.helpers.check_nonreparse_chain(path)
        with path.open('rb') as stream:return stream.read(limit)


def create_private_directory(parent,leaf,sid,helpers):
    """Atomic owner/SYSTEM ACL and actual OWNER/DACL/control readback."""
    require(os.name=='nt','windows_capture_required');helpers.check_nonreparse_chain(parent)
    adv=c.WinDLL('advapi32',use_last_error=True);kernel=c.WinDLL('kernel32',use_last_error=True)
    adv.ConvertStringSecurityDescriptorToSecurityDescriptorW.argtypes=[w.LPCWSTR,w.DWORD,c.POINTER(c.c_void_p),c.POINTER(w.ULONG)]
    adv.ConvertStringSecurityDescriptorToSecurityDescriptorW.restype=w.BOOL
    class SA(c.Structure):_fields_=[('length',w.DWORD),('descriptor',c.c_void_p),('inherit',w.BOOL)]
    kernel.CreateDirectoryW.argtypes=[w.LPCWSTR,c.POINTER(SA)];kernel.CreateDirectoryW.restype=w.BOOL
    kernel.LocalFree.argtypes=[c.c_void_p];kernel.LocalFree.restype=c.c_void_p
    descriptor=c.c_void_p();target=Path(parent)/leaf
    sddl='O:'+sid+'D:P(A;OICI;FA;;;'+sid+')(A;OICI;FA;;;SY)'
    require(adv.ConvertStringSecurityDescriptorToSecurityDescriptorW(sddl,1,c.byref(descriptor),None),'capture_descriptor')
    try:require(kernel.CreateDirectoryW(str(target),c.byref(SA(c.sizeof(SA),descriptor,False))),'capture_occupied_or_create_failed')
    finally:kernel.LocalFree(descriptor)
    helpers.check_nonreparse_chain(target);require(helpers.windows_sid(target)==sid,'capture_owner_readback')
    acl=c.c_void_p();security=c.c_void_p();control=w.WORD();revision=w.DWORD()
    adv.GetNamedSecurityInfoW.argtypes=[w.LPCWSTR,w.DWORD,w.DWORD,c.c_void_p,c.c_void_p,c.POINTER(c.c_void_p),c.c_void_p,c.POINTER(c.c_void_p)]
    adv.GetNamedSecurityInfoW.restype=w.DWORD
    adv.GetSecurityDescriptorControl.argtypes=[c.c_void_p,c.POINTER(w.WORD),c.POINTER(w.DWORD)];adv.GetSecurityDescriptorControl.restype=w.BOOL
    adv.GetAce.argtypes=[c.c_void_p,w.DWORD,c.POINTER(c.c_void_p)];adv.GetAce.restype=w.BOOL
    adv.ConvertSidToStringSidW.argtypes=[c.c_void_p,c.POINTER(w.LPWSTR)];adv.ConvertSidToStringSidW.restype=w.BOOL
    class ACL(c.Structure):_fields_=[('revision',w.BYTE),('reserved',w.BYTE),('size',w.WORD),('count',w.WORD),('reserved2',w.WORD)]
    class ACE(c.Structure):_fields_=[('type',w.BYTE),('flags',w.BYTE),('size',w.WORD),('mask',w.DWORD)]
    observed=[]
    try:
        require(adv.GetNamedSecurityInfoW(str(target),1,4,None,None,c.byref(acl),None,c.byref(security))==0 and acl,'capture_acl_readback')
        require(adv.GetSecurityDescriptorControl(security,c.byref(control),c.byref(revision)) and control.value&0x1000,'capture_acl_not_protected')
        require(c.cast(acl,c.POINTER(ACL)).contents.count==2,'capture_acl_count')
        for index in range(2):
            ace=c.c_void_p();text=w.LPWSTR();require(adv.GetAce(acl,index,c.byref(ace)),'capture_ace_readback')
            header=c.cast(ace,c.POINTER(ACE)).contents
            require(header.type==0 and header.flags==3 and header.mask==0x1F01FF and 16<=header.size<=256,'capture_ace_policy')
            try:
                require(adv.ConvertSidToStringSidW(c.c_void_p(ace.value+c.sizeof(ACE)),c.byref(text)),'capture_ace_sid')
                observed.append(text.value)
            finally:
                if text:kernel.LocalFree(c.cast(text,c.c_void_p))
        require(sorted(observed)==sorted([sid,'S-1-5-18']),'capture_acl_principals')
    finally:
        if security:kernel.LocalFree(security)
    return target,{'requested_sddl':sddl,'owner_sid':sid,'dacl_principals':observed,'protected':True,'readback':True}


class NativeFactory:
    def __init__(self,helpers,guard,on_staged):
        self.helpers=helpers;self.guard=guard;self.on_staged=on_staged;self.root_provenance={}
    def create_capture(self,packet):
        self.guard();parent=Path(packet['capture_parent']);sid=packet['owner_sid']
        require(parent.is_absolute() and len(parent.drive)==2 and parent.drive[1]==':' and
                not str(parent).lower().startswith('c:\\wamp64\\www') and
                '.syndicatum' not in [p.lower() for p in parent.parts],'capture_parent_scope')
        self.helpers.check_nonreparse_chain(parent)
        require(sha(str(parent).encode())==packet['capture_parent_sha256'] and
                self.helpers.windows_sid()==sid and self.helpers.windows_sid(parent) in (sid,'S-1-5-18'),
                'capture_parent_owner_binding')
        # Both supplied runtime paths are exact retained pins, not discovered
        # process origins. No unknown executable is opened by this preflight.
        require(sum(packet[name]['pin']['size'] for name in ('python','cli','powershell'))<=1073741824,
                'admission_runtime_total')
        for row in (packet['python'],packet['cli'],packet['powershell']):
            path=Path(row['path']);self.helpers.check_nonreparse_chain(path)
            before=path.stat();require(before.st_size==row['pin']['size']<=536870912,'admission_runtime_size')
            digest=hashlib.sha256();count=0
            with path.open('rb') as stream:
                while True:
                    self.guard()
                    raw=stream.read(min(65536,before.st_size+1-count))
                    if not raw:break
                    count+=len(raw);require(count<=before.st_size,'admission_runtime_read_bound');digest.update(raw)
            actual=digest.hexdigest();require(count==before.st_size,'admission_runtime_read_size')
            self.helpers.check_nonreparse_chain(path);after=path.stat()
            require(actual==row['pin']['sha256'] and (before.st_size,before.st_mtime_ns,before.st_ino)==
                    (after.st_size,after.st_mtime_ns,after.st_ino),'admission_runtime_pin')
        self.helpers.check_nonreparse_chain(sys.executable)
        require(Path(sys.executable).samefile(packet['python']['path']),'admission_python_identity')
        kernel=c.WinDLL('kernel32',use_last_error=True)
        kernel.GetSystemDirectoryW.argtypes=[w.LPWSTR,w.UINT];kernel.GetSystemDirectoryW.restype=w.UINT
        system=c.create_unicode_buffer(32768);size=kernel.GetSystemDirectoryW(system,len(system))
        require(0<size<len(system),'admission_system_directory')
        expected=[str(Path(packet['python']['path']).parent),str(parent/packet['capture_identity']),system.value]
        require([PureWindowsPath(p) for p in packet['runtime_discovery_roots']]==[PureWindowsPath(p) for p in expected],
                'admission_runtime_discovery_scope')
        path,acl=create_private_directory(parent,packet['capture_identity'],sid,self.helpers)
        self.store=NativeCapture(path,self.helpers,self.guard,sid)
        self.root_provenance={'capture':str(path),'acl':acl,'identity':self.store.identity,
                              'final_path':str(path.resolve(strict=True)),'handle_final_path_verified':False}
        self.root_provenance['host_overhead']=getattr(self,'host_overhead',{})
        return self.store
    def staged(self,store):
        self.guard();sys.path.insert(0,str(store.path));self.on_staged(store.path)
    def observers(self,packet):
        self.guard()
        from codex_developer_install_collector import NativeInstallReader
        from codex_developer_windows_observer import NativeWindowsObserver
        from codex_developer_metadata_producer import NativeReadOnlyCli,retained_root_map
        guard=self.guard
        class Reader(NativeInstallReader):
            def gate(self):guard()
        class Windows(NativeWindowsObserver):
            def gate(self):guard()
        class Cli(NativeReadOnlyCli):
            def gate(self):guard()
        records=[]
        expected_records=[(1,1324,'fdb41dfe306df541c8db84a8773d58b31a445bc9cfaab0f2c35f39079ea066b9'),
                          (2,13954,'6a16fd4282fabc7576544d09555c43c0d3813288748da0a68aa517a27ec41083')]
        require(len(packet['retained_a1_results'])==2,'retained_record_count')
        for row,(index,size,digest) in zip(packet['retained_a1_results'],expected_records):
            expected=Path(packet['capture_parent'])/'developer-a1-5eb6ed67761e450288a3da8968d93f72'/('result-'+str(index)+'.json')
            require(row['path']==str(expected) and row['bytes']==size and row['sha256']==digest,'retained_record_scope')
            path=Path(row['path']);self.helpers.check_nonreparse_chain(path)
            require(path.stat().st_size==row['bytes']<=1048576,'retained_record_bound')
            raw=path.read_bytes();require(sha(raw)==row['sha256'],'retained_record_pin')
            value=json.loads(raw);require(value['exit']==0,'retained_record_exit')
            import base64
            records.append(json.loads(base64.b64decode(value['stdout_base64'],validate=True)))
        require(len(records)==2,'retained_record_count');roots=retained_root_map(*records)
        require(sha(json.dumps(roots,sort_keys=True,separators=(',',':')).encode())==packet['retained_root_map_sha256'],'retained_root_map_pin')
        self.reader=Reader(roots,retain_ref_bytes=True);self.windows=Windows()
        self.root_provenance.update({'roots':roots,'cache_roots':'source_derived_not_observed'})
        binding={'roots':self.reader.roots,'managed_root_role':'managed','cli':packet['cli'],'neutral_directory':str(self.store.path)}
        return self.reader,self.windows,Cli(binding),binding
    def check_cli_projection(self,markets,plugins,binding):
        from codex_developer_metadata_producer import project_cli_facts
        project_cli_facts(markets,plugins,binding)
    def sibling_roles(self,plugins,binding):
        roles=[]
        for row in plugins['installed']:
            if row['marketplaceName']!='syndicatum' or row['pluginId']=='codex@syndicatum':continue
            name=row['name'];require(name not in ('','.','..') and all(c.isascii() and (c.isalnum() or c in '-_.') for c in name),'sibling_name')
            role='sibling_'+str(len(roles));require(len(roles)<16,'sibling_scope_bound')
            self.reader.roots[role]=str(PureWindowsPath(binding['roots']['cache_marketplace'])/name);roles.append(role)
        return roles
    def staging(self,binding):
        from codex_developer_metadata_producer import NativeMetadataProducer
        guard=self.guard
        class Staging(NativeMetadataProducer):
            def gate(self):guard()
        scope={'roots':binding['roots'],'staging_scopes':[
            {'root_role':'marketplace_staging','prefixes':None},
            {'root_role':'cache_marketplace','prefixes':['plugin-install-','plugin-backup-']}]}
        return Staging(scope,self.reader,lambda:0).staging()
