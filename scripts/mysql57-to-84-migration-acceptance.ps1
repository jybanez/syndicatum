[CmdletBinding()]
param(
    [string]$ComposeFile = (Join-Path $PSScriptRoot '..\compose.yaml'),
    [ValidateRange(1, 65535)]
    [int]$HttpPort = 18085,
    [ValidateRange(30, 600)]
    [int]$StartupTimeoutSeconds = 240,
    [string]$SourceImage = 'mysql:5.7.44@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb',
    [string]$TargetImage = 'mysql:8.4@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a'
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

function Invoke-Docker([string[]]$Arguments, [switch]$Capture) {
    $output = @(& docker @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "docker $($Arguments -join ' ') failed:`n$($output -join "`n")"
    }
    if ($Capture) { return ($output -join "`n") }
    $output | ForEach-Object { Write-Host $_ }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI is required.'
}
Invoke-Docker -Arguments @('info') -Capture | Out-Null

$composePath = (Resolve-Path -LiteralPath $ComposeFile).Path
$suffix = (New-HexSecret 6).Substring(0, 12)
$projectName = "syndicatum-migration-$suffix"
$databaseName = "syndicatum_migration_$suffix"
if ($projectName -notmatch '^syndicatum-migration-[a-f0-9]{12}$' -or
    $databaseName -notmatch '^syndicatum_migration_[a-f0-9]{12}$') {
    throw 'Generated migration-acceptance identity is unsafe.'
}

$rootPassword = New-HexSecret 24
$applicationPassword = New-HexSecret 24
$applicationSecret = New-HexSecret 32
$masterKey = New-HexSecret 32
$environmentPath = Join-Path ([System.IO.Path]::GetTempPath()) "$projectName.env"
$dumpPath = Join-Path ([System.IO.Path]::GetTempPath()) "$projectName.sql"
$applicationImage = "${projectName}-app:acceptance"
$databaseImage57 = "${projectName}-db57:acceptance"
$databaseImage84 = "${projectName}-db84:acceptance"
$databaseContainer = "${projectName}-db-1"
$sourceSqlMode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'
$targetSqlMode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
$script:ComposeOptions = @('--project-name', $projectName, '--env-file', $environmentPath, '--file', $composePath)

function Write-AcceptanceEnvironment([string]$MySqlImage, [string]$DatabaseImage, [string]$SqlMode) {
    @(
        "COMPOSE_PROJECT_NAME=$projectName"
        "SYNDICATUM_IMAGE=$applicationImage"
        "SYNDICATUM_DB_IMAGE=$DatabaseImage"
        "SYNDICATUM_MYSQL_IMAGE=$MySqlImage"
        "SYNDICATUM_MYSQL_SQL_MODE=$SqlMode"
        'SYNDICATUM_HTTP_BIND=127.0.0.1'
        "SYNDICATUM_HTTP_PORT=$HttpPort"
        "PBB_AGENTCHAT_DB_NAME=$databaseName"
        'PBB_AGENTCHAT_DB_USER=syndicatum'
        "PBB_AGENTCHAT_DB_PASS=$applicationPassword"
        "MYSQL_ROOT_PASSWORD=$rootPassword"
        'PBB_AGENTCHAT_DB_HOST=db'
        "PBB_AGENTCHAT_SECRET=$applicationSecret"
        "SYNDICATUM_MASTER_KEY=$masterKey"
        'TZ=UTC'
    ) | Set-Content -LiteralPath $environmentPath -Encoding utf8NoBOM
}

function Invoke-Compose([string[]]$Arguments, [switch]$Capture) {
    Invoke-Docker -Arguments (@('compose') + $script:ComposeOptions + $Arguments) -Capture:$Capture
}

function Read-DatabaseVersion {
    $probe = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot -e "SELECT VERSION()"'
    return (Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $probe) -Capture).Trim()
}

function Verify-Migrations {
    Invoke-Compose -Arguments @('exec', '-T', 'app', 'php', 'scripts/chat-db.php', 'migrate') -Capture | Out-Null
    $status = Invoke-Compose -Arguments @('exec', '-T', 'app', 'php', 'scripts/chat-db.php', 'migration-status') -Capture
    $rows = @($status | ConvertFrom-Json)
    $incomplete = @($rows | Where-Object { -not $_.applied -or -not $_.checksum_valid })
    if ($rows.Count -eq 0 -or $incomplete.Count -gt 0) {
        throw "Migration verification failed; rows=$($rows.Count), incomplete=$($incomplete.Count)."
    }
    Write-Host "Verified $($rows.Count) migrations."
}

try {
    Write-Step 'Starting pinned MySQL 5.7 source and applying the complete schema'
    Write-AcceptanceEnvironment -MySqlImage $SourceImage -DatabaseImage $databaseImage57 -SqlMode $sourceSqlMode
    Invoke-Compose -Arguments @('config', '--quiet')
    Invoke-Compose -Arguments @('up', '--build', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), 'db', 'app', 'worker')
    $sourceVersion = Read-DatabaseVersion
    if ($sourceVersion -notmatch '^5\.7\.44(?:$|[.-])') { throw "Expected MySQL 5.7.44 source; observed $sourceVersion." }
    Verify-Migrations

    Write-Step 'Creating representative identity, membership, message, and addressee data on 5.7'
    $seedSql = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "INSERT INTO users (username, display_name, created_at, updated_at) VALUES (''migration_owner'', ''Migration Owner'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @uid=LAST_INSERT_ID(); INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (@uid, ''Migration Workspace'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @wid=LAST_INSERT_ID(); INSERT INTO projects (public_id, workspace_id, owner_user_id, name, slug, created_at, updated_at) VALUES (''11111111-1111-4111-8111-111111111111'', @wid, @uid, ''Migration Project'', ''migration-project'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @pid=LAST_INSERT_ID(); INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES (@pid,@uid,''owner'',''active'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); INSERT INTO project_participants (project_id,kind,user_id,status,created_at,updated_at) VALUES (@pid,''human'',@uid,''active'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); SET @sender=LAST_INSERT_ID(); INSERT INTO chat_agents (project_name,created_at,updated_at) VALUES (''Migration Agent'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); SET @aid=LAST_INSERT_ID(); INSERT INTO project_agents (project_id,agent_id,display_name,provider,status,created_at,updated_at) VALUES (@pid,@aid,''Migration Agent'',''ChatGPT'',''active'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); INSERT INTO project_participants (project_id,kind,agent_id,status,created_at,updated_at) VALUES (@pid,''agent'',@aid,''active'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); SET @target=LAST_INSERT_ID(); INSERT INTO messages (message_uuid,project_id,project_sequence,sender_participant_id,body,created_at,updated_at) VALUES (''22222222-2222-4222-8222-222222222222'',@pid,1,@sender,''Migration acceptance message'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); SET @mid=LAST_INSERT_ID(); INSERT INTO message_addressees (message_id,participant_id,reason,created_at) VALUES (@mid,@target,''direct'',UTC_TIMESTAMP());"'
    Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $seedSql) -Capture | Out-Null
    $expectedState = '1|1|1|1|2|1|1'
    $stateSql = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT CONCAT_WS(''|'',(SELECT COUNT(*) FROM users WHERE username=''migration_owner''),(SELECT COUNT(*) FROM workspaces WHERE name=''Migration Workspace''),(SELECT COUNT(*) FROM projects WHERE slug=''migration-project''),(SELECT COUNT(*) FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE u.username=''migration_owner'' AND pm.status=''active''),(SELECT COUNT(*) FROM project_participants pp JOIN projects p ON p.id=pp.project_id WHERE p.slug=''migration-project''),(SELECT COUNT(*) FROM messages WHERE message_uuid=''22222222-2222-4222-8222-222222222222'' AND body=''Migration acceptance message''),(SELECT COUNT(*) FROM message_addressees ma JOIN messages m ON m.id=ma.message_id WHERE m.message_uuid=''22222222-2222-4222-8222-222222222222'' AND ma.reason=''direct''))"'
    $sourceState = (Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $stateSql) -Capture).Trim()
    if ($sourceState -ne $expectedState) { throw "Unexpected 5.7 fixture state: $sourceState" }

    Write-Step 'Exporting the 5.7 database and replacing it with a fresh pinned 8.4 database'
    $dumpInContainer = "/tmp/$projectName.sql"
    $dumpSql = "set -eu; mysqldump --single-transaction --routines --triggers --set-gtid-purged=OFF -uroot -p`"`$MYSQL_ROOT_PASSWORD`" `"`$MYSQL_DATABASE`" > '$dumpInContainer'; test -s '$dumpInContainer'"
    Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $dumpSql) -Capture | Out-Null
    Invoke-Docker -Arguments @('cp', "${databaseContainer}:$dumpInContainer", $dumpPath)
    if (-not (Test-Path -LiteralPath $dumpPath) -or (Get-Item -LiteralPath $dumpPath).Length -lt 1024) {
        throw 'MySQL 5.7 export is missing or unexpectedly small.'
    }
    Invoke-Compose -Arguments @('down', '--volumes', '--remove-orphans')

    Write-AcceptanceEnvironment -MySqlImage $TargetImage -DatabaseImage $databaseImage84 -SqlMode $targetSqlMode
    Invoke-Compose -Arguments @('config', '--quiet')
    Invoke-Compose -Arguments @('up', '--build', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), 'db')
    $targetVersion = Read-DatabaseVersion
    if ($targetVersion -notmatch '^8\.4(?:$|[.-])') { throw "Expected MySQL 8.4 target; observed $targetVersion." }
    Invoke-Docker -Arguments @('cp', $dumpPath, "${databaseContainer}:/tmp/source57.sql")
    $restoreSql = 'set -eu; MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" < /tmp/source57.sql'
    Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $restoreSql) -Capture | Out-Null

    Write-Step 'Starting the application on restored 8.4 data and verifying schema and behavior'
    Invoke-Compose -Arguments @('up', '--build', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), 'app', 'worker')
    Verify-Migrations
    $targetState = (Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $stateSql) -Capture).Trim()
    if ($targetState -ne $expectedState) { throw "Restored 8.4 fixture state differs: $targetState" }
    $health = Invoke-RestMethod -Uri "http://127.0.0.1:$HttpPort/api/v1/health.php" -TimeoutSec 15
    if ($health.data.core.status -ne 'ok' -or -not $health.data.core.database -or -not $health.data.core.expanded_schema) {
        throw 'Application health failed after restoring the 5.7 export into MySQL 8.4.'
    }
    Write-Host "MySQL migration acceptance passed: $sourceVersion export restored into $targetVersion with $targetState and healthy application behavior."
} finally {
    Write-Step "Removing isolated migration project $projectName and its volumes"
    try { Invoke-Compose -Arguments @('down', '--volumes', '--remove-orphans') } catch { Write-Warning $_ }
    if (Test-Path -LiteralPath $environmentPath) { Remove-Item -LiteralPath $environmentPath -Force }
    if (Test-Path -LiteralPath $dumpPath) { Remove-Item -LiteralPath $dumpPath -Force }
}
