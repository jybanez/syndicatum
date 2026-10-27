"""Real ACL constructor logic with all Win32/filesystem primitives stubbed."""
import ctypes as c
from ctypes import wintypes as w
from pathlib import Path
import sys
import unittest
from unittest.mock import MagicMock,patch

ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools/release'))
import codex_developer_admission_native as native
SID='S-1-5-21-1-2-3-1001'


class Cases(unittest.TestCase):
    def test_atomic_descriptor_and_actual_dacl_readback_with_stubbed_calls(self):
        class ACL(c.Structure):_fields_=[('revision',w.BYTE),('reserved',w.BYTE),('size',w.WORD),('count',w.WORD),('reserved2',w.WORD)]
        class ACE(c.Structure):_fields_=[('type',w.BYTE),('flags',w.BYTE),('size',w.WORD),('mask',w.DWORD)]
        for fault in (None,'unprotected','extra_ace','rights','principal'):
            with self.subTest(fault=fault):
                acl=ACL(2,0,c.sizeof(ACL),3 if fault=='extra_ace' else 2,0)
                aces=[ACE(0,3,24,0x1F01FF),ACE(0,3,24,0x1F01FF)]
                if fault=='rights':aces[0].mask=1
                current=[0];adv=MagicMock();kernel=MagicMock()
                adv.ConvertStringSecurityDescriptorToSecurityDescriptorW.side_effect=lambda s,r,p,l:setattr(p._obj,'value',100) or True
                kernel.CreateDirectoryW.return_value=True
                def named(name,kind,info,owner,group,dacl,sacl,descriptor):
                    dacl._obj.value=c.addressof(acl);descriptor._obj.value=101;return 0
                def control(descriptor,out,revision):out._obj.value=0 if fault=='unprotected' else 0x1000;return True
                def ace(ptr,index,out):current[0]=index;out._obj.value=c.addressof(aces[index]);return True
                def sid(ptr,out):out._obj.value=SID if current[0]==0 else ('S-1-5-32-545' if fault=='principal' else 'S-1-5-18');return True
                adv.GetNamedSecurityInfoW.side_effect=named;adv.GetSecurityDescriptorControl.side_effect=control
                adv.GetAce.side_effect=ace;adv.ConvertSidToStringSidW.side_effect=sid
                parent=MagicMock();target=MagicMock();parent.__truediv__.return_value=target
                helpers=MagicMock();helpers.windows_sid.return_value=SID
                with patch.object(native.os,'name','nt'),patch.object(native,'Path',return_value=parent), \
                     patch.object(c,'WinDLL',side_effect=lambda name,**kw:adv if name=='advapi32' else kernel,create=True):
                    if fault:
                        with self.assertRaises(ValueError):native.create_private_directory('C:/inert','new',SID,helpers)
                    else:
                        root,result=native.create_private_directory('C:/inert','new',SID,helpers)
                        self.assertIs(root,target);self.assertTrue(result['readback']);self.assertTrue(result['protected'])
                        self.assertEqual(set(result['dacl_principals']),{SID,'S-1-5-18'})
                    self.assertEqual(kernel.CreateDirectoryW.call_count,1)
                    self.assertGreaterEqual(kernel.LocalFree.call_count,2)

    def test_denied_native_capability_precedes_every_allocation(self):
        def denied():raise ValueError('no_fresh_authority')
        factory=native.NativeFactory(MagicMock(),denied,lambda root:None)
        with patch.object(native,'create_private_directory') as create:
            with self.assertRaisesRegex(ValueError,'no_fresh_authority'):factory.create_capture({})
            create.assert_not_called()


if __name__=='__main__':unittest.main()
