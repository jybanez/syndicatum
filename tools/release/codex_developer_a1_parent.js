(function(){
// Trusted cooperating Codex parent. Pure JS hashing/framing; no helper process.
const PROFILE='1f397680dd1cddc0.2.33';
const fail=code=>{throw new Error(code);};
function sha256(s){
 const k=[],h=[];for(let n=2;k.length<64;n++){let prime=true;for(let d=2;d*d<=n;d++)if(n%d===0){prime=false;break;}if(prime){if(h.length<8)h.push((Math.sqrt(n)%1*4294967296)|0);k.push((Math.cbrt(n)%1*4294967296)|0);}}
 const l=Math.ceil((s.length+9)/64)*16,w=new Array(l).fill(0);for(let i=0;i<s.length;i++){if(s.charCodeAt(i)>127)fail('hash_requires_ascii');w[i>>2]|=s.charCodeAt(i)<<(24-(i%4)*8);}w[s.length>>2]|=128<<(24-(s.length%4)*8);w[l-1]=s.length*8;
 const r=(x,n)=>(x>>>n)|(x<<(32-n));for(let j=0;j<l;j+=16){let [a,b,c,d,e,f,g,z]=h;const v=w.slice(j,j+16);for(let i=0;i<64;i++){if(i>=16){const x=v[i-15],y=v[i-2];v[i]=((r(x,7)^r(x,18)^(x>>>3))+v[i-16]+(r(y,17)^r(y,19)^(y>>>10))+v[i-7])|0;}const t1=(z+(r(e,6)^r(e,11)^r(e,25))+((e&f)^(~e&g))+k[i]+v[i])|0,t2=((r(a,2)^r(a,13)^r(a,22))+((a&b)^(a&c)^(b&c)))|0;z=g;g=f;f=e;e=(d+t1)|0;d=c;c=b;b=a;a=(t1+t2)|0;}[a,b,c,d,e,f,g,z].forEach((x,i)=>h[i]=(h[i]+x)|0);}return h.map(x=>(x>>>0).toString(16).padStart(8,'0')).join('');
}
function sorted(x){if(Array.isArray(x))return x.map(sorted);if(x&&typeof x==='object')return Object.fromEntries(Object.keys(x).sort().map(k=>[k,sorted(x[k])]));return x;}
const canonical=x=>JSON.stringify(sorted(x)).replace(/[^\x00-\x7F]/g,c=>'\\u'+c.charCodeAt(0).toString(16).padStart(4,'0'));
function base64(raw){const abc='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';let out='';for(let i=0;i<raw.length;i+=3){const n=(raw.charCodeAt(i)<<16)|((raw.charCodeAt(i+1)||0)<<8)|(raw.charCodeAt(i+2)||0);out+=abc[(n>>>18)&63]+abc[(n>>>12)&63]+(i+1<raw.length?abc[(n>>>6)&63]:'=')+(i+2<raw.length?abc[n&63]:'=');}return out;}
const markerTag=({session,requestId,approvalId,mode})=>sha256(`${session}|${requestId}|${approvalId}|${mode}`).slice(0,12);
function marker(output,context,sequence){
 if(output.length>131072)fail('terminal_output_bound');const tag=markerTag(context),expected=`!Q${tag}:${sequence}!`;
 // Exact compact literal only. NEVER strip ANSI/CRLF into a marker or authority.
 if(output.includes(`!O${tag}:STOP!`))return 'STOP';
 if(output.includes(`!O${tag}:OK!`))return 'OK';
 const found=[...output.matchAll(new RegExp(`!Q${tag}:([0-9]+)!`,'g'))].map(x=>Number(x[1]));
 if(found.some(x=>x!==sequence)||found.length>1)fail('duplicate_or_unexpected_checkpoint');
 return output.includes(expected)?'QUERY':null;
}
function toolValue(result){if(result.isError)fail('protected_read_failed');const text=result.content?.find(x=>x.type==='text')?.text;if(typeof text!=='string'||text.length>32768)fail('protected_result_bound');return JSON.parse(text);}
function wire(value,session,sequence){const raw=canonical(value);if(raw.length>65536)fail('response_bound');const chunks=base64(raw).match(/.{1,768}/g);return chunks.map((x,i)=>`A1R ${session} ${sequence} ${i+1} ${chunks.length} ${x}\n`).join('')+`A1E ${session} ${sequence} ${sha256(raw)}\n`;}
async function pump(tools,context,initial,{now=Date.now,onProgress=()=>{}}={}){
 if(!/^[a-f0-9]{48}$/.test(context.session)||!['inert','run'].includes(context.mode)||![context.requestId,context.approvalId].every(x=>Number.isInteger(x)&&x>0))fail('parent_context');
 if(sha256('abc')!=='ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad')fail('parent_hash_selftest');
 const id=initial.session_id;if(!id)fail('host_session_not_running');let current=initial,buffer='',responses=0;
 for(let sequence=1;sequence<=8;sequence++){
  const started=now();let state=null;buffer=current.output||'';
  while(!(state=marker(buffer,context,sequence))){if(!current.session_id)fail('host_eof');if(now()-started>90000)fail('parent_checkpoint_deadline');current=await tools.write_stdin({session_id:id,chars:'',yield_time_ms:1000,max_output_tokens:2000});buffer+=current.output||'';}
  if(state==='STOP')return {status:'STOP_NO_RETRY',responses};
  if(state==='OK'){if(responses!==7)fail('premature_terminal');return {status:'transport_complete',responses};}
  if(sequence>7)fail('too_many_checkpoints');
  const [r,a]=await Promise.all([
   tools.mcp__syndicatum_codex__syndicatum_get_message({profile_id:PROFILE,message_id:context.requestId}),
   tools.mcp__syndicatum_codex__syndicatum_get_message({profile_id:PROFILE,message_id:context.approvalId})]);
  const request=toolValue(r),approval=toolValue(a);
  if(request.profile?.profile_id!==PROFILE||approval.profile?.profile_id!==PROFILE||request.message?.id!==context.requestId||approval.message?.id!==context.approvalId||request.message.deleted_at||approval.message.deleted_at)fail('protected_context_changed');
  if(now()-started>80000)fail('insufficient_response_deadline');
  const value={session:context.session,sequence,host_observed_ms:now(),request,approval};
  current=await tools.write_stdin({session_id:id,chars:wire(value,context.session,sequence),yield_time_ms:1000,max_output_tokens:2000});responses++;onProgress({sequence,responses});
 }
 fail('parent_terminal_missing');
}

return {PROFILE,sha256,canonical,base64,markerTag,marker,wire,pump};
})()
