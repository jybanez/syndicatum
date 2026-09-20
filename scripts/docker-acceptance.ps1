[CmdletBinding()]
param(
    [string]$ComposeFile = (Join-Path $PSScriptRoot '..\compose.yaml'),
    [string]$AppService = 'app',
    [string]$DatabaseService = 'db',
    [string]$WorkerService = 'worker',
    [ValidateNotNullOrEmpty()]
    [string]$MySqlImage = 'mysql:8.4@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a',
    [ValidateNotNullOrEmpty()]
    [string]$ExpectedMySqlVersionPattern = '^8\.4(?:$|[.-])',
    [ValidateNotNullOrEmpty()]
    [string]$DatabaseSqlMode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
    [string]$PackageSha256 = $env:SYNDICATUM_PACKAGE_SHA256,
    [string]$ReleaseSourceCommit = $env:SYNDICATUM_RELEASE_SOURCE_COMMIT,
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
        [switch]$Capture,
        [int[]]$AllowedExitCodes = @(0)
    )

    $allArguments = @('compose') + $script:ComposeOptions + $Arguments
    if ($Capture) {
        $output = & docker @allArguments 2>&1
        if ($LASTEXITCODE -notin $AllowedExitCodes) {
            throw "docker $($allArguments -join ' ') failed:`n$($output -join "`n")"
        }
        return ($output -join "`n")
    }

    & docker @allArguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker $($allArguments -join ' ') failed with exit code $LASTEXITCODE."
    }
}

function Invoke-OperatorMcpHealth {
    param(
        [Parameter(Mandatory = $true)][string]$Token,
        [string]$ContextToken = '',
        [string]$Url = 'http://127.0.0.1:8080/mcp'
    )
    $secretInput = @{ access_token = $Token; binding_context_id = $ContextToken } | ConvertTo-Json -Compress
    $arguments = @('compose') + $script:ComposeOptions + @('exec', '-T', $AppService,
        'php', 'scripts/plugin-mcp-connection-status.php', "--url=$Url")
    $output = $secretInput | & docker @arguments 2>&1
    $exitCode = $LASTEXITCODE
    $rendered = ($output -join "`n")
    if (($Token -ne '' -and $rendered.Contains($Token)) -or
        ($ContextToken -ne '' -and $rendered.Contains($ContextToken))) {
        throw 'Operator MCP status leaked a credential in its output.'
    }
    try { $health = $rendered | ConvertFrom-Json -ErrorAction Stop }
    catch { throw 'Operator MCP status did not return clean JSON.' }
    if ($exitCode -notin @(0, 2, 3)) { throw 'Operator MCP status returned an unexpected exit code.' }
    return $health
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
if ($PackageSha256 -notmatch '^[a-f0-9]{64}$') {
    throw 'PackageSha256 must be the lowercase SHA-256 of the exact acceptance package.'
}
if ($ReleaseSourceCommit -notmatch '^[a-f0-9]{40}$') {
    throw 'ReleaseSourceCommit must be the full lowercase Git commit of the acceptance package.'
}
$dockerServerVersion = & docker info --format '{{.ServerVersion}}' 2>$null
if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace(($dockerServerVersion -join ''))) {
    throw 'Docker is installed, but its engine is not running. Start Docker and rerun this acceptance harness.'
}
$dockerDefaultRuntime = (& docker info --format '{{.DefaultRuntime}}' 2>$null | Out-String).Trim()
$dockerRuntimeMapJson = (& docker info --format '{{json .Runtimes}}' 2>$null | Out-String).Trim()
$dockerRuntimeVersion = ''
if ($LASTEXITCODE -eq 0 -and $dockerRuntimeMapJson) {
    try {
        $runtimeMap = $dockerRuntimeMapJson | ConvertFrom-Json
        $runtimeEntry = $runtimeMap.PSObject.Properties[$dockerDefaultRuntime].Value
        $featuresJson = $runtimeEntry.status.'org.opencontainers.runtime-spec.features'
        if ($featuresJson) {
            $runtimeFeatures = $featuresJson | ConvertFrom-Json
            $dockerRuntimeVersion = [string]$runtimeFeatures.annotations.'org.opencontainers.runc.version'
            $dockerRuntimeVersion = $dockerRuntimeVersion.Trim()
        }
    } catch {
        Write-Warning 'Docker did not expose a parseable OCI runtime version; record and review it manually before external acceptance.'
    }
}
Write-Host "Docker engine: $(($dockerServerVersion -join '').Trim()); default runtime: $dockerDefaultRuntime; runc version: $(if ($dockerRuntimeVersion) { $dockerRuntimeVersion } else { 'unavailable' })"

$composePath = [System.IO.Path]::GetFullPath($ComposeFile)
if (-not (Test-Path -LiteralPath $composePath -PathType Leaf)) {
    throw "Compose file not found: $composePath"
}
$acceptanceComposePath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\compose.acceptance.yaml'))
if (-not (Test-Path -LiteralPath $acceptanceComposePath -PathType Leaf)) {
    throw "Acceptance-only Compose override not found: $acceptanceComposePath"
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
$backupKeyPath = Join-Path ([System.IO.Path]::GetTempPath()) "$projectName.backup-key"
if (Test-Path -LiteralPath $backupKeyPath) {
    throw "Refusing to overwrite unexpected temporary backup key file: $backupKeyPath"
}

$backupPath = Join-Path ([System.IO.Path]::GetTempPath()) "$projectName.sql"
$rootPassword = New-HexSecret 24
$applicationPassword = New-HexSecret 24
$applicationSecret = New-HexSecret 32
$masterKey = New-HexSecret 32
$backupKeyBytes = New-Object byte[] 32
$backupKeyRng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
try { $backupKeyRng.GetBytes($backupKeyBytes) } finally { $backupKeyRng.Dispose() }
[System.IO.File]::WriteAllText($backupKeyPath, [Convert]::ToBase64String($backupKeyBytes) + "`n", [System.Text.UTF8Encoding]::new($false))
if ([System.Environment]::OSVersion.Platform -eq [System.PlatformID]::Unix) {
    & chmod 600 -- $backupKeyPath
    if ($LASTEXITCODE -ne 0) { throw 'Could not make the acceptance backup key private.' }
}
$probeValue = "restore-$suffix"
if ([string]::IsNullOrWhiteSpace($BaseUrl)) {
    $BaseUrl = "http://127.0.0.1:$HttpPort"
}
$BaseUrl = $BaseUrl.TrimEnd('/')

$environment = @(
    "COMPOSE_PROJECT_NAME=$projectName"
    "SYNDICATUM_IMAGE=$applicationImage"
    "SYNDICATUM_DB_IMAGE=$databaseImage"
    "SYNDICATUM_MYSQL_IMAGE=$MySqlImage"
    "SYNDICATUM_MYSQL_SQL_MODE=$DatabaseSqlMode"
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
    "SYNDICATUM_BACKUP_KEY_FILE=$backupKeyPath"
    "SYNDICATUM_PACKAGE_SHA256=$PackageSha256"
    "SYNDICATUM_RELEASE_SOURCE_COMMIT=$ReleaseSourceCommit"
) -join "`n"
[System.IO.File]::WriteAllText($environmentPath, $environment + "`n", [System.Text.UTF8Encoding]::new($false))

$script:ComposeOptions = @(
    '--project-name', $projectName,
    '--env-file', $environmentPath,
    '--file', $composePath,
    '--file', $acceptanceComposePath
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
        $renderedConfig.services.$DatabaseService.build.args.MYSQL_IMAGE -ne $MySqlImage) {
        throw "Acceptance images do not match the isolated $MySqlImage candidate baseline."
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

    Write-Step "Starting isolated $MySqlImage database for $projectName"
    $started = $true
    try {
        Invoke-Compose -Arguments @('up', '--build', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), $DatabaseService)

        $databaseProbe = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot -e "SELECT VERSION(), @@GLOBAL.sql_mode"'
        $databaseDetails = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $databaseProbe) -Capture).Trim()
        $databaseParts = $databaseDetails -split "`t", 2
        if ($databaseParts.Count -ne 2 -or $databaseParts[0] -notmatch $ExpectedMySqlVersionPattern) {
            throw "Acceptance requires a database version matching '$ExpectedMySqlVersionPattern' from $MySqlImage; observed: $databaseDetails"
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
        $workerContainerId = (Invoke-Compose -Arguments @('ps', '-q', $WorkerService) -Capture).Trim()
        if (-not $workerContainerId) {
            throw 'Worker container ID was not available after Compose startup.'
        }
        $workerHealth = (& docker inspect --format '{{.State.Health.Status}}' $workerContainerId 2>&1 | Out-String).Trim()
        if ($LASTEXITCODE -ne 0 -or $workerHealth -ne 'healthy') {
            throw "Worker heartbeat health check did not report healthy: $workerHealth"
        }
        Write-Host 'Verified worker heartbeat health check: healthy.'
        $backupKeyLength = (Invoke-Compose -Arguments @('exec', '-T', '--user', '33:33', $AppService, 'php', '-r', 'require "src/BackupKeyFile.php"; echo strlen(BackupKeyFile::loadFromEnvironment());') -Capture).Trim()
        if ($backupKeyLength -ne '32') {
            throw "Application could not read the private 32-byte backup key; observed length: $backupKeyLength"
        }
        $backupPaths = (Invoke-Compose -Arguments @('exec', '-T', $AppService, 'sh', '-lc', 'stat -c "%a:%u:%g:%n" /var/lib/syndicatum/staging /var/lib/syndicatum/backups /run/syndicatum-backup-key/backup-key') -Capture).Trim()
        if ($backupPaths -notmatch '(?m)^700:33:33:/var/lib/syndicatum/staging\r?$' -or
            $backupPaths -notmatch '(?m)^700:33:33:/var/lib/syndicatum/backups\r?$' -or
            $backupPaths -notmatch '(?m)^400:33:33:/run/syndicatum-backup-key/backup-key\r?$') {
            throw "Backup staging/storage/key permissions are not private: $backupPaths"
        }
        Write-Host 'Verified private backup key, tmpfs staging, and persistent encrypted-backup storage.'
    } catch {
        Write-Warning 'Container startup failed. Capturing service state and logs before cleanup.'
        try { Invoke-Compose -Arguments @('ps', '--all') } catch { Write-Warning $_ }
        try { Invoke-Compose -Arguments @('logs', '--no-color', '--tail', '200', $DatabaseService, $AppService, $WorkerService) } catch { Write-Warning $_ }
        throw
    }

    Write-Step 'Confirming baseline identity and zero fabricated historical migration rows'
    $installationOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/chat-db.php', 'installation-status') -Capture
    $installation = $installationOutput | ConvertFrom-Json
    if (-not $installation.ready -or $installation.state -ne 'ready' -or
        $installation.identity.package_sha256 -ne $PackageSha256 -or
        $installation.identity.release_source_commit -ne $ReleaseSourceCommit) {
        throw 'Baseline installation identity does not match the exact acceptance package.'
    }
    $statusOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/chat-db.php', 'migration-status') -Capture
    $migrationStatus = @($statusOutput | ConvertFrom-Json)
    if ($migrationStatus.Count -ne 0 -or [int]$installation.migration_rows -ne 0) {
        throw 'Fresh baseline installation must have zero historical migration rows and no declared post-baseline migrations.'
    }
    Write-Host 'Baseline identity matched the package and historical migration row count is zero.'

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
    Write-Step 'Checking isolated delivery observability'
    $operationalOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/plugin-operational-status.php') -Capture
    $operational = $operationalOutput | ConvertFrom-Json
    if ($operational.state -ne 'ok' -or $operational.attention -or @($operational.missing_delivery_tables).Count -gt 0) {
        throw 'Fresh-install delivery status did not report complete, healthy observability.'
    }
    if (-not $operational.worker -or $operational.worker.state -ne 'ok' -or
        $null -eq $operational.worker.age_seconds -or
        $operational.worker.age_seconds -gt $operational.worker.stale_after_seconds -or
        -not $operational.worker.last_success_at) {
        throw 'Fresh-install operator status did not report a recent successful worker cycle.'
    }
    foreach ($component in @('realtime_outbox', 'agent_webhooks', 'workspace_agent_triggers', 'responses_api_activations')) {
        $observed = $operational.$component
        if (-not $observed -or $observed.state -ne 'ok' -or $observed.pending -ne 0 -or
            $observed.PSObject.Properties.Name -notcontains 'last_success_at') {
            throw "Fresh-install delivery status is incomplete for $component."
        }
    }
    Write-Step 'Checking missing-table delivery status in the isolated database'
    $hideDeliveryTable = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "RENAME TABLE message_events_outbox TO message_events_outbox_acceptance_hidden"'
    $restoreDeliveryTable = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "RENAME TABLE message_events_outbox_acceptance_hidden TO message_events_outbox"'
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $hideDeliveryTable) -Capture | Out-Null
    try {
        $missingOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/plugin-operational-status.php') -Capture -AllowedExitCodes @(2)
        $missingStatus = $missingOutput | ConvertFrom-Json
        if ($missingStatus.state -ne 'unknown' -or -not $missingStatus.attention -or
            @($missingStatus.missing_delivery_tables) -notcontains 'message_events_outbox' -or
            $missingStatus.realtime_outbox.state -ne 'unknown' -or
            $null -ne $missingStatus.realtime_outbox.pending) {
            throw 'Missing Realtime table was not reported as unknown with null metrics.'
        }
    } finally {
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $restoreDeliveryTable) -Capture | Out-Null
    }
    $restoredOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/plugin-operational-status.php') -Capture
    $restoredStatus = $restoredOutput | ConvertFrom-Json
    if ($restoredStatus.state -ne 'ok' -or @($restoredStatus.missing_delivery_tables).Count -gt 0) {
        throw 'Delivery status did not return to healthy after restoring the isolated table.'
    }
    Write-Host 'Verified missing-table unknown state and healthy state after restoration.'
    Write-Step 'Checking stalled-worker status and due-event consumption in the isolated project'
    $workerFixtureUsername = "acceptance_worker_$suffix"
    $dueEventUuid = [guid]::NewGuid().ToString()
    $workerStaleAge = [int]$operational.worker.stale_after_seconds + 1
    Invoke-Compose -Arguments @('stop', $WorkerService)
    try {
        $createWorkerFixture = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "INSERT INTO users (username, display_name, created_at, updated_at) VALUES (''{username}'', ''Acceptance worker owner'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @uid = LAST_INSERT_ID(); INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (@uid, ''Acceptance worker workspace'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @wid = LAST_INSERT_ID(); INSERT INTO projects (public_id, workspace_id, owner_user_id, name, slug, created_at, updated_at) VALUES (UUID(), @wid, @uid, ''Acceptance worker project'', ''acceptance-worker'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @pid = LAST_INSERT_ID(); INSERT INTO message_events_outbox (event_uuid, project_id, event_type, payload_json, available_at, created_at) VALUES (UUID(), @pid, ''acceptance.future'', ''{}'', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), UTC_TIMESTAMP()); INSERT INTO message_events_outbox (event_uuid, project_id, event_type, payload_json, available_at, created_at) VALUES (''{due_uuid}'', @pid, ''acceptance.due'', ''{}'', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE))"'.Replace('{username}', $workerFixtureUsername).Replace('{due_uuid}', $dueEventUuid)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createWorkerFixture) -Capture | Out-Null
        $healthSessionToken = New-HexSecret 32
        $healthSessionHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData([System.Text.Encoding]::UTF8.GetBytes($healthSessionToken))).ToLowerInvariant()
        $createHealthSession = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @uid = (SELECT id FROM users WHERE username = ''{username}''); INSERT INTO user_system_roles (user_id, role_id, created_at) SELECT @uid, id, UTC_TIMESTAMP() FROM system_roles WHERE code = ''administrator''; INSERT INTO syndicatum_sessions (user_id, token_hash, csrf_token_hash, auth_provider, created_at, last_seen_at, expires_at) VALUES (@uid, ''{token_hash}'', REPEAT(''0'', 64), ''native'', UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))"'.Replace('{username}', $workerFixtureUsername).Replace('{token_hash}', $healthSessionHash)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createHealthSession) -Capture | Out-Null
        $deliveryHealthUrl = "$BaseUrl/api/v1/admin/delivery-health.php"
        $deliveryHealthHeaders = @{ Cookie = "syndicatum_session=$healthSessionToken" }
        Write-Step 'Checking authenticated API and revoked-session outcomes'
        $sessionToken = New-HexSecret 32
        $sessionHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData([System.Text.Encoding]::UTF8.GetBytes($sessionToken))).ToLowerInvariant()
        $createSession = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "INSERT INTO syndicatum_sessions (user_id, token_hash, csrf_token_hash, auth_provider, created_at, last_seen_at, expires_at) SELECT id, ''{token_hash}'', REPEAT(''0'', 64), ''native'', UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) FROM users WHERE username = ''{username}''"'.Replace('{token_hash}', $sessionHash).Replace('{username}', $workerFixtureUsername)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createSession) -Capture | Out-Null
        $projectsUrl = "$BaseUrl/api/v1/projects.php"
        $anonymousStatus = 0
        try { Invoke-WebRequest -Uri $projectsUrl -TimeoutSec 10 | Out-Null } catch { $anonymousStatus = [int]$_.Exception.Response.StatusCode }
        if ($anonymousStatus -ne 401) { throw "Unauthenticated project API probe did not return 401: $anonymousStatus" }
        $sessionResponse = Invoke-RestMethod -Uri "$BaseUrl/api/v1/session.php" -Headers @{ Cookie = "syndicatum_session=$sessionToken" } -TimeoutSec 10
        if (-not $sessionResponse.data.authenticated -or $sessionResponse.data.user.display_name -ne 'Acceptance worker owner') {
            throw 'Session API did not authenticate the disposable user.'
        }
        $projectsResponse = Invoke-RestMethod -Uri $projectsUrl -Headers @{ Cookie = "syndicatum_session=$sessionToken" } -TimeoutSec 10
        if ($null -eq $projectsResponse.data -or @($projectsResponse.data).Count -ne 0) {
            throw 'Authenticated project API did not return the expected empty disposable scope.'
        }
        $fixtureProjectIdQuery = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT p.id FROM projects p JOIN users u ON u.id = p.owner_user_id WHERE u.username = ''{username}''"'.Replace('{username}', $workerFixtureUsername)
        $fixtureProjectId = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $fixtureProjectIdQuery) -Capture).Trim()
        if ($fixtureProjectId -notmatch '^[1-9][0-9]*$') { throw 'Membership fixture project ID was unavailable.' }
        $activateMembership = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @uid = (SELECT id FROM users WHERE username = ''{username}''); INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES ({project_id}, @uid, ''owner'', ''active'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); INSERT INTO project_participants (project_id, kind, user_id, status, created_at, updated_at) VALUES ({project_id}, ''human'', @uid, ''active'', UTC_TIMESTAMP(), UTC_TIMESTAMP())"'.Replace('{username}', $workerFixtureUsername).Replace('{project_id}', $fixtureProjectId)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $activateMembership) -Capture | Out-Null
        $foreignFixtureUsername = "acceptance_foreign_$suffix"
        $createForeignProject = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "INSERT INTO users (username, display_name, created_at, updated_at) VALUES (''{username}'', ''Acceptance foreign owner'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @uid = LAST_INSERT_ID(); INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (@uid, ''Acceptance foreign workspace'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @wid = LAST_INSERT_ID(); INSERT INTO projects (public_id, workspace_id, owner_user_id, name, slug, created_at, updated_at) VALUES (UUID(), @wid, @uid, ''Acceptance foreign project'', ''acceptance-foreign'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @pid = LAST_INSERT_ID(); INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES (@pid, @uid, ''owner'', ''active'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); INSERT INTO project_participants (project_id, kind, user_id, status, created_at, updated_at) VALUES (@pid, ''human'', @uid, ''active'', UTC_TIMESTAMP(), UTC_TIMESTAMP())"'.Replace('{username}', $foreignFixtureUsername)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createForeignProject) -Capture | Out-Null
        $foreignProjectIdQuery = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT p.id FROM projects p JOIN users u ON u.id = p.owner_user_id WHERE u.username = ''{username}''"'.Replace('{username}', $foreignFixtureUsername)
        $foreignProjectId = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $foreignProjectIdQuery) -Capture).Trim()
        if ($foreignProjectId -notmatch '^[1-9][0-9]*$' -or $foreignProjectId -eq $fixtureProjectId) {
            throw 'Foreign-owner project fixture ID was unavailable or reused.'
        }
        $projectsResponse = Invoke-RestMethod -Uri $projectsUrl -Headers @{ Cookie = "syndicatum_session=$sessionToken" } -TimeoutSec 10
        if (@($projectsResponse.data).Count -ne 1 -or [string]$projectsResponse.data[0].id -ne $fixtureProjectId) {
            throw 'Active member did not see exactly the authorized disposable project.'
        }
        $foreignProjectStatus = 0
        try { Invoke-WebRequest -Uri "$BaseUrl/api/v1/project.php?project_id=$foreignProjectId" -Headers @{ Cookie = "syndicatum_session=$sessionToken" } -TimeoutSec 10 | Out-Null } catch { $foreignProjectStatus = [int]$_.Exception.Response.StatusCode }
        if ($foreignProjectStatus -ne 404) { throw "Authenticated user could read a foreign owner's project context: $foreignProjectStatus" }
        $projectContextUrl = "$BaseUrl/api/v1/project.php?project_id=$fixtureProjectId"
        $projectContextResponse = Invoke-RestMethod -Uri $projectContextUrl -Headers @{ Cookie = "syndicatum_session=$sessionToken" } -TimeoutSec 10
        if (-not $projectContextResponse.data.project -or [string]$projectContextResponse.data.project.id -ne $fixtureProjectId) {
            throw 'Active member could not read the authorized project context.'
        }
        $removeMembership = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE project_members SET status = ''removed'', removed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE project_id = {project_id} AND user_id = (SELECT id FROM users WHERE username = ''{username}'')"'.Replace('{username}', $workerFixtureUsername).Replace('{project_id}', $fixtureProjectId)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $removeMembership) -Capture | Out-Null
        $projectsResponse = Invoke-RestMethod -Uri $projectsUrl -Headers @{ Cookie = "syndicatum_session=$sessionToken" } -TimeoutSec 10
        if (@($projectsResponse.data).Count -ne 0) { throw 'Removed member still saw the disposable project.' }
        $removedProjectStatus = 0
        try { Invoke-WebRequest -Uri $projectContextUrl -Headers @{ Cookie = "syndicatum_session=$sessionToken" } -TimeoutSec 10 | Out-Null } catch { $removedProjectStatus = [int]$_.Exception.Response.StatusCode }
        if ($removedProjectStatus -ne 404) { throw "Removed membership did not conceal project context: $removedProjectStatus" }
        $clearMembership = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "DELETE FROM project_participants WHERE project_id = {project_id} AND user_id = (SELECT id FROM users WHERE username = ''{username}''); DELETE FROM project_members WHERE project_id = {project_id} AND user_id = (SELECT id FROM users WHERE username = ''{username}'')"'.Replace('{username}', $workerFixtureUsername).Replace('{project_id}', $fixtureProjectId)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $clearMembership) -Capture | Out-Null
        $deleteForeignProject = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @uid = (SELECT id FROM users WHERE username = ''{username}''); SET @pid = (SELECT id FROM projects WHERE owner_user_id = @uid LIMIT 1); DELETE FROM project_participants WHERE project_id = @pid; DELETE FROM project_members WHERE project_id = @pid; DELETE FROM projects WHERE id = @pid; DELETE FROM workspaces WHERE owner_user_id = @uid; DELETE FROM users WHERE id = @uid"'.Replace('{username}', $foreignFixtureUsername)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $deleteForeignProject) -Capture | Out-Null
        $revokeSession = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE syndicatum_sessions SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = ''{token_hash}''"'.Replace('{token_hash}', $sessionHash)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $revokeSession) -Capture | Out-Null
        $revokedStatus = 0
        try { Invoke-WebRequest -Uri $projectsUrl -Headers @{ Cookie = "syndicatum_session=$sessionToken" } -TimeoutSec 10 | Out-Null } catch { $revokedStatus = [int]$_.Exception.Response.StatusCode }
        if ($revokedStatus -ne 401) { throw "Revoked session project API probe did not return 401: $revokedStatus" }
        Write-Host 'Verified anonymous 401, authenticated session/API 200, active-member visibility, foreign-owner concealment, removed-member concealment, and revoked-session 401.'
        $ageWorkerHeartbeat = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE delivery_worker_heartbeats SET last_success_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL {age} SECOND) WHERE worker_name = ''delivery''"'.Replace('{age}', $workerStaleAge.ToString())
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $ageWorkerHeartbeat) -Capture | Out-Null
        $staleOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/plugin-operational-status.php') -Capture -AllowedExitCodes @(2)
        $staleStatus = $staleOutput | ConvertFrom-Json
        if ($staleStatus.state -ne 'degraded' -or -not $staleStatus.attention -or
            $staleStatus.worker.state -ne 'degraded' -or
            $staleStatus.worker.age_seconds -le $staleStatus.worker.stale_after_seconds -or
            $staleStatus.realtime_outbox.pending -lt 2 -or
            $staleStatus.realtime_outbox.oldest_pending_seconds -lt 180) {
            throw 'Stopped worker with queued work and stale heartbeat did not produce degraded status and operator attention.'
        }
        $staleHealth = (Invoke-RestMethod -Uri $deliveryHealthUrl -Headers $deliveryHealthHeaders -TimeoutSec 10).data
        if ($staleHealth.state -ne 'degraded' -or $staleHealth.worker.state -ne 'degraded' -or
            $staleHealth.paths.realtime.pending -lt 2 -or $staleHealth.paths.realtime.oldest_pending_seconds -lt 180) {
            throw 'Administrator Delivery health did not expose the stopped worker and aging Realtime queue.'
        }
        if (($staleHealth | ConvertTo-Json -Depth 12) -match 'Acceptance canonical message|MYSQL_ROOT_PASSWORD|payload_json') {
            throw 'Administrator Delivery health exposed protected delivery content.'
        }
        Start-Sleep -Seconds 2
        $laterStaleOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/plugin-operational-status.php') -Capture -AllowedExitCodes @(2)
        $laterStaleStatus = $laterStaleOutput | ConvertFrom-Json
        if ($laterStaleStatus.realtime_outbox.pending -ne $staleStatus.realtime_outbox.pending -or
            $laterStaleStatus.realtime_outbox.oldest_pending_seconds -le $staleStatus.realtime_outbox.oldest_pending_seconds) {
            throw 'Due backlog did not remain queued and age while the worker was stopped.'
        }
    } finally {
        Invoke-Compose -Arguments @('up', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), $WorkerService)
    }
    $recoveredOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/plugin-operational-status.php') -Capture
    $recoveredStatus = $recoveredOutput | ConvertFrom-Json
    if ($recoveredStatus.worker.state -ne 'ok' -or $recoveredStatus.state -ne 'ok' -or
        $recoveredStatus.worker.age_seconds -gt $recoveredStatus.worker.stale_after_seconds -or
        $recoveredStatus.realtime_outbox.pending -ne 1) {
        throw 'Worker status did not recover after restarting the isolated worker.'
    }
    $dueEventQuery = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*), COALESCE(MAX(attempt_count), 0), SUM(published_at IS NOT NULL), SUM(failed_at IS NOT NULL) FROM message_events_outbox WHERE event_uuid = ''{due_uuid}''"'.Replace('{due_uuid}', $dueEventUuid)
    $dueEventState = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $dueEventQuery) -Capture).Trim() -split "`t"
    if ($dueEventState.Count -ne 4 -or $dueEventState[0] -ne '1' -or $dueEventState[1] -ne '1' -or
        $dueEventState[2] -ne '1' -or $dueEventState[3] -ne '0') {
        throw "Due event did not reach a unique terminal published state after recovery: $($dueEventState -join ', ')"
    }
    Start-Sleep -Seconds 6
    $receiptQuery = '$p="/receipts/".getenv("ACCEPTANCE_EVENT_UUID"); $l=is_file($p)?file($p, FILE_IGNORE_NEW_LINES):[]; echo count($l);'
    $receiptCount = (Invoke-Compose -Arguments @('exec', '-T', '-e', "ACCEPTANCE_EVENT_UUID=$dueEventUuid", 'acceptance-ingress', 'php', '-r', $receiptQuery) -Capture).Trim()
    if ($receiptCount -ne '1') {
        throw "Due event was not accepted exactly once by isolated ingress; receipts: $receiptCount"
    }
    $recoveredHealth = (Invoke-RestMethod -Uri $deliveryHealthUrl -Headers $deliveryHealthHeaders -TimeoutSec 10).data
    if ($recoveredHealth.state -ne 'ok' -or $recoveredHealth.worker.state -ne 'ok' -or
        $recoveredHealth.paths.realtime.state -ne 'ok' -or $recoveredHealth.paths.realtime.pending -ne 1 -or
        -not $recoveredHealth.paths.realtime.last_success_at) {
        throw 'Administrator Delivery health did not return to healthy after worker recovery and ingress acceptance.'
    }
    Write-Host 'Verified administrator Delivery health degraded/healthy transition around real worker restart and one ingress receipt.'
    Write-Step 'Checking retry exhaustion and dead-letter observability'
    $retryEventUuid = [guid]::NewGuid().ToString()
    $retryWorkerArguments = @(
        'exec', '-T',
        '-e', 'SYNDICATUM_SETTING_REALTIME_ENABLED=true',
        '-e', 'SYNDICATUM_SETTING_REALTIME_PUBLISH_URL=http://acceptance-ingress:8123/publish',
        '-e', 'SYNDICATUM_SETTING_REALTIME_BACKEND_INGRESS_SECRET=acceptance-only-ingress-secret',
        '-e', 'SYNDICATUM_SETTING_REALTIME_CLIENT_CODE=acceptance-client',
        '-e', 'SYNDICATUM_SETTING_REALTIME_PROJECT_CODE=acceptance-project',
        $AppService, 'php', 'scripts/process-message-outbox.php', '--limit=1'
    )
    $twoAttemptWorkerArguments = @($retryWorkerArguments) + @('--max-attempts=2')
    Invoke-Compose -Arguments @('stop', $WorkerService)
    try {
        $createRetryEvent = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @pid = (SELECT p.id FROM projects p JOIN users u ON u.id = p.owner_user_id WHERE u.username = ''{username}'' LIMIT 1); INSERT INTO message_events_outbox (event_uuid, project_id, event_type, payload_json, available_at, created_at) VALUES (''{retry_uuid}'', @pid, ''acceptance.retry'', ''{}'', UTC_TIMESTAMP(), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY))"'.Replace('{username}', $workerFixtureUsername).Replace('{retry_uuid}', $retryEventUuid)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createRetryEvent) -Capture | Out-Null
        $retryStateQuery = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT attempt_count, (available_at > created_at), (published_at IS NOT NULL), (failed_at IS NOT NULL) FROM message_events_outbox WHERE event_uuid = ''{retry_uuid}''"'.Replace('{retry_uuid}', $retryEventUuid)
        $releaseRetry = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE message_events_outbox SET available_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE event_uuid = ''{retry_uuid}''"'.Replace('{retry_uuid}', $retryEventUuid)
        for ($attempt = 1; $attempt -le 8; $attempt++) {
            if ($attempt -gt 1) {
                Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $releaseRetry) -Capture | Out-Null
            }
            $retryOutput = Invoke-Compose -Arguments $retryWorkerArguments -Capture -AllowedExitCodes @(0, 3)
            $retryResult = $retryOutput | ConvertFrom-Json
            $retryState = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $retryStateQuery) -Capture).Trim() -split "`t"
            if ($retryState.Count -ne 4 -or $retryState[0] -ne $attempt.ToString() -or $retryState[2] -ne '0') {
                throw "Default retry attempt $attempt had an unexpected outbox state: $($retryState -join ', ')"
            }
            if ($attempt -lt 8) {
                if ($retryResult.realtime.retried -ne 1 -or $retryResult.realtime.dead -ne 0 -or
                    $retryState[1] -ne '1' -or $retryState[3] -ne '0') {
                    throw "Default retry attempt $attempt was not rescheduled: $retryOutput"
                }
            } elseif ($retryResult.realtime.dead -ne 1 -or $retryResult.realtime.retried -ne 0 -or $retryState[3] -ne '1') {
                throw "Default retry attempt 8 did not enter terminal failure: $retryOutput"
            }
        }
        $retryReceiptCount = (Invoke-Compose -Arguments @('exec', '-T', '-e', "ACCEPTANCE_EVENT_UUID=$retryEventUuid", 'acceptance-ingress', 'php', '-r', $receiptQuery) -Capture).Trim()
        if ($retryReceiptCount -ne '8') {
            throw "Default retry exhaustion did not make exactly eight ingress attempts: $retryReceiptCount"
        }
        $deadOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/plugin-operational-status.php') -Capture -AllowedExitCodes @(2)
        $deadStatus = $deadOutput | ConvertFrom-Json
        if ($deadStatus.state -ne 'degraded' -or -not $deadStatus.attention -or
            $deadStatus.realtime_outbox.state -ne 'degraded' -or
            $deadStatus.realtime_outbox.failed_last_24h -lt 1) {
            throw 'Operator status did not expose the retry-exhausted dead letter as degraded.'
        }
    } finally {
        Invoke-Compose -Arguments @('up', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), $WorkerService)
    }
    Write-Step 'Checking uncertain remote acceptance and idempotent receiver replay'
    $uncertainEventUuid = [guid]::NewGuid().ToString()
    Invoke-Compose -Arguments @('stop', $WorkerService)
    try {
        $createUncertainEvent = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @pid = (SELECT p.id FROM projects p JOIN users u ON u.id = p.owner_user_id WHERE u.username = ''{username}'' LIMIT 1); INSERT INTO message_events_outbox (event_uuid, project_id, event_type, payload_json, available_at, created_at) VALUES (''{uncertain_uuid}'', @pid, ''acceptance.uncertain'', ''{}'', UTC_TIMESTAMP(), UTC_TIMESTAMP())"'.Replace('{username}', $workerFixtureUsername).Replace('{uncertain_uuid}', $uncertainEventUuid)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createUncertainEvent) -Capture | Out-Null
        $firstUncertainOutput = Invoke-Compose -Arguments $twoAttemptWorkerArguments -Capture
        $firstUncertainResult = $firstUncertainOutput | ConvertFrom-Json
        if ($firstUncertainResult.realtime.retried -ne 1 -or $firstUncertainResult.realtime.published -ne 0) {
            throw "Ambiguous first ingress outcome did not retain retryable work: $firstUncertainOutput"
        }
        $uncertainStateQuery = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT attempt_count, (published_at IS NOT NULL), (failed_at IS NOT NULL) FROM message_events_outbox WHERE event_uuid = ''{uncertain_uuid}''"'.Replace('{uncertain_uuid}', $uncertainEventUuid)
        $firstUncertainState = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $uncertainStateQuery) -Capture).Trim() -split "`t"
        if ($firstUncertainState.Count -ne 3 -or $firstUncertainState[0] -ne '1' -or
            $firstUncertainState[1] -ne '0' -or $firstUncertainState[2] -ne '0') {
            throw "Ambiguous first outcome did not remain locally unmarked: $($firstUncertainState -join ', ')"
        }
        $effectQuery = '$p="/receipts/effect-".getenv("ACCEPTANCE_EVENT_UUID"); echo is_file($p)?"1":"0";'
        $effectCount = (Invoke-Compose -Arguments @('exec', '-T', '-e', "ACCEPTANCE_EVENT_UUID=$uncertainEventUuid", 'acceptance-ingress', 'php', '-r', $effectQuery) -Capture).Trim()
        if ($effectCount -ne '1') {
            throw 'The mock destination did not apply the first uncertain effect.'
        }
        $releaseUncertain = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE message_events_outbox SET available_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE event_uuid = ''{uncertain_uuid}''"'.Replace('{uncertain_uuid}', $uncertainEventUuid)
        Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $releaseUncertain) -Capture | Out-Null
        $finalUncertainOutput = Invoke-Compose -Arguments $twoAttemptWorkerArguments -Capture
        $finalUncertainResult = $finalUncertainOutput | ConvertFrom-Json
        if ($finalUncertainResult.realtime.published -ne 1 -or $finalUncertainResult.realtime.dead -ne 0) {
            throw "Idempotent replay did not reach terminal published state: $finalUncertainOutput"
        }
        $finalUncertainState = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $uncertainStateQuery) -Capture).Trim() -split "`t"
        if ($finalUncertainState.Count -ne 3 -or $finalUncertainState[0] -ne '2' -or
            $finalUncertainState[1] -ne '1' -or $finalUncertainState[2] -ne '0') {
            throw "Idempotent replay did not mark the exact outbox row published: $($finalUncertainState -join ', ')"
        }
        $uncertainReceiptCount = (Invoke-Compose -Arguments @('exec', '-T', '-e', "ACCEPTANCE_EVENT_UUID=$uncertainEventUuid", 'acceptance-ingress', 'php', '-r', $receiptQuery) -Capture).Trim()
        $effectCount = (Invoke-Compose -Arguments @('exec', '-T', '-e', "ACCEPTANCE_EVENT_UUID=$uncertainEventUuid", 'acceptance-ingress', 'php', '-r', $effectQuery) -Capture).Trim()
        if ($uncertainReceiptCount -ne '2' -or $effectCount -ne '1') {
            throw "Uncertain replay did not produce two sends and one deduplicated destination effect: sends=$uncertainReceiptCount effects=$effectCount"
        }
    } finally {
        Invoke-Compose -Arguments @('up', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), $WorkerService)
    }
    Write-Step 'Checking canonical message versus failed activation and handling'
    $canonicalMessageUuid = [guid]::NewGuid().ToString()
    $activationDeliveryUuid = [guid]::NewGuid().ToString()
    $fixtureAgentName = "acceptance_agent_$suffix"
    $createMessageStatusFixture = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @uid = (SELECT id FROM users WHERE username = ''{username}''); SET @pid = (SELECT id FROM projects WHERE owner_user_id = @uid LIMIT 1); INSERT IGNORE INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES (@pid, @uid, ''owner'', ''active'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); INSERT INTO project_participants (project_id, kind, user_id, status, created_at, updated_at) VALUES (@pid, ''human'', @uid, ''active'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @sender = LAST_INSERT_ID(); INSERT INTO chat_agents (project_name, created_at, updated_at) VALUES (''{agent_name}'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @aid = LAST_INSERT_ID(); INSERT INTO project_agents (project_id, agent_id, display_name, provider, status, created_at, updated_at) VALUES (@pid, @aid, ''Acceptance agent'', ''ChatGPT'', ''active'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); INSERT INTO project_participants (project_id, kind, agent_id, status, created_at, updated_at) VALUES (@pid, ''agent'', @aid, ''active'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @target = LAST_INSERT_ID(); INSERT INTO messages (message_uuid, project_id, project_sequence, sender_participant_id, body, created_at, updated_at) VALUES (''{message_uuid}'', @pid, 1, @sender, ''Acceptance canonical message'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @mid = LAST_INSERT_ID(); INSERT INTO message_addressees (message_id, participant_id, reason, created_at) VALUES (@mid, @target, ''direct'', UTC_TIMESTAMP()); INSERT INTO responses_api_deliveries (delivery_uuid, project_id, message_id, agent_id, status, attempt_count, next_attempt_at, created_at) VALUES (''{delivery_uuid}'', @pid, @mid, @aid, ''dead'', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"'.Replace('{username}', $workerFixtureUsername).Replace('{agent_name}', $fixtureAgentName).Replace('{message_uuid}', $canonicalMessageUuid).Replace('{delivery_uuid}', $activationDeliveryUuid)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createMessageStatusFixture) -Capture | Out-Null
    $messageIdQuery = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT id FROM messages WHERE message_uuid = ''{message_uuid}''"'.Replace('{message_uuid}', $canonicalMessageUuid)
    $canonicalMessageId = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $messageIdQuery) -Capture).Trim()
    if ($canonicalMessageId -notmatch '^[1-9][0-9]*$') {
        throw 'Canonical message fixture ID was not available.'
    }
    $messageStatusOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/plugin-message-delivery-status.php', "--message-id=$canonicalMessageId") -Capture
    $messageStatus = $messageStatusOutput | ConvertFrom-Json
    if (-not $messageStatus.found -or $messageStatus.canonical.state -ne 'active' -or
        $messageStatus.canonical.message_uuid -ne $canonicalMessageUuid -or
        $messageStatus.realtime.state -ne 'not_enqueued' -or
        @($messageStatus.addressees).Count -ne 1 -or
        $messageStatus.addressees[0].handling_state -ne 'unconfirmed' -or
        $messageStatus.addressees[0].activation.responses_api.state -ne 'failed' -or
        $messageStatus.addressees[0].activation.responses_api.queue_status -ne 'dead' -or
        $messageStatus.addressees[0].activation.responses_api.attempt_count -ne 1) {
        throw 'Operator message status conflated a present canonical message with failed activation or handling.'
    }
    Write-Step 'Checking OAuth and service-token authorization at the MCP tool boundary'
    $acceptanceOrigin = "http://127.0.0.1:$HttpPort"
    $mcpUrl = "$BaseUrl/mcp"
    $mcpRequest = @{ jsonrpc = '2.0'; id = 1; method = 'tools/call'; params = @{ name = 'diagnose_connection'; arguments = @{} } } | ConvertTo-Json -Depth 6 -Compress
    $configureMcpOrigin = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "INSERT INTO system_settings (setting_key, value_json, updated_at) VALUES (''general.public_origin'', JSON_QUOTE(''{origin}''), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)"'.Replace('{origin}', $acceptanceOrigin)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $configureMcpOrigin) -Capture | Out-Null
    $missingMcpStatus = 0
    try { Invoke-WebRequest -Uri $mcpUrl -Method POST -ContentType 'application/json' -Body $mcpRequest -TimeoutSec 10 | Out-Null } catch { $missingMcpStatus = [int]$_.Exception.Response.StatusCode }
    if ($missingMcpStatus -ne 401) { throw "MCP tool call without authorization did not return 401: $missingMcpStatus" }
    $invalidMcpStatus = 0
    try { Invoke-WebRequest -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = 'Bearer acceptance-invalid-token' } -Body $mcpRequest -TimeoutSec 10 | Out-Null } catch { $invalidMcpStatus = [int]$_.Exception.Response.StatusCode }
    if ($invalidMcpStatus -ne 401) { throw "MCP tool call with an invalid token did not return 401: $invalidMcpStatus" }
    $invalidOperator = Invoke-OperatorMcpHealth -Token 'acceptance-invalid-token'
    if ($invalidOperator.authentication -ne 'invalid' -or $invalidOperator.binding -ne 'unknown' -or
        $null -ne $invalidOperator.project_access -or $invalidOperator.state -ne 'degraded') {
        throw "Operator MCP status did not distinguish invalid bearer from binding failure: state=$($invalidOperator.state), authentication=$($invalidOperator.authentication), binding=$($invalidOperator.binding), project_access=$($invalidOperator.project_access), http_status=$($invalidOperator.http_status)."
    }
    $oauthToken = New-HexSecret 32
    $oauthTokenHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData([System.Text.Encoding]::UTF8.GetBytes($oauthToken))).ToLowerInvariant()
    $oauthClientId = "acceptance-oauth-$suffix"
    $createOAuthAccess = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "INSERT INTO oauth_clients (client_id, client_name, redirect_uris_json, created_at, updated_at) VALUES (''{client_id}'', ''Acceptance OAuth'', ''[]'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, project_id, agent_id, resource_uri, scope_text, created_at, expires_at) SELECT ''{token_hash}'', ''{client_id}'', id, NULL, NULL, ''{origin}/mcp'', ''projects:read'', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) FROM users WHERE username = ''{username}''"'.Replace('{client_id}', $oauthClientId).Replace('{token_hash}', $oauthTokenHash).Replace('{origin}', $acceptanceOrigin).Replace('{username}', $workerFixtureUsername)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createOAuthAccess) -Capture | Out-Null
    $oauthMcp = Invoke-RestMethod -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = "Bearer $oauthToken" } -Body $mcpRequest -TimeoutSec 10
    $oauthResult = $oauthMcp.result.structuredContent.result
    if ($oauthMcp.result.isError -or -not $oauthResult.checks.authentication_valid -or $oauthResult.checks.project_access_valid -or
        $oauthResult.discussion_binding -ne 'Required' -or $oauthResult.binding_health.state -ne 'missing') {
        throw 'Valid account-scoped OAuth token did not produce authenticated but unbound MCP status.'
    }
    $missingOperator = Invoke-OperatorMcpHealth -Token $oauthToken
    if ($missingOperator.authentication -ne 'valid' -or $missingOperator.binding -ne 'missing' -or
        $missingOperator.project_access -ne $false -or $missingOperator.state -ne 'degraded') {
        throw 'Operator MCP status did not distinguish valid OAuth from missing binding.'
    }
    $invalidContextRequest = @{ jsonrpc = '2.0'; id = 3; method = 'tools/call'; params = @{ name = 'diagnose_connection'; arguments = @{ binding_context_id = 'invalid-acceptance-context' } } } | ConvertTo-Json -Depth 6 -Compress
    $invalidContextMcp = Invoke-RestMethod -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = "Bearer $oauthToken" } -Body $invalidContextRequest -TimeoutSec 10
    $invalidContextResult = $invalidContextMcp.result.structuredContent.result
    if ($invalidContextMcp.result.isError -or $invalidContextResult.checks.project_access_valid -or
        $invalidContextResult.binding_health.state -ne 'invalid') {
        throw 'Unknown binding context unexpectedly authorized or failed to report invalid state.'
    }
    $bindingToken = 'syndicatum_context_' + (New-HexSecret 32)
    $bindingTokenHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData([System.Text.Encoding]::UTF8.GetBytes($bindingToken))).ToLowerInvariant()
    $bindingIntentId = [guid]::NewGuid().ToString()
    $createBindingContext = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @uid = (SELECT id FROM users WHERE username = ''{username}''); SET @pid = (SELECT id FROM projects WHERE owner_user_id = @uid LIMIT 1); SET @aid = (SELECT id FROM chat_agents WHERE project_name = ''{agent_name}'' LIMIT 1); SET @oauth = (SELECT id FROM oauth_access_tokens WHERE token_hash = ''{oauth_hash}'' LIMIT 1); INSERT INTO connector_discussion_binding_intents (id, context_token_hash, oauth_access_token_id, created_by_user_id, project_id, requested_agent_id, requested_agent_name, provider, status, confirmed_agent_id, created_at, expires_at, resolved_at) VALUES (''{intent_id}'', ''{binding_hash}'', @oauth, @uid, @pid, @aid, ''Acceptance agent'', ''chatgpt'', ''confirmed'', @aid, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), UTC_TIMESTAMP())"'.Replace('{username}', $workerFixtureUsername).Replace('{agent_name}', $fixtureAgentName).Replace('{oauth_hash}', $oauthTokenHash).Replace('{intent_id}', $bindingIntentId).Replace('{binding_hash}', $bindingTokenHash)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createBindingContext) -Capture | Out-Null
    $boundMcpRequest = @{ jsonrpc = '2.0'; id = 2; method = 'tools/call'; params = @{ name = 'diagnose_connection'; arguments = @{ binding_context_id = $bindingToken } } } | ConvertTo-Json -Depth 6 -Compress
    $boundMcp = Invoke-RestMethod -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = "Bearer $oauthToken" } -Body $boundMcpRequest -TimeoutSec 10
    $boundResult = $boundMcp.result.structuredContent.result
    if ($boundMcp.result.isError -or -not $boundResult.checks.project_access_valid -or
        $boundResult.binding_health.state -ne 'healthy' -or $boundResult.context_type -ne 'interactive') {
        throw 'Current interactive context did not produce healthy project-bound MCP status.'
    }
    $healthyOperator = Invoke-OperatorMcpHealth -Token $oauthToken -ContextToken $bindingToken
    if ($healthyOperator.authentication -ne 'valid' -or $healthyOperator.binding -ne 'healthy' -or
        $healthyOperator.project_access -ne $true -or $healthyOperator.context_type -ne 'interactive' -or
        $healthyOperator.state -ne 'ok') {
        throw 'Operator MCP status did not project healthy OAuth binding.'
    }
    $expireBindingContext = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE connector_discussion_binding_intents SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = ''{intent_id}''"'.Replace('{intent_id}', $bindingIntentId)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $expireBindingContext) -Capture | Out-Null
    $staleMcp = Invoke-RestMethod -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = "Bearer $oauthToken" } -Body $boundMcpRequest -TimeoutSec 10
    $staleResult = $staleMcp.result.structuredContent.result
    if ($staleMcp.result.isError -or $staleResult.checks.project_access_valid -or $staleResult.binding_health.state -ne 'stale') {
        throw 'Expired interactive context did not produce stale and unauthorized MCP status.'
    }
    $staleOperator = Invoke-OperatorMcpHealth -Token $oauthToken -ContextToken $bindingToken
    if ($staleOperator.authentication -ne 'valid' -or $staleOperator.binding -ne 'stale' -or
        $staleOperator.project_access -ne $false -or $staleOperator.state -ne 'degraded') {
        throw 'Operator MCP status did not project stale binding separately from authentication.'
    }
    $restoreBindingExpiry = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE connector_discussion_binding_intents SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE) WHERE id = ''{intent_id}''"'.Replace('{intent_id}', $bindingIntentId)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $restoreBindingExpiry) -Capture | Out-Null
    $disableBindingOwner = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE project_members SET status = ''removed'' WHERE project_id = (SELECT id FROM projects WHERE owner_user_id = (SELECT id FROM users WHERE username = ''{username}'') LIMIT 1) AND user_id = (SELECT id FROM users WHERE username = ''{username}'')"'.Replace('{username}', $workerFixtureUsername)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $disableBindingOwner) -Capture | Out-Null
    $unusableMcp = Invoke-RestMethod -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = "Bearer $oauthToken" } -Body $boundMcpRequest -TimeoutSec 10
    $unusableResult = $unusableMcp.result.structuredContent.result
    if ($unusableMcp.result.isError -or $unusableResult.checks.project_access_valid -or $unusableResult.binding_health.state -ne 'unusable') {
        throw 'Removed binding owner did not produce unusable and unauthorized MCP status.'
    }
    $unusableOperator = Invoke-OperatorMcpHealth -Token $oauthToken -ContextToken $bindingToken
    if ($unusableOperator.authentication -ne 'valid' -or $unusableOperator.binding -ne 'unusable' -or
        $unusableOperator.project_access -ne $false -or $unusableOperator.state -ne 'degraded') {
        throw 'Operator MCP status did not project unusable binding separately from authentication.'
    }
    $restoreBindingOwner = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE project_members SET status = ''active'' WHERE project_id = (SELECT id FROM projects WHERE owner_user_id = (SELECT id FROM users WHERE username = ''{username}'') LIMIT 1) AND user_id = (SELECT id FROM users WHERE username = ''{username}'')"'.Replace('{username}', $workerFixtureUsername)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $restoreBindingOwner) -Capture | Out-Null
    $cancelBindingContext = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE connector_discussion_binding_intents SET status = ''cancelled'' WHERE id = ''{intent_id}''"'.Replace('{intent_id}', $bindingIntentId)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $cancelBindingContext) -Capture | Out-Null
    $revokedMcp = Invoke-RestMethod -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = "Bearer $oauthToken" } -Body $boundMcpRequest -TimeoutSec 10
    $revokedResult = $revokedMcp.result.structuredContent.result
    if ($revokedMcp.result.isError -or $revokedResult.checks.project_access_valid -or $revokedResult.binding_health.state -ne 'revoked') {
        throw 'Cancelled context did not produce revoked and unauthorized MCP status.'
    }
    $agentIdentityCountQuery = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM chat_agents WHERE project_name = ''{agent_name}''"'.Replace('{agent_name}', $fixtureAgentName)
    $agentIdentityCount = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $agentIdentityCountQuery) -Capture).Trim()
    if ($agentIdentityCount -ne '1') { throw "Binding diagnosis created or replaced the fixture agent identity: $agentIdentityCount" }
    $revokeOAuthAccess = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE oauth_access_tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = ''{token_hash}''"'.Replace('{token_hash}', $oauthTokenHash)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $revokeOAuthAccess) -Capture | Out-Null
    $revokedOAuthStatus = 0
    try { Invoke-WebRequest -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = "Bearer $oauthToken" } -Body $mcpRequest -TimeoutSec 10 | Out-Null } catch { $revokedOAuthStatus = [int]$_.Exception.Response.StatusCode }
    if ($revokedOAuthStatus -ne 401) { throw "Revoked OAuth access token did not return 401: $revokedOAuthStatus" }
    $revokedOperator = Invoke-OperatorMcpHealth -Token $oauthToken
    if ($revokedOperator.authentication -ne 'invalid' -or $revokedOperator.binding -ne 'unknown' -or
        $null -ne $revokedOperator.project_access -or $revokedOperator.state -ne 'degraded') {
        throw 'Operator MCP status did not project revoked bearer as authentication failure.'
    }
    $serviceToken = 'syndicatum_mcp_' + (New-HexSecret 32)
    $serviceTokenHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData([System.Text.Encoding]::UTF8.GetBytes($serviceToken))).ToLowerInvariant()
    $createServiceToken = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @aid = (SELECT id FROM chat_agents WHERE project_name = ''{agent_name}'' LIMIT 1); SET @pid = (SELECT id FROM projects WHERE owner_user_id = (SELECT id FROM users WHERE username = ''{username}'') LIMIT 1); INSERT INTO mcp_service_tokens (token_hash, project_id, agent_id, created_at) VALUES (''{token_hash}'', @pid, @aid, UTC_TIMESTAMP())"'.Replace('{agent_name}', $fixtureAgentName).Replace('{username}', $workerFixtureUsername).Replace('{token_hash}', $serviceTokenHash)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $createServiceToken) -Capture | Out-Null
    $serviceMcp = Invoke-RestMethod -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = "Bearer $serviceToken" } -Body $mcpRequest -TimeoutSec 10
    $serviceResult = $serviceMcp.result.structuredContent.result
    if ($serviceMcp.result.isError -or -not $serviceResult.checks.authentication_valid -or -not $serviceResult.checks.project_access_valid -or
        $serviceResult.context_type -ne 'service_token' -or $serviceResult.binding_health.state -ne 'not_required') {
        throw 'Valid project-agent service token did not produce bound MCP status.'
    }
    $serviceOperator = Invoke-OperatorMcpHealth -Token $serviceToken
    if ($serviceOperator.authentication -ne 'valid' -or $serviceOperator.binding -ne 'not_required' -or
        $serviceOperator.project_access -ne $true -or $serviceOperator.context_type -ne 'service_token' -or
        $serviceOperator.state -ne 'ok') {
        throw 'Operator MCP status did not project valid service-token authorization.'
    }
    $unreachableOperator = Invoke-OperatorMcpHealth -Token $serviceToken -Url 'http://127.0.0.1:9/mcp'
    if ($unreachableOperator.state -ne 'unknown' -or $unreachableOperator.authentication -ne 'unknown' -or
        $null -ne $unreachableOperator.project_access) {
        throw 'Operator MCP status treated unreachable MCP as healthy.'
    }
    $revokeServiceToken = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "UPDATE mcp_service_tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = ''{token_hash}''"'.Replace('{token_hash}', $serviceTokenHash)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $revokeServiceToken) -Capture | Out-Null
    $revokedServiceStatus = 0
    try { Invoke-WebRequest -Uri $mcpUrl -Method POST -ContentType 'application/json' -Headers @{ Authorization = "Bearer $serviceToken" } -Body $mcpRequest -TimeoutSec 10 | Out-Null } catch { $revokedServiceStatus = [int]$_.Exception.Response.StatusCode }
    if ($revokedServiceStatus -ne 401) { throw "Revoked MCP service token did not return 401: $revokedServiceStatus" }
    $deleteOAuthClient = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "DELETE FROM oauth_clients WHERE client_id = ''{client_id}''"'.Replace('{client_id}', $oauthClientId)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $deleteOAuthClient) -Capture | Out-Null
    Write-Host 'Verified MCP token states and missing, invalid, healthy, stale, unusable, revoked, and not-required binding health without identity creation.'
    $deleteHealthSession = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @uid = (SELECT id FROM users WHERE username = ''{username}''); DELETE FROM syndicatum_sessions WHERE token_hash = ''{token_hash}''; DELETE FROM user_system_roles WHERE user_id = @uid AND role_id IN (SELECT id FROM system_roles WHERE code = ''administrator'')"'.Replace('{username}', $workerFixtureUsername).Replace('{token_hash}', $healthSessionHash)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $deleteHealthSession) -Capture | Out-Null
    $deleteWorkerFixture = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "SET @uid = (SELECT id FROM users WHERE username = ''{username}''); SET @pid = (SELECT id FROM projects WHERE owner_user_id = @uid LIMIT 1); DELETE FROM messages WHERE project_id = @pid; DELETE FROM project_participants WHERE project_id = @pid; DELETE FROM project_agents WHERE project_id = @pid; DELETE FROM project_members WHERE project_id = @pid; DELETE FROM projects WHERE id = @pid; DELETE FROM workspaces WHERE owner_user_id = @uid; DELETE FROM users WHERE id = @uid"'.Replace('{username}', $workerFixtureUsername)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $deleteWorkerFixture) -Capture | Out-Null
    $deleteFixtureAgent = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "DELETE FROM chat_agents WHERE project_name = ''{agent_name}''"'.Replace('{agent_name}', $fixtureAgentName)
    Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-lc', $deleteFixtureAgent) -Capture | Out-Null
    $cleanOutput = Invoke-Compose -Arguments @('exec', '-T', $AppService, 'php', 'scripts/plugin-operational-status.php') -Capture
    $cleanStatus = $cleanOutput | ConvertFrom-Json
    if ($cleanStatus.state -ne 'ok' -or $cleanStatus.realtime_outbox.pending -ne 0) {
        throw 'Worker acceptance fixture did not cleanly leave healthy delivery status.'
    }
    Write-Host 'Verified due-event recovery, retry exhaustion, uncertain-outcome replay, canonical-versus-activation status, and fixture cleanup.'
    $indexResponse = Invoke-WebRequest -Uri "$BaseUrl/" -TimeoutSec 10
    if ($indexResponse.StatusCode -ne 200) {
        throw "Application root returned HTTP $($indexResponse.StatusCode)."
    }

    Write-Step 'Recording effective Apache and MySQL process privileges'
    $processProbe = 'for file in /proc/[0-9]*/status; do name= uid= cap= nnp=; while read -r field value rest; do case "$field" in Name:) name=$value;; Uid:) uid=$value;; CapEff:) cap=$value;; NoNewPrivs:) nnp=$value;; esac; done < "$file"; case "$name" in apache2|mysqld) printf "%s:%s:CapEff=%s:NoNewPrivs=%s\n" "$name" "$uid" "$cap" "$nnp";; esac; done'
    $appProcesses = (Invoke-Compose -Arguments @('exec', '-T', $AppService, 'sh', '-c', $processProbe) -Capture).Trim()
    $databaseProcesses = (Invoke-Compose -Arguments @('exec', '-T', $DatabaseService, 'sh', '-c', $processProbe) -Capture).Trim()
    if ($appProcesses -match '(?m)^apache2:0:' -or $appProcesses -notmatch 'apache2:[1-9][0-9]*' -or
        $databaseProcesses -notmatch 'mysqld:[1-9][0-9]*') {
        throw "Unexpected runtime process privileges. Apache: $appProcesses; MySQL: $databaseProcesses"
    }
    if ($appProcesses -notmatch '(?m)^apache2:33:CapEff=0000000000000000:NoNewPrivs=1\r?$' -or
        $appProcesses -match '(?m)^apache2:[^:]+:CapEff=(?!0000000000000000)' -or
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
    $apacheModules = (Invoke-Compose -Arguments @('exec', '-T', $AppService, 'sh', '-c', '. /etc/apache2/envvars; apache2 -M') -Capture)
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
    Remove-Item -LiteralPath $backupKeyPath -Force -ErrorAction SilentlyContinue
}
