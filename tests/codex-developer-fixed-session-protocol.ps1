# Inert session API only. Worker is parsed, never executed. No native calls/files.
$ErrorActionPreference='Stop'
$worker=Join-Path $PSScriptRoot '../tools/release/codex-developer-fixed-session-worker.ps1'
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile((Resolve-Path $worker),[ref]$tokens,[ref]$errors)
if ($errors.Count -or $ast.EndBlock.Statements[0] -isnot [Management.Automation.Language.ThrowStatementAst]) {
    throw 'Worker must refuse before all imports/IO/native calls'
}
$protocol=Join-Path $PSScriptRoot '../tools/release/codex-developer-fixed-session-protocol.ps1'
Import-Module (Join-Path $PSScriptRoot '../tools/release/codex-developer-fixed-storage.psm1') -Force
$module=Get-Module 'codex-developer-fixed-storage'
& $module {
    param($Protocol)
    . $Protocol
    function New-Context { @{sequence=0;identity='';terminal=$false;openAttempted=$false;closeAttempted=$false;closeUncertain=$false;session=$null} }
    $manifest=@{root='inert-root';namespace='inert-session';slots=2;width=512;files=@{
        records=@{path='inert-records';final_path='inert-records';identity='one';length=1024;acl='private'}
        heads=@{path='inert-heads';final_path='inert-heads';identity='two';length=1024;acl='private'}}}
    $state=@{bytes=@{'inert-records'=[byte[]]::new(1024);'inert-heads'=[byte[]]::new(1024)};opens=0;closes=0;flushes=0}
    $api=@{
        Open={param($pin) $state.opens++;$pin}.GetNewClosure()
        Inspect={param($handle,$pin) $handle}
        Read={param($handle,$offset,$length)
            $raw=[byte[]]::new($length);[Array]::Copy($state.bytes[$handle.path],$offset,$raw,0,$length);return ,$raw
        }.GetNewClosure()
        Write={param($handle,$offset,$bytes) [Array]::Copy($bytes,0,$state.bytes[$handle.path],$offset,$bytes.Length)}.GetNewClosure()
        Flush={param($handle) $state.flushes++}.GetNewClosure()
        Close={param($handle) $state.closes++}.GetNewClosure()
    }
    $factory={param($pin) $api}.GetNewClosure()
    $raw=[Text.Encoding]::UTF8.GetBytes(($manifest | ConvertTo-Json -Depth 10 -Compress))
    $open=@{manifest_base64=[Convert]::ToBase64String($raw);sha256=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($raw)).ToLowerInvariant()}
    function Send($Context,$Op,$Arguments) {
        Invoke-FixedProtocol $Context @{session='inert-one';sequence=($Context.sequence+1);operation=$Op;arguments=$Arguments} $factory
    }
    $context=New-Context
    $null=Send $context 'open' $open
    if ($state.opens -ne 2) { throw 'Pair was not opened once' }
    $record=[byte[]]::new(512);$record[0]=82
    $null=Send $context 'write' @{name='records';index=0;base64=[Convert]::ToBase64String($record)}
    $null=Send $context 'flush' @{name='records'}
    $read=Send $context 'read' @{name='records';index=0}
    if ($read.value -cne [Convert]::ToBase64String($record)) { throw 'Protocol read mismatch' }
    $null=Send $context 'inspect' @{}
    if ($state.closes -ne 0 -or $state.opens -ne 2) { throw 'Protocol reopened or closed between operations' }
    $null=Send $context 'close' @{}
    Close-FixedProtocol $context
    if ($state.closes -ne 2 -or -not $context.terminal) { throw 'Terminal pair did not close once' }
    $failed=$false
    try { $null=Send $context 'open' $open } catch { $failed=$true }
    if (-not $failed -or $state.opens -ne 2 -or $state.closes -ne 2) { throw 'Terminal replay' }
    $context=New-Context
    $null=Send $context 'open' $open
    $failed=$false
    try { $null=Invoke-FixedProtocol $context @{session='wrong';sequence=2;operation='inspect';arguments=@{}} $factory } catch { $failed=$true }
    if (-not $failed -or -not $context.terminal -or $state.closes -ne 4) { throw 'Wrong-session response not terminal' }
} $protocol
'Persistent session protocol framing, continuous pair, read/write/flush and one-shot close checks pass; native worker NOT executed.'
