<?php

require_once dirname(__DIR__) . '/src/BackupKeyFile.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/StagedBackupRestore.php';

function restoreCliFail($message, $code = 64) { fwrite(STDERR, $message . PHP_EOL); exit($code); }
function restoreCliOption(array $options, $name, $default = null) { return isset($options[$name]) && is_string($options[$name]) && trim($options[$name]) !== '' ? $options[$name] : $default; }

try {
    $options = getopt('', ['input:', 'staging-root::', 'public-root::', 'baseline::']);
    $input = restoreCliOption($options, 'input');
    if ($input === null) { restoreCliFail('Usage: php scripts/restore-encrypted-backup.php --input=/private/path/file.syndicatum-backup'); }
    $root = dirname(__DIR__);
    $staging = restoreCliOption($options, 'staging-root', getenv('SYNDICATUM_STAGING_DIR') ?: '/var/lib/syndicatum/staging');
    $public = restoreCliOption($options, 'public-root', $root);
    $baselinePath = restoreCliOption($options, 'baseline', $root . '/schema/mysql84/baseline.json');
    $baselineJson = file_get_contents($baselinePath);
    $baselineArray = is_string($baselineJson) ? json_decode($baselineJson, true) : null;
    if (!is_array($baselineArray) || json_last_error() !== JSON_ERROR_NONE) { throw new RuntimeException('Trusted baseline metadata could not be loaded.'); }
    $targetSecrets = [
        'PBB_AGENTCHAT_SECRET' => Db::secretValue('PBB_AGENTCHAT_SECRET'),
        'SYNDICATUM_MASTER_KEY' => Db::secretValue('SYNDICATUM_MASTER_KEY'),
    ];
    $previous = Db::secretValue('PBB_AGENTCHAT_PREVIOUS_SECRET');
    if (is_string($previous) && trim($previous) !== '') { $targetSecrets['PBB_AGENTCHAT_PREVIOUS_SECRET'] = $previous; }
    $result = (new StagedBackupRestore(new PdoBackupRestoreTarget(Db::pdo()), BaselineMetadata::fromArray($baselineArray), $staging, $public))
        ->restore($input, BackupKeyFile::loadFromEnvironment(), $targetSecrets);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    restoreCliFail('Staged encrypted backup restore failed: ' . $exception->getMessage(), 1);
}
