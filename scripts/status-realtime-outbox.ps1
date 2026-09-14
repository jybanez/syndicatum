$worker = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot "process-message-outbox.php"))
$processes = @(Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'php.exe' -and
    $_.CommandLine -like ('*' + $worker + '*') -and
    $_.CommandLine -like '*--watch*'
})
$taskName = 'Syndicatum Realtime Outbox'
$task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
$taskInfo = if ($task) { Get-ScheduledTaskInfo -TaskName $taskName -ErrorAction SilentlyContinue } else { $null }

[pscustomobject]@{
    running = $processes.Count -gt 0
    process_ids = @($processes | ForEach-Object { $_.ProcessId })
    process_count = $processes.Count
    task_installed = $null -ne $task
    task_state = if ($task) { [string]$task.State } else { $null }
    task_last_result = if ($taskInfo) { $taskInfo.LastTaskResult } else { $null }
    task_last_run = if ($taskInfo) { $taskInfo.LastRunTime } else { $null }
} | ConvertTo-Json -Depth 3
