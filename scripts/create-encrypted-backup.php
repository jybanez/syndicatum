<?php

require_once dirname(__DIR__) . '/src/BackupKeyFile.php';
require_once dirname(__DIR__) . '/src/BackupProducer.php';
require_once dirname(__DIR__) . '/src/Db.php';

function backupCliFail($message, $code = 64) { fwrite(STDERR, $message . PHP_EOL); exit($code); }
function backupCliOption(array $options, $name, $default = null) { return isset($options[$name]) && is_string($options[$name]) && trim($options[$name]) !== '' ? $options[$name] : $default; }

try {
    $options = getopt('', ['output:', 'staging-root::', 'asset-root::', 'public-root::', 'baseline::']);
    $output = backupCliOption($options, 'output');
    if ($output === null) { backupCliFail('Usage: php scripts/create-encrypted-backup.php --output=/private/path/file.syndicatum-backup'); }
    $root = dirname(__DIR__);
    $staging = backupCliOption($options, 'staging-root', getenv('SYNDICATUM_STAGING_DIR') ?: '/var/lib/syndicatum/staging');
    $assets = backupCliOption($options, 'asset-root', getenv('SYNDICATUM_AVATAR_DIR') ?: '/var/lib/syndicatum/avatars');
    $public = backupCliOption($options, 'public-root', $root);
    $baselinePath = backupCliOption($options, 'baseline', $root . '/schema/mysql84/baseline.json');
    $baselineJson = file_get_contents($baselinePath);
    $baselineArray = is_string($baselineJson) ? json_decode($baselineJson, true) : null;
    if (!is_array($baselineArray) || json_last_error() !== JSON_ERROR_NONE) { throw new RuntimeException('Trusted baseline metadata could not be loaded.'); }
    $baseline = BaselineMetadata::fromArray($baselineArray);
    $pdo = Db::pdo();
    $identity = $pdo->query('SELECT application_version, release_source_commit AS source_commit, schema_baseline, schema_head FROM syndicatum_installation_identity WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identity)) { throw new RuntimeException('Installed release identity is missing.'); }
    $exportedSecrets = [
        'PBB_AGENTCHAT_SECRET' => Db::secretValue('PBB_AGENTCHAT_SECRET'),
        'SYNDICATUM_MASTER_KEY' => Db::secretValue('SYNDICATUM_MASTER_KEY'),
    ];
    $previous = Db::secretValue('PBB_AGENTCHAT_PREVIOUS_SECRET');
    if (is_string($previous) && trim($previous) !== '') { $exportedSecrets['PBB_AGENTCHAT_PREVIOUS_SECRET'] = $previous; }
    $result = (new BackupProducer(new PdoBackupDatabaseSource($pdo), $baseline, $staging, $assets, $public))
        ->produce($output, BackupKeyFile::loadFromEnvironment(), $identity, $exportedSecrets);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    backupCliFail('Encrypted backup creation failed: ' . $exception->getMessage(), 1);
}
