raise RuntimeError("SOURCE_ONLY: A1 module/stdin probe disabled")
import importlib, json, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
names=('codex_developer_host_assembly','codex_developer_install_collector','codex_developer_process_ipc')
modules={name:importlib.import_module(name).__file__ for name in names}
raw=sys.stdin.buffer.readline(4097)
if len(raw)>4096 or not raw.endswith(b'\n'): raise ValueError('frame_bound')
value=json.loads(raw)
if set(value)!={'nonce'} or len(value['nonce'])!=48 or any(c not in '0123456789abcdef' for c in value['nonce']): raise ValueError('nonce_schema')
print(json.dumps({'nonce':value['nonce'],'modules':modules},sort_keys=True))
