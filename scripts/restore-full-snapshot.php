<?php

require_once dirname(__DIR__) . '/src/FullSnapshotRestore.php';
require_once dirname(__DIR__) . '/src/BackupKeyFile.php';
require_once dirname(__DIR__) . '/src/Db.php';

function snapshotRestoreFail($message) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function snapshotRestoreOption(array $options, $name) {
    return isset($options[$name]) && is_string($options[$name]) && trim($options[$name]) !== '' ? $options[$name] : null;
}

try {
    $options = getopt('', ['input:', 'target-host:', 'target-port:', 'target-db:', 'target-user:',
        'approve-empty-target:', 'staging-root:', 'target-asset-root:']);
    $input = snapshotRestoreOption($options, 'input');
    $host = snapshotRestoreOption($options, 'target-host');
    $port = snapshotRestoreOption($options, 'target-port');
    $database = snapshotRestoreOption($options, 'target-db');
    $user = snapshotRestoreOption($options, 'target-user');
    $approval = snapshotRestoreOption($options, 'approve-empty-target');
    $staging = snapshotRestoreOption($options, 'staging-root');
    $assets = snapshotRestoreOption($options, 'target-asset-root');
    if ($input === null || $host === null || $port === null || $database === null || $user === null
        || $approval === null || $staging === null || $assets === null) {
        snapshotRestoreFail('Usage: php scripts/restore-full-snapshot.php --input=/private/backup.syndicatum-backup --target-host=HOST --target-port=3306 --target-db=EMPTY_DB --target-user=USER --approve-empty-target=EMPTY_DB --staging-root=/private/staging --target-asset-root=/private/empty-avatars');
    }
    if (!preg_match('/\A[a-z0-9.-]+\z/i', $host) || !preg_match('/\A\d{1,5}\z/', $port)
        || (int) $port < 1 || (int) $port > 65535
        || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $database)
        || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $user)
        || !hash_equals($database, $approval)) {
        throw new InvalidArgumentException('Explicit empty-target connection or approval is invalid.');
    }
    $password = getenv('SYNDICATUM_RESTORE_TARGET_DB_PASS');
    if (!is_string($password)) { throw new InvalidArgumentException('SYNDICATUM_RESTORE_TARGET_DB_PASS must be set.'); }
    $pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $user, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $root = dirname(__DIR__);
    $metadata = json_decode(file_get_contents($root . '/schema/mysql84/baseline.json'), true);
    if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) { throw new RuntimeException('Trusted baseline metadata is unavailable.'); }
    $secrets = ['PBB_AGENTCHAT_SECRET' => Db::secretValue('PBB_AGENTCHAT_SECRET'),
        'SYNDICATUM_MASTER_KEY' => Db::secretValue('SYNDICATUM_MASTER_KEY')];
    $previous = Db::secretValue('PBB_AGENTCHAT_PREVIOUS_SECRET');
    if (is_string($previous) && $previous !== '') { $secrets['PBB_AGENTCHAT_PREVIOUS_SECRET'] = $previous; }
    $result = (new FullSnapshotRestore($pdo, BaselineMetadata::fromArray($metadata), $staging, $assets, $root, $database))
        ->restore($input, BackupKeyFile::loadFromEnvironment(), $secrets);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) { snapshotRestoreFail('Full-snapshot restore failed: ' . $error->getMessage()); }
