<?php

/** Offline clone only. Encrypted result stays on an ephemeral container tmpfs. */
require_once dirname(__DIR__) . '/src/InstallationState.php';
require_once dirname(__DIR__) . '/src/BackupProducer.php';
require_once dirname(__DIR__) . '/src/StagedBackupRestore.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbb_agentchat;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'pbb_agentchat') {
    throw new RuntimeException('Backup probe is not connected to the disposable upgraded clone.');
}
$status = (new InstallationState($pdo, '/repo/schema/mysql84/baseline.json'))->assertReady();
if ($status['state'] !== 'legacy_upgraded_ready') {
    throw new RuntimeException('Backup probe requires trusted legacy-upgraded readiness.');
}
$baselineArray = json_decode(file_get_contents('/repo/schema/mysql84/baseline.json'), true);
$baseline = BaselineMetadata::fromArray($baselineArray);
$source = new PdoBackupDatabaseSource($pdo);
$target = new PdoBackupRestoreTarget($pdo);
$names = array_column($baseline->tablePolicies(), 'name');
sort($names, SORT_STRING);
if ($source->tableNames() !== $names || $target->tableNames() !== $names) {
    throw new RuntimeException('Backup or restore schema table inventory differs from baseline.');
}
foreach ($baseline->tablePolicies() as $table) {
    if ($source->columns($table['name']) !== $table['columns']
        || $target->columns($table['name']) !== $table['columns']) {
        throw new RuntimeException('Unchanged Backup/Restore column guard rejected ' . $table['name']);
    }
}
foreach (['temporary', 'avatars', 'output'] as $directory) {
    if (!mkdir('/private/' . $directory, 0700)) {
        throw new RuntimeException('Ephemeral backup probe directory is unavailable.');
    }
}
$result = (new BackupProducer($source, $baseline, '/private/temporary', '/private/avatars', '/repo'))
    ->produce('/private/output/clone.syndicatum-backup', random_bytes(32), [
        'application_version' => $baselineArray['application_version'],
        'source_commit' => $status['identity']['release_source_commit'],
        'schema_baseline' => $baselineArray['baseline_id'],
        'schema_head' => $baselineArray['schema_head'],
    ], [
        'PBB_AGENTCHAT_SECRET' => bin2hex(random_bytes(32)),
        'SYNDICATUM_MASTER_KEY' => bin2hex(random_bytes(32)),
    ]);
echo json_encode([
    'readiness' => $status['state'],
    'backup_produced' => true,
    'backup_source_columns' => 'match',
    'restore_target_columns' => 'match',
    'tables' => count($names),
    'envelope_sha256' => $result['envelope_sha256'],
], JSON_UNESCAPED_SLASHES) . "\n";
