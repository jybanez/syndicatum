# Source-only gate fixture: no native collector or cutover action is invoked.
$ErrorActionPreference='Stop'
$taskModule=Join-Path $PSScriptRoot '../tools/release/codex-developer-native-b.psm1'
$taskTokens=$null; $taskErrors=$null
$taskAst=[System.Management.Automation.Language.Parser]::ParseFile(
    (Resolve-Path -LiteralPath $taskModule).Path,[ref]$taskTokens,[ref]$taskErrors)
if ($taskErrors.Count) { throw 'Native B module syntax failure' }
$taskGuard=@($taskAst.FindAll({param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -ceq 'Assert-DeveloperBGate'
},$true))
if ($taskGuard.Count -ne 1 -or $taskGuard[0].Body.EndBlock.Statements.Count -ne 1 -or
    $taskGuard[0].Body.EndBlock.Statements[0] -isnot [System.Management.Automation.Language.ThrowStatementAst]) {
    throw 'Source-only guard must unconditionally throw without any preceding action'
}
Import-Module $taskModule -Force
foreach ($taskOperation in @('admission','snapshot','suppress','stop','cutoff','flush')) {
    $taskRejected=$false
    try { Invoke-DeveloperNative -Operation $taskOperation -Plan @{} }
    catch {
        if ($_.Exception.Message -notlike 'SOURCE_ONLY:*') { throw }
        $taskRejected=$true
    }
    if (-not $taskRejected) { throw 'A native operation escaped the closed source gate' }
}
'All six native entry operations rejected before collection/action.'
