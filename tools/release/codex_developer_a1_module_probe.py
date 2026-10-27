"""A1-only module probe. Parent approval/launch is owned by A1 runner."""
import importlib, json, re, sys
from pathlib import Path

def parse_frame(raw):
    if type(raw) is not bytes or len(raw)>4096: raise ValueError('frame_bound')
    if not re.fullmatch(rb'{"nonce":"[a-f0-9]{48}"}\n',raw): raise ValueError('frame_contract')
    return json.loads(raw)['nonce']

def main():
    if len(sys.argv)!=3 or sys.argv[1]!='--a1-nonce' or not re.fullmatch('[a-f0-9]{48}',sys.argv[2]): raise ValueError('launch_context')
    raw=sys.stdin.buffer.read(4097) # EOF required, parent deadline includes this read.
    nonce=parse_frame(raw)
    if nonce!=sys.argv[2]: raise ValueError('nonce_context')
    sys.path.insert(0,str(Path(__file__).resolve().parent))
    names=('codex_developer_host_assembly','codex_developer_install_collector','codex_developer_process_ipc')
    modules={name:importlib.import_module(name).__file__ for name in names}
    print(json.dumps({'nonce':nonce,'modules':modules},sort_keys=True))

if __name__=='__main__':main()
