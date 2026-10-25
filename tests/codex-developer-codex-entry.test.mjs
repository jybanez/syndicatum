import test from 'node:test';
import assert from 'node:assert/strict';
import {CodexToolSession,runCodexHost} from '../tools/release/codex_developer_codex_entry.mjs';
const profile='1f397680dd1cddc0.2.33', tool='mcp__syndicatum_codex__syndicatum_get_message';
const packet={session:'assembly',reviewed:{request_id:99998,approval_id:99999,context_sha256:'a'.repeat(64)}};
const challenge={...packet.reviewed,session:'tools',sequence:1,invocation_id:'b'.repeat(48),tool,
  arguments:{profile_id:profile,message_id:99998}};
test('actual host pump reaches protected handler and correlated private coordinator delivery',async()=>{
  const calls=[],deliveries=[];
  const tools={[tool]:async args=>{calls.push(args);return {content:[{type:'text',text:JSON.stringify({
    profile:{profile_id:profile,project_id:2,participant_id:36,agent_id:33,syndicatum_url:'https://syndicatum.wizaya.com'},
    message:{id:99998}})}]};}};
  const session={
    async start(){return JSON.stringify({kind:'tool_challenge',session:packet.session,challenge})+'\n';},
    async send(value){deliveries.push(value);return JSON.stringify({kind:'assembly_result',session:packet.session,
      result:{result:'cutoff_prepared_only'},session_closed:true,authorizes_install:false})+'\n';},
    async finish(){this.exitConfirmed=true;},
    poison(){this.terminal=true;},
  };
  const result=await runCodexHost({tools,packet,session});
  assert.equal(result.authorizes_install,false);
  assert.deepEqual(calls,[{profile_id:profile,message_id:99998}]);
  assert.equal(deliveries[0].kind,'tool_delivery');
  assert.equal(deliveries[0].session,packet.session);
  assert.deepEqual(deliveries[0].envelope.invocation,challenge);
  assert.equal(session.terminal,true);
  assert.equal(session.exitConfirmed,true);
});
test('source entry refuses before any execution tool or private packet operation',async()=>{
  let calls=0;const tools={exec_command:async()=>{calls++;}};
  await assert.rejects(new CodexToolSession(tools,packet).start(),/SOURCE_ONLY/);
  assert.equal(calls,0);
});
test('pilot independent evidence capability is correlated and required before launch',async()=>{
  const p={...packet,operation:'pilot',installation_spec_sha256:'c'.repeat(64),collector_binding_sha256:'d'.repeat(64),
    collector_binding:{metadata_producer_source_sha256:'f'.repeat(64)},source_files:[{name:'codex_developer_codex_entry.mjs',sha256:'f'.repeat(64)}]};
  let started=0, collected=0, delivered;
  const session={async start(){started++;return JSON.stringify({kind:'install_observation_challenge',session:p.session,
    operation:'before-marketplace_remove',spec_sha256:p.installation_spec_sha256,challenge:{operation:'before-marketplace_remove',
      context_sha256:p.reviewed.context_sha256,binding_sha256:p.collector_binding_sha256,nonce:'e'.repeat(48),sequence:1}})+'\n';},
    async send(value){delivered=value;return JSON.stringify({kind:'assembly_result',session:p.session,
      session_closed:true,authorizes_install:false})+'\n';},async finish(){},poison(){}};
  const tools={[tool]:async()=>{throw new Error('unused');}};
  await assert.rejects(runCodexHost({tools,packet:p,session}),/collector not admitted/);
  assert.equal(started,0);
  await runCodexHost({tools,packet:p,session,collectInstallation:async value=>{
    collected++;assert.equal(value.spec_sha256,p.installation_spec_sha256);return new TextEncoder().encode('{"inert":true}');
  }});
  assert.equal(collected,1);
  assert.equal(delivered.kind,'install_observation_delivery');
  assert.deepEqual(JSON.parse(Buffer.from(delivered.raw_base64,'base64').toString()).facts,{inert:true});
});
