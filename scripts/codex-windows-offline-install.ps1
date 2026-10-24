[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $HandoffRoot,
    [Parameter(Mandatory = $true)][string] $WebRoot,
    [Parameter(Mandatory = $true)][string] $NodePath,
    [string] $CodexRoot = (Join-Path $env:USERPROFILE '.codex')
)

# Run from an independent PowerShell 7 window after Codex fully exits.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
function Assert-OfflineHandoff([string] $Destination, [string[]] $ProtectedRoots) {
    if (-not [IO.Path]::IsPathFullyQualified($Destination) -or $Destination.StartsWith('\\') -or $Destination.Contains('::')) { throw 'Expected an absolute local handoff path.' }
    $resolved = [IO.Path]::GetFullPath($Destination).TrimEnd('\')
    if ($resolved -notmatch '^[A-Za-z]:\\' -or $resolved.Length -le 3) { throw 'Handoff must be a specific local directory.' }
    $volume = [IO.DriveInfo]::new([IO.Path]::GetPathRoot($resolved))
    if (-not $volume.IsReady -or $volume.DriveType -ne [IO.DriveType]::Fixed) { throw 'Handoff requires a fixed local volume.' }
    foreach ($root in $ProtectedRoots) {
        if (-not [IO.Path]::IsPathFullyQualified($root)) { throw 'Protected root must be absolute.' }
        $other = [IO.Path]::GetFullPath($root).TrimEnd('\')
        if ($resolved.Equals($other, [StringComparison]::OrdinalIgnoreCase) -or
            $resolved.StartsWith($other + '\', [StringComparison]::OrdinalIgnoreCase) -or
            $other.StartsWith($resolved + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Handoff overlaps a protected root.' }
    }
    $cursor = $resolved
    while ($cursor) {
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force
            if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Unsafe handoff ancestor.' }
        }
        $parent = Split-Path -Parent $cursor
        if ($parent -eq $cursor) { break }
        $cursor = $parent
    }
    if (Test-Path -LiteralPath $resolved) { throw 'An attempt already exists. Preserve it; do not replay.' }
    return $resolved
}

function Test-OwnedListenerProcess($Candidate, [string] $ExpectedNode, [string] $ExpectedRuntime) {
    if (-not $Candidate -or $Candidate.Name -ne 'node.exe' -or $Candidate.ExecutablePath -ne $ExpectedNode -or -not $Candidate.CommandLine) { return $false }
    return $Candidate.CommandLine -match ('^\s*(?:"[^"]+"|\S+)\s+"' + [regex]::Escape($ExpectedRuntime) + '"\s*$')
}

function New-OfflineAttemptStatus([string] $Result, [string] $Stage, [string] $Detail, [string] $StartupMethod, [string] $RestartError) {
    return [ordered]@{
        result = $(if ($RestartError) { 'failed' } else { $Result })
        installation_result = $Result
        stage = $Stage
        detail = $Detail
        startup_method = $StartupMethod
        restart_error = $RestartError
        target_version = '0.2.9'
        target_revision = '4c2d2b64154bb8e111778dbc9defcfce1eff8371'
        updated_at = [DateTime]::UtcNow.ToString('o')
    }
}

function Test-ListenerRunCommand([string] $Stored, [string] $PowerShellPath, [string] $Arguments) {
    return $Stored -cin @(( $PowerShellPath + ' ' + $Arguments ), ( '"' + $PowerShellPath + '" ' + $Arguments ))
}

if ($PSVersionTable.PSVersion.Major -lt 7) { throw 'Run with PowerShell 7 (pwsh).' }
if (-not $IsWindows) { throw 'This runner supports Windows only.' }
if (@(Get-Process -Name Codex,ChatGPT -ErrorAction SilentlyContinue).Count) { throw 'Fully exit Codex and ChatGPT Desktop first.' }
$taskPluginDataRoot = Join-Path $env:USERPROFILE '.syndicatum\codex-plugin'
$taskHandoff = Assert-OfflineHandoff $HandoffRoot @($CodexRoot, $taskPluginDataRoot, $WebRoot)
$taskBackup = Join-Path $PSScriptRoot 'codex-windows-lifecycle-backup.ps1'
$taskTransition = Join-Path $PSScriptRoot 'codex-windows-lifecycle-transition.ps1'
$taskBackupPins = @('B69F8D0F53CF3CF2247193F6AEFD75926CC238C16C0D4F20331CE9E861CBC80B', '18E678D1E78EB7EE83E15CCD0B74795DEB93A2312B2EA215D3781167EB073E12')
$taskTransitionPins = @('798DA9DDB50835C32125CBEA16B5D87C8C2CFBE25A494E61D3397FCFE479C0A9', 'CC67656EAE4A913DA2321501BA91B17F8294CA4BAB1311EC179769E06FCDF33B')
if ((Get-FileHash -LiteralPath $taskBackup -Algorithm SHA256).Hash -notin $taskBackupPins -or
    (Get-FileHash -LiteralPath $taskTransition -Algorithm SHA256).Hash -notin $taskTransitionPins) { throw 'Reviewed helper hash mismatch.' }
$taskRemote = @(& git ls-remote --tags https://github.com/jybanez/syndicatum.git refs/tags/codex-v0.2.9)
if ($LASTEXITCODE -ne 0 -or $taskRemote.Count -ne 1 -or $taskRemote[0].Split("`t")[0] -ne '4c2d2b64154bb8e111778dbc9defcfce1eff8371') { throw 'Immutable tag mismatch.' }
$taskCodexRoot = [IO.Path]::GetFullPath($CodexRoot)
$taskDataRoot = [IO.Path]::GetFullPath($taskPluginDataRoot)
$taskExpectedLauncher = Join-Path $taskDataRoot 'background-launcher.ps1'
$taskMetadata = Get-Content -LiteralPath (Join-Path $taskDataRoot 'background.json') -Raw | ConvertFrom-Json
$taskRuntime = Join-Path $taskDataRoot 'runtime\background-service.mjs'
if ($taskMetadata.serviceEntry -ne $taskRuntime -or $taskMetadata.execPath -ne [IO.Path]::GetFullPath($NodePath)) { throw 'Unexpected background runtime identity.' }
$taskStartupMethod = [string]$taskMetadata.startupMethod
if ($taskStartupMethod -notin @('run_key', 'scheduled_task')) { throw 'Unknown background startup method.' }
$taskPowerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$taskLaunchArguments = '-NoLogo -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "' + $taskExpectedLauncher + '"'
$taskService = $null
$taskRunCommand = $null
if ($taskStartupMethod -eq 'scheduled_task') {
    $taskService = Get-ScheduledTask -TaskName 'Syndicatum Codex Connector' -TaskPath '\' -ErrorAction Stop
    if (@($taskService.Actions).Count -ne 1 -or -not $taskService.Settings.Enabled -or
        $taskService.Actions[0].Execute -ne $taskPowerShell -or
        $taskService.Actions[0].Arguments -ne $taskLaunchArguments) { throw 'Unexpected listener task; refusing shutdown.' }
    $taskPrincipalSid = ([Security.Principal.NTAccount]::new($taskService.Principal.UserId)).Translate([Security.Principal.SecurityIdentifier]).Value
    if ($taskPrincipalSid -ne [Security.Principal.WindowsIdentity]::GetCurrent().User.Value -or
        [string]$taskService.Principal.LogonType -ne 'Interactive' -or [string]$taskService.Principal.RunLevel -ne 'Limited') { throw 'Unexpected listener task principal.' }
} else {
    $taskRunCommand = Get-ItemPropertyValue -LiteralPath 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'SyndicatumCodexConnector' -ErrorAction Stop
    if (-not (Test-ListenerRunCommand $taskRunCommand $taskPowerShell $taskLaunchArguments)) { throw 'Unexpected listener Run-key command.' }
}
$taskOwnedProcesses = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object {
    Test-OwnedListenerProcess $_ $taskMetadata.execPath $taskRuntime
})
if ($taskOwnedProcesses.Count -gt 1) { throw 'Multiple background owners; refusing shutdown.' }
$taskRestart = $taskOwnedProcesses.Count -eq 1
if ($taskRestart) {
    $taskHealth = Get-Content -LiteralPath (Join-Path $taskDataRoot 'background-health.json') -Raw | ConvertFrom-Json
    $taskAge = [DateTime]::UtcNow.Subtract([DateTime]::Parse($taskHealth.updatedAt).ToUniversalTime()).TotalSeconds
    $taskLockReader = 'const fs=require("node:fs");console.log(JSON.stringify(process.argv.slice(1).map(p=>Number(fs.readFileSync(p,"utf8").trim()))))'
    $taskLockOwners = @(& $NodePath -e $taskLockReader (Join-Path $taskDataRoot 'background.lock') (Join-Path $taskDataRoot 'listener.lock'))
    if ($LASTEXITCODE -ne 0) { throw 'Unable to verify listener lock owners.' }
    $taskLockIds = @($taskLockOwners -join '' | ConvertFrom-Json)
    if ($taskAge -lt -5 -or $taskAge -ge 45 -or $taskHealth.pid -ne $taskOwnedProcesses[0].ProcessId -or
        $taskLockIds.Count -ne 2 -or $taskLockIds[0] -ne $taskHealth.pid -or $taskLockIds[1] -ne $taskHealth.pid) { throw 'Listener ownership/health mismatch.' }
}
$taskStage = 'stop_listener'
$taskResult = 'failed'
$taskDetail = 'Attempt did not complete.'
$taskRestartError = $null
New-Item -ItemType Directory -Path $taskHandoff | Out-Null
function Invoke-TaskHelper([string] $Script, [string[]] $Arguments, [string] $Log) {
    & pwsh -NoLogo -NoProfile -NonInteractive -File $Script @Arguments *> $Log
    if ($LASTEXITCODE -ne 0) { throw "Helper failed at $taskStage. Retain logs; do not replay." }
}
try {
    if ($taskRestart -and $taskStartupMethod -eq 'scheduled_task') { Stop-ScheduledTask -InputObject $taskService -ErrorAction Stop }
    if ($taskRestart) {
        $taskOwner = Get-CimInstance Win32_Process -Filter ("ProcessId = " + $taskOwnedProcesses[0].ProcessId) -ErrorAction Stop
        if ($taskOwner) {
            if (-not (Test-OwnedListenerProcess $taskOwner $taskMetadata.execPath $taskRuntime) -or $taskOwner.CreationDate -ne $taskOwnedProcesses[0].CreationDate) { throw 'Background owner identity changed.' }
            Stop-Process -Id $taskOwner.ProcessId -ErrorAction Stop
        }
    }
    $taskDeadline = [DateTime]::UtcNow.AddSeconds(30)
    do {
        $taskProcesses = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object {
            $_.Name -eq 'node.exe' -and $_.CommandLine -and $_.CommandLine.Contains($taskRuntime)
        })
        if (-not $taskProcesses.Count) { break }
        Start-Sleep -Seconds 1
    } while ([DateTime]::UtcNow -lt $taskDeadline)
    if ($taskProcesses.Count) { throw 'Listener remains active; installation was not attempted.' }
    foreach ($taskLock in @('background.lock', 'listener.lock')) {
        $taskLockPath = Join-Path $taskDataRoot $taskLock
        if (Test-Path -LiteralPath $taskLockPath) {
            $taskStream = [IO.File]::Open($taskLockPath, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::None)
            $taskStream.Dispose()
        }
    }
    $taskStage = 'verified_backup'
    Invoke-TaskHelper $taskBackup @(
        '-HandoffRoot', $taskHandoff,
        '-MarketplaceRoot', (Join-Path $taskCodexRoot '.tmp\marketplaces\syndicatum'),
        '-PluginCacheRoot', (Join-Path $taskCodexRoot 'plugins\cache\syndicatum\codex'),
        '-ProtectedStateRoot', $taskDataRoot,
        '-CodexRoot', $taskCodexRoot,
        '-WebRoot', $WebRoot
    ) (Join-Path $taskHandoff 'backup.log')
    $taskManifest = Join-Path $taskHandoff 'backup-first-transition\verified-manifest.json'
    foreach ($taskMode in @('Inspect', 'RejectFixture', 'Apply')) {
        $taskStage = $taskMode
        Invoke-TaskHelper $taskTransition @(
            '-Mode', $taskMode, '-TargetVersion', '0.2.9',
            '-CodexRoot', $taskCodexRoot,
            '-BackupManifestPath', $taskManifest,
            '-ReportPath', (Join-Path $taskHandoff ($taskMode + '-report.json'))
        ) (Join-Path $taskHandoff ($taskMode + '.log'))
    }
    $taskReport = Get-Content -LiteralPath (Join-Path $taskHandoff 'Apply-report.json') -Raw | ConvertFrom-Json
    if ($taskReport.result -ne 'transition_completed_restart_required' -or
        $taskReport.installed_manifest_version -ne '0.2.9' -or -not $taskReport.mutation_occurred) { throw 'Apply did not prove the expected installation.' }
    $taskResult = 'success'
    $taskDetail = 'One verified transition completed. Reopen Codex for installed health and live routing acceptance.'
    $taskStage = 'installed_restart_required'
} catch {
    $taskDetail = $_.Exception.Message
} finally {
    if ($taskRestart) {
        try {
            if ($taskStartupMethod -eq 'scheduled_task') {
                Start-ScheduledTask -TaskName 'Syndicatum Codex Connector' -TaskPath '\' -ErrorAction Stop
            } else {
                $null = Start-Process -FilePath $taskPowerShell -ArgumentList $taskLaunchArguments -WindowStyle Hidden -PassThru -ErrorAction Stop
            }
            $taskRestartDeadline = [DateTime]::UtcNow.AddSeconds(30)
            do {
                $taskRestored = $null
                try { $taskRestored = Get-Content -LiteralPath (Join-Path $taskDataRoot 'background-health.json') -Raw | ConvertFrom-Json } catch {}
                if ($taskRestored) {
                    $taskRestoredAge = [DateTime]::UtcNow.Subtract([DateTime]::Parse($taskRestored.updatedAt).ToUniversalTime()).TotalSeconds
                    $taskRestoredProcess = Get-CimInstance Win32_Process -Filter ("ProcessId = " + [int]$taskRestored.pid) -ErrorAction Stop
                    if ($taskRestoredAge -ge -5 -and $taskRestoredAge -lt 45 -and
                        $taskRestored.state -in @('running','degraded','authorized_idle') -and
                        (Test-OwnedListenerProcess $taskRestoredProcess $taskMetadata.execPath $taskRuntime) -and
                        $taskRestored.pid -ne $taskOwnedProcesses[0].ProcessId) {
                        $taskRestoredLockOutput = @(& $NodePath -e $taskLockReader (Join-Path $taskDataRoot 'background.lock') (Join-Path $taskDataRoot 'listener.lock'))
                        $taskRestoredLockIds = @($taskRestoredLockOutput -join '' | ConvertFrom-Json)
                        if ($LASTEXITCODE -eq 0 -and $taskRestoredLockIds.Count -eq 2 -and
                            $taskRestoredLockIds[0] -eq $taskRestored.pid -and $taskRestoredLockIds[1] -eq $taskRestored.pid) { break }
                    }
                }
                Start-Sleep -Seconds 1
            } while ([DateTime]::UtcNow -lt $taskRestartDeadline)
            if ([DateTime]::UtcNow -ge $taskRestartDeadline) { throw 'Restarted listener did not report fresh health.' }
        } catch { $taskRestartError = $_.Exception.Message }
    }
    if ($taskStartupMethod -eq 'run_key') {
        try {
            if ((Get-ItemPropertyValue -LiteralPath 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'SyndicatumCodexConnector' -ErrorAction Stop) -cne $taskRunCommand) { throw 'Run-key registration changed during the attempt.' }
        } catch { $taskRestartError = @($taskRestartError, $_.Exception.Message) -join ' ' }
    }
    New-OfflineAttemptStatus $taskResult $taskStage $taskDetail $taskStartupMethod $taskRestartError | ConvertTo-Json |
        Set-Content -LiteralPath (Join-Path $taskHandoff 'status.json') -Encoding utf8NoBOM
}
Write-Host $taskDetail
if ($taskRestartError) { Write-Host ('Listener restart error: ' + $taskRestartError) }
if ($taskResult -ne 'success' -or $taskRestartError) { exit 1 }
