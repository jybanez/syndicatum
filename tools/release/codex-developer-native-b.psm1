# SOURCE-ONLY. Import defines functions; the exported dispatcher always refuses
# execution until a DIFFERENT reviewed source revision deliberately opens its gate.
Set-StrictMode -Version Latest
function Assert-DeveloperBGate { throw 'SOURCE_ONLY: Developer B native execution is disabled.' }

function Get-BHash([byte[]]$Bytes) {
    [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($Bytes)).ToLowerInvariant()
}
function Get-BSid([string]$Value) {
    if ($Value -match '^S-[0-9]+-') { return ([Security.Principal.SecurityIdentifier]::new($Value)).Value }
    ([Security.Principal.NTAccount]::new($Value)).Translate([Security.Principal.SecurityIdentifier]).Value
}
function Assert-BPath([string]$Path) {
    $taskCursor = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    while ($null -ne $taskCursor) {
        if ($taskCursor.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'STOP: reparse path' }
        $taskCursor = if ($taskCursor.PSIsContainer) { $taskCursor.Parent } else { $taskCursor.Directory }
    }
}
function Initialize-BHandleApi {
    if ('DeveloperBHandle' -as [type]) { return }
    Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;
public static class DeveloperBHandle {
 [StructLayout(LayoutKind.Sequential)] public struct Info {
  public uint Attributes, CreationLow, CreationHigh, AccessLow, AccessHigh;
  public uint WriteLow, WriteHigh, Volume, SizeHigh, SizeLow, Links, IndexHigh, IndexLow;
 }
 [DllImport("kernel32.dll", SetLastError=true)]
 public static extern bool GetFileInformationByHandle(SafeFileHandle file, out Info info);
 [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)]
 private static extern SafeFileHandle CreateFileW(string name, uint access, uint share, IntPtr security, uint creation, uint flags, IntPtr template);
 [DllImport("kernel32.dll", SetLastError=true)] private static extern bool FlushFileBuffers(SafeFileHandle file);
 public static void FlushDirectory(string name) {
  using (var handle=CreateFileW(name,0x40000000,7,IntPtr.Zero,3,0x02000000,IntPtr.Zero)) {
   if (handle.IsInvalid || !FlushFileBuffers(handle)) throw new System.ComponentModel.Win32Exception();
  }
 }
 public static string Identity(SafeFileHandle file) {
  Info info; if (!GetFileInformationByHandle(file, out info)) throw new System.ComponentModel.Win32Exception();
  return info.Volume.ToString("x8")+":"+info.IndexHigh.ToString("x8")+info.IndexLow.ToString("x8");
 }
 public static string Metadata(SafeFileHandle file) {
  Info info; if (!GetFileInformationByHandle(file, out info)) throw new System.ComponentModel.Win32Exception();
  return info.SizeHigh+":"+info.SizeLow+":"+info.WriteHigh+":"+info.WriteLow;
 }
}
'@
}
function Read-BStable([string]$Path, [long]$MaximumBytes = 4194304) {
    Assert-BPath $Path
    Initialize-BHandleApi
    # Cutoff files are read after quiescence. Deny writes/delete/rename while
    # comparing the open handle with the path; unexpected writers cause STOP.
    $taskShare = [IO.FileShare]::Read
    $taskStream = [IO.File]::Open($Path,[IO.FileMode]::Open,[IO.FileAccess]::Read,$taskShare)
    try {
        $taskIdentity = [DeveloperBHandle]::Identity($taskStream.SafeFileHandle)
        $taskMetadata = [DeveloperBHandle]::Metadata($taskStream.SafeFileHandle)
        if ($taskStream.Length -gt $MaximumBytes) { throw 'STOP: native file bound' }
        $taskBytes = [byte[]]::new([int]$taskStream.Length)
        $taskStream.ReadExactly($taskBytes)
        if ([DeveloperBHandle]::Metadata($taskStream.SafeFileHandle) -cne $taskMetadata) { throw 'STOP: handle write drift' }
        $taskPathStream = [IO.File]::Open($Path,[IO.FileMode]::Open,[IO.FileAccess]::Read,$taskShare)
        try {
            $taskPathIdentity = [DeveloperBHandle]::Identity($taskPathStream.SafeFileHandle)
            $taskPathMetadata = [DeveloperBHandle]::Metadata($taskPathStream.SafeFileHandle)
            if ($taskPathIdentity -cne $taskIdentity -or $taskPathMetadata -cne $taskMetadata) { throw 'STOP: path/handle drift' }
        } finally { $taskPathStream.Dispose() }
        Assert-BPath $Path
        @{raw_base64=[Convert]::ToBase64String($taskBytes);sha256=(Get-BHash $taskBytes);size=$taskBytes.Length;
          handle_identity=$taskIdentity;path_identity=$taskPathIdentity;before_metadata=$taskMetadata;after_metadata=$taskPathMetadata}
    } finally { $taskStream.Dispose() }
}
function Get-BClock {
    @{utc_ms=[DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds();ticks=[Diagnostics.Stopwatch]::GetTimestamp();
      frequency=[Diagnostics.Stopwatch]::Frequency;boot_utc=(Get-CimInstance Win32_OperatingSystem -ErrorAction Stop).LastBootUpTime.ToUniversalTime().ToString('o')}
}
function Confirm-BEvidenceBoundary([hashtable]$Plan) {
    $taskSid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    foreach ($taskParent in @($Plan.transport_root,(Split-Path $Plan.evidence_root -Parent),(Split-Path $Plan.head_root -Parent))) {
        Assert-BPath $taskParent
        $taskAbsolute=[IO.Path]::GetFullPath($taskParent).TrimEnd('\')
        foreach ($taskForbidden in @($Plan.data_root,(Join-Path $env:USERPROFILE '.codex'),'C:\wamp64\www')) {
            $taskForbidden=[IO.Path]::GetFullPath($taskForbidden).TrimEnd('\')
            if ($taskAbsolute.Equals($taskForbidden,[StringComparison]::OrdinalIgnoreCase) -or
                $taskAbsolute.StartsWith($taskForbidden+'\',[StringComparison]::OrdinalIgnoreCase) -or
                $taskForbidden.StartsWith($taskAbsolute+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'STOP: evidence overlap' }
        }
        $taskAcl=Get-Acl -LiteralPath $taskAbsolute -ErrorAction Stop
        $taskOwner=Get-BSid $taskAcl.Owner
        if ($taskOwner -cne $taskSid) { throw 'STOP: evidence owner' }
        foreach ($taskRule in $taskAcl.Access) {
            $taskRuleSid=$taskRule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value
            if ($taskRule.AccessControlType -eq 'Allow' -and $taskRuleSid -notin @($taskSid,'S-1-5-18')) { throw 'STOP: evidence ACL' }
        }
        $taskDrive=[IO.DriveInfo]::new([IO.Path]::GetPathRoot($taskAbsolute))
        if (-not $taskDrive.IsReady -or $taskDrive.DriveType -ne 'Fixed' -or $taskDrive.AvailableFreeSpace -lt 268435456) { throw 'STOP: evidence volume/capacity' }
    }
    @{private_roots_checked=$true;clock=(Get-BClock)}
}
function Get-BRuns {
    $taskResult = @{}
    foreach ($taskView in @([Microsoft.Win32.RegistryView]::Registry32,[Microsoft.Win32.RegistryView]::Registry64)) {
        $taskHive = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::CurrentUser,$taskView)
        try {
            $taskKey = $taskHive.OpenSubKey('Software\Microsoft\Windows\CurrentVersion\Run',$false)
            if ($null -eq $taskKey) { continue }
            try { foreach ($taskName in $taskKey.GetValueNames()) {
                $taskResult[$taskView.ToString()+':'+$taskName] = @{
                    kind=$taskKey.GetValueKind($taskName).ToString();
                    value=$taskKey.GetValue($taskName,$null,[Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)}
            } } finally { $taskKey.Dispose() }
        } finally { $taskHive.Dispose() }
    }
    $taskResult
}
function Get-BSnapshot([hashtable]$Plan) {
    $taskTasks = @{}; $taskTotal = 0
    Get-ScheduledTask -ErrorAction Stop | ForEach-Object {
        $taskRaw = [Text.Encoding]::UTF8.GetBytes((Export-ScheduledTask -TaskName $_.TaskName -TaskPath $_.TaskPath -ErrorAction Stop))
        $taskTotal += $taskRaw.Length
        if ($taskTotal -gt 8388608) { throw 'STOP: Task evidence bound' }
        $taskTasks[$_.TaskPath+$_.TaskName] = [Convert]::ToBase64String($taskRaw)
    }
    $taskProcesses = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object {
        $_.ProcessId -eq $Plan.listener.pid -or ($_.Name -eq 'node.exe' -and $_.CommandLine -and
        $_.CommandLine.Contains($Plan.service_entry,[StringComparison]::OrdinalIgnoreCase))
    } | ForEach-Object {
        $taskProcess = [Diagnostics.Process]::GetProcessById($_.ProcessId)
        try { $taskStart = $taskProcess.StartTime.ToUniversalTime().ToString('o') } finally { $taskProcess.Dispose() }
        @{pid=[int]$_.ProcessId;started=$taskStart;executable=[string]$_.ExecutablePath;
          command_line=[string]$_.CommandLine;image_sha256=(Get-FileHash -LiteralPath $_.ExecutablePath -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()}
    })
    $taskLocks = @{}
    foreach ($taskName in @('background.lock','listener.lock')) {
        $taskPath = Join-Path $Plan.data_root $taskName
        try { $taskItem = Get-Item -LiteralPath $taskPath -Force -ErrorAction Stop }
        catch [System.Management.Automation.ItemNotFoundException] { $taskLocks[$taskName]=@{present=$false;pid=0;live=$false};continue }
        $taskWitness = Read-BStable $taskPath 4096
        $taskText = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($taskWitness.raw_base64)).Trim()
        if ($taskText -notmatch '^[1-9][0-9]{0,9}$') { throw 'STOP: lock PID format' }
        $taskPid = [int]::Parse($taskText)
        # Separate CIM query: never infer lock liveness from the process list above.
        $taskAlive = $null -ne (Get-CimInstance Win32_Process -Filter "ProcessId=$taskPid" -ErrorAction Stop)
        $taskLocks[$taskName]=@{present=$true;pid=$taskPid;live=$taskAlive;witness=$taskWitness}
    }
    $taskStatic = @{}
    Get-ChildItem -LiteralPath $Plan.data_root -Recurse -Force -ErrorAction Stop | ForEach-Object {
        if ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'STOP: static reparse entry' }
        if ($_.PSIsContainer) { return }
        $taskIsRoot=$_.DirectoryName -ceq $Plan.data_root
        if (($taskIsRoot -and ($_.Name -cmatch '^state\.json\.participant-[0-9]+$' -or
            $_.Name -cin @('background-health.json','background.lock','listener.lock','startup.lock','connector.log','background-startup.log'))) -or
            $_.Name.EndsWith('.tmp',[StringComparison]::Ordinal)) { return }
        $taskRelative=[IO.Path]::GetRelativePath($Plan.data_root,$_.FullName).Replace('\','/')
        $taskStatic[$taskRelative]=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
    }
    @{tasks=$taskTasks;runs=(Get-BRuns);processes=$taskProcesses;locks=$taskLocks;static_files=$taskStatic;clock=(Get-BClock)}
}
function Remove-BExactStartup([hashtable]$Plan) {
    $taskTarget = Get-ScheduledTask -TaskName 'Syndicatum Codex Connector' -TaskPath '\' -ErrorAction Stop
    if ($taskTarget.TaskName -cne 'Syndicatum Codex Connector' -or $taskTarget.TaskPath -cne '\') { throw 'STOP: exact Task casing/path' }
    $taskXml = [Text.Encoding]::UTF8.GetBytes((Export-ScheduledTask -TaskName $taskTarget.TaskName -TaskPath $taskTarget.TaskPath -ErrorAction Stop))
    if ((Get-BHash $taskXml) -cne $Plan.task_xml_sha256 -or @($taskTarget.Actions).Count -ne 1) { throw 'STOP: Task preimage' }
    $taskSid = Get-BSid $taskTarget.Principal.UserId
    if ($taskSid -cne [Security.Principal.WindowsIdentity]::GetCurrent().User.Value) { throw 'STOP: Task principal' }
    $taskPowerShell=Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $taskArguments='-NoLogo -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "'+(Join-Path $Plan.data_root 'background-launcher.ps1')+'"'
    if ($taskTarget.Actions[0].Execute -ine $taskPowerShell -or $taskTarget.Actions[0].Arguments -cne $taskArguments -or
        @($taskTarget.Triggers).Count -ne 1 -or $taskTarget.Triggers[0].CimClass.CimClassName -cne 'MSFT_TaskLogonTrigger') { throw 'STOP: Task action/trigger scope' }
    $taskTriggerSid=Get-BSid $taskTarget.Triggers[0].UserId
    if ($taskTriggerSid -cne $taskSid) { throw 'STOP: trigger owner' }
    $taskRuns = Get-BRuns
    if ($taskRuns.Count -ne $Plan.run_preimage.Count) { throw 'STOP: Run inventory set' }
    foreach ($taskKeyName in $taskRuns.Keys) {
        if ($Plan.run_preimage.Keys -cnotcontains $taskKeyName -or
            $taskRuns[$taskKeyName].kind -cne $Plan.run_preimage[$taskKeyName].kind -or
            ($taskRuns[$taskKeyName].value | ConvertTo-Json -Depth 4 -Compress) -cne
            ($Plan.run_preimage[$taskKeyName].value | ConvertTo-Json -Depth 4 -Compress)) { throw 'STOP: Run preimage' }
    }
    if (@($taskRuns.Keys | Where-Object { $_ -cmatch '^Registry(32|64):SyndicatumCodexConnector$' }).Count -eq 0) { throw 'STOP: Run target absent' }
    foreach ($taskKeyName in @($taskRuns.Keys | Where-Object { $_ -cmatch '^Registry(32|64):SyndicatumCodexConnector$' })) {
        if ($taskRuns[$taskKeyName].kind -cne 'String' -or $taskRuns[$taskKeyName].value -cne ($taskPowerShell+' '+$taskArguments)) { throw 'STOP: Run command scope' }
    }
    Unregister-ScheduledTask -TaskName 'Syndicatum Codex Connector' -TaskPath '\' -Confirm:$false -ErrorAction Stop
    $taskRemovedAny=$false
    foreach ($taskView in @([Microsoft.Win32.RegistryView]::Registry32,[Microsoft.Win32.RegistryView]::Registry64)) {
        $taskHive = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::CurrentUser,$taskView)
        try {
            $taskKey = $taskHive.OpenSubKey('Software\Microsoft\Windows\CurrentVersion\Run',$true)
            if ($null -eq $taskKey) { throw 'STOP: Run key missing' }
            try {
                # Registry views may alias. Absence after the first deletion is
                # acceptable only after both views passed the full preimage gate.
                if ($taskKey.GetValueNames() -ccontains 'SyndicatumCodexConnector') {
                    $taskExpectedKey=$taskView.ToString()+':SyndicatumCodexConnector'
                    if ($Plan.run_preimage.Keys -cnotcontains $taskExpectedKey -or
                        $taskKey.GetValueKind('SyndicatumCodexConnector').ToString() -cne $Plan.run_preimage[$taskExpectedKey].kind -or
                        $taskKey.GetValue('SyndicatumCodexConnector',$null,[Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames) -cne
                        $Plan.run_preimage[$taskExpectedKey].value) { throw 'STOP: Run changed immediately before deletion' }
                    $taskKey.DeleteValue('SyndicatumCodexConnector',$true)
                    $taskRemovedAny=$true
                } elseif (-not $taskRemovedAny -and $Plan.run_preimage.Keys -ccontains ($taskView.ToString()+':SyndicatumCodexConnector')) {
                    throw 'STOP: Run disappeared before suppression'
                }
            } finally { $taskKey.Dispose() }
        } finally { $taskHive.Dispose() }
    }
    @{operation='exact_startup_suppression';completed=$true}
}
function Stop-BExactListener([hashtable]$Plan) {
    $taskSnapshot = Get-BSnapshot $Plan
    $taskActual = @($taskSnapshot.processes | Where-Object pid -eq $Plan.listener.pid)
    if (@($taskActual).Count -ne 1 -or $taskSnapshot.processes.Count -ne 1) { throw 'STOP: owned process missing/ambiguous' }
    foreach ($taskField in @('pid','started','executable','command_line','image_sha256')) {
        if ([string]$taskActual.$taskField -cne [string]$Plan.listener[$taskField]) { throw 'STOP: process identity drift' }
    }
    foreach ($taskName in @('background.lock','listener.lock')) {
        $taskLock = $taskSnapshot.locks[$taskName]
        if (-not $taskLock.present -or -not $taskLock.live -or $taskLock.pid -ne $Plan.listener.pid -or
            $taskLock.witness.sha256 -cne $Plan.lock_sha256[$taskName]) { throw 'STOP: owned lock drift' }
    }
    # Hold the exact process handle and compare its start time before signaling.
    $taskProcess = [Diagnostics.Process]::GetProcessById($Plan.listener.pid)
    try {
        $null = $taskProcess.Handle
        if ($taskProcess.StartTime.ToUniversalTime().ToString('o') -cne $Plan.listener.started) { throw 'STOP: process-handle reuse' }
        $taskProcess.Kill()
        if (-not $taskProcess.WaitForExit(10000)) { throw 'STOP: stop outcome uncertain' }
    } finally { $taskProcess.Dispose() }
    @{operation='exact_owned_listener_stop';completed=$true}
}
function Get-BCutoff([hashtable]$Plan) {
    $taskBefore = Get-BSnapshot $Plan
    if ($taskBefore.processes.Count -or @($taskBefore.locks.Values | Where-Object live).Count) { throw 'STOP: not quiescent' }
    $taskNames = @(Get-ChildItem -LiteralPath $Plan.data_root -File -Force -ErrorAction Stop |
        Where-Object Name -cmatch '^state\.json\.participant-[0-9]+$' | Select-Object -ExpandProperty Name | Sort-Object -CaseSensitive)
    if (($taskNames -join "`n") -cne (($Plan.route_names | Sort-Object -CaseSensitive) -join "`n")) { throw 'STOP: route identity set' }
    $taskStates=@{}; $taskTotal=0
    foreach ($taskName in $taskNames) {
        $taskWitness=Read-BStable (Join-Path $Plan.data_root $taskName)
        $taskTotal+=$taskWitness.size
        if ($taskTotal -gt 16777216) { throw 'STOP: total route bytes bound' }
        $taskStates[$taskName]=$taskWitness
    }
    $taskAfter = Get-BSnapshot $Plan
    if ($taskAfter.processes.Count -or @($taskAfter.locks.Values | Where-Object live).Count) { throw 'STOP: writer returned' }
    $taskNamesAfter=@(Get-ChildItem -LiteralPath $Plan.data_root -File -Force -ErrorAction Stop |
        Where-Object Name -cmatch '^state\.json\.participant-[0-9]+$' | Select-Object -ExpandProperty Name | Sort-Object -CaseSensitive)
    if (($taskNamesAfter -join "`n") -cne ($taskNames -join "`n")) { throw 'STOP: route set changed during capture' }
    foreach ($taskName in $taskNames) {
        $taskRecheck=Read-BStable (Join-Path $Plan.data_root $taskName)
        if ($taskRecheck.sha256 -cne $taskStates[$taskName].sha256 -or
            $taskRecheck.handle_identity -cne $taskStates[$taskName].handle_identity) { throw 'STOP: route changed during cutoff' }
    }
    @{before=$taskBefore;states=$taskStates;after=$taskAfter;clock=(Get-BClock)}
}
function Invoke-DeveloperNative {
    [CmdletBinding()]
    param([ValidateSet('admission','snapshot','suppress','stop','cutoff','flush')][string]$Operation,[hashtable]$Plan)
    Assert-DeveloperBGate
    $taskExpectedRoot=[IO.Path]::GetFullPath((Join-Path $env:USERPROFILE '.syndicatum\codex-plugin'))
    if ([IO.Path]::GetFullPath($Plan.data_root) -cne $taskExpectedRoot) { throw 'STOP: not Developer data root' }
    $taskExpectedEntry=Join-Path $taskExpectedRoot 'runtime\background-service.mjs'
    if ($Plan.service_entry -cne $taskExpectedEntry -or [IO.Path]::GetFileName($Plan.listener.executable) -cne 'node.exe' -or
        $Plan.listener.command_line -cne ('"'+$Plan.listener.executable+'" "'+$taskExpectedEntry+'"')) { throw 'STOP: service ownership scope' }
    Assert-BPath $taskExpectedRoot
    foreach ($taskRelative in $Plan.static_files.Keys) {
        if ([IO.Path]::IsPathRooted($taskRelative) -or $taskRelative -match '(^|[\\/])\.\.([\\/]|$)|:') { throw 'STOP: static path boundary' }
    }
    switch ($Operation) {
        admission { Confirm-BEvidenceBoundary $Plan }
        snapshot { Get-BSnapshot $Plan }
        suppress { Remove-BExactStartup $Plan }
        stop { Stop-BExactListener $Plan }
        cutoff { Get-BCutoff $Plan }
        flush {
            Initialize-BHandleApi
            foreach ($taskDirectory in @($Plan.evidence_root,(Join-Path $Plan.evidence_root 'records'),$Plan.head_root,
                (Split-Path $Plan.evidence_root -Parent),(Split-Path $Plan.head_root -Parent))) {
                Assert-BPath $taskDirectory
                [DeveloperBHandle]::FlushDirectory($taskDirectory)
            }
            @{directory_flush_confirmed=$true}
        }
    }
}
Export-ModuleMember -Function Invoke-DeveloperNative
