<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once dirname(__DIR__) . '/src/CurrentBackupWorker.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/PrivateStorage.php';

try {
    $root = dirname(__DIR__);
    $avatarConfigured = getenv('SYNDICATUM_AVATAR_DIR');
    $avatarRoot = is_string($avatarConfigured) && trim($avatarConfigured) !== ''
        ? $avatarConfigured : PrivateStorage::file('syndicatum-avatars');
    $settings = new SettingsService(Db::pdo());
    $storage = new CurrentBackupStorage($root, $settings->get('recovery.backup_base_path'));
    $jobs = new CurrentBackupJobStore($storage);
    $realtime = new RealtimeIntegration($settings);
    $processed = (new CurrentBackupWorker($storage, $jobs, $root, $avatarRoot, $realtime))->runPending();
    echo 'Processed backup jobs: ' . $processed . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Backup worker failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
