"""Inert parsers and A1 orchestration only; never native launch/capture."""
import importlib.util, io, json, sys, tempfile, unittest, hashlib, subprocess
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
  for fault in ('nonzero','timeout','trailing','bad_nonce','missing','wrong_python','wrong_node','wrong_ps','none'):
   with self.subTest(fault=fault),tempfile.TemporaryDirectory() as tmp:
    calls=[];auth=[];source={n:(ROOT/('docs' if n.endswith('-admission.md') else 'tools/release')/n).read_bytes() for n in runner.REQUIRED_SOURCES}
    exe=Path(tmp)/'inert-executable';exe.write_bytes(b'inert executable fixture; never launched');pin=runner.sha(exe.read_bytes())
    p={'capture_identity':runner.CAPTURE_ID,'scope':'Developer A1 only','native_effects_accepted':True,'executables':{r:{'path':str(exe),'sha256':pin} for r in ('cli','python','node','powershell')},'sources':{n:{'sha256':runner.sha(b),'size':len(b)} for n,b in source.items()},'scope_document_sha256':runner.sha(source['codex-developer-a1-admission.md']),'capture_parent':tmp,'owner_sid':'inert'}
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
       if fault=='wrong_ps' and i==5:out['module']='outside.psm1'
       raw=json.dumps(out).encode()
       if i==3 and fault=='trailing':raw+=b'x'
      return {'identity':{'pid':123},'stdout':raw,'stderr':b'','exit':0}
    if fault=='none':self.assertEqual(runner.run(p,source,lambda *v:auth.append(v),Host())['status'],'observations_only_not_install_admission');self.assertEqual(len(calls),6)
    else:
     with self.assertRaises(Exception) as stopped:runner.run(p,source,lambda *v:auth.append(v),Host())
     self.assertEqual(len(calls),1 if fault in ('nonzero','timeout') else 5 if fault=='wrong_node' else 6 if fault=='wrong_ps' else 4)
     if fault in ('nonzero','timeout'):self.assertEqual(str(stopped.exception),'ORIGINAL_'+fault)
    count=len(calls)
    with self.assertRaises(FileExistsError):runner.run(p,source,lambda *v:auth.append(v),Host())
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
 def test_authority_before_any_host_call(self):
  class Host:
   def __getattr__(self,name):raise AssertionError('host touched before authorization')
  p={'scope':'Developer A1 only','capture_identity':runner.CAPTURE_ID,'executables':dict.fromkeys(('cli','python','node','powershell')),'native_effects_accepted':True}
  def deny(*args):raise RuntimeError('owner_missing')
  with self.assertRaisesRegex(RuntimeError,'owner_missing'):runner.run(p,{},deny,Host())
if __name__=='__main__':unittest.main()
