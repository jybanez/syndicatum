import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {execFileSync} from 'node:child_process';
const parentSource=execFileSync('git',['show','HEAD:tools/release/codex_developer_a1_parent.js'],{encoding:'utf8'});
const launcherSource=fs.readFileSync(new URL('../tools/release/codex_developer_process_launch.js',import.meta.url),'utf8');
const parent=vm.runInNewContext(parentSource),launcher=vm.runInNewContext(launcherSource);
const hostSource='print("inert")\n',profile='1f397680dd1cddc0.2.33';
function setup(){
 const context={session:'a'.repeat(48),requestId:99998,approvalId:99999};
 const p={scope:'Developer PROCESS-ONLY',capture_identity:'developer-0210-process-origin-11752-v1',
  source_head:'b'.repeat(40),launcher_sha256:parent.sha256(launcherSource),
  sources:{'codex_developer_process_host.py':{sha256:parent.sha256(hostSource),size:hostSource.length}},
  python:{path:'C:\\inert\\python.exe'},powershell:{path:'C:\\inert\\pwsh.exe'},
  proposal_path:'C:\\inert\\Syndicatum-Developer-process-origin-11752-v1.json',
  repository_root:'C:\\inert\\repo',capture_parent:'C:\\inert\\private'};
 return {parent,parentSource,launcherSource,hostSource,proposalSource:parent.canonical(p),context};
}
test('pure launch builder has fixed flags, pinned shell, no helper hash process and bounded command',()=>{
 const result=launcher.build(setup());
 assert.match(result.command,/-E -s -S -B -P -u -c/);assert.match(result.command,/--process-only/);
 assert.equal(result.launch.shell,'C:\\inert\\pwsh.exe');assert.equal(result.launch.login,false);
 assert.equal(result.launch.tty,true);assert.ok(result.command.length<=30000);
 for(const change of ['hostSource','parentSource','launcherSource']){
  const options=setup();options[change]+='drift';assert.throws(()=>launcher.build(options));
 }
});
test('actual pinned bootstrap fits the Windows launch bound without source normalization',()=>{
 const options=setup();options.hostSource=fs.readFileSync(new URL('../tools/release/codex_developer_process_host.py',import.meta.url),'utf8');
 const p=JSON.parse(options.proposalSource);p.sources['codex_developer_process_host.py']={
  sha256:parent.sha256(options.hostSource),size:options.hostSource.length};
 options.proposalSource=parent.canonical(p);
 const result=launcher.build(options);assert.ok(result.command.length<=30000);
});
test('canonical denial prevents every native execution tool call',async()=>{
 const options=setup();let execs=0;
 const tools={exec_command:async()=>{execs++;},mcp__syndicatum_codex__syndicatum_get_message:async args=>({
  content:[{type:'text',text:JSON.stringify({profile:{profile_id:profile},message:{id:args.message_id}})}]})};
 await assert.rejects(launcher.launch({...options,tools}),/owner authority/);assert.equal(execs,0);
});
test('exact owner/pins permit only one capture host launch and reuse unchanged parent',async()=>{
 const options=setup(),b=launcher.build(options);let execs=0,reads=0;
 const message={id:99998,project_id:2,sender:{participant_id:36},deleted_at:null,revision_count:0,
  action_requested:true,action_request_type:'approval',addressees:[{participant_id:28}],
  body:[b.packet.source_head,b.proposalSha,b.entrySha,launcher.PARENT_SHA,b.packet.launcher_sha256,b.packet.scope,b.packet.capture_identity].join(' ')};
 const approval={id:99999,project_id:2,sender:{participant_id:28},deleted_at:null,revision_count:0,
  system_event:{type:'responsibility.approval_approved',data:{request_message_id:99998,actor_participant_id:28,kind:'approval_approved'}}};
 const tools={mcp__syndicatum_codex__syndicatum_get_message:async args=>{
  reads++;return {content:[{type:'text',text:JSON.stringify({profile:{profile_id:profile},message:args.message_id===99998?message:approval})}]};},
  exec_command:async args=>{execs++;assert.equal(args.cmd,b.command);return {session_id:123,
   output:`!O${parent.markerTag({...options.context,mode:'run'})}:STOP!`};}};
 const result=await launcher.launch({...options,tools});assert.equal(result.status,'STOP_NO_RETRY');
 assert.equal(execs,1);assert.equal(reads,2);
 await assert.rejects(launcher.launch({...options,tools}),/decision consumed/);assert.equal(execs,1);
});
