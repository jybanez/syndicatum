# Inert API seam + AST only. Never calls New-FixedWindowsApi or a native API.
$ErrorActionPreference='Stop'
$modulePath=Join-Path $PSScriptRoot '../tools/release/codex-developer-fixed-storage.psm1'
$tokens=$null; $errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile((Resolve-Path $modulePath),[ref]$tokens,[ref]$errors)
if ($errors.Count) { throw 'Fixed storage parse failure' }
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
    try { Write-FixedSlot $session 'records' 0 $bytes } catch { $rejected=$true }
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
}
'Fixed storage AST/gate and inert identity/ACL/length/exclusive/slot/flush/readback checks pass.'
