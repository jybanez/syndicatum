// Actual trusted Codex tools -> JS handler -> one Python coordinator pump.
// Production start refuses BEFORE tools/file/runtime/child operations.
import {createTrustedAgentHost} from './codex_developer_agent_host.mjs';

export const SOURCE_FILES = Object.freeze([
  'codex_developer_codex_entry.mjs','codex_developer_agent_host.mjs','codex_developer_host_assembly.py',
  'codex_developer_host_handoff.py','codex_developer_integrated_preparation.py','codex_developer_transport.py',
  'codex_developer_process_ipc.py','codex_developer_fixed_journal.py','codex_developer_native_b.py',
  'codex_developer_freshness.py','codex-developer-fixed-session-worker.ps1',
  'codex_developer_lifecycle.py','codex_pilot_sequence.py',
  'codex_developer_install_collector.py',
  'codex-developer-fixed-session-protocol.ps1','codex-developer-fixed-storage.psm1','codex-developer-native-b.psm1',
]);
const fail = message => {throw new Error(message);};
const quote = value => "'" + value.replaceAll("'", "''") + "'";
const MAX_OUTPUT = 32768;
const canonical=value=>Array.isArray(value)?value.map(canonical):value&&typeof value==='object'?
  Object.fromEntries(Object.keys(value).sort().map(key=>[key,canonical(value[key])])):value;

export class CodexToolSession {
  constructor(tools, packet) {this.tools=tools;this.packet=structuredClone(packet);this.id=null;this.terminal=false;this.exitCode=null;}
  async start() {
    fail('SOURCE_ONLY: trusted Codex assembly launch disabled');
    const p=this.packet;
    if (!['prepare_only','pilot'].includes(p.operation) || p.evidence_status!=='captured' || !/^[a-f0-9]{64}$/.test(p.private_packet_sha256))
      fail('uncaptured/unreviewed assembly packet');
    // Full dependency and runtime inventory, not only a launcher executable.
    if (JSON.stringify([...p.source_files.map(x=>x.name)].sort())!==JSON.stringify([...SOURCE_FILES].sort()))
      fail('incomplete mechanism source pin set');
    if (JSON.stringify(Object.keys(p.runtime_trees).sort())!==JSON.stringify(['codex_host','node','powershell','python']))
      fail('incomplete runtime tree set');
    const roots=Object.values(p.runtime_trees);
    if(roots.some(x=>x.status!=='captured'||!Array.isArray(x.files)||!x.files.length||x.files.length>10000))
      fail('uncaptured runtime inventory');
    if(roots.some(x=>!['dependency_closure','full_tree'].includes(x.inventory_mode)))fail('runtime inventory breadth not admitted');
    const pins=[...p.source_files,...roots.flatMap(x=>x.files)];
    if(pins.some(x=>!/^([A-Za-z]:\\)/.test(x.path)||!/^([a-f0-9]{64})$/.test(x.sha256)))fail('invalid trusted artifact pins');
    if(!/^[a-f0-9]{64}$/.test(p.private_packet_acl_sha256))fail('uncaptured private packet ACL');
    const encoded=Buffer.from(JSON.stringify({pins,roots,private_packet:p.private_packet,
      private_packet_sha256:p.private_packet_sha256,private_packet_acl_sha256:p.private_packet_acl_sha256})).toString('base64');
    const verification=`$ErrorActionPreference='Stop'
$p=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('${encoded}')) | ConvertFrom-Json -AsHashtable
$pinBytes=0L
foreach($f in $p.pins){$i=Get-Item -LiteralPath $f.path -Force -ErrorAction Stop
$pinBytes+=$i.Length;if($i.Length -gt 536870912 -or $pinBytes -gt 4294967296){throw 'bounded dependency hash budget'}
if($i.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'reparse artifact'}
if((Get-FileHash -LiteralPath $f.path -Algorithm SHA256).Hash.ToLowerInvariant() -cne $f.sha256){throw 'artifact drift'}}
foreach($r in $p.roots){
$root=[IO.Path]::GetFullPath($r.root);if($root -cne $r.root){throw 'runtime root canonicality'}
$expected=[Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
foreach($f in $r.files){if(-not $f.path.StartsWith($root+'\\',[StringComparison]::OrdinalIgnoreCase) -or -not $expected.Add($f.path)){throw 'runtime pin boundary/duplicate'}}
if($r.inventory_mode -ceq 'dependency_closure'){continue}
$todo=[Collections.Generic.Stack[string]]::new();$todo.Push($root);$count=0;$total=0L;$directories=0
while($todo.Count){$dir=$todo.Pop();$directories++;if($directories -gt 10000){throw 'runtime directory bound'}
$di=Get-Item -LiteralPath $dir -Force -ErrorAction Stop
if($di.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'runtime parent reparse'}
foreach($i in @(Get-ChildItem -LiteralPath $dir -Force -ErrorAction Stop)){
if($i.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'runtime reparse'}
if($i -is [IO.DirectoryInfo]){$todo.Push($i.FullName)}else{
$count++;$total+=$i.Length;if($count -gt 10000 -or $total -gt 1073741824 -or -not $expected.Remove($i.FullName)){throw 'runtime set/size drift'}
}}}
if($expected.Count -ne 0 -or $count -ne $r.files.Count){throw 'runtime inventory incomplete'}}
$packet=Get-Item -LiteralPath $p.private_packet -Force -ErrorAction Stop
if($packet -isnot [IO.FileInfo] -or $packet.Length -gt 1048576){throw 'private packet type/bound'}
$node=$packet;$depth=0
while($null -ne $node){$depth++;if($depth -gt 128 -or $node.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'private packet ancestor'}
if($node -is [IO.FileInfo]){$node=$node.Directory}else{$node=$node.Parent}}
$acl=Get-Acl -LiteralPath $p.private_packet -ErrorAction Stop
$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
if(-not $acl.AreAccessRulesProtected -or $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -cne $sid){throw 'private packet owner/protection'}
foreach($rule in $acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])){
if($rule.IdentityReference.Value -cnotin @($sid,'S-1-5-18')){throw 'private packet non-private ACL'}}
$aclHash=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($acl.GetSecurityDescriptorBinaryForm())).ToLowerInvariant()
if($aclHash -cne $p.private_packet_acl_sha256 -or (Get-FileHash -LiteralPath $p.private_packet -Algorithm SHA256).Hash.ToLowerInvariant() -cne $p.private_packet_sha256){throw 'private packet pin drift'}
'PIN_SET_VERIFIED'`;
    const checked=await this.bounded(()=>this.tools.exec_command({cmd:verification,login:false,max_output_tokens:1000}));
    if(checked.exit_code!==0||checked.output.trim()!=='PIN_SET_VERIFIED')fail('source/runtime admission failed');
    const cmd=`& ${quote(p.python_executable)} -E -s -S -B -u ${quote(p.python_entry)} ${quote(p.private_packet)} ${quote(p.private_packet_sha256)}`;
    return this.observe(await this.bounded(()=>this.tools.exec_command({cmd,login:false,tty:false,yield_time_ms:1000,max_output_tokens:MAX_OUTPUT})));
  }
  async bounded(work){
    let timer;
    try{return await Promise.race([work(),new Promise((_,reject)=>{
      timer=setTimeout(()=>reject(new Error('Codex session tool timeout; retain outcome, no replay')),30000);
    })]);}finally{clearTimeout(timer);}
  }
  observe(result){
    if(this.terminal)fail('terminal assembly session');
    if(result.session_id!==undefined){
      if(this.id!==null&&this.id!==result.session_id)fail('assembly tool session changed');
      this.id=result.session_id;
    }
    if(result.original_token_count>MAX_OUTPUT||typeof result.output!=='string')fail('truncated/invalid assembly output');
    if(result.exit_code!==undefined&&result.exit_code!==0)fail('assembly exited unsuccessfully; no replay');
    if(result.exit_code!==undefined)this.exitCode=result.exit_code;
    return result.output;
  }
  async send(value){
    if(this.terminal||this.id===null)fail('assembly session not writable');
    const chars=JSON.stringify(value)+'\n';
    if(Buffer.byteLength(chars)>65536)fail('assembly delivery bound');
    return this.observe(await this.bounded(()=>this.tools.write_stdin({session_id:this.id,chars,yield_time_ms:1000,max_output_tokens:MAX_OUTPUT})));
  }
  async read(){
    if(this.terminal||this.id===null)fail('assembly session unavailable');
    return this.observe(await this.bounded(()=>this.tools.write_stdin({session_id:this.id,chars:'',yield_time_ms:1000,max_output_tokens:MAX_OUTPUT})));
  }
  async finish(){
    const deadline=performance.now()+30000;
    while(this.exitCode===null&&performance.now()<=deadline){
      if((await this.read()).trim())fail('unexpected output after assembly completion');
    }
    if(this.exitCode!==0)fail('assembly child exit uncertain; no restart/replay');
  }
  poison(){this.terminal=true;} // Never terminate/restart/replay an uncertain process.
}

export async function runCodexHost({tools, packet, session=new CodexToolSession(tools,packet),
    clock=()=>Date.now(), monotonic=()=>performance.now(), collectInstallation=null}){
  if(packet.operation==='pilot'&&typeof collectInstallation!=='function')fail('independent installation collector not admitted');
  const began=monotonic();
  const evidenceOrder=['before-marketplace_remove','before-marketplace_remove','marketplace_remove',
    'before-marketplace_add','marketplace_add','before-plugin_add','plugin_add'];
  let evidenceIndex=0;
  const queue=[];let pending='', host;
  const append=chunk=>{
    pending+=chunk;
    if(Buffer.byteLength(pending)>65536)fail('assembly output frame bound');
    let end;
    while((end=pending.indexOf('\n'))>=0){
      const line=pending.slice(0,end).replace(/\r$/,'');pending=pending.slice(end+1);
      if(!line)continue;
      queue.push(JSON.parse(line));
      if(queue.length>4)fail('unexpected assembly output backlog');
    }
  };
  host=createTrustedAgentHost({tools, reviewed:packet.reviewed,clock,monotonic,
    deliver:async envelope=>{
      append(await session.send({kind:'tool_delivery',session:packet.session,envelope}));
      return {delivered_once:true};
    }});
  try{
    append(await session.start());
    let count=0;
    while(monotonic()-began<=120000){
      if(!queue.length){append(await session.read());continue;}
      const value=queue.shift();
      if(value.session!==packet.session)fail('assembly session substitution');
      if(value.kind==='tool_challenge'){
        if(++count>128)fail('assembly tool invocation capacity');
        await host.handle(value.challenge);
      }else if(value.kind==='install_observation_challenge'){
        const allowed=['before-marketplace_remove','marketplace_remove','before-marketplace_add','marketplace_add','before-plugin_add','plugin_add'];
        if(packet.operation!=='pilot'||typeof collectInstallation!=='function'||!allowed.includes(value.operation)||
            value.operation!==evidenceOrder[evidenceIndex++]||
            value.spec_sha256!==packet.installation_spec_sha256||value.challenge?.operation!==value.operation||
            value.challenge?.context_sha256!==packet.reviewed.context_sha256||
            value.challenge?.binding_sha256!==packet.collector_binding_sha256)fail('unadmitted installation observation');
        // Capability belongs to the trusted host. Never eval a collector or read
        // an arbitrary path/command from a challenge or an untrusted plan.
        let timer;
        let raw;
        try{raw=await Promise.race([collectInstallation({operation:value.operation,spec_sha256:value.spec_sha256,challenge:value.challenge}),
          new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('installation observation timeout; no replay')),30000);})]);
        }finally{clearTimeout(timer);}
        if(!(raw instanceof Uint8Array)||raw.byteLength>32768)fail('installation observation shape/bound');
        const producer=packet.source_files.find(x=>x.name==='codex_developer_codex_entry.mjs')?.sha256;
        if(producer!==packet.collector_binding.metadata_producer_source_sha256)fail('metadata producer source mismatch');
        const envelope={challenge:value.challenge,producer_source_sha256:producer,facts:JSON.parse(Buffer.from(raw).toString('utf8'))};
        append(await session.send({kind:'install_observation_delivery',session:packet.session,
          operation:value.operation,spec_sha256:value.spec_sha256,raw_base64:Buffer.from(JSON.stringify(canonical(envelope))
            .replace(/[\u007f-\uffff]/g,char=>'\\u'+char.charCodeAt(0).toString(16).padStart(4,'0'))).toString('base64')}));
      }else if(value.kind==='assembly_result'){
        if(value.authorizes_install!==false||value.session_closed!==true||queue.length||pending.trim())
          fail('incomplete/overbroad assembly completion');
        await session.finish();
        return value;
      }else fail('unknown assembly event');
    }
    fail('assembly total deadline; retain uncertain outcome');
  }finally{host.close();session.poison();}
}
