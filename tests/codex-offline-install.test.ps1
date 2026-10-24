$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$taskSource = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts\codex-windows-offline-install.ps1'
$taskTokens = $null
$taskErrors = $null
$taskAst = [System.Management.Automation.Language.Parser]::ParseFile($taskSource,[ref]$taskTokens,[ref]$taskErrors)
if ($taskErrors.Count) { throw 'Offline installer syntax is invalid.' }
foreach ($taskName in @('Assert-OfflineHandoff','Test-OwnedListenerProcess','New-OfflineAttemptStatus','Test-ListenerRunCommand')) {
    $taskFunction = $taskAst.Find({ param($node)
        $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $taskName
    }, $true)
    if (-not $taskFunction) { throw "Missing function $taskName" }
    . ([scriptblock]::Create($taskFunction.Extent.Text))
}
function Assert-Test([bool] $Condition, [string] $Message) { if (-not $Condition) { throw $Message } }
$taskPowerShellPath = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$taskArguments = '-NoProfile -File "C:\User Data\launcher.ps1"'
Assert-Test (Test-ListenerRunCommand ($taskPowerShellPath + ' ' + $taskArguments) $taskPowerShellPath $taskArguments) 'Exact unquoted executable registration should pass.'
Assert-Test (Test-ListenerRunCommand ('"' + $taskPowerShellPath + '" ' + $taskArguments) $taskPowerShellPath $taskArguments) 'Exact quoted executable registration should pass.'
Assert-Test (-not (Test-ListenerRunCommand ($taskPowerShellPath + ' ' + $taskArguments + ' -extra') $taskPowerShellPath $taskArguments)) 'Modified startup arguments must fail.'
$taskNode = 'C:\Program Files\nodejs\node.exe'
$taskRuntime = 'C:\User Data\runtime\background-service.mjs'
$taskProcess = [pscustomobject]@{ Name='node.exe';ExecutablePath=$taskNode;CommandLine=('"' + $taskNode + '" "' + $taskRuntime + '"') }
Assert-Test (Test-OwnedListenerProcess $taskProcess $taskNode $taskRuntime) 'Exact listener should match.'
foreach ($taskAlteration in @(
    @{Name='other.exe'}, @{ExecutablePath='C:\other\node.exe'},
    @{CommandLine=($taskProcess.CommandLine + ' --eval unsafe')},
    @{CommandLine=($taskProcess.CommandLine.Replace('background-service.mjs','background-service.mjs-other'))},
    @{CommandLine=''}, @{CommandLine=('"' + $taskNode + '" --eval "' + $taskRuntime + '"')}
)) {
    $taskCandidate = [pscustomobject]@{Name=$taskProcess.Name;ExecutablePath=$taskProcess.ExecutablePath;CommandLine=$taskProcess.CommandLine}
    foreach ($taskKey in $taskAlteration.Keys) { $taskCandidate.$taskKey=$taskAlteration[$taskKey] }
    Assert-Test (-not (Test-OwnedListenerProcess $taskCandidate $taskNode $taskRuntime)) 'Unrelated/modified process must not match.'
}
$taskFailed = New-OfflineAttemptStatus 'failed' 'verified_backup' 'Original backup failure' 'run_key' 'Separate restart failure'
Assert-Test ($taskFailed.stage -eq 'verified_backup' -and $taskFailed.detail -eq 'Original backup failure' -and $taskFailed.restart_error -eq 'Separate restart failure') 'Restart failure must not replace original failure.'
$taskInstalled = New-OfflineAttemptStatus 'success' 'installed_restart_required' 'Installed' 'run_key' 'Restart failed'
Assert-Test ($taskInstalled.result -eq 'failed' -and $taskInstalled.installation_result -eq 'success') 'Installed-but-restart-failed must remain distinguishable.'
if ($IsWindows) {
    $taskRoot = Join-Path ([IO.Path]::GetTempPath()) ('syndicatum-offline-test-' + [guid]::NewGuid())
    try {
        New-Item -ItemType Directory -Path $taskRoot | Out-Null
        $taskProtected = Join-Path $taskRoot 'protected'
        New-Item -ItemType Directory -Path $taskProtected | Out-Null
        Assert-Test ((Assert-OfflineHandoff (Join-Path $taskRoot 'fresh') @($taskProtected)) -eq (Join-Path $taskRoot 'fresh')) 'Separate absent handoff should pass.'
        foreach ($taskBad in @($taskRoot,$taskProtected,(Join-Path $taskProtected 'child'))) {
            $taskRejected = $false
            try { $null = Assert-OfflineHandoff $taskBad @($taskProtected) } catch { $taskRejected=$true }
            Assert-Test $taskRejected 'Existing/overlapping handoff must fail.'
        }
    } finally {
        $taskResolvedRoot = [IO.Path]::GetFullPath($taskRoot)
        $taskTemp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
        if ($taskResolvedRoot.StartsWith($taskTemp, [StringComparison]::OrdinalIgnoreCase) -and (Split-Path -Leaf $taskResolvedRoot).StartsWith('syndicatum-offline-test-')) {
            Remove-Item -LiteralPath $taskResolvedRoot -Recurse -Force
        }
    }
}
$taskCommandNames = @($taskAst.FindAll({ param($node) $node -is [System.Management.Automation.Language.CommandAst] },$true) | ForEach-Object { $_.GetCommandName() })
foreach ($taskForbidden in @('Register-ScheduledTask','Unregister-ScheduledTask','Enable-ScheduledTask','Disable-ScheduledTask','Set-ItemProperty','Remove-ItemProperty')) {
    Assert-Test ($taskForbidden -notin $taskCommandNames) 'Installer must not mutate startup registration.'
}
Write-Output 'PASS: owned process isolation, separate failure reporting, no registration mutation, and Windows handoff boundary checks. Installer main was not executed.'
