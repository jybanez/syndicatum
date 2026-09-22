<?php

require_once dirname(__DIR__) . '/src/FullSnapshotProducer.php';
require_once dirname(__DIR__) . '/src/BackupKeyFile.php';
require_once dirname(__DIR__) . '/src/Db.php';

function snapshotCreateFail($message) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function snapshotCreateOption(array $options, $name) {
    return isset($options[$name]) && is_string($options[$name]) && trim($options[$name]) !== '' ? $options[$name] : null;
}

try {
    $options = getopt('', ['output:', 'staging-root:', 'asset-root:', 'confirm-quiesced-source:']);
    $output = snapshotCreateOption($options, 'output');
    $staging = snapshotCreateOption($options, 'staging-root');
    $assets = snapshotCreateOption($options, 'asset-root');
    if ($output === null || $staging === null || $assets === null
        || snapshotCreateOption($options, 'confirm-quiesced-source') !== 'yes') {
        snapshotCreateFail('Usage: php scripts/create-full-snapshot.php --output=/private/backup.syndicatum-backup --staging-root=/private/staging --asset-root=/private/avatars --confirm-quiesced-source=yes');
    }
    $root = dirname(__DIR__);
    $metadata = json_decode(file_get_contents($root . '/schema/mysql84/baseline.json'), true);
    if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) { throw new RuntimeException('Trusted baseline metadata is unavailable.'); }
    $secrets = ['PBB_AGENTCHAT_SECRET' => Db::secretValue('PBB_AGENTCHAT_SECRET'),
        'SYNDICATUM_MASTER_KEY' => Db::secretValue('SYNDICATUM_MASTER_KEY')];
    $previous = Db::secretValue('PBB_AGENTCHAT_PREVIOUS_SECRET');
    if (is_string($previous) && $previous !== '') { $secrets['PBB_AGENTCHAT_PREVIOUS_SECRET'] = $previous; }
    $result = (new FullSnapshotProducer(Db::pdo(), BaselineMetadata::fromArray($metadata), $staging, $assets, $root))
        ->produce($output, BackupKeyFile::loadFromEnvironment(), $secrets);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) { snapshotCreateFail('Full-snapshot creation failed: ' . $error->getMessage()); }
