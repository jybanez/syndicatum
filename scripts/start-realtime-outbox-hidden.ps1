param(
    [string]$PhpPath = "C:\wamp64\bin\php\php8.2.29\php.exe",
    [int]$IdleMilliseconds = 500
)

$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot ".."))
$worker = Join-Path $projectRoot "scripts\process-message-outbox.php"
$runtimeDirectory = Join-Path $projectRoot "runtime"
$stdout = Join-Path $runtimeDirectory "realtime-outbox.stdout.log"
$stderr = Join-Path $runtimeDirectory "realtime-outbox.stderr.log"

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP executable was not found: $PhpPath" }
if (-not (Test-Path -LiteralPath $worker -PathType Leaf)) { throw "Realtime outbox worker was not found: $worker" }
$IdleMilliseconds = [Math]::Max(100, [Math]::Min(60000, $IdleMilliseconds))

$existing = @(Get-CimInstance Win32_Process | Where-Object {
    $_.Name -eq 'php.exe' -and
    $_.CommandLine -like ('*' + $worker + '*') -and
    $_.CommandLine -like '*--watch*'
})
if ($existing.Count -gt 0) {
    Write-Output "Syndicatum Realtime outbox worker is already running with process ID $($existing[0].ProcessId)."
    exit 0
}

New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
$arguments = @('"' + $worker + '"', '--watch', "--idle-ms=$IdleMilliseconds")
$process = Start-Process -FilePath $PhpPath `
    -ArgumentList $arguments `
    -WorkingDirectory $projectRoot `
    -WindowStyle Hidden `
    -RedirectStandardOutput $stdout `
    -RedirectStandardError $stderr `
    -PassThru

Write-Output "Syndicatum Realtime outbox worker started in the background with process ID $($process.Id)."
