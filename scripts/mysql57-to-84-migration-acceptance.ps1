[CmdletBinding()]
param(
    [string]$ComposeFile = (Join-Path $PSScriptRoot '..\compose.yaml'),
    [ValidateRange(1, 65535)]
    [int]$HttpPort = 18085,
    [ValidateRange(30, 600)]
    [int]$StartupTimeoutSeconds = 240,
    [Parameter(Mandatory = $true)]
    [string]$CandidatePackageDir,
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
$candidatePackagePath = (Resolve-Path -LiteralPath $CandidatePackageDir).Path
$testHelpersPath = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\tests')).Path
foreach ($name in @('syndicatum-v1.0.0.zip', 'syndicatum-v1.0.0.manifest.json', 'syndicatum-v1.0.0.provenance.json')) {
    if (-not (Test-Path -LiteralPath (Join-Path $candidatePackagePath $name) -PathType Leaf)) {
        throw "Pinned legacy candidate file is missing: $name"
    }
}
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
$backupKeyPath = Join-Path ([System.IO.Path]::GetTempPath()) "$projectName.backup-key"
$dumpPath = Join-Path ([System.IO.Path]::GetTempPath()) "$projectName.sql"
$legacySourcePath = Join-Path ([System.IO.Path]::GetTempPath()) "$projectName-legacy-source"
$applicationImage = "${projectName}-app:acceptance"
$databaseImage57 = "${projectName}-db57:acceptance"
$databaseImage84 = "${projectName}-db84:acceptance"
$databaseContainer = "${projectName}-db-1"
$sourceSqlMode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'
$targetSqlMode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
$script:ComposeOptions = @('--project-name', $projectName, '--env-file', $environmentPath, '--file', $composePath)

if (Test-Path -LiteralPath $backupKeyPath) {
    throw "Refusing to overwrite unexpected temporary backup key file: $backupKeyPath"
}
$backupKeyBytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Fill($backupKeyBytes)
[System.IO.File]::WriteAllText(
    $backupKeyPath,
    [Convert]::ToBase64String($backupKeyBytes) + "`n",
    [System.Text.UTF8Encoding]::new($false)
)
if ([System.Environment]::OSVersion.Platform -eq [System.PlatformID]::Unix) {
    & chmod 600 -- $backupKeyPath
    if ($LASTEXITCODE -ne 0) { throw 'Could not make the migration acceptance backup key private.' }
}

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
        "SYNDICATUM_BACKUP_KEY_FILE=$backupKeyPath"
        'SYNDICATUM_ALLOW_LEGACY_UPGRADE=1'
        'SYNDICATUM_PACKAGE_SHA256=0000000000000000000000000000000000000000000000000000000000000000'
        'SYNDICATUM_RELEASE_SOURCE_COMMIT=0000000000000000000000000000000000000000'
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

function Install-And-Verify-Legacy-Migrations {
    $legacyMount = "${legacySourcePath}:/var/www/html:ro"
    Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '-v', $legacyMount, '--entrypoint', 'php', 'app', 'scripts/chat-db.php', 'install-schema') -Capture | Out-Null
    $status = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '-v', $legacyMount, '--entrypoint', 'php', 'app', 'scripts/chat-db.php', 'migration-status') -Capture
    $rows = @($status | ConvertFrom-Json)
    $incomplete = @($rows | Where-Object { -not $_.applied -or -not $_.checksum_valid })
    if ($rows.Count -eq 0 -or $incomplete.Count -gt 0) {
        throw "Migration verification failed; rows=$($rows.Count), incomplete=$($incomplete.Count)."
    }
    Write-Host "Verified $($rows.Count) migrations."
}

function Read-LedgerSnapshot {
    $query = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --raw --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT version, checksum FROM syndicatum_schema_migrations ORDER BY version"'
    $output = Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $query) -Capture
    $lines = @($output -split "`r?`n" | Where-Object { $_ -ne '' })
    $planPath = Join-Path $PSScriptRoot '..\schema\mysql84\legacy-upgrade-plan.json'
    $plan = Get-Content -LiteralPath $planPath -Raw | ConvertFrom-Json
    $expected = @($plan.historical_migrations) + @($plan.forward_migrations)
    $expected = @($expected | Sort-Object -Property id)
    if ($lines.Count -ne 30 -or $expected.Count -ne 30) {
        throw "Expected exactly 30 legacy ledger rows; actual=$($lines.Count), plan=$($expected.Count)."
    }
    for ($index = 0; $index -lt 30; $index++) {
        $fields = @($lines[$index] -split "`t")
        if ($fields.Count -ne 2 -or $fields[0] -cne $expected[$index].id -or $fields[1] -cne $expected[$index].sha256) {
            throw "Legacy ledger differs from the approved plan at row $index."
        }
    }
    $bytes = [System.Text.Encoding]::UTF8.GetBytes(($lines -join "`n") + "`n")
    $digest = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
    Write-Host "Verified exact 30-row legacy ledger; sha256=$digest"
    return $digest
}

function Read-IdentityCount {
    $query = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --raw --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=''syndicatum_installation_identity''"'
    return [int](Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $query) -Capture).Trim()
}

function Read-InstallationState {
    $output = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'app', 'scripts/chat-db.php', 'installation-status') -Capture
    $json = [regex]::Match($output, '(?s)\{.*\}').Value
    if (-not $json) { throw 'InstallationState probe did not return JSON.' }
    return ($json | ConvertFrom-Json)
}

function Read-SchemaFingerprint {
    $code = 'require "/var/www/html/src/Db.php"; require "/var/www/html/src/LegacySchemaFingerprint.php"; echo LegacySchemaFingerprint::sha256(Db::pdo()), "\n";'
    $output = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'app', '-r', $code) -Capture
    $digest = [regex]::Match($output, '[a-f0-9]{64}').Value
    if (-not $digest) { throw 'Legacy schema fingerprint probe did not return a digest.' }
    return $digest
}

function Read-DeliveryCounts {
    $query = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --raw --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT CONCAT_WS(''|'',(SELECT COUNT(*) FROM message_events_outbox),(SELECT COUNT(*) FROM agent_webhook_deliveries),(SELECT COUNT(*) FROM workspace_agent_trigger_deliveries),(SELECT COUNT(*) FROM responses_api_deliveries))"'
    return (Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $query) -Capture).Trim()
}

function Read-ClaimColumns {
    $code = @'
require "/var/www/html/src/Db.php";
$q = "SELECT table_name,ordinal_position,column_name,column_type,is_nullable,column_default,extra,character_set_name,collation_name,generation_expression FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN ('agents','chat_agents') ORDER BY table_name,ordinal_position";
echo json_encode(Db::pdo()->query($q)->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR), "\n";
'@
    $output = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'app', '-r', $code) -Capture
    $json = [regex]::Match($output, '(?s)\[.*\]').Value
    if (-not $json) { throw 'Claim-column inventory probe did not return JSON.' }
    return @(ConvertFrom-Json -InputObject $json)
}

function Read-ClaimRowDigest {
    $code = 'require "/var/www/html/src/Db.php"; $rows=[]; foreach (["agents","chat_agents"] as $table) { $items=Db::pdo()->query("SELECT * FROM " . $table . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); foreach ($items as &$item) { ksort($item); } unset($item); $rows[$table]=$items; } echo hash("sha256", json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), "\n";'
    $output = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'app', '-r', $code) -Capture
    $digest = [regex]::Match($output, '[a-f0-9]{64}').Value
    if (-not $digest) { throw 'Claim-row digest probe did not return a digest.' }
    return $digest
}

function Assert-ClaimColumns([object[]]$Before, [object[]]$After, [switch]$Legacy, [switch]$CrossVersion) {
    if ($Before.Count -ne 34 -or $After.Count -ne 34) { throw 'Expected exactly 17 claim columns in each of two tables.' }
    foreach ($table in @('agents', 'chat_agents')) {
        $old = @($Before | Where-Object { $_.TABLE_NAME -eq $table })
        $new = @($After | Where-Object { $_.TABLE_NAME -eq $table })
        for ($index = 0; $index -lt 17; $index++) {
            $expected = $old[$index]
            if ($Legacy -and $index -eq 9) { $expected = $old[10] }
            if ($Legacy -and $index -eq 10) { $expected = $old[9] }
            $actual = $new[$index]
            # MySQL 8.4 normalizes some 5.7 metadata (for example integer display widths).
            # The exact 8.4 schema fingerprint is checked before this cross-version comparison.
            $definition = if ($CrossVersion) { @('COLUMN_NAME') } else { @('COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA', 'CHARACTER_SET_NAME', 'COLLATION_NAME', 'GENERATION_EXPRESSION') }
            foreach ($field in $definition) {
                if ($actual.$field -cne $expected.$field) {
                    throw "Claim-column definition or order changed unexpectedly: $table ordinal $($index + 1) $field."
                }
            }
            if ([int]$actual.ORDINAL_POSITION -ne $index + 1) { throw "Unexpected ordinal in $table." }
        }
        $first = if ($Legacy) { 'claim_expires_at' } else { 'claim_secret_version' }
        $second = if ($Legacy) { 'claim_secret_version' } else { 'claim_expires_at' }
        if ($new[9].COLUMN_NAME -ne $first -or $new[10].COLUMN_NAME -ne $second -or
            $new[9].COLUMN_TYPE -ne $(if ($Legacy) { 'datetime' } else { 'varchar(24)' }) -or
            $new[10].COLUMN_TYPE -ne $(if ($Legacy) { 'varchar(24)' } else { 'datetime' })) {
            throw "Claim-column layout does not match the expected physical order: $table."
        }
    }
}

try {
    Write-Step 'Starting pinned MySQL 5.7 source and applying the complete schema'
    if (Test-Path -LiteralPath $legacySourcePath) {
        throw "Refusing to overwrite unexpected legacy source directory: $legacySourcePath"
    }
    [System.IO.Compression.ZipFile]::ExtractToDirectory(
        (Join-Path $candidatePackagePath 'syndicatum-v1.0.0.zip'),
        $legacySourcePath
    )
    if (-not (Test-Path -LiteralPath (Join-Path $legacySourcePath 'scripts/chat-db.php')) -or
        -not (Test-Path -LiteralPath (Join-Path $legacySourcePath 'migrations/202609180004_delivery_terminal_timestamps.php'))) {
        throw 'Pinned legacy candidate did not extract the expected migration source.'
    }
    Write-AcceptanceEnvironment -MySqlImage $SourceImage -DatabaseImage $databaseImage57 -SqlMode $sourceSqlMode
    Invoke-Compose -Arguments @('config', '--quiet')
    Invoke-Compose -Arguments @('up', '--build', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), 'db')
    Invoke-Compose -Arguments @('build', 'app')
    $sourceVersion = Read-DatabaseVersion
    if ($sourceVersion -notmatch '^5\.7\.44(?:$|[.-])') { throw "Expected MySQL 5.7.44 source; observed $sourceVersion." }
    Install-And-Verify-Legacy-Migrations
    $sourceLedger = Read-LedgerSnapshot
    if ((Read-IdentityCount) -ne 0) { throw 'The 5.7 legacy fixture unexpectedly has an installation identity.' }

    Write-Step 'Creating representative identity, membership, message, and addressee data on 5.7'
    $seedSql = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "INSERT INTO users (username, display_name, created_at, updated_at) VALUES (''migration_owner'', ''Migration Owner'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @uid=LAST_INSERT_ID(); INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (@uid, ''Migration Workspace'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @wid=LAST_INSERT_ID(); INSERT INTO projects (public_id, workspace_id, owner_user_id, name, slug, created_at, updated_at) VALUES (''11111111-1111-4111-8111-111111111111'', @wid, @uid, ''Migration Project'', ''migration-project'', UTC_TIMESTAMP(), UTC_TIMESTAMP()); SET @pid=LAST_INSERT_ID(); INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES (@pid,@uid,''owner'',''active'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); INSERT INTO project_participants (project_id,kind,user_id,status,created_at,updated_at) VALUES (@pid,''human'',@uid,''active'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); SET @sender=LAST_INSERT_ID(); INSERT INTO chat_agents (project_name,created_at,updated_at) VALUES (''Migration Agent'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); SET @aid=LAST_INSERT_ID(); INSERT INTO project_agents (project_id,agent_id,display_name,provider,status,created_at,updated_at) VALUES (@pid,@aid,''Migration Agent'',''ChatGPT'',''active'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); INSERT INTO project_participants (project_id,kind,agent_id,status,created_at,updated_at) VALUES (@pid,''agent'',@aid,''active'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); SET @target=LAST_INSERT_ID(); INSERT INTO messages (message_uuid,project_id,project_sequence,sender_participant_id,body,created_at,updated_at) VALUES (''22222222-2222-4222-8222-222222222222'',@pid,1,@sender,''Migration acceptance message'',UTC_TIMESTAMP(),UTC_TIMESTAMP()); SET @mid=LAST_INSERT_ID(); INSERT INTO message_addressees (message_id,participant_id,reason,created_at) VALUES (@mid,@target,''direct'',UTC_TIMESTAMP());"'
    Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $seedSql) -Capture | Out-Null
    $expectedState = '1|1|1|1|2|1|1'
    $stateSql = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT CONCAT_WS(''|'',(SELECT COUNT(*) FROM users WHERE username=''migration_owner''),(SELECT COUNT(*) FROM workspaces WHERE name=''Migration Workspace''),(SELECT COUNT(*) FROM projects WHERE slug=''migration-project''),(SELECT COUNT(*) FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE u.username=''migration_owner'' AND pm.status=''active''),(SELECT COUNT(*) FROM project_participants pp JOIN projects p ON p.id=pp.project_id WHERE p.slug=''migration-project''),(SELECT COUNT(*) FROM messages WHERE message_uuid=''22222222-2222-4222-8222-222222222222'' AND body=''Migration acceptance message''),(SELECT COUNT(*) FROM message_addressees ma JOIN messages m ON m.id=ma.message_id WHERE m.message_uuid=''22222222-2222-4222-8222-222222222222'' AND ma.reason=''direct''))"'
    $sourceState = (Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $stateSql) -Capture).Trim()
    if ($sourceState -ne $expectedState) { throw "Unexpected 5.7 fixture state: $sourceState" }

    Write-Step 'Reproducing only the preserved legacy claim-column order before export'
    $targetClaimColumns = Read-ClaimColumns
    Assert-ClaimColumns -Before $targetClaimColumns -After $targetClaimColumns
    $claimRowsBefore = Read-ClaimRowDigest
    $claimOrderSql = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE" -e "ALTER TABLE chat_agents MODIFY COLUMN claim_secret_version VARCHAR(24) COLLATE utf8mb4_unicode_ci NULL AFTER claim_expires_at; ALTER TABLE agents MODIFY COLUMN claim_secret_version VARCHAR(24) COLLATE utf8mb4_unicode_ci NULL AFTER claim_expires_at"'
    Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $claimOrderSql) -Capture | Out-Null
    $legacyClaimColumns = Read-ClaimColumns
    Assert-ClaimColumns -Before $targetClaimColumns -After $legacyClaimColumns -Legacy
    $claimRowsAfter = Read-ClaimRowDigest
    if ($claimRowsAfter -ne $claimRowsBefore) { throw 'Claim rows or values changed while reproducing the legacy order.' }
    if ((Read-LedgerSnapshot) -ne $sourceLedger) { throw 'Claim-order fixture changed the exact 30-row ledger.' }
    Write-Host "Preserved only the legacy claim-column ordinals; affected row/value sha256=$claimRowsAfter"

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

    Write-Step 'Verifying the exact restored ledger and fail-closed pre-adoption state'
    $restoredLedger = Read-LedgerSnapshot
    if ($restoredLedger -ne $sourceLedger) { throw 'Restored ledger changed during export or import.' }
    if ((Read-IdentityCount) -ne 0) { throw 'Restored legacy fixture unexpectedly has an installation identity.' }
    $preState = Read-InstallationState
    if ($preState.state -ne 'legacy_upgrade_incomplete' -or $preState.ready) {
        throw "Expected fail-closed legacy_upgrade_incomplete; observed $($preState.state)."
    }
    $preFingerprint = Read-SchemaFingerprint
    if ($preFingerprint -ne 'ee76b3cbe9969031141613e24fc345777983817be132d4b78950e2a5fa86754a') {
        throw "Restored legacy prefix[5] fingerprint differs from the pinned reference: $preFingerprint"
    }
    Write-Host "Restored pre-adoption prefix[5] sha256=$preFingerprint"
    Assert-ClaimColumns -Before $targetClaimColumns -After (Read-ClaimColumns) -Legacy -CrossVersion
    $preDelivery = Read-DeliveryCounts
    if ($preDelivery -ne '0|0|0|0') { throw "Unexpected queued delivery fixture before adoption: $preDelivery" }
    $workerProbe = @(& docker compose @script:ComposeOptions run --rm --no-deps worker 2>&1)
    if ($LASTEXITCODE -eq 0 -or ($workerProbe -join "`n") -notmatch 'legacy_upgrade_incomplete') {
        throw "Unadopted worker did not fail closed as expected; exit=$LASTEXITCODE; output=$($workerProbe -join ' ')"
    }
    if ((Read-LedgerSnapshot) -ne $restoredLedger -or (Read-IdentityCount) -ne 0 -or
        (Read-SchemaFingerprint) -ne $preFingerprint -or (Read-DeliveryCounts) -ne $preDelivery) {
        throw 'Fail-closed worker changed legacy ledger, identity, schema, or delivery state.'
    }

    Write-Step 'Adopting the exact 30-row legacy clone with the pinned authenticated candidate'
    $upgradeCode = @'
require "/var/www/html/src/Db.php";
require "/var/www/html/src/LegacyForwardUpgrader.php";
require "/var/www/html/tests/legacy-candidate-package.php";
$public = "/tmp/legacy-public";
if (!is_dir($public) && !mkdir($public, 0700, true)) { throw new RuntimeException("Cannot create isolated package staging directory."); }
$package = legacyCandidateOpen(
    "/tmp/legacy-package/syndicatum-v1.0.0.zip",
    "/tmp/legacy-package/syndicatum-v1.0.0.manifest.json",
    "/tmp/legacy-package/syndicatum-v1.0.0.provenance.json",
    "/var/lib/syndicatum/staging",
    $public
);
$upgrader = new LegacyForwardUpgrader(Db::pdo(), $package, getenv("PBB_AGENTCHAT_DB_NAME"));
$preflight = $upgrader->preflight();
if ($preflight["complete"] || $preflight["prefix"] !== 5 || $preflight["pending"] !== []) {
    throw new RuntimeException("Authenticated upgrade preflight did not match the full 30-row ledger.");
}
$result = $upgrader->execute();
if (!$result["changed"] || $result["executed"] !== []) {
    throw new RuntimeException("Authenticated bridge did not preserve all 30 migration rows.");
}
echo json_encode(["preflight" => $preflight, "result" => $result], JSON_THROW_ON_ERROR), "\n";
'@
    $upgradeOutput = Invoke-Compose -Arguments @('run', '--rm', '--no-deps', '-v', "${candidatePackagePath}:/tmp/legacy-package:ro", '-v', "${testHelpersPath}:/var/www/html/tests:ro", '--entrypoint', 'php', 'app', '-r', $upgradeCode) -Capture
    if ($upgradeOutput -notmatch '"changed":true') { throw "Authenticated upgrade did not confirm the bridge: $upgradeOutput" }
    if ((Read-LedgerSnapshot) -ne $restoredLedger) { throw 'Authenticated bridge changed the 30-row migration ledger.' }
    Assert-ClaimColumns -Before $targetClaimColumns -After (Read-ClaimColumns) -CrossVersion
    if ((Read-ClaimRowDigest) -ne $claimRowsBefore) { throw 'Authenticated claim-column bridge changed rows or values.' }
    if ((Read-IdentityCount) -ne 1) { throw 'Authenticated bridge did not create exactly one installation identity table.' }
    $postState = Read-InstallationState
    if ($postState.state -ne 'legacy_upgraded_ready' -or -not $postState.ready) {
        throw "Authenticated bridge did not reach legacy_upgraded_ready; observed $($postState.state)."
    }
    $checkQuery = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --raw --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name=''project_participants'' AND constraint_name=''chk_project_participants_identity'' AND constraint_type=''CHECK''"'
    if ([int](Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $checkQuery) -Capture).Trim() -ne 1) {
        throw 'Authenticated bridge did not install the participant identity CHECK.'
    }
    if ((Read-DeliveryCounts) -ne $preDelivery) { throw 'Authenticated bridge replayed or queued delivery work.' }

    Write-Step 'Starting the adopted application and worker under their unchanged healthchecks'
    Invoke-Compose -Arguments @('up', '--build', '--detach', '--wait', '--wait-timeout', $StartupTimeoutSeconds.ToString(), 'app', 'worker')
    if ((Read-LedgerSnapshot) -ne $restoredLedger) { throw 'App/worker startup changed the 30-row migration ledger.' }
    if ((Read-DeliveryCounts) -ne $preDelivery) { throw 'App/worker startup replayed stale delivery work.' }
    $targetState = (Invoke-Compose -Arguments @('exec', '-T', 'db', 'sh', '-lc', $stateSql) -Capture).Trim()
    if ($targetState -ne $expectedState) { throw "Restored 8.4 fixture state differs: $targetState" }
    $health = Invoke-RestMethod -Uri "http://127.0.0.1:$HttpPort/api/v1/health.php" -TimeoutSec 15
    if ($health.data.core.status -ne 'ok' -or -not $health.data.core.database -or -not $health.data.core.expanded_schema) {
        throw 'Application health failed after restoring the 5.7 export into MySQL 8.4.'
    }
    Write-Host "MySQL migration acceptance passed: $sourceVersion export restored into $targetVersion with $targetState and healthy application behavior."
} catch {
    $failure = $_
    Write-Step 'Capturing isolated migration container diagnostics before cleanup'
    try { Invoke-Compose -Arguments @('ps', '--all') } catch { Write-Warning $_ }
    foreach ($service in @('worker', 'app', 'db')) {
        $container = "${projectName}-${service}-1"
        try { Invoke-Docker -Arguments @('inspect', '--format', '{{json .State}}', $container) } catch { Write-Warning $_ }
        try { Invoke-Compose -Arguments @('logs', '--no-color', '--timestamps', $service) } catch { Write-Warning $_ }
    }
    try { Invoke-Docker -Arguments @('inspect', '--format', '{{json .Config.Healthcheck}}', "${projectName}-worker-1") } catch { Write-Warning $_ }
    try { Invoke-Compose -Arguments @('exec', '-T', 'app', 'php', 'scripts/chat-db.php', 'installation-status') } catch { Write-Warning $_ }
    throw $failure
} finally {
    Write-Step "Removing isolated migration project $projectName and its volumes"
    try { Invoke-Compose -Arguments @('down', '--volumes', '--remove-orphans') } catch { Write-Warning $_ }
    if (Test-Path -LiteralPath $environmentPath) { Remove-Item -LiteralPath $environmentPath -Force }
    if (Test-Path -LiteralPath $dumpPath) { Remove-Item -LiteralPath $dumpPath -Force }
    if (Test-Path -LiteralPath $backupKeyPath) { Remove-Item -LiteralPath $backupKeyPath -Force }
    if (Test-Path -LiteralPath $legacySourcePath) { Remove-Item -LiteralPath $legacySourcePath -Recurse -Force }
}
