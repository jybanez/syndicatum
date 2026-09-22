<?php

require_once dirname(__DIR__) . '/src/FullSnapshotProducer.php';
require_once dirname(__DIR__) . '/src/FullSnapshotRestore.php';

foreach (['5.7.44', '8.0.36', '9.0.0', '10.11.6-MariaDB'] as $unsupported) {
    try {
        FullSnapshotSql::assertSupportedVersion($unsupported);
        throw new RuntimeException('Unsupported database version was accepted: ' . $unsupported);
    } catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), 'requires MySQL 8.4.x') === false) { throw $expected; }
    }
}
if (FullSnapshotSql::assertSupportedVersion('8.4.11') !== '8.4.11') {
    throw new RuntimeException('Supported MySQL version was rejected.');
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'full-snapshot-version-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('Version fixture root could not be created.'); }
try {
    foreach (['stage', 'source-assets', 'target-assets', 'output'] as $name) {
        if (!mkdir($root . DIRECTORY_SEPARATOR . $name, 0700)) { throw new RuntimeException('Version fixture directory could not be created.'); }
    }
    // The backend is intentionally a fake: VERSION() returns an unsupported MySQL
    // version, and all other database operations fail if preflight is too late.
    $fake = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $fake->sqliteCreateFunction('VERSION', function () { return '8.0.36'; }, 0);
    $baselineArray = json_decode(file_get_contents(dirname(__DIR__) . '/schema/mysql84/baseline.json'), true);
    $baseline = BaselineMetadata::fromArray($baselineArray);
    $producer = new FullSnapshotProducer($fake, $baseline, $root . '/stage', $root . '/source-assets', dirname(__DIR__));
    try {
        $producer->produce($root . '/output/snapshot.backup', random_bytes(32), []);
        throw new RuntimeException('Producer accepted unsupported MySQL.');
    } catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), 'requires MySQL 8.4.x') === false) { throw $expected; }
    }
    $restore = new FullSnapshotRestore($fake, $baseline, $root . '/stage', $root . '/target-assets', dirname(__DIR__), 'approved_target');
    try {
        $restore->restore($root . '/missing.backup', random_bytes(32), []);
        throw new RuntimeException('Restore accepted unsupported MySQL.');
    } catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), 'requires MySQL 8.4.x') === false) { throw $expected; }
    }
    if (file_exists($root . '/output/snapshot.backup') || array_values(array_diff(scandir($root . '/stage'), ['.', '..'])) !== []) {
        throw new RuntimeException('Unsupported version preflight created an artifact or plaintext stage.');
    }
    echo json_encode(['status' => 'ok', 'unsupported_versions' => 4, 'producer_preflight' => true,
        'restore_preflight' => true]) . PHP_EOL;
} finally {
    foreach (['stage', 'source-assets', 'target-assets', 'output'] as $name) { rmdir($root . DIRECTORY_SEPARATOR . $name); }
    rmdir($root);
}
