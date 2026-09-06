$worker = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot "process-message-outbox.php"))
$processes = @(Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'php.exe' -and
    $_.CommandLine -like ('*' + $worker + '*') -and
    $_.CommandLine -like '*--watch*'
})

[pscustomobject]@{
    running = $processes.Count -gt 0
    process_ids = @($processes | ForEach-Object { $_.ProcessId })
    process_count = $processes.Count
} | ConvertTo-Json -Depth 3
