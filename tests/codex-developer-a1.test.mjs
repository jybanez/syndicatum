import test from 'node:test';import assert from 'node:assert/strict';
import {parseFrame} from '../tools/release/codex_developer_a1_module_probe.mjs';
const good=Buffer.from(JSON.stringify({nonce:'a'.repeat(48)})+'\n');
test('one canonical nonce frame followed by EOF; no trailing data or duplicate keys',()=>{
 assert.equal(parseFrame(good),'a'.repeat(48));
 for(const raw of [Buffer.concat([good,good]),Buffer.concat([good,Buffer.from('x')]),Buffer.from('{'),Buffer.from('{"nonce":42}\n'),Buffer.from('{"nonce":null}\n'),Buffer.from('{"nonce":"'+ 'a'.repeat(48)+'","nonce":"'+'a'.repeat(48)+'"}\n'),Buffer.alloc(4097),Buffer.alloc(0),good.subarray(0,-1)])assert.throws(()=>parseFrame(raw));
});
