# SOURCE ONLY. No exported native entry can pass the unconditional gate.
Set-StrictMode -Version Latest
function Assert-FixedStorageGate { throw 'SOURCE_ONLY: fixed storage native execution disabled.' }

function Assert-FixedPath([string]$Path,[string]$Root) {
    $absolute=[IO.Path]::GetFullPath($Path)
    if ($Path -cne $absolute -or $Path.StartsWith('\\') -or $Path.Substring(2).Contains(':') -or
        -not $absolute.StartsWith($Root+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'STOP: storage path boundary' }
    foreach ($forbidden in @('C:\wamp64\www',(Join-Path $env:USERPROFILE '.codex'),(Join-Path $env:USERPROFILE '.syndicatum'))) {
        if ($absolute.StartsWith($forbidden+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'STOP: live storage overlap' }
    }
    $cursor=Get-Item -LiteralPath $absolute -Force -ErrorAction Stop
    while ($null -ne $cursor) {
        if ($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'STOP: reparse ancestor' }
        $cursor=if ($cursor.PSIsContainer) { $cursor.Parent } else { $cursor.Directory }
    }
}
function Initialize-FixedHandleApi {
    if ('FixedStorageHandle' -as [type]) { return }
    Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using System.Text;
using Microsoft.Win32.SafeHandles;
public static class FixedStorageHandle {
 [StructLayout(LayoutKind.Sequential)] public struct Info {
  public uint Attributes, CreationLow, CreationHigh, AccessLow, AccessHigh;
  public uint WriteLow, WriteHigh, Volume, SizeHigh, SizeLow, Links, IndexHigh, IndexLow;
 }
 [DllImport("kernel32.dll", SetLastError=true)]
 public static extern bool GetFileInformationByHandle(SafeFileHandle h, out Info info);
 [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
 static extern uint GetFinalPathNameByHandleW(SafeFileHandle h, StringBuilder p, uint n, uint flags);
 public static string Identity(SafeFileHandle h) {
  Info i; if (!GetFileInformationByHandle(h,out i)) throw new System.ComponentModel.Win32Exception();
  if (i.Links!=1 || (i.Attributes & 0x400)!=0) throw new Exception("STOP: link/reparse handle");
  return i.Volume.ToString("x8")+":"+i.IndexHigh.ToString("x8")+i.IndexLow.ToString("x8");
 }
 public static string Path(SafeFileHandle h) {
  var p=new StringBuilder(32768); uint n=GetFinalPathNameByHandleW(h,p,32768,0);
  if(n==0 || n>=32768) throw new System.ComponentModel.Win32Exception();
  return p.ToString();
 }
}
'@
}
function Get-FixedAclPin($Stream) {
    $acl=[IO.FileSystemAclExtensions]::GetAccessControl($Stream)
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    if ($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -cne $sid -or -not $acl.AreAccessRulesProtected) {
        throw 'STOP: storage owner/inheritance'
    }
    foreach ($rule in $acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])) {
        if ($rule.AccessControlType -eq 'Allow' -and $rule.IdentityReference.Value -notin @($sid,'S-1-5-18')) {
            throw 'STOP: storage ACL grants'
        }
    }
    $sddl=$acl.GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]'Owner,Group,Access')
    [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes($sddl))).ToLowerInvariant()
}
function New-FixedBoundApi([string]$Root,[hashtable]$Bindings) {
    # Pure callback composition. Capture bound scriptblocks, not private command
    # names that disappear when GetNewClosure creates a dynamic module.
    $ops=@{}
    foreach ($name in @('Validate','Open','Inspect','Read','Write','Flush','Close')) {
        if ($Bindings[$name] -isnot [scriptblock]) { throw 'STOP: missing API binding' }
        $ops[$name]=$Bindings[$name]
    }
    @{
        Open={param($Pin)
            & $ops.Validate $Pin.path $Root
            & $ops.Open $Pin
        }.GetNewClosure()
        Inspect={param($Handle,$Pin)
            & $ops.Validate $Pin.path $Root
            & $ops.Inspect $Handle $Pin
        }.GetNewClosure()
        Read=$ops.Read;Write=$ops.Write;Flush=$ops.Flush;Close=$ops.Close
    }
}
function New-FixedWindowsApi([hashtable]$Manifest) {
    # Internal definitions only. Exported dispatcher gates before reaching here.
    Initialize-FixedHandleApi
    $aclInspector=${function:Get-FixedAclPin}
    $bindings=@{
        Validate=${function:Assert-FixedPath}
        Open={param($Pin)
            # Open == OPEN_EXISTING; never OpenOrCreate/Create/Truncate.
            [IO.FileStream]::new($Pin.path,[IO.FileMode]::Open,[IO.FileAccess]::ReadWrite,
                [IO.FileShare]::None,4096,[IO.FileOptions]::WriteThrough)
        }
        Inspect={param($Handle,$Pin)
            $final=[FixedStorageHandle]::Path($Handle.SafeFileHandle)
            if ($final -cne ('\\?\'+$Pin.path)) { throw 'STOP: final handle path' }
            @{identity=[FixedStorageHandle]::Identity($Handle.SafeFileHandle);length=$Handle.Length;acl=(& $aclInspector $Handle)}
        }.GetNewClosure()
        Read={param($Handle,$Offset,$Count)
            $null=$Handle.Seek($Offset,[IO.SeekOrigin]::Begin)
            $bytes=[byte[]]::new($Count); $Handle.ReadExactly($bytes); ,$bytes
        }
        Write={param($Handle,$Offset,$Bytes)
            $null=$Handle.Seek($Offset,[IO.SeekOrigin]::Begin)
            $Handle.Write($Bytes,0,$Bytes.Length)
        }
        Flush={param($Handle) $Handle.Flush($true)}
        Close={param($Handle) $Handle.Dispose()}
    }
    New-FixedBoundApi $Manifest.root $bindings
}
function Assert-FixedSession($Session) {
    if ($Session.closed) { throw 'STOP: terminal fixed session' }
    foreach ($name in @('records','heads')) {
        $pin=$Session.manifest.files[$name]
        $actual=& $Session.api.Inspect $Session.handles[$name] $pin
        if ($actual.identity -cne $pin.identity -or $actual.length -ne $pin.length -or $actual.acl -cne $pin.acl) {
            throw 'STOP: fixed identity/length/ACL drift'
        }
    }
}
function Close-FixedSession($Session) {
    $Session.closed=$true
    foreach ($handle in $Session.handles.Values) { & $Session.api.Close $handle }
    $Session.handles.Clear()
}
function Open-FixedSession([hashtable]$Manifest,[hashtable]$Api) {
    # Caller must supply a trusted independently digest-verified manifest.
    # Copy it to prevent later caller mutation from changing the pin.
    $pin=$Manifest | ConvertTo-Json -Depth 10 -Compress | ConvertFrom-Json -AsHashtable
    if ($pin.files.Count -ne 2 -or -not $pin.files.ContainsKey('records') -or -not $pin.files.ContainsKey('heads') -or
        $pin.files.records.identity -ceq $pin.files.heads.identity -or
        $pin.files.records.path -ieq $pin.files.heads.path -or
        $pin.slots -lt 1 -or $pin.slots -gt 512 -or $pin.width -lt 512 -or $pin.width -gt 65536) { throw 'STOP: manifest bounds' }
    foreach ($file in $pin.files.Values) {
        if ($file.length -ne ($pin.slots*$pin.width)) { throw 'STOP: fixed capacity' }
    }
    $session=@{manifest=$pin;api=$Api;handles=@{};closed=$false}
    try {
        foreach ($name in @('records','heads')) { $session.handles[$name]=& $Api.Open $pin.files[$name] }
        Assert-FixedSession $session
        return $session
    } catch { Close-FixedSession $session; throw }
}
function Write-FixedSlot($Session,[string]$Name,[int]$Index,[byte[]]$Bytes) {
    try {
        Assert-FixedSession $Session
        if ($Name -cnotin @('records','heads') -or $Index -lt 0 -or $Index -ge $Session.manifest.slots -or
            $Bytes.Length -ne $Session.manifest.width -or -not @($Bytes | Where-Object { $_ -ne 0 }).Count) {
            throw 'STOP: slot bounds/empty record'
        }
        $offset=[long]$Index*$Session.manifest.width
        $handle=$Session.handles[$Name]
        $old=& $Session.api.Read $handle $offset $Bytes.Length
        if ($old.Length -ne $Bytes.Length) { throw 'STOP: short slot read' }
        if (@($old | Where-Object { $_ -ne 0 }).Count) {
            throw [Management.Automation.ErrorRecord]::new(
                [InvalidOperationException]::new('STOP: occupied slot'),'FixedStorageOccupiedSlot',
                [Management.Automation.ErrorCategory]::InvalidOperation,$null)
        }
        & $Session.api.Write $handle $offset $Bytes
        & $Session.api.Flush $handle
        $actual=& $Session.api.Read $handle $offset $Bytes.Length
        if ([Convert]::ToBase64String($actual) -cne [Convert]::ToBase64String($Bytes)) { throw 'STOP: readback' }
        Assert-FixedSession $Session
    } catch { Close-FixedSession $Session; throw }
}
function Confirm-FixedOccupiedEvidence($Failure,[hashtable]$Before,[hashtable]$After) {
    # Pure comparison. Unexpected backend errors retain their original record.
    if ($null -eq $Failure) { throw 'STOP: overwrite did not reject' }
    if (($Failure.FullyQualifiedErrorId -split ',')[0] -cne 'FixedStorageOccupiedSlot') { throw $Failure }
    foreach ($map in @($Before,$After)) {
        if ($null -eq $map -or $map.Count -ne 2 -or -not $map.ContainsKey('records') -or
            -not $map.ContainsKey('heads')) { throw 'STOP: incomplete container evidence' }
    }
    foreach ($name in @('records','heads')) {
        if ($Before[$name] -isnot [byte[]] -or $After[$name] -isnot [byte[]] -or
            [Convert]::ToBase64String($Before[$name]) -cne [Convert]::ToBase64String($After[$name])) {
            throw 'STOP: rejected overwrite changed container bytes'
        }
    }
}
function Assert-FixedSyntheticInventory([array]$Entries) {
    # Flat footprint only. No compiler scratch is currently approved.
    $allowed=@{'attempt.json'=65536;'records.bin'=8192;'heads.bin'=8192;'manifest.json'=65536;'result.json'=65536}
    $seen=@{}; $total=0L
    foreach ($entry in $Entries) {
        if ($entry.reparse -or $entry.directory) { throw 'STOP: reparse/directory object forbidden' }
        if (-not $allowed.ContainsKey($entry.name) -or $seen.ContainsKey($entry.name)) { throw 'STOP: unknown/duplicate synthetic object' }
        if ($entry.length -lt 0 -or $entry.length -gt $allowed[$entry.name]) { throw 'STOP: synthetic file length' }
        $seen[$entry.name]=$true; $total+=$entry.length
    }
    if ($Entries.Count -gt 5 -or $total -gt 1048576) { throw 'STOP: synthetic footprint bounds' }
}
function Invoke-FixedStorage {
    [CmdletBinding()]
    param([hashtable]$Manifest)
    Assert-FixedStorageGate
    # No cutover/mutation integration. Future trusted orchestration must keep
    # these handles for the entire attempt, not reopen a batch per action.
    $api=New-FixedWindowsApi $Manifest
    $session=Open-FixedSession $Manifest $api
    try { Assert-FixedSession $session } finally { Close-FixedSession $session }
}
Export-ModuleMember -Function Invoke-FixedStorage
