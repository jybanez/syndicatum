$worker = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot "process-message-outbox.php"))
$processes = @(Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'php.exe' -and
    $_.CommandLine -like ('*' + $worker + '*') -and
    $_.CommandLine -like '*--watch*'
})

foreach ($process in $processes) {
    Stop-Process -Id $process.ProcessId -Force
}
Write-Output "Stopped $($processes.Count) Syndicatum Realtime outbox worker process(es)."
