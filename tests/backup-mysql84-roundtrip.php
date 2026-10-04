<?php

require_once dirname(__DIR__) . '/src/BaselineInstaller.php';
require_once dirname(__DIR__) . '/src/BackupProducer.php';
require_once dirname(__DIR__) . '/src/StagedBackupRestore.php';
require_once dirname(__DIR__) . '/src/McpServiceTokenService.php';
require_once dirname(__DIR__) . '/src/CurrentBackupProducer.php';
require_once dirname(__DIR__) . '/src/CurrentBackupStorage.php';
require_once dirname(__DIR__) . '/src/CurrentRestoreWorker.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';

function mysql84BackupFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function mysql84BackupAssert($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

function mysql84BackupPdo($database = null)
{
    $host = getenv('PBB_AGENTCHAT_DB_HOST') ?: '127.0.0.1';
    $port = getenv('PBB_AGENTCHAT_DB_PORT') ?: '3306';
    $user = getenv('PBB_AGENTCHAT_DB_USER') ?: 'root';
    $password = getenv('PBB_AGENTCHAT_DB_PASS');
    if ($password === false) { $password = ''; }
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ($database === null ? '' : ';dbname=' . $database) . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

function mysql84BackupIdentity(array $baseline, $releaseCommit, $packageHash, $installationId)
{
    return [
        'application_version' => $baseline['application_version'],
        'schema_baseline' => $baseline['baseline_id'],
        'schema_head' => $baseline['schema_head'],
        'baseline_source_commit' => $baseline['source_commit'],
        'release_source_commit' => $releaseCommit,
        'package_sha256' => $packageHash,
        'package_format_version' => '1.0',
        'installation_id' => $installationId,
        'installed_at' => '2026-09-20T00:00:00Z',
    ];
}

function mysql84BackupRemoveTree($path)
{
    if (!is_dir($path)) { return; }
    $items = scandir($path);
    if (!is_array($items)) { throw new RuntimeException('Could not enumerate disposable backup test root.'); }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') { continue; }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_link($child) || is_file($child)) { unlink($child); }
        elseif (is_dir($child)) { mysql84BackupRemoveTree($child); }
    }
    rmdir($path);
}

function mysql84BackupPort()
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    if (!$socket) { throw new RuntimeException('Could not reserve public-link acceptance port: ' . $message); }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($name, ':'), 1);
}

function mysql84BackupPublicServer($applicationRoot, $router, $port, $database)
{
    $environment = getenv();
    if (!is_array($environment)) { $environment = []; }
    $environment['PBB_AGENTCHAT_DB_HOST'] = getenv('PBB_AGENTCHAT_DB_HOST') ?: '127.0.0.1';
    $environment['PBB_AGENTCHAT_DB_PORT'] = getenv('PBB_AGENTCHAT_DB_PORT') ?: '3306';
    $environment['PBB_AGENTCHAT_DB_USER'] = getenv('PBB_AGENTCHAT_DB_USER') ?: 'root';
    $environment['PBB_AGENTCHAT_DB_PASS'] = getenv('PBB_AGENTCHAT_DB_PASS') === false ? '' : getenv('PBB_AGENTCHAT_DB_PASS');
    $environment['PBB_AGENTCHAT_DB_NAME'] = $database;
    $log = tempnam(sys_get_temp_dir(), 'syndicatum-public-link-');
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $applicationRoot, $router], [
        0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a'],
    ], $pipes, $applicationRoot, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Could not start public-link acceptance server.'); }
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2);
        if (is_resource($socket)) { fclose($socket); return [$process, $log]; }
    }
    proc_terminate($process);
    throw new RuntimeException('Public-link acceptance server failed: ' . (is_file($log) ? file_get_contents($log) : ''));
}

function mysql84BackupPublicGet($url)
{
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $bytes = file_get_contents($url, false, $context);
    $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
    $status = isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches) ? (int) $matches[1] : 0;
    if (!is_string($bytes)) { throw new RuntimeException('Public-link acceptance request failed.'); }
    return ['status' => $status, 'headers' => $headers, 'bytes' => $bytes];
}

final class Mysql84RestoreRealtime
{
    public $updates = [];
    public function publishRestoreUpdated(array $restore) { $this->updates[] = $restore; }
}

if (DIRECTORY_SEPARATOR !== '/') {
    mysql84BackupFail('MySQL 8.4 backup round-trip requires the POSIX controlled-extraction implementation.');
}

$root = dirname(__DIR__);
$baselinePath = $root . '/schema/mysql84/baseline.json';
$schemaPath = $root . '/schema/mysql84/schema.sql';
$baselineArray = json_decode((string) file_get_contents($baselinePath), true);
if (!is_array($baselineArray) || json_last_error() !== JSON_ERROR_NONE) { mysql84BackupFail('Baseline metadata is invalid.'); }
$baseline = BaselineMetadata::fromArray($baselineArray);
$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$sourceName = 'syndicatum_backup_source_' . $suffix;
$targetName = 'syndicatum_backup_target_' . $suffix;
$temporaryRoot = sys_get_temp_dir() . '/syndicatum-backup-mysql84-' . $suffix;
$server = null;
$assetStage = null;
$publicServer = null;
$publicServerLog = null;

try {
    $server = mysql84BackupPdo();
    foreach ([$sourceName, $targetName] as $database) {
        $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $source = mysql84BackupPdo($sourceName);
    $target = mysql84BackupPdo($targetName);
    $sourceIdentity = mysql84BackupIdentity($baselineArray, str_repeat('a', 40), str_repeat('1', 64), '11111111-1111-4111-8111-111111111111');
    $targetIdentity = mysql84BackupIdentity($baselineArray, str_repeat('b', 40), str_repeat('2', 64), '22222222-2222-4222-8222-222222222222');
    (new BaselineInstaller($source, $schemaPath, $baselinePath))->install($sourceIdentity);
    (new BaselineInstaller($target, $schemaPath, $baselinePath))->install($targetIdentity);
    $target->exec('SET FOREIGN_KEY_CHECKS = 0');
    $target->exec('TRUNCATE TABLE project_template_agents');
    $target->exec('TRUNCATE TABLE project_templates');
    $target->exec('TRUNCATE TABLE project_template_categories');
    $target->exec('SET FOREIGN_KEY_CHECKS = 1');

    $now = '2026-09-20 00:00:00';
    $future = '2027-09-20 00:00:00';
    $avatarName = str_repeat('f', 40) . '.png';
    $avatarUrl = 'api/v1/avatar.php?file=' . $avatarName;
    $source->exec("INSERT INTO users (id, normalized_email, username, password_hash, display_name, avatar_url, status, created_at, updated_at) VALUES (100, 'backup@example.test', 'backup-owner', 'fixture-hash', 'Backup Owner', '$avatarUrl', 'active', '$now', '$now')");
    $source->exec("INSERT INTO workspaces (id, owner_user_id, name, created_at, updated_at) VALUES (200, 100, 'Recovered workspace', '$now', '$now')");
    $source->exec("INSERT INTO projects (id, public_id, workspace_id, owner_user_id, name, slug, status, created_at, updated_at) VALUES (300, '33333333-3333-4333-8333-333333333333', 200, 100, 'Recovered project', 'recovered-project', 'active', '$now', '$now')");
    $source->exec("INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES (300, 100, 'owner', 'active', '$now', '$now')");
    $source->exec("INSERT INTO chat_agents (id, project_name, role, is_active, created_at, updated_at) VALUES (400, 'Recovered Agent', 'agent', 1, '$now', '$now')");
    $source->exec("INSERT INTO project_agents (project_id, agent_id, display_name, provider, status, created_at, updated_at) VALUES (300, 400, 'Recovered Agent', 'codex', 'active', '$now', '$now')");
    $source->exec("INSERT INTO project_participants (id, project_id, kind, user_id, agent_id, status, created_at, updated_at) VALUES (500, 300, 'human', 100, NULL, 'active', '$now', '$now'), (501, 300, 'agent', NULL, 400, 'active', '$now', '$now')");
    $source->exec("INSERT INTO messages (id, message_uuid, project_id, project_sequence, sender_participant_id, body, reply_depth, created_at, updated_at) VALUES (600, '55555555-5555-4555-8555-555555555555', 300, 1, 500, 'Durable backup fixture', 0, '$now', '$now')");
    $source->exec("INSERT INTO oauth_clients (client_id, client_name, redirect_uris_json, token_endpoint_auth_method, created_at, updated_at) VALUES ('backup-client', 'Backup client', '[\"https://example.test/callback\"]', 'none', '$now', '$now')");
    $source->exec("INSERT INTO system_settings (setting_key, value_json, encrypted_value, updated_by_user_id, updated_at) VALUES ('backup.fixture', '{\"enabled\":true}', 'encrypted-fixture-value', 100, '$now')");
    $administratorRole = (int) $source->query("SELECT id FROM system_roles WHERE code = 'administrator'")->fetchColumn();
    $source->exec("INSERT INTO user_system_roles (user_id, role_id, granted_by_user_id, created_at) VALUES (100, $administratorRole, 100, '$now')");
    $source->exec('ALTER TABLE users AUTO_INCREMENT = 1001');

    $source->exec("INSERT INTO syndicatum_sessions (user_id, token_hash, csrf_token_hash, auth_provider, created_at, last_seen_at, expires_at) VALUES (100, '" . str_repeat('a', 64) . "', '" . str_repeat('b', 64) . "', 'native', '$now', '$now', '$future')");
    $source->exec("INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, resource_uri, scope_text, created_at, expires_at) VALUES ('" . str_repeat('c', 64) . "', 'backup-client', 100, 'https://example.test/resource', 'read', '$now', '$future')");
    $oldMcpToken = 'fixture-old-mcp-token';
    $source->exec("INSERT INTO mcp_service_tokens (token_hash, project_id, agent_id, created_by_user_id, created_at) VALUES ('" . hash('sha256', $oldMcpToken) . "', 300, 400, 100, '$now')");
    $source->exec("INSERT INTO connector_devices (id, user_id, display_name, platform, token_prefix, token_hash, created_at, last_seen_at, expires_at) VALUES ('66666666-6666-4666-8666-666666666666', 100, 'Fixture device', 'test', 'fixture-prefix', '" . str_repeat('e', 64) . "', '$now', '$now', '$future')");
    $source->exec("INSERT INTO connector_device_activation_routes (device_id, agent_id, project_id, runtime_type, conversation_id, working_directory, enabled, created_at, updated_at) VALUES ('66666666-6666-4666-8666-666666666666', 400, 300, 'codex', 'fixture-conversation', '/fixture', 1, '$now', '$now')");
    $source->exec("INSERT INTO message_events_outbox (event_uuid, project_id, message_id, event_type, payload_json, attempt_count, available_at, created_at) VALUES ('44444444-4444-4444-8444-444444444444', 300, 600, 'fixture', '{}', 0, '$now', '$now')");
    $source->exec("INSERT INTO agent_webhook_deliveries (delivery_uuid, project_id, message_id, agent_id, event_type, payload_json, status, attempt_count, next_attempt_at, created_at) VALUES ('77777777-7777-4777-8777-777777777777', 300, 600, 400, 'fixture', '{}', 'queued', 0, '$now', '$now')");
    $source->exec("INSERT INTO responses_api_deliveries (delivery_uuid, project_id, message_id, agent_id, status, attempt_count, next_attempt_at, created_at) VALUES ('88888888-8888-4888-8888-888888888888', 300, 600, 400, 'queued', 0, '$now', '$now')");
    $source->exec("INSERT INTO workspace_agent_trigger_deliveries (delivery_uuid, project_id, message_id, agent_id, status, attempt_count, next_attempt_at, created_at) VALUES ('99999999-9999-4999-8999-999999999999', 300, 600, 400, 'queued', 0, '$now', '$now')");

    foreach (['staging', 'assets', 'public', 'backups', 'project-source', 'project-target', 'current-target-assets'] as $directory) {
        $path = $temporaryRoot . '/' . $directory;
        if (!mkdir($path, 0700, true) && !is_dir($path)) { throw new RuntimeException('Could not create private backup acceptance directory.'); }
        chmod($path, 0700);
    }
    $avatarBytes = "\x89PNG\x0d\x0a\x1a\x0a" . str_repeat('asset-fidelity', 32);
    file_put_contents($temporaryRoot . '/assets/' . $avatarName, $avatarBytes);
    chmod($temporaryRoot . '/assets/' . $avatarName, 0600);
    $backupPath = $temporaryRoot . '/backups/roundtrip.syndicatum-backup';
    $key = random_bytes(32);
    $portableSecrets = ['PBB_AGENTCHAT_SECRET' => str_repeat('h', 32), 'SYNDICATUM_MASTER_KEY' => str_repeat('m', 32)];
    $producerIdentity = [
        'application_version' => $baselineArray['application_version'],
        'source_commit' => $sourceIdentity['release_source_commit'],
        'schema_baseline' => $baselineArray['baseline_id'],
        'schema_head' => $baselineArray['schema_head'],
    ];
    $produced = (new BackupProducer(new PdoBackupDatabaseSource($source), $baseline,
        $temporaryRoot . '/staging', $temporaryRoot . '/assets', $temporaryRoot . '/public',
        function () { return '2026-09-20T00:00:00Z'; }))
        ->produce($backupPath, $key, $producerIdentity, $portableSecrets);

    mysql84BackupAssert(is_file($backupPath) && filesize($backupPath) > 0, 'Encrypted backup envelope was not produced.');
    mysql84BackupAssert(!is_file($backupPath . '.zip') && !is_file($backupPath . '.manifest.json'), 'Portable plaintext backup artifact was emitted.');
    foreach (['syndicatum_sessions', 'oauth_access_tokens', 'mcp_service_tokens', 'connector_devices',
              'connector_device_activation_routes', 'message_events_outbox', 'agent_webhook_deliveries',
              'responses_api_deliveries', 'workspace_agent_trigger_deliveries'] as $table) {
        mysql84BackupAssert(!array_key_exists($table, $produced['row_counts']), 'Reset table was exported as durable data: ' . $table);
    }

    $restored = (new StagedBackupRestore(new PdoBackupRestoreTarget($target), $baseline,
        $temporaryRoot . '/staging', $temporaryRoot . '/public'))
        ->restore($backupPath, $key, $portableSecrets);
    $assetStage = $restored['asset_stage_path'];
    mysql84BackupAssert($restored['cutover_performed'] === false, 'Staged restore performed an automatic cutover.');
    $restoredAvatar = $assetStage . '/' . $avatarName;
    mysql84BackupAssert(is_file($restoredAvatar) && hash_file('sha256', $restoredAvatar) === hash('sha256', $avatarBytes), 'Persistent avatar asset did not round-trip exactly.');
    foreach (['users' => 1, 'workspaces' => 1, 'projects' => 1, 'oauth_clients' => 1, 'system_settings' => 1, 'user_system_roles' => 1] as $table => $count) {
        mysql84BackupAssert((int) $target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === $count, 'Durable table did not round-trip: ' . $table);
    }
    $resetTables = [];
    $excludedTables = [];
    foreach ($baselineArray['tables'] as $tablePolicy) {
        if ($tablePolicy['backup_policy'] === 'reset') { $resetTables[] = $tablePolicy['name']; }
        if ($tablePolicy['backup_policy'] === 'excluded') { $excludedTables[] = $tablePolicy['name']; }
    }
    sort($resetTables, SORT_STRING);
    sort($excludedTables, SORT_STRING);
    mysql84BackupAssert(count($resetTables) === 17 && count($excludedTables) === 3, 'Closed table-policy counts changed unexpectedly.');
    foreach ($resetTables as $table) {
        mysql84BackupAssert((int) $target->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, 'Reset table was revived: ' . $table);
    }
    $mcpTokens = new McpServiceTokenService($target);
    mysql84BackupAssert($mcpTokens->authenticate($oldMcpToken) === null, 'Pre-backup MCP bearer token remained valid after restore.');
    $newMcpToken = $mcpTokens->issue(300, 400, 100);
    mysql84BackupAssert(is_array($mcpTokens->authenticate($newMcpToken)), 'A replacement MCP service token could not be issued and authenticated.');
    mysql84BackupAssert($target->query("SELECT client_name FROM oauth_clients WHERE client_id = 'backup-client'")->fetchColumn() === 'Backup client', 'OAuth client configuration was not recovered.');
    mysql84BackupAssert((int) $target->query('SELECT COUNT(*) FROM system_roles')->fetchColumn() === 2, 'Target-local system roles were not preserved.');
    mysql84BackupAssert($target->query('SELECT installation_id FROM syndicatum_installation_identity WHERE singleton_id = 1')->fetchColumn() === $targetIdentity['installation_id'], 'Target-local installation identity was overwritten.');
    mysql84BackupAssert((int) $target->query('SELECT COUNT(*) FROM syndicatum_schema_migrations')->fetchColumn() === count($baselineArray['post_baseline_migrations']), 'Target-local migration ledger changed.');
    $nextUserId = (int) $target->query("SELECT auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
    mysql84BackupAssert($nextUserId === 1001, 'Durable AUTO_INCREMENT state above MAX(id) was not preserved.');

    // Exercise the production format-3 producer and in-app restore worker with a real
    // Project File object. This is deliberately separate from the legacy round trip
    // above so the compatibility evidence remains independently meaningful.
    $projectBytes = str_repeat('portable-project-file-', 131072);
    $projectSha256 = hash('sha256', $projectBytes);
    $projectPublicId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $projectStorageKey = 'objects/33333333-3333-4333-8333-333333333333/'
        . substr($projectSha256, 0, 2) . '/' . $projectSha256;
    $projectSourcePath = $temporaryRoot . '/project-source/' . str_replace('/', DIRECTORY_SEPARATOR, $projectStorageKey);
    if (!mkdir(dirname($projectSourcePath), 0700, true) && !is_dir(dirname($projectSourcePath))) {
        throw new RuntimeException('Could not create Project File source directory.');
    }
    file_put_contents($projectSourcePath, $projectBytes);
    chmod($projectSourcePath, 0600);
    $source->exec("INSERT INTO project_file_folders (id, public_id, project_id, parent_folder_id, name, normalized_name, root_marker, created_by_participant_id, version, created_at, updated_at, deleted_at) VALUES (700, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 300, NULL, 'Recovered project', 'recovered project', 1, 500, 1, '$now', '$now', NULL)");
    $projectInsert = $source->prepare("INSERT INTO project_files (id, public_id, project_id, folder_id, storage_driver, storage_key, original_name, display_name, normalized_name, mime_type, size_bytes, sha256, uploaded_by_participant_id, state, version, created_at, updated_at, deleted_at) VALUES (701, ?, 300, 700, 'local', ?, 'recovery-proof.txt', 'recovery-proof.txt', 'recovery-proof.txt', 'text/plain', ?, ?, 500, 'available', 1, ?, ?, NULL)");
    $projectInsert->execute([$projectPublicId, $projectStorageKey, strlen($projectBytes), $projectSha256, $now, $now]);
    $source->exec("INSERT INTO message_file_attachments (id, project_id, message_id, project_file_id, attached_by_participant_id, position, created_at) VALUES (702, 300, 600, 701, 500, 0, '$now')");
    (new SettingsService($source))->update(['storage.local_base_path' => $temporaryRoot . '/project-source'], 100);

    $publicRouter = $temporaryRoot . '/public-link-router.php';
    file_put_contents($publicRouter, "<?php\n\$path = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);\n"
        . "if (preg_match('#^/files/([a-f0-9-]{36})$#i', (string) \$path, \$matches)) { \$_GET['id'] = \$matches[1]; require "
        . var_export($root . '/files.php', true) . "; return true; }\nreturn false;\n");
    $publicPort = mysql84BackupPort();
    list($publicServer, $publicServerLog) = mysql84BackupPublicServer($root, $publicRouter, $publicPort, $sourceName);
    $canonicalPath = '/files/' . $projectPublicId;
    $sourcePublic = mysql84BackupPublicGet('http://127.0.0.1:' . $publicPort . $canonicalPath);
    mysql84BackupAssert($sourcePublic['status'] === 200 && hash_equals($projectSha256, hash('sha256', $sourcePublic['bytes'])),
        'The pre-backup canonical public URL did not serve the Project File bytes.');
    proc_terminate($publicServer); proc_close($publicServer); $publicServer = null;
    if (is_file($publicServerLog)) { unlink($publicServerLog); } $publicServerLog = null;

    $secretsPath = $temporaryRoot . '/portable-secrets.php';
    $backupConfigPath = $temporaryRoot . '/portable-backup-config.php';
    file_put_contents($secretsPath, "<?php return ['PBB_AGENTCHAT_SECRET' => '" . str_repeat('s', 32)
        . "', 'SYNDICATUM_MASTER_KEY' => '" . str_repeat('m', 32) . "'];\n");
    file_put_contents($backupConfigPath, "<?php return ['SYNDICATUM_BACKUP_STORAGE' => 'acceptance'];\n");
    chmod($secretsPath, 0600); chmod($backupConfigPath, 0600);
    putenv('SYNDICATUM_SECRETS_FILE=' . $secretsPath);
    putenv('SYNDICATUM_BACKUP_CONFIG=' . $backupConfigPath);

    $currentStorage = new CurrentBackupStorage($root, $temporaryRoot . '/current-backup-storage');
    $currentOperationId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    $currentKey = random_bytes(32);
    $currentArtifact = $currentStorage->artifact($currentOperationId);
    $currentProduced = (new CurrentBackupProducer(
        $source,
        $currentStorage->stage(),
        $temporaryRoot . '/assets',
        $root,
        $temporaryRoot . '/project-source'
    ))->produce($currentArtifact, $currentKey, $currentOperationId);
    mysql84BackupAssert($currentProduced['project_file_count'] === 1, 'Current backup did not inventory the Project File object.');

    $authenticated = CurrentBackupEnvelope::decryptToPrivateStage($currentArtifact, $currentStorage->stage(), $currentKey);
    try {
        $currentManifest = PortableBackupManifest::parse($authenticated['manifest_json']);
        PortableBackupArchive::verify($authenticated['archive_path'], $currentManifest);
        mysql84BackupAssert($currentManifest['format_version'] === '3.0', 'Current backup did not emit portable format 3.');
        mysql84BackupAssert(count($currentManifest['project_files']) === 1, 'Current manifest Project File inventory differs.');
        $projectEntry = $currentManifest['project_files'][0];
        mysql84BackupAssert($projectEntry['public_id'] === $projectPublicId
            && $projectEntry['key'] === $projectStorageKey
            && $projectEntry['bytes'] === strlen($projectBytes)
            && hash_equals($projectSha256, $projectEntry['sha256']),
            'Current manifest did not preserve Project File identity and integrity metadata.');
    } finally {
        CurrentBackupEnvelope::removePrivateStage($authenticated['stage_path'], $currentStorage->stage());
    }

    $keyFile = $temporaryRoot . '/current-restore.key';
    file_put_contents($keyFile, base64_encode($currentKey));
    chmod($keyFile, 0600);
    $restoreJobs = new CurrentRestoreJobStore($currentStorage);
    $restoreJob = $restoreJobs->create(100, 'Backup Owner', 'mysql84-current-project-file-roundtrip',
        'existing', $currentOperationId, $currentArtifact, $keyFile);
    putenv('PBB_AGENTCHAT_DB_NAME=' . $targetName);
    $restoreRealtime = new Mysql84RestoreRealtime();
    $currentWorker = new CurrentRestoreWorker($currentStorage, $restoreJobs,
        $temporaryRoot . '/current-target-assets', $restoreRealtime, $root, $temporaryRoot . '/project-target');
    mysql84BackupAssert($currentWorker->runPending() === 1, 'Current restore worker did not claim the queued round trip.');
    $completedRestore = $restoreJobs->get($restoreJob['operation_id']);
    mysql84BackupAssert(is_array($completedRestore) && $completedRestore['status'] === 'Complete',
        'Current project-file restore did not complete: ' . (is_array($completedRestore) ? (string) $completedRestore['failure_summary'] : 'missing job'));
    mysql84BackupAssert($completedRestore['restored_project_file_count'] === 1,
        'Current restore evidence did not report the restored Project File count.');
    $restoreAudit = $target->prepare("SELECT metadata_json FROM administrative_audit_events WHERE action = 'restore.completed' AND subject_type = 'restore' AND subject_id = ? ORDER BY id DESC LIMIT 1");
    $restoreAudit->execute([$restoreJob['operation_id']]);
    $restoreAuditMetadata = json_decode((string) $restoreAudit->fetchColumn(), true);
    mysql84BackupAssert(is_array($restoreAuditMetadata)
        && (int) $restoreAuditMetadata['restored_project_file_count'] === 1
        && $restoreAuditMetadata['project_file_verification'] === 'count_size_sha256'
        && $restoreAuditMetadata['database_cutover'] === 'committed'
        && $restoreAuditMetadata['project_file_cutover'] === 'verified_exact_keys',
        'Current restore did not commit Project File verification and cutover audit evidence.');
    $restoredProjectPath = $temporaryRoot . '/project-target/' . str_replace('/', DIRECTORY_SEPARATOR, $projectStorageKey);
    mysql84BackupAssert(is_file($restoredProjectPath)
        && filesize($restoredProjectPath) === strlen($projectBytes)
        && hash_equals($projectSha256, hash_file('sha256', $restoredProjectPath)),
        'Current restore did not reproduce the exact Project File bytes at the provider-neutral key.');
    $restoredProject = $target->query("SELECT public_id, storage_driver, storage_key, size_bytes, sha256 FROM project_files WHERE id = 701")->fetch(PDO::FETCH_ASSOC);
    mysql84BackupAssert(is_array($restoredProject)
        && $restoredProject['public_id'] === $projectPublicId
        && $restoredProject['storage_driver'] === 'local'
        && $restoredProject['storage_key'] === $projectStorageKey
        && (int) $restoredProject['size_bytes'] === strlen($projectBytes)
        && hash_equals($projectSha256, $restoredProject['sha256']),
        'Current restore did not preserve Project File database identity and integrity metadata.');
    mysql84BackupAssert((int) $target->query('SELECT COUNT(*) FROM message_file_attachments WHERE message_id = 600 AND project_file_id = 701')->fetchColumn() === 1,
        'Current restore did not preserve the message attachment association.');
    (new SettingsService($target))->update(['storage.local_base_path' => $temporaryRoot . '/project-target'], 100);
    list($publicServer, $publicServerLog) = mysql84BackupPublicServer($root, $publicRouter, $publicPort, $targetName);
    $restoredPublic = mysql84BackupPublicGet('http://127.0.0.1:' . $publicPort . $canonicalPath);
    mysql84BackupAssert($restoredPublic['status'] === 200
        && hash_equals(hash('sha256', $sourcePublic['bytes']), hash('sha256', $restoredPublic['bytes']))
        && hash_equals($projectSha256, hash('sha256', $restoredPublic['bytes'])),
        'The exact canonical public URL did not serve byte-identical content after restore.');
    proc_terminate($publicServer); proc_close($publicServer); $publicServer = null;

    echo json_encode([
        'mysql_version' => $target->query('SELECT VERSION()')->fetchColumn(),
        'baseline_id' => $baselineArray['baseline_id'],
        'baseline_metadata_sha256' => hash_file('sha256', $baselinePath),
        'table_count' => count($baselineArray['tables']),
        'policy_counts' => ['durable' => 47, 'reset' => 17, 'excluded' => 3],
        'envelope_sha256' => $produced['envelope_sha256'],
        'archive_sha256' => $produced['archive_sha256'],
        'manifest_sha256' => $produced['manifest_sha256'],
        'content_tree_sha256' => $produced['content_tree_sha256'],
        'durable_row_counts' => $produced['row_counts'],
        'persistent_asset_count' => $produced['asset_count'],
        'persistent_asset_sha256' => hash('sha256', $avatarBytes),
        'durable_fidelity' => true,
        'reset_tables' => $resetTables,
        'all_reset_tables_empty' => true,
        'delivery_outbox_replay_possible' => false,
        'excluded_tables' => $excludedTables,
        'all_excluded_tables_target_local' => true,
        'pre_backup_mcp_token_rejected' => true,
        'replacement_mcp_token_authenticated' => true,
        'next_users_auto_increment' => $nextUserId,
        'cutover_performed' => false,
        'current_project_file_format' => $currentManifest['format_version'],
        'current_project_file_count' => $completedRestore['restored_project_file_count'],
        'current_project_file_sha256' => $projectSha256,
        'current_project_file_audit_evidence' => true,
        'current_message_attachment_preserved' => true,
        'canonical_public_path' => $canonicalPath,
        'canonical_public_link_byte_identical' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    mysql84BackupFail('MySQL 8.4 encrypted-backup round-trip failed: ' . $exception->getMessage());
} finally {
    if (is_resource($publicServer)) { @proc_terminate($publicServer); @proc_close($publicServer); }
    if (is_string($publicServerLog) && is_file($publicServerLog)) { @unlink($publicServerLog); }
    if (is_string($assetStage) && is_dir($assetStage) && is_dir($temporaryRoot . '/staging')) {
        try { BackupEnvelope::removePrivateStage($assetStage, $temporaryRoot . '/staging'); } catch (Throwable $ignored) {}
    }
    if ($server instanceof PDO) {
        foreach ([$sourceName, $targetName] as $database) {
            try { $server->exec('DROP DATABASE IF EXISTS `' . $database . '`'); } catch (Throwable $ignored) {}
        }
    }
    if (is_dir($temporaryRoot)) { mysql84BackupRemoveTree($temporaryRoot); }
}
