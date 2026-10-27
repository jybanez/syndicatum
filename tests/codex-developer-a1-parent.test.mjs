import test from 'node:test';import assert from 'node:assert/strict';import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
const {PROFILE,sha256,canonical,base64,markerTag,marker,pump}=eval(readFileSync(new URL('../tools/release/codex_developer_a1_parent.js',import.meta.url),'utf8'));
const context={session:'a'.repeat(48),requestId:11697,approvalId:11698,mode:'inert'},tag=markerTag(context);
const Q=n=>`!Q${tag}:${n}!`,OK=`!O${tag}:OK!`,STOP=`!O${tag}:STOP!`;
function fixture(stopAt=0){let seq=1,writes=0,reads=0;const tools={
 async mcp__syndicatum_codex__syndicatum_get_message({profile_id,message_id}){assert.equal(profile_id,PROFILE);reads++;return {content:[{type:'text',text:JSON.stringify({profile:{profile_id:PROFILE},message:{id:message_id,deleted_at:null,body:'actual-shaped '+ 'x'.repeat(5000),system_event:{type:'responsibility.approval_approved',data:{request_message_id:context.requestId,actor_participant_id:28}}}})}]};},
 async write_stdin({chars}){assert.ok(chars.startsWith('A1R '));writes++;assert.equal(writes,seq);assert.ok(chars.endsWith('\n'));if(stopAt===seq)return {output:STOP,exit_code:0};seq++;return {session_id:7,output:seq===8?OK:`wrapped\r\n\x1b[23;80Hdisplay noise\r\n${Q(seq)}\r\n`};},
 exec_command(){throw new Error('helper process prohibited');}};
 return {tools,initial:{session_id:7,output:Q(1)},counts:()=>({writes,reads})};}
test('pure JS hashes and ASCII framing match standard implementation',()=>{for(const s of ['', 'abc','x'.repeat(10000),canonical({name:'Ybañez'})]){assert.equal(sha256(s),createHash('sha256').update(s).digest('hex'));assert.equal(base64(s),Buffer.from(s).toString('base64'));}});
test('seven checkpoints with wrapped terminal noise; no helper/native process',async()=>{const f=fixture();assert.deepEqual(await pump(f.tools,context,f.initial),{status:'transport_complete',responses:7});assert.deepEqual(f.counts(),{writes:7,reads:14});});
test('timeout/late result terminates without successor or replay',async()=>{const f=fixture(3);assert.deepEqual(await pump(f.tools,context,f.initial),{status:'STOP_NO_RETRY',responses:3});assert.deepEqual(f.counts(),{writes:3,reads:6});});
test('compact known marker only; never arbitrary control stripping or input echo',()=>{assert.equal(marker('A1R echo\r\n\x1b[2J'+Q(3),context,3),'QUERY');assert.equal(marker(Q(3).slice(0,8)+'\x1b[23;80H'+Q(3).slice(8),context,3),null);assert.equal(marker(Q(3).slice(0,8)+'\r\n'+Q(3).slice(8),context,3),null);assert.throws(()=>marker(Q(2),context,3));assert.throws(()=>marker(Q(3)+Q(3),context,3));});
