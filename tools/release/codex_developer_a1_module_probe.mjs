import {pathToFileURL} from 'node:url';
export function parseFrame(raw){
  if(!Buffer.isBuffer(raw)||raw.length>4096)throw new Error('frame_bound');
  if(!/^\{"nonce":"[a-f0-9]{48}"\}\n$/.test(raw.toString('utf8'))||raw.length!==61)throw new Error('frame_contract');
  return JSON.parse(raw.toString('utf8')).nonce;
}
async function main(){
  if(process.argv.length!==4||process.argv[2]!=='--a1-nonce'||!/^[a-f0-9]{48}$/.test(process.argv[3]))throw new Error('launch_context');
  const chunks=[];let size=0;
  for await(const chunk of process.stdin){size+=chunk.length;if(size>4096)throw new Error('frame_bound');chunks.push(chunk);}
  const nonce=parseFrame(Buffer.concat(chunks));if(nonce!==process.argv[3])throw new Error('nonce_context');
  const entry=await import('./codex_developer_codex_entry.mjs');
  console.log(JSON.stringify({nonce,source_files:entry.SOURCE_FILES,entry_url:new URL('./codex_developer_codex_entry.mjs',import.meta.url).href}));
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href)await main();
