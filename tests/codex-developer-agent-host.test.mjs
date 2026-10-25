import test from 'node:test';
import assert from 'node:assert/strict';
import {createTrustedAgentHost} from '../tools/release/codex_developer_agent_host.mjs';
const tool = 'mcp__syndicatum_codex__syndicatum_get_message';
const profile = '1f397680dd1cddc0.2.33';
const reviewed = {request_id:99998, approval_id:99999, context_sha256:'a'.repeat(64)};
const challenge = () => ({...reviewed, invocation_id:'b'.repeat(48), tool,
  arguments:{profile_id:profile,message_id:99998},session:'inert',sequence:1});
const canonical = {profile:{profile_id:profile,project_id:2,participant_id:36,agent_id:33,
  syndicatum_url:'https://syndicatum.wizaya.com'},message:{id:99998}};
test('existing protected tool result is delivered once with exact original bytes', async () => {
  const text = JSON.stringify(canonical,null,2), calls=[], deliveries=[];
  const host=createTrustedAgentHost({reviewed, tools:{[tool]: async args => {
    calls.push(args);return {content:[{type:'text',text}]};
  }}, deliver:async value => {deliveries.push(value);return 'retained';}});
  assert.equal(await host.handle(challenge()),'retained');
  assert.equal(Buffer.from(deliveries[0].raw_base64,'base64').toString(),text);
  assert.deepEqual(calls,[{profile_id:profile,message_id:99998}]);
  await assert.rejects(host.handle(challenge()));
  assert.equal(deliveries.length,1);
});
test('context/substitution/late result cannot deliver or replay', async () => {
  for (const mode of ['context','profile','late']) {
    let delivered=0, now=1000;
    const host=createTrustedAgentHost({reviewed,clock:()=>now,tools:{[tool]:async()=>{
      if(mode==='late')now+=120001;
      return {content:[{type:'text',text:JSON.stringify(canonical)}]};
    }},deliver:async()=>{delivered++;}});
    const value=challenge();
    if(mode==='context')value.context_sha256='0'.repeat(64);
    if(mode==='profile')value.arguments.profile_id='wrong';
    await assert.rejects(host.handle(value));
    await assert.rejects(host.handle(challenge()));
    assert.equal(delivered,0);
  }
});
test('deadline ignores late readonly tool completion without starting delivery', async () => {
  let delivered=0;
  const host=createTrustedAgentHost({reviewed,deadlineMs:5,tools:{[tool]:async()=>{
    await new Promise(resolve=>setTimeout(resolve,20));
    return {content:[{type:'text',text:JSON.stringify(canonical)}]};
  }},deliver:async()=>{delivered++;}});
  await assert.rejects(host.handle(challenge()));
  await new Promise(resolve=>setTimeout(resolve,30));
  assert.equal(delivered,0);
});
