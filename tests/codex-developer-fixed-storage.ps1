# Inert API seam + AST only. Never calls New-FixedWindowsApi or a native API.
$ErrorActionPreference='Stop'
$modulePath=Join-Path $PSScriptRoot '../tools/release/codex-developer-fixed-storage.psm1'
$tokens=$null; $errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile((Resolve-Path $modulePath),[ref]$tokens,[ref]$errors)
if ($errors.Count) { throw 'Fixed storage parse failure' }
$providerMembers=@($ast.FindAll({param($node)
    $node -is [Management.Automation.Language.MemberExpressionAst] -and $node.Member.Value -ceq 'PSIsContainer'
},$true))
if ($providerMembers.Count) { throw 'Backend relies on provider-only container property' }
$gate=@($ast.FindAll({param($node)
    $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -ceq 'Assert-FixedStorageGate'
},$true))
if ($gate.Count -ne 1 -or $gate[0].Body.EndBlock.Statements.Count -ne 1 -or
    $gate[0].Body.EndBlock.Statements[0] -isnot [Management.Automation.Language.ThrowStatementAst]) {
    throw 'Fixed native gate must unconditionally throw'
}
Import-Module $modulePath -Force
$blocked=$false
try { Invoke-FixedStorage -Manifest @{} } catch {
    if ($_.Exception.Message -notlike 'SOURCE_ONLY:*') { throw }
    $blocked=$true
}
if (-not $blocked) { throw 'Native fixed gate opened' }
$module=Get-Module 'codex-developer-fixed-storage'
# Exercise returned callbacks AFTER leaving module scope, with pure private
# helpers and fake file/handle operations. No New-FixedWindowsApi invocation.
$scopeFixture=& $module {
    function Assert-PureScopePath($Path,$Root) {
        if ($Path -cne ($Root+'/file')) { throw 'pure scope path rejected' }
    }
    function Get-PureScopeAcl($Handle) { 'pure-private-acl' }
    $acl=${function:Get-PureScopeAcl}
    $bindings=@{
        Validate=${function:Assert-PureScopePath}
        Open={param($pin) @{path=$pin.path;fake=$true}}
        Inspect={param($handle,$pin) @{acl=(& $acl $handle);fake=$handle.fake}}.GetNewClosure()
        Read={};Write={};Flush={};Close={}
    }
    $api=New-FixedBoundApi 'synthetic-root' $bindings
    $broken={Assert-PureScopePath 'synthetic-root/file' 'synthetic-root'}.GetNewClosure()
    @{api=$api;broken=$broken}
}
$scopePin=@{path='synthetic-root/file'}
$scopeHandle=& $scopeFixture.api.Open $scopePin
$scopeObserved=& $scopeFixture.api.Inspect $scopeHandle $scopePin
if (-not $scopeObserved.fake -or $scopeObserved.acl -cne 'pure-private-acl') { throw 'Bound private helper lost module scope' }
$scopeRejected=$false
try { & $scopeFixture.api.Open @{path='wrong-path'} } catch { $scopeRejected=$true }
if (-not $scopeRejected) { throw 'Bound private path helper bypassed' }
$legacyFailed=$false
try { & $scopeFixture.broken } catch { $legacyFailed=$true }
if (-not $legacyFailed) { throw 'Scope regression negative control did not reproduce missing helper' }
& $module {
    function Fixture {
        $manifest=@{root='fake-root';namespace='fake-authority';slots=2;width=512;files=@{
            records=@{path='fake-records';identity='file-one';length=1024;acl='private'}
            heads=@{path='fake-heads';identity='file-two';length=1024;acl='private'}}}
        $state=@{held=@{};bytes=@{};fault='';events=[Collections.Generic.List[string]]::new()}
        foreach ($pin in $manifest.files.Values) { $state.bytes[$pin.path]=[byte[]]::new(1024) }
        $api=@{
            Open={param($pin)
                if ($state.held[$pin.path]) { throw 'contended' }
                $state.held[$pin.path]=$true
                @{path=$pin.path;identity=$pin.identity;length=$pin.length;acl=$pin.acl}
            }.GetNewClosure()
            Inspect={param($handle,$pin)
                if ($state.fault -eq 'identity') { return @{identity='changed';length=1024;acl='private'} }
                if ($state.fault -eq 'acl') { return @{identity=$handle.identity;length=1024;acl='changed'} }
                if ($state.fault -eq 'length') { return @{identity=$handle.identity;length=1;acl='private'} }
                @{identity=$handle.identity;length=$handle.length;acl=$handle.acl}
            }.GetNewClosure()
            Read={param($handle,$offset,$count)
                $result=[byte[]]::new($count)
                [Buffer]::BlockCopy($state.bytes[$handle.path],$offset,$result,0,$count)
                if ($state.fault -eq 'readback' -and $state.events.Contains('write')) { $result[0]=2 }
                ,$result
            }.GetNewClosure()
            Write={param($handle,$offset,$bytes)
                $state.events.Add('write')
                if ($state.fault -eq 'partial') {
                    $state.bytes[$handle.path][$offset]=$bytes[0]; throw 'partial'
                }
                [Buffer]::BlockCopy($bytes,0,$state.bytes[$handle.path],$offset,$bytes.Length)
            }.GetNewClosure()
            Flush={param($handle)
                $state.events.Add('flush')
                if ($state.fault -eq 'flush') { throw 'flush failed' }
            }.GetNewClosure()
            Close={param($handle) $state.held[$handle.path]=$false}.GetNewClosure()
        }
        @{manifest=$manifest;state=$state;api=$api}
    }
    $fixture=Fixture
    $session=Open-FixedSession $fixture.manifest $fixture.api
    $bytes=[byte[]]::new(512); $bytes[0]=1
    Write-FixedSlot $session 'records' 0 $bytes
    if (($fixture.state.events -join ',') -cne 'write,flush') { throw 'write/flush order' }
    # Caller mutating its manifest cannot change the retained pin.
    $fixture.manifest.files.records.identity='caller-change'
    Assert-FixedSession $session
    $rejected=$false
    try { Write-FixedSlot $session 'records' 0 $bytes } catch {
        if (($_.FullyQualifiedErrorId -split ',')[0] -cne 'FixedStorageOccupiedSlot') { throw }
        $rejected=$true
    }
    if (-not $rejected -or -not $session.closed) { throw 'occupied slot accepted' }
    foreach ($fault in @('identity','acl','length','partial','flush','readback','capacity')) {
        $fixture=Fixture
        $session=Open-FixedSession $fixture.manifest $fixture.api
        $fixture.state.fault=$fault
        $index=if ($fault -eq 'capacity') {2} else {0}
        $rejected=$false
        try { Write-FixedSlot $session 'records' $index $bytes } catch { $rejected=$true }
        if (-not $rejected -or -not $session.closed) { throw "fault accepted: $fault" }
        $count=$fixture.state.events.Count
        try { Write-FixedSlot $session 'heads' 0 $bytes } catch { }
        if ($fixture.state.events.Count -ne $count) { throw 'terminal replay issued API write' }
    }
    $fixture=Fixture
    $first=Open-FixedSession $fixture.manifest $fixture.api
    $rejected=$false
    try { Open-FixedSession $fixture.manifest $fixture.api } catch { $rejected=$true }
    if (-not $rejected -or -not $fixture.state.held['fake-records']) { throw 'contention lease damaged' }
    Close-FixedSession $first
    # Compose exact Python model frames with the PowerShell storage contract.
    # Python emits memory-only bytes; this API remains the fake fixture above.
    $pythonCode=@'
import base64, json, pathlib, sys
sys.path.insert(0, str(pathlib.Path.cwd() / "tools/release"))
sys.path.insert(0, str(pathlib.Path.cwd() / "tests"))
import importlib.util
spec=importlib.util.spec_from_file_location("fixture", "tests/codex-developer-fixed-journal.py")
fixture=importlib.util.module_from_spec(spec); spec.loader.exec_module(fixture)
store=fixture.fixed.MemoryStorage(slots=2, width=2048)
model=fixture.open_model(store)
result=model.model_action("synthetic-first", model.external_head, "reviewed-first")
print(json.dumps({"frames": {name: [base64.b64encode(store.read(name,i)).decode() for i in range(2)]
                            for name in ("records","heads")}, "head":result["head_sha256"]}))
'@
    $compositionText=$pythonCode | python -
    if ($LASTEXITCODE -ne 0) { throw 'Python composition model failed' }
    $composition=$compositionText | ConvertFrom-Json -AsHashtable
    $fixture=Fixture
    $fixture.manifest.width=2048
    foreach ($pin in $fixture.manifest.files.Values) {
        $pin.length=4096
        $fixture.state.bytes[$pin.path]=[byte[]]::new(4096)
    }
    $session=Open-FixedSession $fixture.manifest $fixture.api
    foreach ($index in @(0,1)) {
        foreach ($name in @('records','heads')) {
            $frame=[Convert]::FromBase64String($composition.frames[$name][$index])
            Write-FixedSlot $session $name $index $frame
        }
    }
    $actualHead=[byte[]]::new(2048)
    [Buffer]::BlockCopy($fixture.state.bytes['fake-heads'],2048,$actualHead,0,2048)
    $actualDigest=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($actualHead)).ToLowerInvariant()
    if ($actualDigest -cne $composition.head) { throw 'Cross-language retained head mismatch' }
    Close-FixedSession $session
    # Expected rejection must be specifically classified and preserve BOTH files.
    $specific=[Management.Automation.ErrorRecord]::new([InvalidOperationException]::new('occupied'),
        'FixedStorageOccupiedSlot',[Management.Automation.ErrorCategory]::InvalidOperation,$null)
    $unrelated=[Management.Automation.ErrorRecord]::new([IO.IOException]::new('read failed'),
        'UnrelatedReadFailure',[Management.Automation.ErrorCategory]::ReadError,$null)
    $before=@{records=[byte[]]@(1,2);heads=[byte[]]@(3,4)}
    Confirm-FixedOccupiedEvidence $specific $before $before
    $rejected=$false
    try { Confirm-FixedOccupiedEvidence $unrelated $before $before } catch {
        if (($_.FullyQualifiedErrorId -split ',')[0] -cne 'UnrelatedReadFailure') { throw }
        $rejected=$true
    }
    if (-not $rejected) { throw 'Unrelated exception reported as occupied success' }
    foreach ($name in @('records','heads')) {
        $after=@{records=[byte[]]@(1,2);heads=[byte[]]@(3,4)}
        $after[$name][0]=9
        $rejected=$false
        try { Confirm-FixedOccupiedEvidence $specific $before $after } catch { $rejected=$true }
        if (-not $rejected) { throw 'Unexpected changed bytes reported as success' }
    }
    $known=@{name='records.bin';directory=$false;reparse=$false;length=8192}
    Assert-FixedSyntheticInventory @($known)
    foreach ($bad in @(
        @{name='unknown.txt';directory=$false;reparse=$false;length=1},
        @{name='records.bin';directory=$false;reparse=$true;length=-1},
        @{name='scratch';directory=$true;reparse=$false;length=-1})) {
        $rejected=$false
        try { Assert-FixedSyntheticInventory @($bad) } catch { $rejected=$true }
        if (-not $rejected) { throw 'Unknown/reparse/directory footprint accepted' }
    }
}
'Fixed storage AST/gate and inert identity/ACL/length/exclusive/slot/flush/readback checks pass.'

# Parse the native-validation candidate, never invoke it (even in default mode).
$harnessPath=Join-Path $PSScriptRoot '../tools/release/codex-fixed-synthetic-validation.ps1'
$harnessTokens=$null; $harnessErrors=$null
$harnessAst=[Management.Automation.Language.Parser]::ParseFile((Resolve-Path $harnessPath),[ref]$harnessTokens,[ref]$harnessErrors)
if ($harnessErrors.Count) { throw 'Synthetic native harness parse failure' }
$providerMembers=@($harnessAst.FindAll({param($node)
    $node -is [Management.Automation.Language.MemberExpressionAst] -and $node.Member.Value -ceq 'PSIsContainer'
},$true))
if ($providerMembers.Count) { throw 'Harness relies on provider-only container property' }
$calls=@($harnessAst.FindAll({param($node) $node -is [Management.Automation.Language.CommandAst]},$true) |
    ForEach-Object { $_.GetCommandName() })
if ($calls -contains 'Invoke-FixedStorage' -or $calls -contains 'Invoke-DeveloperNative' -or
    $calls -contains 'Remove-Item' -or $calls -contains 'Start-Process') { throw 'Harness scope escaped' }
'Synthetic native-validation harness parsed only; NOT executed.'
