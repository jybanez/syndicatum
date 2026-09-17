[CmdletBinding()]
param(
    [string]$ComposeFile = (Join-Path $PSScriptRoot '..\compose.yaml'),
    [string]$AppService = 'app',
    [string]$DatabaseService = 'db',
    [string]$WorkerService = 'worker',
    [string]$BaseUrl = '',
    [ValidateRange(1, 65535)]
    [int]$HttpPort = 18080,
    [ValidateRange(10, 600)]
    [int]$StartupTimeoutSeconds = 180
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Write-Step([string]$Message) {
    Write-Host "`n==> $Message" -ForegroundColor Cyan
}

function New-HexSecret([int]$Bytes = 32) {
    $buffer = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Fill($buffer)
    return [Convert]::ToHexString($buffer).ToLowerInvariant()
}

function Assert-AcceptanceIdentity([string]$Project, [string]$Database) {
    if ($Project -notmatch '^syndicatum-acceptance-[a-z0-9-]+$') {
        throw "Unsafe Compose project name '$Project'. Acceptance projects must start with syndicatum-acceptance-."
    }
    if ($Database -notmatch '^syndicatum_acceptance_[a-z0-9_]+$') {
        throw "Unsafe database name '$Database'. Acceptance databases must start with syndicatum_acceptance_."
    }
}

function Invoke-Compose {
    param(
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [switch]$Capture
    )

    $allArguments = @('compose') + $script:ComposeOptions + $Arguments
    if ($Capture) {
        $output = & docker @allArguments 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "docker $($allArguments -join ' ') failed:`n$($output -join "`n")"
        }
        return ($output -join "`n")
    }

    & docker @allArguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker $($allArguments -join ' ') failed with exit code $LASTEXITCODE."
    }
}

function Invoke-DockerWithFile {
    param(
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [string]$InputFile,
        [string]$OutputFile
    )

    $startInfo = [System.Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = 'docker'
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardError = $true
    $startInfo.RedirectStandardInput = [bool]$InputFile
    $startInfo.RedirectStandardOutput = [bool]$OutputFile
    foreach ($argument in $Arguments) {
        [void]$startInfo.ArgumentList.Add($argument)
    }

    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    if (-not $process.Start()) {
        throw 'Unable to start Docker.'
    }

    $errorTask = $process.StandardError.ReadToEndAsync()
    $outputTask = $null
    $inputTask = $null
    $outputStream = $null
    $inputStream = $null
    try {
        if ($OutputFile) {
            $outputStream = [System.IO.File]::Create($OutputFile)
            $outputTask = $process.StandardOutput.BaseStream.CopyToAsync($outputStream)
        }
        if ($InputFile) {
            $inputStream = [System.IO.File]::OpenRead($InputFile)
            $inputTask = $inputStream.CopyToAsync($process.StandardInput.BaseStream)
            [void]$inputTask.GetAwaiter().GetResult()
            $process.StandardInput.Close()
        }
        $process.WaitForExit()
        if ($outputTask) {
            [void]$outputTask.GetAwaiter().GetResult()
        }
        $stderr = $errorTask.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) {
            throw "docker $($Arguments -join ' ') failed with exit code $($process.ExitCode):`n$stderr"
        }
    } finally {
        if ($inputStream) { $inputStream.Dispose() }
        if ($outputStream) { $outputStream.Dispose() }
        $process.Dispose()
    }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI is not installed or is not available on PATH.'
}
$dockerServerVersion = & docker info --format '{{.ServerVersion}}' 2>$null
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace(($dockerServerVersion -join ''))) {
    throw 'Docker is installed, but its engine is not running. Start Docker and rerun this acceptance harness.'
}

$composePath = [System.IO.Path]::GetFullPath($ComposeFile)
if (-not (Test-Path -LiteralPath $composePath -PathType Leaf)) {
    throw "Compose file not found: $composePath"
}

$suffix = ((New-HexSecret 6) -replace '[^a-z0-9]', '').Substring(0, 12)
$projectName = "syndicatum-acceptance-$suffix"
$databaseName = "syndicatum_acceptance_$suffix"
Assert-AcceptanceIdentity $projectName $databaseName
$applicationImage = "${projectName}-app:acceptance"
$databaseImage = "${projectName}-db:acceptance"

$environmentPath = Join-Path ([System.IO.Path]::GetTempPath()) "$projectName.env"
if (Test-Path -LiteralPath $environmentPath) {
    throw "Refusing to overwrite unexpected temporary environment file: $environmentPath"
}

$backupPath = Join-Path ([System.IO.Path]::GetTempPath()) "$projectName.sql"
$rootPassword = New-HexSecret 24
$applicationPassword = New-HexSecret 24
$applicationSecret = New-HexSecret 32
$masterKey = New-HexSecret 32
$probeValue = "restore-$suffix"
if ([string]::IsNullOrWhiteSpace($BaseUrl)) {
    $BaseUrl = "http://127.0.0.1:$HttpPort"
}
$BaseUrl = $BaseUrl.TrimEnd('/')

$environment = @(
    "COMPOSE_PROJECT_NAME=$projectName"
    "SYNDICATUM_IMAGE=$applicationImage"
    "SYNDICATUM_DB_IMAGE=$databaseImage"
    'SYNDICATUM_MYSQL_IMAGE=mysql:5.7.44'
    'SYNDICATUM_HTTP_BIND=127.0.0.1'
    "SYNDICATUM_HTTP_PORT=$HttpPort"
    "MYSQL_DATABASE=$databaseName"
    'MYSQL_USER=syndicatum'
    "MYSQL_PASSWORD=$applicationPassword"
    "MYSQL_ROOT_PASSWORD=$rootPassword"
    'PBB_AGENTCHAT_DB_HOST=db'
    "PBB_AGENTCHAT_DB_NAME=$databaseName"
    'PBB_AGENTCHAT_DB_USER=syndicatum'
    "PBB_AGENTCHAT_DB_PASS=$applicationPassword"
    "PBB_AGENTCHAT_SECRET=$applicationSecret"
    "SYNDICATUM_MASTER_KEY=$masterKey"
) -join "`n"
[System.IO.File]::WriteAllText($environmentPath, $environment + "`n", [System.Text.UTF8Encoding]::new($false))

$script:ComposeOptions = @(
    '--project-name', $projectName,
    '--env-file', $environmentPath,
    '--file', $composePath
)
$started = $false
$passed = $false

try {
    Write-Step 'Validating the rendered Compose configuration'
    Invoke-Compose -Arguments @('config', '--quiet')
    $renderedConfig = (Invoke-Compose -Arguments @('config', '--format', 'json') -Capture) | ConvertFrom-Json
    $serviceNames = @($renderedConfig.services.PSObject.Properties.Name)
    foreach ($requiredService in @($AppService, $DatabaseService, $WorkerService)) {
        if ($serviceNames -notcontains $requiredService) {
            throw "Rendered Compose configuration does not contain required service '$requiredService'."
        }
    }
    if ($renderedConfig.services.$DatabaseService.image -ne $databaseImage -or
        $renderedConfig.services.$AppService.image -ne $applicationImage -or
        $renderedConfig.services.$WorkerService.image -ne $applicationImage -or
        $renderedConfig.services.$DatabaseService.build.args.MYSQL_IMAGE -ne 'mysql:5.7.44') {
        throw 'Acceptance images do not match the isolated MySQL 5.7.44 candidate baseline.'
    }
    foreach ($volumeProperty in @($renderedConfig.volumes.PSObject.Properties)) {
        $volume = $volumeProperty.Value
        $externalProperty = $volume.PSObject.Properties['external']
        $nameProperty = $volume.PSObject.Properties['name']
        if ($externalProperty -and [bool]$externalProperty.Value) {
            throw "Acceptance refuses external volume '$($volumeProperty.Name)'."
        }
        if ($nameProperty -and $nameProperty.Value -and -not ([string]$nameProperty.Value).StartsWith("${projectName}_", [System.StringComparison]::Ordinal)) {
            throw "Acceptance refuses non-project-scoped volume '$($nameProperty.Value)'."
        }
    }

    Write-Step "Starting isolated MySQL 5.7.44 database for $projectName"
    $started = $true
    try {
        Invoke-Compose -Arguments @('up', '--build', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), $DatabaseService)

        $databaseProbe = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot -e "SELECT VERSION(), @@GLOBAL.sql_mode"'
        $databaseDetails = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $databaseProbe) -Capture).Trim()
        $databaseParts = $databaseDetails -split "`t", 2
        if ($databaseParts.Count -ne 2 -or $databaseParts[0] -notmatch '^5\.7\.44(?:$|[.-])') {
            throw "Acceptance requires MySQL 5.7.44; observed: $databaseDetails"
        }
        $sqlModes = @($databaseParts[1].Split(',') | ForEach-Object { $_.Trim() })
        if ($sqlModes -notcontains 'STRICT_TRANS_TABLES' -and $sqlModes -notcontains 'STRICT_ALL_TABLES') {
            throw "Acceptance requires strict SQL mode; observed: $($databaseParts[1])"
        }
        Write-Host "Verified database version $($databaseParts[0]) and SQL mode $($databaseParts[1])."

        Write-Step 'Starting application and worker after database verification'
        Invoke-Compose -Arguments @('up', '--build', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), $AppService, $WorkerService)
        $workerUid = (Invoke-Compose -Arguments @('exec', '-T', $WorkerService, 'id', '-u') -Capture).Trim()
        if ($workerUid -eq '0' -or $workerUid -notmatch '^[1-9][0-9]*$') {
            throw "Worker must run as a non-root numeric user; observed UID: $workerUid"
        }
        Write-Host "Verified worker runs as non-root UID $workerUid."
    } catch {
        Write-Warning 'Container startup failed. Capturing service state and logs before cleanup.'
        try { Invoke-Compose -Arguments @('ps', '--all') } catch { Write-Warning $_ }
        try { Invoke-Compose -Arguments @('logs', '--no-color', '--tail', '200', $DatabaseService, $AppService, $WorkerService) } catch { Write-Warning $_ }
        throw
    }

    Write-Step 'Applying migrations and confirming they are complete'
    $migrationOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/chat-db.php', 'migrate') -Capture
    $statusOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/chat-db.php', 'migration-status') -Capture
    $migrationStatus = $statusOutput | ConvertFrom-Json
    $incomplete = @($migrationStatus | Where-Object { -not $_.applied -or -not $_.checksum_valid })
    if ($incomplete.Count -gt 0) {
        throw "Migration verification found $($incomplete.Count) incomplete or checksum-invalid migration(s)."
    }
    Write-Host "Migration verification passed for $(@($migrationStatus).Count) migration(s)."

    Write-Step 'Checking application and machine-readable health endpoints'
    $deadline = [DateTime]::UtcNow.AddSeconds($StartupTimeoutSeconds)
    $health = $null
    do {
        try {
            $health = Invoke-RestMethod -Uri "$BaseUrl/api/v1/health.php" -TimeoutSec 5
        } catch {
            Start-Sleep -Seconds 2
        }
    } while (-not $health -and [DateTime]::UtcNow -lt $deadline)
    if (-not $health) {
        throw "Health endpoint did not become available at $BaseUrl/api/v1/health.php."
    }
    if ($health.data.service.id -ne 'syndicatum' -or $health.data.core.status -ne 'ok' -or -not $health.data.core.database -or -not $health.data.core.expanded_schema) {
        throw 'Health endpoint did not report a ready Syndicatum core, database, and expanded schema.'
    }
    $indexResponse = Invoke-WebRequest -Uri "$BaseUrl/" -TimeoutSec 10
    if ($indexResponse.StatusCode -ne 200) {
        throw "Application root returned HTTP $($indexResponse.StatusCode)."
    }

    Write-Step 'Recording effective Apache and MySQL process privileges'
    $processProbe = 'for file in /proc/[0-9]*/status; do name= uid= cap= nnp=; while read -r field value rest; do case "$field" in Name:) name=$value;; Uid:) uid=$value;; CapEff:) cap=$value;; NoNewPrivs:) nnp=$value;; esac; done < "$file"; case "$name" in apache2|mysqld) printf "%s:%s:CapEff=%s:NoNewPrivs=%s\n" "$name" "$uid" "$cap" "$nnp";; esac; done'
    $appProcesses = (Invoke-Compose -Arguments @('exec', '-T', $AppService, 'sh', '-c', $processProbe) -Capture).Trim()
    $databaseProcesses = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-c', $processProbe) -Capture).Trim()
    if ($appProcesses -notmatch 'apache2:0' -or $appProcesses -notmatch 'apache2:[1-9][0-9]*' -or
        $databaseProcesses -notmatch 'mysqld:[1-9][0-9]*') {
        throw "Unexpected runtime process privileges. Apache: $appProcesses; MySQL: $databaseProcesses"
    }
    if ($appProcesses -notmatch '(?m)^apache2:0:CapEff=00000000000004c0:NoNewPrivs=1\r?$' -or
        $appProcesses -notmatch '(?m)^apache2:[1-9][0-9]*:CapEff=0000000000000000:NoNewPrivs=1\r?$' -or
        $databaseProcesses -notmatch '(?m)^mysqld:[1-9][0-9]*:CapEff=0000000000000000:NoNewPrivs=1\r?$') {
        throw "Runtime capability or no-new-privileges regression. Apache: $appProcesses; MySQL: $databaseProcesses"
    }
    Write-Host "Apache processes: $appProcesses"
    Write-Host "MySQL processes: $databaseProcesses"
    $appArchitecture = (Invoke-Compose -Arguments @('exec', '-T', $AppService, 'dpkg', '--print-architecture') -Capture).Trim()
    if (-not $appArchitecture) {
        throw 'Application image architecture could not be determined.'
    }
    Write-Host "Application image architecture: $appArchitecture"

    Write-Step 'Checking shipped Apache Perl/CGI runtime paths'
    $apacheModules = (Invoke-Compose -Arguments @('exec', '-T', $AppService, 'apache2ctl', '-M') -Capture)
    $apacheLibraries = (Invoke-Compose -Arguments @('exec', '-T', $AppService, 'sh', '-c', 'ldd /usr/sbin/apache2') -Capture)
    if ($apacheModules -match '(?m)\b(?:cgi|cgid|perl)_module\b' -or $apacheLibraries -match '(?i)libperl') {
        throw 'The shipped Apache runtime enables CGI/Perl or links libperl; review Perl advisory exposure before acceptance.'
    }
    Write-Host 'Apache Perl/CGI modules: absent; Apache libperl linkage: absent.'

    Write-Step 'Creating an acceptance-only database probe'
    $createProbe = 'require "src/Db.php"; $p=Db::pdo(); $p->exec("CREATE TABLE syndicatum_acceptance_probe (probe_key VARCHAR(64) PRIMARY KEY, probe_value VARCHAR(255) NOT NULL)"); $s=$p->prepare("INSERT INTO syndicatum_acceptance_probe (probe_key, probe_value) VALUES (?, ?)"); $s->execute(["backup_restore", getenv("SYNDICATUM_ACCEPTANCE_PROBE")]);'
    Invoke-Compose -Arguments @('exec', '-T', '-e', "SYNDICATUM_ACCEPTANCE_PROBE=$probeValue", $AppService, 'php', '-r', $createProbe)

    Write-Step 'Creating a logical database backup'
    $dumpCommand = 'set -eu; command -v mariadb-dump >/dev/null 2>&1 && D=mariadb-dump || D=mysqldump; exec "$D" --single-transaction --routines --triggers -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
    $dumpArguments = @('compose') + $script:ComposeOptions + @('exec', '-T', $DatabaseService, 'sh', '-lc', $dumpCommand)
    Invoke-DockerWithFile -Arguments $dumpArguments -OutputFile $backupPath
    if (-not (Test-Path -LiteralPath $backupPath) -or (Get-Item -LiteralPath $backupPath).Length -lt 256) {
        throw 'Database backup is missing or unexpectedly small.'
    }

    Write-Step 'Mutating the isolated database, restoring it, and verifying the probe'
    $dropProbe = 'require "src/Db.php"; Db::pdo()->exec("DROP TABLE syndicatum_acceptance_probe");'
    Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', '-r', $dropProbe)
    $restoreCommand = 'set -eu; command -v mariadb >/dev/null 2>&1 && C=mariadb || C=mysql; exec "$C" -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
    $restoreArguments = @('compose') + $script:ComposeOptions + @('exec', '-T', $DatabaseService, 'sh', '-lc', $restoreCommand)
    Invoke-DockerWithFile -Arguments $restoreArguments -InputFile $backupPath

    $verifyProbe = 'require "src/Db.php"; $s=Db::pdo()->query("SELECT probe_value FROM syndicatum_acceptance_probe WHERE probe_key = ''backup_restore''"); echo $s->fetchColumn();'
    $restoredValue = (Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', '-r', $verifyProbe) -Capture).Trim()
    if ($restoredValue -ne $probeValue) {
        throw "Restored probe mismatch. Expected '$probeValue'; received '$restoredValue'."
    }

    $passed = $true
    Write-Host "`nDocker acceptance passed: clean start, migrations, health, reachability, backup, and restore." -ForegroundColor Green
} finally {
    if ($started) {
        Assert-AcceptanceIdentity $projectName $databaseName
        Write-Step "Removing isolated project $projectName and its volumes"
        try {
            Invoke-Compose -Arguments @('down', '--volumes', '--remove-orphans', '--timeout', '15')
        } catch {
            Write-Warning "Automatic cleanup failed. Remove only this isolated project with: docker compose --project-name $projectName --env-file `"$environmentPath`" --file `"$composePath`" down --volumes --remove-orphans"
            if ($passed) { throw }
        }
    }
    Remove-Item -LiteralPath $backupPath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $environmentPath -Force -ErrorAction SilentlyContinue
}
