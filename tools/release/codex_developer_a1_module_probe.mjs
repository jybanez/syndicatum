throw new Error('SOURCE_ONLY: A1 module/stdin probe disabled');
const entry=await import('./codex_developer_codex_entry.mjs');
const chunks=[];let size=0;
for await(const chunk of process.stdin){size+=chunk.length;if(size>4096)throw new Error('frame_bound');chunks.push(chunk);}
const raw=Buffer.concat(chunks).toString('utf8');
if(!raw.endsWith('\n')||raw.trim().includes('\n'))throw new Error('frame_count');
const value=JSON.parse(raw);
if(Object.keys(value).join(',')!=='nonce'||!/^[a-f0-9]{48}$/.test(value.nonce))throw new Error('nonce_schema');
console.log(JSON.stringify({nonce:value.nonce,source_files:entry.SOURCE_FILES}));
