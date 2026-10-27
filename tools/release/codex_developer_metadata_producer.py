"""Concrete, closed metadata acquisition; no externally supplied fact maps.

Future owner admission must provide exact private roots/runtime/image pins and
fresh process exclusions. Unknown roots, coverage, CLI outcomes or provenance
are terminal. No installation command is available here.
"""
import base64
import copy
import json
from pathlib import Path, PureWindowsPath
import queue
import subprocess
import threading
import time

from codex_developer_a1_runner import check_nonreparse_chain
from codex_developer_fixed_journal import check, digest, packed
from codex_developer_process_ipc import _unique
from codex_developer_windows_observer import NativeWindowsObserver, ObservationUnavailable, stable_file, verify_process_observation, valid_process_identity


READ_COMMANDS = {'marketplaces':('plugin','marketplace','list','--json'),
                 'plugins':('plugin','list','--json')}
OPERATIONS = ('before-marketplace_remove','marketplace_remove','before-marketplace_add',
              'marketplace_add','before-plugin_add','plugin_add')


def retained_root_map(marketplaces,plugins):
    """Pure mapping from retained A1 JSON plus pinned upstream layout constants.

    Source.path in plugin list is a marketplace source, NOT a cache location.
    Derived paths are proposed read scopes, never assertions of their existence.
    """
    markets=[r for r in marketplaces['marketplaces'] if r.get('name')=='syndicatum']
    selected=[r for r in plugins['installed'] if r.get('pluginId')=='codex@syndicatum']
    check(len(markets)==len(selected)==1,'retained_target_root_unique')
    managed=PureWindowsPath(markets[0]['root']);plugin=selected[0]
    check(managed.is_absolute() and len(managed.drive)==2 and '..' not in managed.parts and
          managed.parts[-3:]==('.tmp','marketplaces','syndicatum'),'retained_managed_root_shape')
    home=managed.parents[2];source=plugin['source']
    check(markets[0]['marketplaceSource']=={'sourceType':'git','source':'https://github.com/jybanez/syndicatum.git'} and
          plugin['version']=='0.2.7' and plugin['installed'] is True and source['source']=='local' and
          PureWindowsPath(source['path'])==managed/'plugins'/'codex','retained_plugin_source_mapping')
    cache=home/'plugins'/'cache'/'syndicatum'
    return {'managed':str(managed),'plugin_source':str(managed/'plugins'/'codex'),
            'installed_027':str(cache/'codex'/'0.2.7'),'installed_0210':str(cache/'codex'/'0.2.10'),
            'marketplace_staging':str(managed.parent/'.staging'),'cache_marketplace':str(cache)}


def validate_root_binding(binding):
    roots=binding['roots'];managed=roots['managed']
    simulated_market={'marketplaces':[{'name':'syndicatum','root':managed,
        'marketplaceSource':{'sourceType':'git','source':'https://github.com/jybanez/syndicatum.git'}}]}
    simulated_plugin={'installed':[{'pluginId':'codex@syndicatum','version':'0.2.7','installed':True,
        'source':{'source':'local','path':roots['plugin_source']}}]}
    expected=retained_root_map(simulated_market,simulated_plugin)
    check(all(roots.get(role)==path for role,path in expected.items()) and
          digest(packed(expected))==binding['retained_root_map_sha256'] and
          binding['managed_root_role']=='managed' and
          binding['installed_before']['root_role']=='installed_027' and
          binding['installed_after']['root_role']=='installed_0210','native_root_mapping_binding')


def validate_native_admission(binding):
    """Pure prerequisite validation before assembly/worker construction.

    This is not authority or native evidence. The owner-bound installation spec
    must separately seal this binding and its freshly reviewed private pins.
    """
    check(binding.get('metadata_schema')==2,'native_metadata_schema_required')
    validate_root_binding(binding)
    pins=binding['runtime_closure'];images=binding['process_image_pins']
    check(type(pins) is dict and set(pins)=={'files','builtin_frozen','absent_bytecode'} and
          type(pins['files']) is dict and 0<len(pins['files'])<=256 and
          type(pins['builtin_frozen']) is list and type(pins['absent_bytecode']) is list,
          'native_runtime_admission_missing')
    check(type(images) is dict and 0<len(images)<=16 and type(binding['process_exclusions']) is list and
          0<len(binding['process_exclusions'])<=32,'native_writer_admission_missing')
    check(all(valid_process_identity(row) for row in binding['process_exclusions']) and
          len({(row['pid'],row['created_filetime']) for row in binding['process_exclusions']})==len(binding['process_exclusions']),
          'native_writer_identity_admission_missing')
    for collection,bound in ((pins['files'],268435456),(images,536870912)):
        check(sum(p['size'] for p in collection.values())<=536870912,'native_pin_total_bound')
        for path,pin in collection.items():
            check(PureWindowsPath(path).is_absolute() and len(PureWindowsPath(path).drive)==2 and
                  '..' not in PureWindowsPath(path).parts and type(pin) is dict and set(pin)=={'size','sha256'} and
                  type(pin['size']) is int and 0<=pin['size']<=bound and type(pin['sha256']) is str and
                  len(pin['sha256'])==64 and all(c in '0123456789abcdef' for c in pin['sha256']),
                  'native_runtime_pin_schema')
    for key in ('installed_before','installed_after'):
        check(type(binding[key]['files']) is list and 0<len(binding[key]['files'])<=512,'native_layout_admission_missing')
    check(type(binding['unrelated_layouts']) is list and len(binding['unrelated_layouts'])<=16,'native_unrelated_admission_missing')
    check(binding['staging_scopes']==[{'root_role':'marketplace_staging','prefixes':None},
          {'root_role':'cache_marketplace','prefixes':['plugin-install-','plugin-backup-']}],'native_staging_admission_missing')


class NativeReadOnlyCli:
    def __init__(self, binding):
        self.binding=binding;self.terminal=False;self.results=[];self.child=None

    @staticmethod
    def gate():raise RuntimeError('SOURCE_ONLY: metadata CLI observation disabled')

    def query_policy(self):
        return ('marketplaces' if len(self.results)%2==0 else 'plugins',8,14)

    def read(self, command, deadline):
        self.gate()
        expected,child_seconds,max_queries=self.query_policy()
        check(not self.terminal and command in READ_COMMANDS and len(self.results)<max_queries and
              command==expected and
              time.monotonic()<deadline,'metadata_cli_lifetime')
        # Mark consumed before touching any native resource. A caller cannot
        # retry a failed query or assume timeout killed a child.
        self.terminal=True
        started=time.monotonic()
        executable=self.binding['cli']['path'];cwd=Path(self.binding['neutral_directory'])
        check_nonreparse_chain(cwd)
        check(cwd.is_dir() and not str(cwd).lower().startswith('c:\\wamp64\\www'),'metadata_neutral_directory')
        check(stable_file(executable)==self.binding['cli']['pin'],'metadata_cli_pin')
        child=subprocess.Popen([executable,*READ_COMMANDS[command]],executable=executable,cwd=str(cwd),
            stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
            shell=False,close_fds=True,creationflags=subprocess.CREATE_NO_WINDOW)
        self.child=child;result=queue.Queue(maxsize=2);lock=threading.Lock()
        channels={name:{'prefix':b'','received_bytes':0,'read_state':'not_started','error_type':None,
                        'prefix_truncated':False} for name in ('stdout','stderr')}
        record={'command':command,'argv_sha256':digest(packed(list(READ_COMMANDS[command]))),
                'root_pid':child.pid,'os_image_verified':False,'creation_time_verified':False,
                'status':'started_uncertain','exit':None}
        self.results.append(record)
        def drain(name,stream,limit):
            chunks=bytearray()
            try:
                # BufferedReader.read may wait for 4096 bytes or EOF. read1
                # returns the available short pipe read without filling it.
                read=stream.read1
                while True:
                    with lock:channels[name]['read_state']='read_inflight'
                    raw=read(min(4096,limit+1-len(chunks)))
                    if not raw:
                        with lock:channels[name]['read_state']='eof'
                        break
                    chunks.extend(raw)
                    with lock:
                        channels[name].update(prefix=bytes(chunks[:limit]),received_bytes=len(chunks),
                                              prefix_truncated=len(chunks)>limit,read_state='prefix_received')
                    check(len(chunks)<=limit,'metadata_cli_output_bound')
                result.put((name,bytes(chunks),None))
            except Exception as error:
                with lock:channels[name].update(read_state='read_unavailable',error_type=type(error).__name__)
                result.put((name,None,error))
        for name,stream,limit in (('stdout',child.stdout,262144),('stderr',child.stderr,65536)):
            threading.Thread(target=drain,args=(name,stream,limit),daemon=True).start()
        limit=min(deadline,time.monotonic()+child_seconds)
        try:
            code=child.wait(timeout=max(.001,limit-time.monotonic()))
            values={}
            for _ in range(2):
                name,raw,error=result.get(timeout=max(.001,limit-time.monotonic()))
                if error:raise error
                values[name]=raw
            with lock:record['channel_reads']={name:{k:v for k,v in state.items() if k!='prefix'} for name,state in channels.items()}
            record.update({'status':'exited','exit':code,'elapsed_ms':round((time.monotonic()-started)*1000),
                **{name+'_base64':base64.b64encode(raw).decode('ascii') for name,raw in values.items()}})
            check(code==0 and not values['stderr'],'metadata_cli_unsuccessful_or_warning')
            value=json.loads(values['stdout'],object_pairs_hook=_unique)
            self.terminal=False
            return value
        except Exception as error:
            # No kill, cleanup, child reattachment or successor command.
            with lock:
                record.update({'status':'failed_uncertain','error_type':type(error).__name__,
                    'elapsed_ms':round((time.monotonic()-started)*1000),
                    **{name+'_prefix_base64':base64.b64encode(state['prefix']).decode('ascii') for name,state in channels.items()},
                    'channel_reads':{name:{k:v for k,v in state.items() if k!='prefix'} for name,state in channels.items()}})
            error.add_note('metadata query uncertain; child may remain; no replay')
            raise


def project_cli_facts(marketplaces, plugins, binding):
    """Strict source projections; never synthesize unreported config ref/sparse."""
    check(type(marketplaces) is dict and set(marketplaces)=={'marketplaces'} and
          type(marketplaces['marketplaces']) is list and len(marketplaces['marketplaces'])<=128,
          'metadata_marketplace_schema')
    check(type(plugins) is dict and set(plugins)=={'installed','available'} and
          all(type(plugins[k]) is list and len(plugins[k])<=512 for k in plugins), 'metadata_plugin_schema')
    market_rows=marketplaces['marketplaces'];installed=plugins['installed']
    check(all(type(row) is dict and type(row.get('name')) is str for row in market_rows),'metadata_marketplace_rows')
    check(len({r['name'] for r in market_rows})==len(market_rows),'metadata_marketplace_duplicate')
    check(all(type(row) is dict and type(row.get('pluginId')) is str for row in installed),'metadata_plugin_rows')
    check(len({r['pluginId'] for r in installed})==len(installed),'metadata_plugin_duplicate')
    targets=[r for r in market_rows if r['name']=='syndicatum']
    target=None
    if targets:
        row=targets[0];source=row.get('marketplaceSource')
        check(row.get('root')==binding['roots'][binding['managed_root_role']] and
              type(source) is dict and source=={'sourceType':'git','source':'https://github.com/jybanez/syndicatum.git'},
              'metadata_marketplace_provenance')
        target={'url':source['source']}
    selected=[r for r in installed if r['pluginId']=='codex@syndicatum']
    check(len(selected)<=1,'metadata_target_plugin_duplicate')
    if selected:
        row=selected[0];source=row.get('source');version=row.get('version')
        check(version in ('0.2.7','0.2.10') and row.get('installed') is True and type(source) is dict and
              source=={'source':'local','path':binding['roots']['plugin_source']},'metadata_installed_provenance')
    # Stable ordering; all unrelated returned fields participate, including
    # policy/enabled/source. The exact unrelated artifact inventory is added by
    # the producer, not inferred from this list.
    unrelated={'marketplaces':sorted((r for r in market_rows if r['name']!='syndicatum'),key=lambda r:r['name']),
               'installed':sorted((r for r in installed if r['pluginId']!='codex@syndicatum'),key=lambda r:r['pluginId']),
               'available':plugins['available']}
    return target,unrelated


def validate_metadata_evidence(evidence,binding):
    """Independent consumer checks; no successful empty-list fallback."""
    check(type(evidence) is dict and set(evidence)=={'writer_before','writer_after','runtime_before','runtime_after',
          'cli_results_sha256','unrelated_inventory_sha256','artifact_inventory_sha256','managed_state'},'native_metadata_evidence_schema')
    managed=evidence['managed_state']
    check(type(managed) is dict and set(managed)=={'present','head'} and type(managed['present']) is bool and
          (managed['head'] is None if not managed['present'] else type(managed['head']) is str and
           len(managed['head'])==40 and all(c in '0123456789abcdef' for c in managed['head'])),'native_managed_state_schema')
    for key in ('writer_before','writer_after'):
        if verify_process_observation(evidence[key],binding['process_exclusions']):
            raise ObservationUnavailable('independent_writer_quiescence_unavailable')
    check(evidence['writer_before']['processes']==evidence['writer_after']['processes'],'native_writer_snapshot_drift')
    pins=binding['runtime_closure']
    expected=digest(packed({'files':{digest(p.encode('utf-8')):v for p,v in pins['files'].items()},
                           'builtin_frozen':pins['builtin_frozen']}))
    runtime={'closure_sha256':expected,'files':len(pins['files']),
             'bytes':sum(v['size'] for v in pins['files'].values()),'boundary':'loaded_python_files_and_psapi_modules'}
    check(evidence['runtime_before']==runtime and evidence['runtime_after']==runtime,'native_runtime_evidence_drift')
    for key in ('cli_results_sha256','unrelated_inventory_sha256','artifact_inventory_sha256'):
        check(type(evidence[key]) is str and len(evidence[key])==64 and all(c in '0123456789abcdef' for c in evidence[key]),
              'native_metadata_evidence_digest')


class NativeMetadataProducer:
    def __init__(self, binding, reader, now_ms, *, windows=None, cli=None):
        self.binding=copy.deepcopy(binding);self.bound_digest=digest(packed(binding));self.reader=reader;self.now=now_ms
        self.windows=windows if windows is not None else NativeWindowsObserver()
        self.cli=cli if cli is not None else NativeReadOnlyCli(self.binding)
        self.sequence=0;self.used=set();self.terminal=False;self.private_evidence=[]

    @staticmethod
    def gate():raise RuntimeError('SOURCE_ONLY: native metadata producer disabled')

    def collect(self,challenge,read_artifacts):
        self.gate()
        check(callable(read_artifacts),'metadata_independent_reader_required')
        check(digest(packed(self.binding))==self.bound_digest,'metadata_binding_drift')
        check(not self.terminal and len(self.private_evidence)<7 and challenge['operation'] in OPERATIONS and
              challenge['sequence']==self.sequence+1 and challenge['nonce'] not in self.used and
              challenge['binding_sha256']==digest(packed(self.binding)) and
              challenge['context_sha256']==self.binding['context_sha256'],'metadata_challenge_binding')
        check(challenge['command_context'] is None or challenge['command_context'].get('collection_safe') is True,
              'metadata_command_quiescence_unavailable')
        self.terminal=True;self.sequence+=1;self.used.add(challenge['nonce'])
        validate_native_admission(self.binding)
        check(stable_file(__file__,1048576)['sha256']==self.binding['metadata_producer_source_sha256'],
              'metadata_producer_source_pin')
        check(self.binding['metadata_schema']==2,'metadata_producer_schema')
        deadline=time.monotonic()+30;began=self.now()
        runtime_before=self.windows.runtime(self.binding['runtime_closure'])
        before=self.windows.processes(self.binding['process_image_pins'])
        writers=verify_process_observation(before,self.binding['process_exclusions'])
        if writers:raise ObservationUnavailable('independent_writer_quiescence_unavailable')
        start=len(self.cli.results)
        market,unrelated=project_cli_facts(self.cli.read('marketplaces',deadline),
            self.cli.read('plugins',deadline),self.binding)
        inventories={}
        check(type(self.binding['unrelated_layouts']) is list and len(self.binding['unrelated_layouts'])<=16,
              'metadata_unrelated_scope')
        # Fixed native operations write only the syndicatum marketplace/cache
        # region. Observe all unrelated registrations, and require complete
        # reviewed layouts for every installed sibling in that affected cache
        # region. Do not silently omit a sibling or walk unrelated global caches.
        siblings={r['pluginId']:r for r in unrelated['installed'] if r.get('marketplaceName')=='syndicatum'}
        descriptions=self.binding['unrelated_layouts']
        check(len({r['plugin_id'] for r in descriptions})==len(descriptions) and
              {r['plugin_id'] for r in descriptions}==set(siblings),'metadata_unrelated_layout_coverage')
        for description in self.binding['unrelated_layouts']:
            role=description['root_role'];check(role not in inventories,'metadata_unrelated_duplicate')
            sibling=siblings[description['plugin_id']];name=sibling['name']
            check(type(name) is str and name not in ('','.','..') and
                  all(c.isascii() and (c.isalnum() or c in '-_.') for c in name) and
                  PureWindowsPath(self.binding['roots'][role])==PureWindowsPath(self.binding['roots']['cache_marketplace'])/name,
                  'metadata_unrelated_root_mapping')
            inventories[role]=self.reader.layout(description)
            check(time.monotonic()<deadline,'metadata_deadline')
        staging=self.staging()
        if staging:raise ObservationUnavailable('shared_staging_not_empty')
        # The collector owns this bounded reader. Bracket its actual target
        # inventory and HEAD/ref reads, not merely the metadata preceding them.
        artifacts=read_artifacts(market)
        after=self.windows.processes(self.binding['process_image_pins'])
        writers=verify_process_observation(after,self.binding['process_exclusions'])
        if writers or before['processes']!=after['processes']:
            raise ObservationUnavailable('independent_writer_quiescence_unavailable')
        runtime_after=self.windows.runtime(self.binding['runtime_closure'])
        check(runtime_before==runtime_after and time.monotonic()<deadline and 0<=self.now()-began<=30000,
              'metadata_runtime_or_time_drift')
        evidence={'writer_before':before,'writer_after':after,'runtime_before':runtime_before,'runtime_after':runtime_after,
                  'cli_results_sha256':digest(packed(self.cli.results[start:])),
                  'unrelated_inventory_sha256':digest(packed(inventories)),
                  'artifact_inventory_sha256':digest(packed(artifacts)),'managed_state':artifacts['managed_state']}
        validate_metadata_evidence(evidence,self.binding)
        facts={'marketplace':market,'unrelated_sha256':digest(packed({'cli':unrelated,'files':inventories})),
               'observed_ms':began,'shared_staging_entries':staging,'concurrent_cli_operations':writers,'writer_processes':writers}
        envelope={'challenge':challenge,'producer_source_sha256':self.binding['metadata_producer_source_sha256'],
                  'facts':facts,'observation_evidence':evidence}
        raw=packed(envelope);check(len(raw)<=32768,'metadata_envelope_bound')
        # Private in-memory evidence is bounded by 6 operations plus one terminal
        # failure observation, each with exactly two query results.
        # Durable capture publication remains an explicit future owner scope.
        check(len(self.private_evidence)<7,'metadata_observation_count')
        self.private_evidence.append({'challenge':challenge,'cli':self.cli.results[start:],'envelope_sha256':digest(raw)})
        self.terminal=False
        return raw,artifacts

    def staging(self):
        self.gate()
        rows=[]
        scopes=self.binding['staging_scopes']
        check(scopes==[{'root_role':'marketplace_staging','prefixes':None},
                       {'root_role':'cache_marketplace','prefixes':['plugin-install-','plugin-backup-']}],
              'metadata_staging_scope')
        for scope in scopes:
            role=scope['root_role']
            root=Path(self.binding['roots'][role]);check_nonreparse_chain(root.parent)
            try:root.lstat()
            except FileNotFoundError:continue
            check_nonreparse_chain(root);check(root.is_dir(),'metadata_staging_directory')
            before=root.stat();visited=0
            for item in root.iterdir():
                visited+=1;check(visited<=512,'metadata_staging_entry_bound')
                check_nonreparse_chain(item)
                if scope['prefixes'] is None or any(item.name.startswith(prefix) for prefix in scope['prefixes']):
                    rows.append({'role':role,'name':item.name})
                check(len(rows)<=128,'metadata_staging_bound')
            check_nonreparse_chain(root);after=root.stat()
            check((before.st_mtime_ns,before.st_ino)==(after.st_mtime_ns,after.st_ino),'metadata_staging_drift')
        return sorted(rows,key=lambda r:(r['role'],r['name']))
