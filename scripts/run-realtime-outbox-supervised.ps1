param(
    [string]$PhpPath = "C:\wamp64\bin\php\php8.2.29\php.exe",
    [int]$IdleMilliseconds = 500,
    [int]$RestartDelaySeconds = 10
)

$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$worker = Join-Path $projectRoot 'scripts\process-message-outbox.php'
$runtimeDirectory = Join-Path $projectRoot 'runtime'
$stdout = Join-Path $runtimeDirectory 'realtime-outbox.stdout.log'
$stderr = Join-Path $runtimeDirectory 'realtime-outbox.stderr.log'
$supervisorLog = Join-Path $runtimeDirectory 'realtime-outbox.supervisor.log'

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP executable was not found: $PhpPath" }
if (-not (Test-Path -LiteralPath $worker -PathType Leaf)) { throw "Realtime outbox worker was not found: $worker" }
$IdleMilliseconds = [Math]::Max(100, [Math]::Min(60000, $IdleMilliseconds))
$RestartDelaySeconds = [Math]::Max(2, [Math]::Min(300, $RestartDelaySeconds))
New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null

function Write-SupervisorLog([string]$Message) {
    $timestamp = [DateTimeOffset]::Now.ToString('o')
    Add-Content -LiteralPath $supervisorLog -Value "[$timestamp] $Message"
}

Write-SupervisorLog "Supervisor started (PID $PID)."
while ($true) {
    Write-SupervisorLog 'Starting Realtime outbox worker.'
    $arguments = @('"' + $worker + '"', '--watch', "--idle-ms=$IdleMilliseconds")
    $workerProcess = Start-Process -FilePath $PhpPath `
        -ArgumentList $arguments `
        -WorkingDirectory $projectRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr `
        -PassThru `
        -Wait
    $workerExitCode = $workerProcess.ExitCode

    if ($workerExitCode -eq 2) {
        Write-SupervisorLog 'Another healthy worker owns the database lock; remaining in standby.'
        Start-Sleep -Seconds 60
        continue
    }

    if ($workerExitCode -eq 0) {
        Write-SupervisorLog 'Worker exited normally (Realtime may be disabled); checking again in 60 seconds.'
        Start-Sleep -Seconds 60
        continue
    }

    Write-SupervisorLog "Worker exited unexpectedly with code $workerExitCode; restarting in $RestartDelaySeconds seconds."
    Start-Sleep -Seconds $RestartDelaySeconds
}
