param(
    [string]$PhpPath = "C:\wamp64\bin\php\php8.2.29\php.exe",
    [int]$IdleMilliseconds = 500
)

$ErrorActionPreference = 'Stop'
$taskName = 'Syndicatum Realtime Outbox'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$launcher = Join-Path $projectRoot 'scripts\run-realtime-outbox-supervised.ps1'
$worker = Join-Path $projectRoot 'scripts\process-message-outbox.php'
$powerShellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$userId = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name

foreach ($requiredFile in @($PhpPath, $launcher, $worker, $powerShellPath)) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "Required executable or script was not found: $requiredFile"
    }
}
$IdleMilliseconds = [Math]::Max(100, [Math]::Min(60000, $IdleMilliseconds))

$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existingTask) {
    Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
}

# Stop only this project's previous detached worker before handing ownership to
# the supervised task. The database lock remains the final duplicate guard.
$existingWorkers = @(Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'php.exe' -and
    $_.CommandLine -like ('*' + $worker + '*') -and
    $_.CommandLine -like '*--watch*'
})
foreach ($process in $existingWorkers) {
    Stop-Process -Id $process.ProcessId -Force
}

$arguments = '-NoLogo -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -PhpPath "{1}" -IdleMilliseconds {2}' -f $launcher, $PhpPath, $IdleMilliseconds
$action = New-ScheduledTaskAction -Execute $powerShellPath -Argument $arguments -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $userId
$principal = New-ScheduledTaskPrincipal -UserId $userId -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -DontStopOnIdleEnd `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Start-ScheduledTask -TaskName $taskName

$deadline = [DateTime]::UtcNow.AddSeconds(90)
$task = $null
$workerProcesses = @()
do {
    Start-Sleep -Milliseconds 500
    $task = Get-ScheduledTask -TaskName $taskName
    $workerProcesses = @(Get-CimInstance Win32_Process | Where-Object {
        $_.Name -eq 'php.exe' -and
        $_.CommandLine -like ('*' + $worker + '*') -and
        $_.CommandLine -like '*--watch*'
    })
} while (($task.State -ne 'Running' -or $workerProcesses.Count -eq 0) -and [DateTime]::UtcNow -lt $deadline)

$taskInfo = Get-ScheduledTaskInfo -TaskName $taskName
$result = [pscustomobject]@{
    installed = $true
    task_name = $taskName
    task_state = [string]$task.State
    last_task_result = $taskInfo.LastTaskResult
    worker_running = $workerProcesses.Count -gt 0
    worker_process_ids = @($workerProcesses | ForEach-Object { $_.ProcessId })
    stopped_previous_worker_count = $existingWorkers.Count
}
$result | ConvertTo-Json -Depth 3

if ($task.State -ne 'Running' -or $workerProcesses.Count -eq 0) {
    throw 'The task was registered but the supervised Realtime outbox worker did not reach its running state.'
}
