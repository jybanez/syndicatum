(function(){
// Existing trusted JS tool runtime. No Node/Python hashing helper or new service.
const PROFILE='1f397680dd1cddc0.2.33';
const PARENT_SHA='f36b007796ba523862c6477c17ff90fc25e5d447f081f972ce336b02361e9857';
const consumed=new Set();
const fail=message=>{throw new Error(message);};
const quote=value=>"'"+value.replaceAll("'","''")+"'";
function parsed(result){
 if(result.isError)fail('protected admission read failed');
 const raw=result.content?.find(x=>x.type==='text')?.text;
 if(typeof raw!=='string'||raw.length>32768)fail('protected admission result bound');
 return JSON.parse(raw);
}
function build({parent,parentSource,launcherSource,hostSource,proposalSource,context}){
 if(parent.sha256(parentSource)!==PARENT_SHA)fail('accepted parent pin');
 const p=JSON.parse(proposalSource),proposalSha=parent.sha256(proposalSource),entrySha=parent.sha256(hostSource);
 if(parent.canonical(p)!==proposalSource||p.scope!=='Developer PROCESS-ONLY'||
    p.capture_identity!=='developer-0210-process-origin-11752-v1'||
    p.launcher_sha256!==parent.sha256(launcherSource))fail('admission proposal scope');
 for(const value of [p.python?.path,p.powershell?.path,p.proposal_path,p.repository_root,p.capture_parent])
  if(typeof value!=='string'||!(/^[A-Za-z]:\\/).test(value))fail('admission local path shape');
 if(p.sources['codex_developer_process_host.py']?.sha256!==entrySha||
    !/^[a-f0-9]{40}$/.test(p.source_head)||!/^[a-f0-9]{48}$/.test(context.session)||
    ![context.requestId,context.approvalId].every(x=>Number.isInteger(x)&&x>0))fail('admission launch binding');
 const code=`import base64;exec(compile(base64.b64decode('${parent.base64(hostSource)}'),'reviewed_admission_host','exec'))`;
 const command=`& ${quote(p.python.path)} -E -s -S -B -P -u -c ${quote(code)} --process-only --head ${p.source_head}`+
  ` --proposal ${quote(p.proposal_path)} --proposal-sha ${proposalSha} --entry-sha ${entrySha} --parent-sha ${PARENT_SHA}`+
  ` --session ${context.session} --request-id ${context.requestId} --approval-id ${context.approvalId}`;
 if(command.length>30000)fail('Windows admission command line bound');
 return {packet:p,proposalSha,entrySha,command,launch:{cmd:command,shell:p.powershell.path,login:false,
  workdir:p.repository_root,tty:true,yield_time_ms:1000,max_output_tokens:2000}};
}
async function launch(options){
 const {tools,parent,context}=options,b=build(options);
 // An initial protected read occurs BEFORE the tool shell, Python or local Git
 // children. The unchanged parent then retains seven fresh native checkpoints.
 const [rr,aa]=await Promise.all([
  tools.mcp__syndicatum_codex__syndicatum_get_message({profile_id:PROFILE,message_id:context.requestId}),
  tools.mcp__syndicatum_codex__syndicatum_get_message({profile_id:PROFILE,message_id:context.approvalId})]);
 const r=parsed(rr),a=parsed(aa),m=r.message,e=a.message;
 if(r.profile?.profile_id!==PROFILE||a.profile?.profile_id!==PROFILE||m?.id!==context.requestId||e?.id!==context.approvalId||
    m.project_id!==2||e.project_id!==2||m.sender?.participant_id!==36||e.sender?.participant_id!==28||
    m.deleted_at||e.deleted_at||m.revision_count!==0||e.revision_count!==0||m.action_requested!==true||
    m.action_request_type!=='approval'||!m.addressees?.some(x=>x.participant_id===28)||
    e.system_event?.type!=='responsibility.approval_approved'||e.system_event.data?.request_message_id!==context.requestId||
    e.system_event.data?.actor_participant_id!==28||e.system_event.data?.kind!=='approval_approved')fail('admission owner authority');
 for(const pin of [b.packet.source_head,b.proposalSha,b.entrySha,PARENT_SHA,b.packet.launcher_sha256,
   'Developer PROCESS-ONLY','developer-0210-process-origin-11752-v1'])if(!m.body.includes(pin))fail('admission canonical scope pins');
 const attempt=`${context.requestId}:${context.approvalId}:${b.proposalSha}`;
 if(consumed.has(attempt))fail('admission decision consumed; no replay');
 consumed.add(attempt);
 const initial=await tools.exec_command(b.launch);
 return parent.pump(tools,{...context,mode:'run'},initial,options.pumpOptions);
}
return {PROFILE,PARENT_SHA,build,launch};
})()
