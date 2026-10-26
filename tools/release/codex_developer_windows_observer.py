"""Source-only Windows observations. No CLI, service, kill or repair entrypoint.

All native methods refuse before DLL loading, process observation or file reads.
An admitted future caller must bind the bounded process families and exact
runtime files; this module never turns missing coverage into an empty list.
"""
import hashlib
import os
from pathlib import Path, PureWindowsPath
import stat
import sys

from codex_developer_a1_runner import check_nonreparse_chain
from codex_developer_fixed_journal import check, digest, packed


class ObservationUnavailable(RuntimeError):
    def __init__(self, code):
        super().__init__(code)
        self.code = code


def close_observation_handle(kernel,handle,code):
    if not kernel.CloseHandle(handle):
        primary=sys.exception()
        if primary is not None:primary.add_note(code)
        else:raise ObservationUnavailable(code)


def record_origin_prefix(evidence,value):
    candidate={**evidence,'origins':[*evidence['origins'],value]}
    if len(packed(candidate))>262144:
        evidence['truncated']=True
        raise ObservationUnavailable('raw_origin_evidence_bound')
    evidence['origins'].append(value)


def stable_file(path, limit=536870912):
    """Only called behind a native gate with an already admitted exact path."""
    path = Path(path)
    check(path.is_absolute() and len(path.drive) == 2 and path.drive[1] == ':', 'observer_local_file')
    check_nonreparse_chain(path)
    before = path.stat()
    check(stat.S_ISREG(before.st_mode) and 0 <= before.st_size <= limit, 'observer_file_bound')
    sha = hashlib.sha256(); count = 0
    with path.open('rb') as stream:
        while True:
            raw = stream.read(min(65536, limit + 1 - count))
            if not raw: break
            count += len(raw)
            check(count <= limit, 'observer_file_bound')
            sha.update(raw)
    check_nonreparse_chain(path)
    after = path.stat()
    check((before.st_size, before.st_mtime_ns, before.st_ino) ==
          (after.st_size, after.st_mtime_ns, after.st_ino) and count == before.st_size,
          'observer_file_drift')
    return {'sha256': sha.hexdigest(), 'size': count}


class NativeWindowsObserver:
    # Includes native CLI/Git and general script hosts capable of running the
    # reviewed installer. Exclusions are exact fresh PID + creation-time + image
    # pins, never names or ancestors alone. This is bounded family coverage,
    # not a claim to detect arbitrary hostile writers or prevent future starts.
    FAMILIES = frozenset(('codex.exe', 'git.exe', 'git-remote-https.exe',
        'python.exe', 'pythonw.exe', 'node.exe', 'pwsh.exe', 'powershell.exe', 'cmd.exe'))

    @staticmethod
    def gate():
        raise RuntimeError('SOURCE_ONLY: Windows observation disabled')

    def processes(self, image_pins, *, discovery=False):
        self.gate()
        self.last_process_evidence={'complete':False,'query_finished':False,'origins':[],'scanned':0,'truncated':False}
        check(os.name == 'nt', 'windows_observation_required')
        check(type(image_pins) is dict and (0 if discovery else 1)<=len(image_pins)<=16 and
              sum(p['size'] for p in image_pins.values())<=536870912,'process_image_scope_bound')
        import ctypes as c
        from ctypes import wintypes as w
        kernel = c.WinDLL('kernel32', use_last_error=True)
        class Entry(c.Structure):
            _fields_ = [('dwSize',w.DWORD),('cntUsage',w.DWORD),('pid',w.DWORD),
                ('heap',c.c_size_t),('module',w.DWORD),('threads',w.DWORD),
                ('parent',w.DWORD),('priority',w.LONG),('flags',w.DWORD),('name',w.WCHAR*260)]
        kernel.CreateToolhelp32Snapshot.argtypes=[w.DWORD,w.DWORD]
        kernel.CreateToolhelp32Snapshot.restype=w.HANDLE
        kernel.Process32FirstW.argtypes=[w.HANDLE,c.POINTER(Entry)];kernel.Process32FirstW.restype=w.BOOL
        kernel.Process32NextW.argtypes=[w.HANDLE,c.POINTER(Entry)];kernel.Process32NextW.restype=w.BOOL
        kernel.OpenProcess.argtypes=[w.DWORD,w.BOOL,w.DWORD];kernel.OpenProcess.restype=w.HANDLE
        kernel.QueryFullProcessImageNameW.argtypes=[w.HANDLE,w.DWORD,w.LPWSTR,c.POINTER(w.DWORD)]
        kernel.QueryFullProcessImageNameW.restype=w.BOOL
        kernel.GetProcessTimes.argtypes=[w.HANDLE,*([c.POINTER(w.FILETIME)]*4)]
        kernel.GetProcessTimes.restype=w.BOOL
        kernel.CloseHandle.argtypes=[w.HANDLE];kernel.CloseHandle.restype=w.BOOL
        snapshot=kernel.CreateToolhelp32Snapshot(2,0)
        if snapshot in (None,c.c_void_p(-1).value):raise ObservationUnavailable('process_snapshot_unavailable')
        rows=[];count=0;hashed={};unadmitted={}
        try:
            entry=Entry();entry.dwSize=c.sizeof(entry)
            ok=kernel.Process32FirstW(snapshot,c.byref(entry))
            if not ok:raise ObservationUnavailable('process_snapshot_empty_or_failed')
            while ok:
                count+=1
                self.last_process_evidence['scanned']=count
                if count>4096:raise ObservationUnavailable('process_snapshot_bound')
                name=entry.name.lower()
                if name in self.FAMILIES:
                    self.last_process_evidence['querying_pid']=int(entry.pid)
                    if len(rows)>=128:raise ObservationUnavailable('process_family_bound')
                    handle=kernel.OpenProcess(0x1000,False,entry.pid)
                    if not handle:raise ObservationUnavailable('process_identity_unavailable')
                    try:
                        size=w.DWORD(32768);buffer=c.create_unicode_buffer(size.value)
                        created,exited,kernel_time,user_time=(w.FILETIME() for _ in range(4))
                        if not kernel.QueryFullProcessImageNameW(handle,0,buffer,c.byref(size)) or size.value>=32768:
                            raise ObservationUnavailable('process_image_unavailable')
                        if not kernel.GetProcessTimes(handle,c.byref(created),c.byref(exited),c.byref(kernel_time),c.byref(user_time)):
                            raise ObservationUnavailable('process_creation_unavailable')
                        # Only read exact reviewed images, not a path nominated by
                        # an unexpected process. Unknown images stop immediately.
                        image=buffer.value
                        record_origin_prefix(self.last_process_evidence,{'pid':int(entry.pid),'parent_pid':int(entry.parent),
                            'created_filetime':(created.dwHighDateTime<<32)|created.dwLowDateTime,'image':image})
                        if image not in image_pins and not discovery:raise ObservationUnavailable('process_image_unadmitted')
                        if image not in image_pins:
                            unadmitted[digest(image.encode('utf-8'))]=image
                            check(len(unadmitted)<=16,'process_origin_discovery_bound')
                        elif image not in hashed:
                            hashed[image]=stable_file(image,image_pins[image]['size'])
                            if hashed[image]!=image_pins[image]:raise ObservationUnavailable('process_image_pin_drift')
                        rows.append({'pid':int(entry.pid),'parent_pid':int(entry.parent),
                            'created_filetime':(created.dwHighDateTime<<32)|created.dwLowDateTime,
                            'image_sha256':hashed[image]['sha256'] if image in hashed else None,
                            'image_path_sha256':digest(image.encode('utf-8'))})
                    finally:
                        close_observation_handle(kernel,handle,'process_handle_close_uncertain')
                ok=kernel.Process32NextW(snapshot,c.byref(entry))
            if c.get_last_error()!=18:raise ObservationUnavailable('process_snapshot_incomplete')
        finally:
            close_observation_handle(kernel,snapshot,'snapshot_close_uncertain')
        report={'complete':not bool(unadmitted),'families':sorted(self.FAMILIES),'scanned':count,
                'observer_pid':os.getpid(),
                'processes':sorted(rows,key=lambda x:(x['pid'],x['created_filetime']))}
        self.last_process_evidence['complete']=report['complete'];self.last_process_evidence['query_finished']=True
        if discovery:
            return {'status':'PROPOSED_NOT_ADMISSION','snapshot':report,'unadmitted_image_origins':unadmitted}
        return report

    def runtime(self, pins, *, discovery_roots=None):
        """Exact loaded Python-file/native-DLL closure, not a Windows tree walk.

        Builtin/frozen module identities are separately pinned by name/origin.
        DATAFILE modules and injected/unlisted executable memory are outside the
        PSAPI observation boundary; never describe this as complete OS trust.
        """
        self.gate()
        self.last_runtime_evidence={'complete':False,'origins':[],'truncated':False}
        check(os.name=='nt','windows_observation_required')
        discovery=pins is None
        if discovery:
            check(type(discovery_roots) is list and 0<len(discovery_roots)<=3 and
                  all(PureWindowsPath(p).is_absolute() and len(PureWindowsPath(p).drive)==2 and
                      '..' not in PureWindowsPath(p).parts for p in discovery_roots),'runtime_discovery_roots')
        else:check(type(pins) is dict and set(pins)=={'files','builtin_frozen','absent_bytecode'} and
              type(pins['files']) is dict and 0<len(pins['files'])<=256 and
              all(type(v['size']) is int and 0<=v['size']<=268435456 for v in pins['files'].values()) and
              sum(v['size'] for v in pins['files'].values())<=536870912,'runtime_closure_bound')
        def admitted_path(path):
            record_origin_prefix(self.last_runtime_evidence,{'path':str(path)})
            if discovery:
                check(PureWindowsPath(path).is_absolute() and '..' not in PureWindowsPath(path).parts and
                      any(PureWindowsPath(path).is_relative_to(PureWindowsPath(root)) for root in discovery_roots),
                      'runtime_discovery_outside_scope')
        import ctypes as c
        from ctypes import wintypes as w
        kernel=c.WinDLL('kernel32',use_last_error=True)
        kernel.GetCurrentProcess.restype=w.HANDLE
        kernel.K32EnumProcessModulesEx.argtypes=[w.HANDLE,c.POINTER(w.HMODULE),w.DWORD,c.POINTER(w.DWORD),w.DWORD]
        kernel.K32EnumProcessModulesEx.restype=w.BOOL
        kernel.GetModuleFileNameW.argtypes=[w.HMODULE,w.LPWSTR,w.DWORD];kernel.GetModuleFileNameW.restype=w.DWORD
        handles=(w.HMODULE*256)();needed=w.DWORD()
        if not kernel.K32EnumProcessModulesEx(kernel.GetCurrentProcess(),handles,c.sizeof(handles),c.byref(needed),3):
            raise ObservationUnavailable('runtime_modules_unavailable')
        if needed.value>c.sizeof(handles) or needed.value%c.sizeof(w.HMODULE):
            raise ObservationUnavailable('runtime_modules_bound')
        files=set();identities=[];absent=set()
        for index in range(needed.value//c.sizeof(w.HMODULE)):
            buffer=c.create_unicode_buffer(32768)
            size=kernel.GetModuleFileNameW(handles[index],buffer,len(buffer))
            if size==0 or size>=len(buffer):raise ObservationUnavailable('runtime_module_path_unavailable')
            admitted_path(buffer.value)
            files.add(buffer.value)
        # No discovery of directories, sys.path or unrelated runtime trees.
        check(len(sys.modules)<=2048,'runtime_python_module_bound')
        for name,module in list(sys.modules.items()):
            origin=getattr(getattr(module,'__spec__',None),'origin',None)
            file=getattr(module,'__file__',None)
            if file:
                admitted_path(str(file))
                files.add(str(file))
                cached=getattr(module,'__cached__',None)
                if cached:
                    # The source name alone does not establish cached bytecode.
                    # Its existence must be an explicit expected pin decision.
                    admitted_path(str(cached))
                    if not discovery and cached in pins['files']:files.add(cached)
                    else:
                        if not discovery and cached not in pins['absent_bytecode']:raise ObservationUnavailable('runtime_bytecode_unadmitted')
                        path=Path(cached);ancestor=path.parent
                        for _ in range(64):
                            try:ancestor.lstat()
                            except FileNotFoundError:ancestor=ancestor.parent
                            else:break
                        else:raise ObservationUnavailable('runtime_bytecode_ancestor_unavailable')
                        check_nonreparse_chain(ancestor)
                        try:path.lstat()
                        except FileNotFoundError:absent.add(str(cached))
                        else:
                            if not discovery:raise ObservationUnavailable('runtime_bytecode_unadmitted')
                            check_nonreparse_chain(path);files.add(str(cached))
            elif origin in ('built-in','frozen'):identities.append([name,origin])
            elif module is not None:raise ObservationUnavailable('runtime_module_origin_unavailable')
        if discovery:
            check(0<len(files)<=256,'runtime_discovery_file_bound')
            proposed={};total=0
            for path in sorted(files):
                check(total<536870912,'runtime_discovery_byte_bound')
                proposed[path]=stable_file(path,min(268435456,536870912-total));total+=proposed[path]['size']
            candidate={'files':proposed,'builtin_frozen':sorted(identities),'absent_bytecode':sorted(absent)}
            self.runtime_proposed_pins=candidate
            # Repeat the actual loaded closure and bytes. This is still a
            # proposed manifest requiring review, never self-admission.
            first=self.last_runtime_evidence;first['complete']=True
            self.runtime_raw_passes=[first]
            try:checked=self.runtime(candidate)
            finally:self.runtime_raw_passes.append(self.last_runtime_evidence)
            return {'status':'PROPOSED_NOT_ADMISSION','pins':candidate,'runtime':checked,
                    'raw_passes':self.runtime_raw_passes}
        if len(files)>256 or sorted(files)!=sorted(pins['files']) or sorted(identities)!=pins['builtin_frozen'] or sorted(absent)!=pins['absent_bytecode']:
            raise ObservationUnavailable('runtime_closure_unadmitted')
        total=0;observed={}
        for path in sorted(files):
            value=stable_file(path,pins['files'][path]['size'])
            total+=value['size']
            if total>536870912 or value!=pins['files'][path]:raise ObservationUnavailable('runtime_closure_pin_drift')
            observed[digest(path.encode('utf-8'))]=value
        self.last_runtime_evidence['complete']=True
        return {'closure_sha256':digest(packed({'files':observed,'builtin_frozen':sorted(identities)})),
                'files':len(files),'bytes':total,'boundary':'loaded_python_files_and_psapi_modules'}


def valid_process_identity(row):
    return type(row) is dict and set(row)=={'pid','parent_pid','created_filetime','image_sha256','image_path_sha256'} and \
        all(type(row[key]) is int and row[key]>=(0 if key=='parent_pid' else 1) for key in ('pid','parent_pid','created_filetime')) and \
        all(type(row[key]) is str and len(row[key])==64 and all(c in '0123456789abcdef' for c in row[key])
            for key in ('image_sha256','image_path_sha256'))


def verify_process_observation(snapshot, exclusions):
    """Pure policy check; no caller can omit the coverage/result keys."""
    if type(snapshot) is not dict or set(snapshot)!={'complete','families','scanned','observer_pid','processes'}:
        raise ObservationUnavailable('writer_observation_missing')
    if snapshot['complete'] is not True or snapshot['families']!=sorted(NativeWindowsObserver.FAMILIES) or \
       type(snapshot['scanned']) is not int or not 0<snapshot['scanned']<=4096 or \
       type(snapshot['observer_pid']) is not int or snapshot['observer_pid']<=0 or \
       type(snapshot['processes']) is not list or not 0<len(snapshot['processes'])<=min(128,snapshot['scanned']):
        raise ObservationUnavailable('writer_coverage_unavailable')
    expected=[]
    for row in exclusions:
        if not valid_process_identity(row):raise ObservationUnavailable('writer_exclusion_schema')
        expected.append(row)
    seen=set();remaining=[]
    for row in snapshot['processes']:
        if not valid_process_identity(row):
            raise ObservationUnavailable('writer_identity_unavailable')
        identity=(row['pid'],row['created_filetime'])
        if identity in seen:raise ObservationUnavailable('writer_identity_duplicate')
        seen.add(identity)
        if row not in expected:remaining.append(row)
    # Exclusions must be live exact identities, not stale PID-only allowlists.
    if len(expected)!=len({packed(row) for row in expected}) or any(row not in snapshot['processes'] for row in expected):
        raise ObservationUnavailable('writer_exclusion_stale')
    if not any(row['pid']==snapshot['observer_pid'] for row in expected):
        raise ObservationUnavailable('writer_observer_identity_unavailable')
    return remaining
