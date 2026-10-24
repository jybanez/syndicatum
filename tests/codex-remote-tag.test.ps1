$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$taskHelper = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts\codex-windows-lifecycle-transition.ps1'
$taskTokens = $null
$taskErrors = $null
$taskAst = [System.Management.Automation.Language.Parser]::ParseFile($taskHelper, [ref]$taskTokens, [ref]$taskErrors)
if ($taskErrors.Count) { throw 'Helper syntax is invalid.' }
$taskFunction = $taskAst.Find({ param($node)
    $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Assert-RemoteTagRevision'
}, $true)
if (-not $taskFunction) { throw 'Tag verifier is missing.' }
# Load only the pure verifier, never the installer or any plugin commands.
. ([scriptblock]::Create($taskFunction.Extent.Text))
$taskRevision = '4c2d2b64154bb8e111778dbc9defcfce1eff8371'
$taskObject = '1111111111111111111111111111111111111111'
$taskRef = 'refs/tags/codex-v0.2.9'
Assert-RemoteTagRevision @("$taskRevision`t$taskRef") 'codex-v0.2.9' $taskRevision
Assert-RemoteTagRevision @("$taskObject`t$taskRef", "$taskRevision`t$taskRef^{}") 'codex-v0.2.9' $taskRevision
Assert-RemoteTagRevision @("$taskRevision`t$taskRef^{}", "$taskObject`t$taskRef") 'codex-v0.2.9' $taskRevision
$taskInvalidCases = @(
    @{ lines = @() },
    @{ lines = @("$taskRevision`t$taskRef^{}") },
    @{ lines = @("$taskObject`t$taskRef") },
    @{ lines = @("$taskRevision`t$taskRef", "$taskObject`t$taskRef^{}") },
    @{ lines = @("$taskRevision`t$taskRef", "$taskRevision`t$taskRef") },
    @{ lines = @("$taskObject`t$taskRef", "$taskRevision`t$taskRef^{}", "$taskRevision`t$taskRef^{}") },
    @{ lines = @("malformed $taskRef") },
    @{ lines = @("$taskRevision`trefs/tags/unexpected") }
)
foreach ($taskCase in $taskInvalidCases) {
    $taskRejected = $false
    try { Assert-RemoteTagRevision $taskCase.lines 'codex-v0.2.9' $taskRevision }
    catch { $taskRejected = $true }
    if (-not $taskRejected) { throw 'Invalid remote tag response was accepted.' }
}
Write-Output 'PASS: lightweight and annotated exact tags accepted; eight malformed/mismatched/ambiguous responses rejected; no installer commands executed.'
