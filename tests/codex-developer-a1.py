"""Inert parsers and A1 orchestration only; never native launch/capture."""
import importlib.util, io, json, sys, tempfile, unittest, hashlib, subprocess, stat
from types import SimpleNamespace
from unittest.mock import patch
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def load(name):
 s=importlib.util.spec_from_file_location(name,ROOT/'tools/release'/(name+'.py'));m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
probe=load('codex_developer_a1_module_probe');runner=load('codex_developer_a1_runner')
GOOD=b'{"nonce":"'+b'a'*48+b'"}\n'
BAD=[GOOD+GOOD,GOOD+b'x',b'{',b'{"nonce":"'+b'a'*48+b'","nonce":"'+b'a'*48+b'"}\n',b'{"nonce":42}\n',b'{"nonce":null}\n',b'x'*4097,b'',GOOD[:-1]]
class Cases(unittest.TestCase):
 def test_review_packet_binds_all_git_blob_payloads_and_scope(self):
  packet=json.loads((ROOT/'docs/codex-developer-pilot-admission-packet.json').read_text())['next_A1_decision_draft']
  rows=packet['complete_sources_and_scope']
  self.assertEqual({Path(r['repository_path']).name for r in rows},runner.REQUIRED_SOURCES)
  self.assertEqual(packet['capture_identity'],runner.CAPTURE_ID)
  for row in rows:
   raw=subprocess.check_output(['git','show',':'+row['repository_path']],cwd=ROOT)
   self.assertEqual(runner.sha(raw),row['git_blob_bytes_sha256'])
   self.assertEqual(len(raw),row['size'])
  scope=next(r for r in rows if r['repository_path'].endswith('admission.md'))
  self.assertEqual(scope['git_blob_bytes_sha256'],packet['scope_document_sha256'])
 def test_parser(self):
  self.assertEqual(probe.parse_frame(GOOD),'a'*48)
  for raw in BAD:
   with self.subTest(raw=raw[:80]),self.assertRaises(ValueError):probe.parse_frame(raw)
 def test_stop_preserves_primary_no_successor_or_replay(self):
  for fault in ('nonzero','timeout','trailing','bad_nonce','missing','wrong_python','wrong_node','wrong_ps','argv_spelling','none'):
   with self.subTest(fault=fault),tempfile.TemporaryDirectory() as tmp:
    calls=[];auth=[];source={n:(ROOT/('docs' if n.endswith('-admission.md') else 'tools/release')/n).read_bytes() for n in runner.REQUIRED_SOURCES}
    exe=Path(tmp)/'inert-executable';exe.write_bytes(b'inert executable fixture; never launched');pin=runner.sha(exe.read_bytes())
    p={'capture_identity':runner.CAPTURE_ID,'scope':'Developer A1 only','native_effects_accepted':True,'executables':{r:{'path':str(exe),'sha256':pin} for r in ('cli','python','node','powershell')},'sources':{n:{'sha256':runner.sha(b),'size':len(b)} for n,b in source.items()},'scope_document_sha256':runner.sha(source['codex-developer-a1-admission.md']),'capture_parent':tmp,'owner_sid':runner.windows_sid() if runner.os.name=='nt' else 'inert'}
    class Host:
     def create_capture(self,parent,sid):
      out=Path(parent)/runner.CAPTURE_ID;out.mkdir();return out
     def command(self,exe,args,cwd,stdin,limit):
      i=len(calls);calls.append(args)
      if i==0 and fault in ('nonzero','timeout'):raise RuntimeError('ORIGINAL_'+fault)
      raw=b'codex-cli 0.164.0-alpha.11.1\n' if i==0 else (b'{"marketplaces":[{"name":"syndicatum","root":"inert","marketplaceSource":"inert"}]}' if i==1 else b'{"installed":[],"available":[]}' if i==2 else json.dumps({'nonce':json.loads(stdin)['nonce'] if fault!='bad_nonce' else 'b'*48}).encode())
      if i>=3:
       nonce=json.loads(stdin)['nonce'];out={'nonce':nonce}
       if i==3:out['modules']={n:str((Path(cwd)/(n+'.py')).resolve()) for n in ('codex_developer_host_assembly','codex_developer_install_collector','codex_developer_process_ipc')}
       elif i==4:
        declared=source['codex_developer_codex_entry.mjs'].decode().split('Object.freeze([',1)[1].split(']);',1)[0]
        out.update(source_files=runner.re.findall(r"'([^']+)'",declared),entry_url=(Path(cwd)/'codex_developer_codex_entry.mjs').resolve().as_uri())
       else:out.update(module=str((Path(cwd)/'codex-developer-fixed-storage.psm1').resolve()),protocol_origin=str((Path(cwd)/'codex-developer-fixed-session-protocol.ps1').resolve()))
       if fault=='bad_nonce':out['nonce']='b'*48
       if fault=='missing':out={'nonce':nonce}
       if fault=='wrong_python' and i==3:out['modules']['codex_developer_host_assembly']='outside.py'
       if fault=='wrong_node' and i==4:out['entry_url']='file:///outside.mjs'
       if fault=='argv_spelling' and i==4:out['entry_url']=(Path(cwd)/'codex_developer_codex_entry.mjs').as_uri()
       if fault=='argv_spelling' and i==5:out.update(module=str(Path(cwd)/'codex-developer-fixed-storage.psm1'),protocol_origin=str(Path(cwd)/'codex-developer-fixed-session-protocol.ps1'))
       if fault=='wrong_ps' and i==5:out['module']='outside.psm1'
       raw=json.dumps(out).encode()
       if i==3 and fault=='trailing':raw+=b'x'
      return {'identity':{'pid':123},'stdout':raw,'stderr':b'','exit':0}
    if fault in ('none','argv_spelling'):self.assertEqual(runner.run(p,source,lambda *v:auth.append(v),Host())['status'],'observations_only_not_install_admission');self.assertEqual(len(calls),6)
    else:
     with self.assertRaises(Exception) as stopped:runner.run(p,source,lambda *v:auth.append(v),Host())
     self.assertEqual(len(calls),1 if fault in ('nonzero','timeout') else 5 if fault=='wrong_node' else 6 if fault=='wrong_ps' else 4)
     if fault in ('nonzero','timeout'):self.assertEqual(str(stopped.exception),'ORIGINAL_'+fault)
    count=len(calls)
    with self.assertRaisesRegex(ValueError,'capture_occupied'):runner.run(p,source,lambda *v:auth.append(v),Host())
    self.assertEqual(len(calls),count)
 def test_parent_deadline_keeps_primary_and_never_kills_or_retries(self):
  class Child:
   pid=99;stdin=io.BytesIO();stdout=io.BytesIO(b'partial');stderr=io.BytesIO(b'original diagnostic')
   def wait(self,timeout):raise subprocess.TimeoutExpired('inert',timeout)
   def kill(self):raise AssertionError('kill prohibited')
   def terminate(self):raise AssertionError('terminate prohibited')
  host=runner.NativeHost()
  with patch.object(runner.subprocess,'CREATE_NO_WINDOW',0,create=True),patch.object(runner.subprocess,'Popen',return_value=Child()) as launch:
   with self.assertRaises(subprocess.TimeoutExpired) as stopped:host.command('inert',[],'inert',b'',65536)
   self.assertEqual(launch.call_count,1)
   self.assertEqual(stopped.exception.child_identity['pid'],99)
   self.assertEqual(stopped.exception.stable_postimage,'unavailable_until_independently_quiescent')
 def test_all_reparse_tags_including_cloud_without_symlink(self):
  with tempfile.TemporaryDirectory() as tmp:
   path=Path(tmp)
   clean=lambda item:SimpleNamespace(st_file_attributes=0,st_mode=stat.S_IFDIR)
   runner.check_nonreparse_chain(path,clean)
   for bad_at in [path,path.parent]:
    def metadata(item):return SimpleNamespace(st_file_attributes=0x400 if item==bad_at else 0,st_mode=stat.S_IFDIR)
    with self.assertRaisesRegex(ValueError,'windows_reparse_point'):runner.check_nonreparse_chain(path,metadata)
   with self.assertRaisesRegex(ValueError,'windows_reparse_point'):runner.check_nonreparse_chain(path,lambda item:SimpleNamespace(st_file_attributes=0,st_mode=stat.S_IFLNK))
   with self.assertRaisesRegex(ValueError,'windows_reparse_point'):runner.check_nonreparse_chain(path,clean,lambda item:0x400)
   with self.assertRaisesRegex(ValueError,'windows_attributes_unavailable'):runner.check_nonreparse_chain(path,clean,lambda item:0xFFFFFFFF)
 def test_shared_preflight_owner_runtime_and_occupied_target_fail_closed(self):
  with tempfile.TemporaryDirectory() as tmp:
   path=Path(tmp);exe=path/'fixture';exe.write_bytes(b'not executable')
   p={'capture_parent':tmp,'capture_identity':runner.CAPTURE_ID,'owner_sid':'inert','executables':{r:{'path':str(exe),'sha256':runner.sha(exe.read_bytes())} for r in ('cli','python','node','powershell')}}
   self.assertTrue(runner.validate_environment(p,lambda:'inert',lambda path:'inert')['target_absent'])
   for sid,parent_sid in [('wrong','inert'),('inert','wrong')]:
    with self.assertRaises(ValueError):runner.validate_environment(p,lambda:sid,lambda path:parent_sid)
   exe.write_bytes(b'drift')
   with self.assertRaisesRegex(ValueError,'runtime_pin'):runner.validate_environment(p,lambda:'inert',lambda path:'inert')
   (path/runner.CAPTURE_ID).mkdir()
   with self.assertRaisesRegex(ValueError,'capture_occupied'):runner.validate_environment(p,lambda:'inert',lambda path:'inert')
 def test_staged_origins_allow_only_two_known_samefile_byte_pinned_spellings(self):
  with tempfile.TemporaryDirectory() as tmp:
   root=Path(tmp);(root/'sub').mkdir();file=root/'entry.mjs';file.write_bytes(b'reviewed source')
   # Portable inert filesystem alias; production parent paths reject '..'.
   known=root/'sub'/'..'/'entry.mjs'
   self.assertNotEqual(known.as_uri(),known.resolve().as_uri())
   for uri in (False,True):
    for path in (known,known.resolve()):
     observed=path.as_uri() if uri else str(path)
     self.assertTrue(runner.staged_origin_matches(observed,known,b'reviewed source',uri))
    outside=root/'outside.mjs';outside.write_bytes(b'reviewed source')
    self.assertFalse(runner.staged_origin_matches(outside.as_uri() if uri else str(outside),known,b'reviewed source',uri))
   for observed in (None,'https://outside/entry.mjs','file://outside/entry.mjs',known.as_uri()+'?x',known.as_uri()+'#x'):
    self.assertFalse(runner.staged_origin_matches(observed,known,b'reviewed source',True))
   file.write_bytes(b'drift')
   with self.assertRaisesRegex(ValueError,'staged_origin_bytes'):runner.staged_origin_matches(known.as_uri(),known,b'reviewed source',True)
 def test_authority_before_any_host_call(self):
  class Host:
   def __getattr__(self,name):raise AssertionError('host touched before authorization')
  p={'scope':'Developer A1 only','capture_identity':runner.CAPTURE_ID,'executables':dict.fromkeys(('cli','python','node','powershell')),'native_effects_accepted':True}
  def deny(*args):raise RuntimeError('owner_missing')
  with self.assertRaisesRegex(RuntimeError,'owner_missing'):runner.run(p,{},deny,Host())
if __name__=='__main__':unittest.main()
