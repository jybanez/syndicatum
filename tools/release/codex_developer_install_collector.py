"""Bound install observer: trusted metadata envelope + independent file reader.

No live entry. Native reader refuses before filesystem access. Layout is a
reviewed installed/managed mapping; archive file count is not an install schema.
"""
import hashlib
import base64
import json
from pathlib import Path, PurePosixPath
import secrets

from codex_developer_fixed_journal import check, digest, packed
from codex_developer_process_ipc import _unique
from codex_developer_a1_runner import check_nonreparse_chain


class ObservationUnavailable(RuntimeError):
    def __init__(self,code):
        super().__init__(code)
        self.code=code


class NativeInstallReader:
    def __init__(self,roots):self.roots=dict(roots)
    @staticmethod
    def gate():raise RuntimeError('SOURCE_ONLY: independent native installation reader disabled')

    def layout(self,description,partial=False):
        self.gate()
        root=Path(self.roots[description['root_role']])
        check(root.is_absolute(),'installed_layout_root')
        check(len(root.drive)==2 and root.drive[0].isalpha() and root.drive[1]==':','local_artifact_drive_required')
        check(not any(part.lower() in ('.syndicatum','agent-identities','.ssh','.aws','credentials') for part in root.parts),
              'protected_root_forbidden')
        # Directory footprint is complete and bounded; never follow reparses or
        # read credential/config state outside the reviewed artifact root.
        # For an absent partial postimage, inspect the existing parent before
        # testing the leaf. Missing/inaccessible ancestors remain unavailable.
        check_nonreparse_chain(root.parent)
        if partial:
            try:root.lstat()
            except FileNotFoundError:return {'!root':'unavailable:root_absent'}
        check_nonreparse_chain(root)
        check(root.exists(),'installed_layout_root_absent')
        check(root.is_dir(),'installed_layout_root')
        todo=[root];seen=[];total=0;directories=0
        while todo:
            directory=todo.pop()
            directories+=1;check(directories<=256,'installed_layout_directory_bound')
            check_nonreparse_chain(directory)
            for item in directory.iterdir():
                check_nonreparse_chain(item)
                if item.is_dir():todo.append(item)
                else:
                    relative=item.relative_to(root).as_posix()
                    check(item.name.lower() not in ('credential','auth.json','.env','config.toml'),'protected_content_forbidden')
                    seen.append(relative);total+=item.stat().st_size
                    check(len(seen)<=512 and total<=16777216,'installed_layout_footprint')
            check(len(todo)<=256,'installed_layout_directory_bound')
        if not partial:check(sorted(seen)==sorted(description['files']),'installed_layout_inventory_drift')
        values={}
        for relative in (seen if partial else description['files']):
            part=PurePosixPath(relative)
            check(not part.is_absolute() and all(x not in ('..','.') for x in part.parts),'installed_layout_relative')
            path=root.joinpath(*part.parts)
            check_nonreparse_chain(path)
            before=path.stat()
            if partial and relative not in description['files']:
                values[relative]='unread:metadata:'+digest(packed({'size':before.st_size,'mtime_ns':before.st_mtime_ns}))
                continue
            with path.open('rb') as stream:
                raw=stream.read(16777217)
            check(len(raw)<=16777216 and len(raw)==before.st_size,'installed_layout_read_bound')
            sha=hashlib.sha256(raw).hexdigest()
            check_nonreparse_chain(path)
            after=path.stat()
            check((before.st_size,before.st_mtime_ns,before.st_ino)==(after.st_size,after.st_mtime_ns,after.st_ino),
                  'installed_layout_read_drift')
            values[relative]=sha
        return values

    def git_head(self,role):
        self.gate()
        root=Path(self.roots[role]);git=root/'.git'
        check(root.is_absolute() and len(root.drive)==2 and root.drive[0].isalpha() and root.drive[1]==':',
              'local_managed_drive_required')
        check(not any(part.lower() in ('.syndicatum','agent-identities','.ssh','.aws','credentials') for part in root.parts),
              'protected_root_forbidden')
        check_nonreparse_chain(root)
        check_nonreparse_chain(git)
        check(git.is_dir(),'managed_git_boundary')
        def read(path):
            check_nonreparse_chain(path)
            before=path.stat()
            check(path.is_file() and before.st_size<=1048576,'managed_ref_bound')
            with path.open('rb') as stream:raw=stream.read(1048577)
            check(len(raw)<=1048576,'managed_ref_bound')
            check_nonreparse_chain(path)
            after=path.stat()
            check((before.st_size,before.st_mtime_ns,before.st_ino)==
                  (after.st_size,after.st_mtime_ns,after.st_ino),'managed_ref_read_drift')
            return raw.decode('ascii').strip()
        value=read(git/'HEAD')
        if value.startswith('ref: '):
            name=value[5:];parts=PurePosixPath(name)
            check(name.startswith('refs/') and not parts.is_absolute() and '..' not in parts.parts,'managed_ref_path')
            path=git.joinpath(*parts.parts)
            check_nonreparse_chain(path.parent)
            try:path.lstat()
            except FileNotFoundError:
                rows=[line.split(' ',1)[0] for line in read(git/'packed-refs').splitlines()
                      if line.endswith(' '+name)]
                check(len(rows)==1,'managed_ref_unavailable');value=rows[0]
            else:value=read(path)
        check(len(value)==40 and all(c in '0123456789abcdef' for c in value),'managed_commit_schema')
        return value


class InstallObservationCollector:
    def __init__(self,metadata,reader,now_ms,binding,expected_binding_sha256,context_sha256):
        check(callable(metadata) and callable(now_ms) and digest(packed(binding))==expected_binding_sha256,
              'install_collector_binding')
        check(hashlib.sha256(Path(__file__).read_bytes()).hexdigest()==binding['collector_source_sha256'],
              'install_collector_source_binding')
        self.metadata,self.reader,self.now=metadata,reader,now_ms
        self.binding,self.context,self.sequence,self.used=binding,context_sha256,0,set()
        self.last={};self.failed_once=set();self.provenance={}

    def __call__(self,operation,command_context=None):
        return self._collect(operation,False,command_context)

    def failed(self,operation,command_context=None):
        check(operation not in self.failed_once,'failed_observation_no_replay')
        self.failed_once.add(operation)
        return self._collect(operation,True,command_context)

    def _collect(self,operation,partial,command_context):
        self.sequence+=1
        challenge={'operation':operation,'sequence':self.sequence,'nonce':secrets.token_hex(24),
                   'binding_sha256':digest(packed(self.binding)),'context_sha256':self.context,
                   'command_context':command_context}
        if partial and operation in self.last:facts=self.last[operation]
        else:
            raw=self.metadata(challenge)
            check(type(raw) is bytes and len(raw)<=32768,'install_metadata_bound')
            envelope=json.loads(raw,object_pairs_hook=_unique)
            check(packed(envelope)==raw and set(envelope)=={'challenge','producer_source_sha256','facts'} and
                  envelope['challenge']==challenge and challenge['nonce'] not in self.used and
                  envelope['producer_source_sha256']==self.binding['metadata_producer_source_sha256'],
                  'install_metadata_provenance')
            self.used.add(challenge['nonce'])
            facts=envelope['facts']
            self.last[operation]=facts
            self.provenance[operation]={'binding_sha256':digest(packed(self.binding)),
                'collector_source_sha256':self.binding['collector_source_sha256'],
                'metadata_sha256':digest(raw),'metadata_raw_base64':base64.b64encode(raw).decode()}
        check(set(facts)=={'marketplace','unrelated_sha256','observed_ms','shared_staging_entries',
                          'concurrent_cli_operations','writer_processes'},'install_metadata_schema')
        self._fresh(facts['observed_ms'])
        if type(facts['writer_processes']) is not list or facts['writer_processes']:
            raise ObservationUnavailable('independent_writer_quiescence_unavailable')
        if partial:
            files={}
            for role in ('installed_before','installed_after'):
                files.update({role+':'+key:value for key,value in self.reader.layout(self.binding[role],partial=True).items()})
        else:
            layout=self.binding['installed_after'] if operation=='plugin_add' else self.binding['installed_before']
            files=self.reader.layout(layout)
        marketplace=facts['marketplace']
        if marketplace is not None:
            marketplace={**marketplace,'head':self.reader.git_head(self.binding['managed_root_role'])}
        self._fresh(facts['observed_ms'])
        return packed({'operation':operation,'marketplace':marketplace,'candidate_files':files,
                       'unrelated_sha256':facts['unrelated_sha256'],'observed_ms':facts['observed_ms'],
                       'collector_provenance':{**self.provenance[operation],'partial':partial},
                       'shared_staging_entries':facts['shared_staging_entries'],
                       'concurrent_cli_operations':facts['concurrent_cli_operations']})

    def _fresh(self,observed):
        check(type(observed) is int and 0<=self.now()-observed<=120000,'install_metadata_stale')
