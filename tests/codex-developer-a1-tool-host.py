"""Inert framing only. No main/run, proposal, native A1 or capture calls."""
import importlib.util,io,json,sys,tempfile,time,unittest,subprocess
from unittest.mock import patch
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('tool_host',ROOT/'tools/release/codex_developer_a1_tool_host.py');host=importlib.util.module_from_spec(spec);spec.loader.exec_module(host)
SESSION='a'*48
VALUE={'session':SESSION,'sequence':1,'host_observed_ms':1,'request':{'profile':{'profile_id':host.PROFILE},'message':{'id':123,'body':'source binding '+('x'*7000)}},'approval':{'profile':{'profile_id':host.PROFILE},'message':{'id':124,'system_event':{'type':'responsibility.approval_approved','data':{'request_message_id':123,'actor_participant_id':28}}}}}
class Cases(unittest.TestCase):
 def test_actual_shaped_chunked_response_lf_crlf(self):
  frame=host.encode_response(VALUE,SESSION,1)
  self.assertGreater(len(frame.splitlines()),2)
  self.assertLessEqual(max(map(len,frame.splitlines())),1024)
  for value in (frame,frame.replace(b'\n',b'\r\n')):self.assertEqual(host.read_response(io.BytesIO(value),SESSION,1),VALUE)
 def test_eof_truncation_wrong_echo_control_and_context_fail_closed(self):
  frame=host.encode_response(VALUE,SESSION,1)
  for bad in (b'',frame[:-1],frame.split(b'\n',1)[0]+b'\n',frame.replace(SESSION.encode(),b'b'*48,1),b'\x1b[2J'+frame,b'A1Q echo\n'+frame,frame.replace(b'A1R',b'A1E',1),frame.replace(b' 1 1 ',b' 2 1 ',1)):
   with self.subTest(prefix=bad[:50]),self.assertRaises(Exception):host.read_response(io.BytesIO(bad),SESSION,1)
 def test_parent_does_not_treat_echo_or_terminal_control_as_request(self):
  for line in ('A1R echoed response','A1E echoed end','\x1b[2JA1Q {}','noise A1Q {}','inert terminal title'):
   self.assertIsNone(host.parse_request_line(line,SESSION,1))
  value={'operation':'protected_authority','session':SESSION,'sequence':1}
  self.assertEqual(host.parse_request_line('A1Q '+json.dumps(value),SESSION,1),value)
  with self.assertRaises(Exception):host.parse_request_line('A1Q '+json.dumps({**value,'sequence':2}),SESSION,1)
 def test_absolute_deadline_and_known_documents_resolution(self):
  class Block:
   def readline(self,bound):time.sleep(.1);return b''
  old=host.DEADLINE;host.DEADLINE=.01
  try:
   with self.assertRaises(TimeoutError):host.read_response(Block(),SESSION,1)
  finally:host.DEADLINE=old
  with tempfile.TemporaryDirectory() as tmp:
   path=host.existing_proposal(lambda:tmp)
   self.assertEqual(path.parent,Path(tmp));self.assertFalse(path.exists())
 def test_stalled_git_retains_original_error_and_no_successor_or_kill(self):
  original=subprocess.TimeoutExpired('inert-git',10)
  class Child:
   pid=543
   def communicate(self,timeout):
    self.timeout=timeout;raise original
   def kill(self):raise AssertionError('kill prohibited')
  child=Child();reader=host.GitBlobReader()
  with patch.object(host.subprocess,'Popen',return_value=child) as launch:
   with self.assertRaises(subprocess.TimeoutExpired) as stopped:reader.read('inert-source')
   self.assertIs(stopped.exception,original);self.assertEqual(original.host_git_pid,543)
   self.assertLessEqual(child.timeout,10)
   with self.assertRaisesRegex(ValueError,'source_reader_terminal'):reader.read('no-successor')
   self.assertEqual(launch.call_count,1)
  ticks=iter([0,61]);reader=host.GitBlobReader(clock=lambda:next(ticks))
  with patch.object(host.subprocess,'Popen') as launch:
   with self.assertRaisesRegex(ValueError,'source_acquisition_deadline'):reader.read('never-started')
   launch.assert_not_called()
 def test_stopped_authority_and_duplicate_frame_schema(self):
  raw=b'{"session":"'+SESSION.encode()+b'","sequence":1,"sequence":2}'
  encoded=host.base64.b64encode(raw).decode()
  frame=('A1R %s 1 1 1 %s\nA1E %s 1 %s\n'%(SESSION,encoded,SESSION,host.digest(raw))).encode()
  with self.assertRaisesRegex(ValueError,'duplicate_key'):host.read_response(io.BytesIO(frame),SESSION,1)
if __name__=='__main__':unittest.main()
