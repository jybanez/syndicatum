# SOURCE-REVIEW CANDIDATE ONLY. Not executed as part of source tests.
# The switch/message ID is an operator handoff, NOT canonical authorization proof.
# Trusted orchestration must verify the exact approved source/packet before launch.
[CmdletBinding()]
param(
    [switch]$RunApprovedSyntheticTest,
    [string]$TestRoot,
    [string]$ExpectedHarnessSha256,
    [string]$ExpectedModuleSha256,
    [long]$ApprovalMessageId
)
$ErrorActionPreference='Stop'
if (-not $RunApprovedSyntheticTest) { throw 'SOURCE_ONLY: isolated test requires separate reviewed owner authority.' }
if ($ApprovalMessageId -le 11618) { throw 'STOP: distinct isolated-test approval required' }
if ($PSVersionTable.PSVersion.ToString() -cne '7.6.8' -or
    (Get-FileHash -LiteralPath (Join-Path $PSHOME 'pwsh.exe') -Algorithm SHA256).Hash.ToLowerInvariant() -cne
    'b90c0f48ca4b13c9426a7863f3d469baf310ca517721dd0b55551b80ec9fee2a') { throw 'STOP: pinned runtime mismatch' }
$runtimePins=@{
    'System.Private.CoreLib.dll'='0a175cdd09067c52f8b2477cfcebf894b2f926d7302e55ae76fc921529662c7b'
    'System.Management.Automation.dll'='b98520502ae9054fd06001f578fc1ab04f4ec5bf56e4b527f51bdca2635d78e3'
    'System.IO.FileSystem.AccessControl.dll'='a87455a818254627457395e85ab4970cc844edf07254456da624a37c99e9a23f'
}
foreach ($name in $runtimePins.Keys) {
    if ((Get-FileHash -LiteralPath (Join-Path $PSHOME $name) -Algorithm SHA256).Hash.ToLowerInvariant() -cne
        $runtimePins[$name]) { throw 'STOP: runtime dependency mismatch' }
}
$modulePath=Join-Path $PSScriptRoot 'codex-developer-fixed-storage.psm1'
foreach ($entry in @(@($PSCommandPath,$ExpectedHarnessSha256),@($modulePath,$ExpectedModuleSha256))) {
    if ($entry[1] -cnotmatch '^[0-9a-f]{64}$' -or
        (Get-FileHash -LiteralPath $entry[0] -Algorithm SHA256).Hash.ToLowerInvariant() -cne $entry[1]) {
        throw 'STOP: exact reviewed source mismatch'
    }
}
$parent=[IO.Path]::GetFullPath($env:TEMP).TrimEnd('\')
$root=[IO.Path]::GetFullPath($TestRoot)
if ([IO.Path]::GetDirectoryName($root) -cne $parent -or
    [IO.Path]::GetFileName($root) -cne 'syndicatum-fixed-validation-55a445a3f2f54df2b3846ec1ea63f1dc') { throw 'STOP: synthetic root scope' }
$cursor=Get-Item -LiteralPath $parent -Force
while ($null -ne $cursor) {
    if ($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'STOP: reparse test ancestor' }
    $cursor=$cursor.Parent
}
if (Test-Path -LiteralPath $root) { throw 'STOP: attempt root exists; no retry/cleanup' }
$watch=[Diagnostics.Stopwatch]::StartNew()
# New-Item without Force rejects existing roots. All later writes stay in this tree.
$null=New-Item -ItemType Directory -Path $root -ErrorAction Stop
$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
$acl=[Security.AccessControl.DirectorySecurity]::new()
$acl.SetOwner($sid); $acl.SetAccessRuleProtection($true,$false)
foreach ($principal in @($sid,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'))) {
    $rule=[Security.AccessControl.FileSystemAccessRule]::new($principal,'FullControl',
        'ContainerInherit,ObjectInherit','None','Allow')
    $acl.AddAccessRule($rule)
}
Set-Acl -LiteralPath $root -AclObject $acl
$script:observedInventory=@()
function Assert-TestBounds {
    if ($watch.Elapsed.TotalSeconds -gt 120) { throw 'STOP: synthetic test time limit' }
    $rootItem=Get-Item -LiteralPath $root -Force
    if ($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) {
        $script:observedInventory=@(@{name='ROOT';directory=$true;reparse=$true;length=-1L})
        throw 'STOP: reparse test root'
    }
    $entries=@(foreach ($item in (Get-ChildItem -LiteralPath $root -Force | Select-Object -First 33)) {
        $reparse=[bool]($item.Attributes -band [IO.FileAttributes]::ReparsePoint)
        # Never descend into a directory or inspect a reparse target's length.
        @{name=$item.Name;directory=[bool]$item.PSIsContainer;reparse=$reparse;
          length=if ($reparse -or $item.PSIsContainer) {-1L} else {$item.Length}}
    })
    $script:observedInventory=$entries
    & $module {param($Entries) Assert-FixedSyntheticInventory $Entries} $entries
}
function Write-TestFile([string]$Name,[byte[]]$Bytes,[switch]$FailureEvidence) {
    if ($FailureEvidence) {
        # One bounded known result only, retaining the rejected inventory.
        # Never walk unknown objects or replace an existing result on failure.
        if ($Name -cne 'result.json' -or
            (Get-Item -LiteralPath $root -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw 'STOP: failure evidence boundary'
        }
    } else { Assert-TestBounds }
    if ($Bytes.Length -gt 65536 -or $Name -cnotin @('attempt.json','manifest.json','result.json')) { throw 'STOP: result bound/name' }
    $writer=[IO.FileStream]::new((Join-Path $root $Name),[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
    try { $writer.Write($Bytes); $writer.Flush($true) } finally { $writer.Dispose() }
}
$attempt=@{approval_message_id=$ApprovalMessageId;scope='isolated synthetic file test only';
    harness_sha256=$ExpectedHarnessSha256;module_sha256=$ExpectedModuleSha256;native_cutover_authority=$false}
Import-Module $modulePath -Force
$module=Get-Module 'codex-developer-fixed-storage'
$session=$null
$originalFailure=$null
$originalTemp=$env:TEMP; $originalTmp=$env:TMP
try {
    Write-TestFile 'attempt.json' ([Text.Encoding]::UTF8.GetBytes(($attempt | ConvertTo-Json -Compress)))
    # Any managed compiler scratch uses the same synthetic tree, process-local.
    $env:TEMP=$root; $env:TMP=$root
    # Establish precisely two new synthetic fixed-size files; never resize existing files.
    foreach ($name in @('records.bin','heads.bin')) {
        $path=Join-Path $root $name
        $writer=[IO.FileStream]::new($path,[IO.FileMode]::CreateNew,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
        try { $writer.Write([byte[]]::new(8192)); $writer.Flush($true) } finally { $writer.Dispose() }
        $fileAcl=Get-Acl -LiteralPath $path
        $fileAcl.SetAccessRuleProtection($true,$true)
        Set-Acl -LiteralPath $path -AclObject $fileAcl
    }
    $manifest=& $module {
        param($Root)
        Initialize-FixedHandleApi
        $files=@{}
        foreach ($name in @('records','heads')) {
            $path=Join-Path $Root ($name+'.bin')
            $stream=[IO.FileStream]::new($path,[IO.FileMode]::Open,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
            try { $files[$name]=@{path=$path;identity=[FixedStorageHandle]::Identity($stream.SafeFileHandle);
                length=$stream.Length;acl=(Get-FixedAclPin $stream)} } finally { $stream.Dispose() }
        }
        @{root=$Root;namespace='isolated-synthetic-only';slots=4;width=2048;files=$files}
    } $root
    Write-TestFile 'manifest.json' ([Text.Encoding]::UTF8.GetBytes(($manifest | ConvertTo-Json -Depth 8 -Compress)))
    Assert-TestBounds
    $session=& $module {param($Manifest) Open-FixedSession $Manifest (New-FixedWindowsApi $Manifest)} $manifest
    # One continuous session; do not invoke/open the exported live dispatcher.
    $result=& $module {
        param($Session)
        $record=[byte[]]::new(2048); $record[0]=82; $record[2047]=1
        $head=[byte[]]::new(2048); $head[0]=72; $head[2047]=1
        Write-FixedSlot $Session 'records' 0 $record
        Write-FixedSlot $Session 'heads' 0 $head
        $before=@{}
        foreach ($name in @('records','heads')) {
            $before[$name]=& $Session.api.Read $Session.handles[$name] 0 $Session.manifest.files[$name].length
        }
        $failure=$null
        try { Write-FixedSlot $Session 'records' 0 $record } catch { $failure=$_ }
        Confirm-FixedOccupiedEvidence $failure $before $before # Classify before further reads.
        if (-not $Session.closed) { throw 'STOP: occupied rejection did not close session' }
        $after=@{}; $hashes=@{}
        foreach ($name in @('records','heads')) {
            $pin=$Session.manifest.files[$name]
            $reader=[IO.FileStream]::new($pin.path,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::Read)
            try {
                $actual=& $Session.api.Inspect $reader $pin
                if ($actual.identity -cne $pin.identity -or $actual.length -ne $pin.length -or $actual.acl -cne $pin.acl) {
                    throw 'STOP: post-rejection read identity/length/ACL'
                }
                $after[$name]=[byte[]]::new($pin.length); $reader.ReadExactly($after[$name])
            } finally { $reader.Dispose() }
            $hashes[$name]=@{
                before=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($before[$name])).ToLowerInvariant()
                after=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($after[$name])).ToLowerInvariant()}
        }
        Confirm-FixedOccupiedEvidence $failure $before $after
        @{result='synthetic API/readback and occupied-slot rejection observed';closed=$true;
          container_hashes=$hashes;hardware_power_loss_proven=$false;production_readiness=$false}
    } $session
    Assert-TestBounds
    $result.inventory=$script:observedInventory
    Write-TestFile 'result.json' ([Text.Encoding]::UTF8.GetBytes(($result | ConvertTo-Json -Depth 8 -Compress)))
} catch {
    # Preserve all partial files; no retry, repair, overwrite or cleanup.
    $originalFailure=$_
    try {
        if (-not (Test-Path -LiteralPath (Join-Path $root 'result.json'))) {
            $evidence=@{result='STOP; evidence retained; no replay';production_readiness=$false;inventory=$script:observedInventory}
            Write-TestFile 'result.json' ([Text.Encoding]::UTF8.GetBytes(($evidence | ConvertTo-Json -Depth 8 -Compress))) -FailureEvidence
        }
    } catch { Write-Warning 'Secondary evidence write failed; original failure retained.' }
    throw $originalFailure
} finally {
    $env:TEMP=$originalTemp; $env:TMP=$originalTmp
    if ($null -ne $session -and -not $session.closed) {
        try { & $module {param($s) Close-FixedSession $s} $session }
        catch {
            if ($null -eq $originalFailure) { throw }
            Write-Warning 'Secondary handle close failed; original failure retained.'
        }
    }
}
