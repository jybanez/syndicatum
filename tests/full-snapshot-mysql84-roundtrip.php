<?php

require_once dirname(__DIR__) . '/src/BaselineInstaller.php';

$host = getenv('PBB_AGENTCHAT_DB_HOST') ?: '127.0.0.1';
$port = getenv('PBB_AGENTCHAT_DB_PORT') ?: '3306';
$user = getenv('PBB_AGENTCHAT_DB_USER') ?: 'root';
$password = getenv('PBB_AGENTCHAT_DB_PASS');
if ($password === false) { $password = ''; }
if (!preg_match('/\A[a-z0-9.-]+\z/i', $host) || !preg_match('/\A\d{1,5}\z/', $port)) {
    throw new RuntimeException('Disposable MySQL 8.4 test connection is invalid.');
}
$server = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user, $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$suffix = bin2hex(random_bytes(6));
$sourceName = 'snapshot_source_' . $suffix;
$targetName = 'snapshot_target_' . $suffix;
$root = dirname(__DIR__);
$baselinePath = $root . '/schema/mysql84/baseline.json';
$baseline = json_decode(file_get_contents($baselinePath), true);
if (!is_array($baseline) || json_last_error() !== JSON_ERROR_NONE) { throw new RuntimeException('Trusted baseline is unavailable.'); }
try {
    foreach ([$sourceName, $targetName] as $name) {
        $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $source = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $sourceName . ';charset=utf8mb4', $user, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $source->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    (new BaselineInstaller($source, $root . '/schema/mysql84/schema.sql', $baselinePath))->install([
        'application_version' => $baseline['application_version'], 'schema_baseline' => $baseline['baseline_id'],
        'schema_head' => $baseline['schema_head'], 'baseline_source_commit' => $baseline['source_commit'],
        'release_source_commit' => $baseline['source_commit'], 'package_sha256' => str_repeat('1', 64),
        'package_format_version' => '1.0', 'installation_id' => '123e4567-e89b-42d3-a456-426614174000',
        'installed_at' => '2026-09-22T00:00:00Z',
    ]);
    $engineCount = (int) $source->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND engine = 'InnoDB'")->fetchColumn();
    if ($engineCount !== count($baseline['tables'])) { throw new RuntimeException('Consistent snapshot source has nontransactional tables.'); }
    $writer = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $sourceName . ';charset=utf8mb4', $user, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try {
        $writer->exec("INSERT INTO users (display_name, created_at, updated_at) VALUES ('Concurrent one', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        if ((int) $source->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
            throw new RuntimeException('A write committed after snapshot start entered the snapshot.');
        }
        $writer->exec("INSERT INTO users (display_name, created_at, updated_at) VALUES ('Concurrent two', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        if ((int) $source->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
            throw new RuntimeException('Snapshot mixed rows from concurrent commits.');
        }
    } finally { $source->rollBack(); }
    if ((int) $writer->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 2) {
        throw new RuntimeException('Concurrent writer did not commit independently.');
    }
    $writer->exec('DELETE FROM users');
    echo json_encode(['consistency' => 'ok', 'innodb_tables' => $engineCount, 'concurrent_commits_excluded' => 2]) . PHP_EOL;
    putenv('SNAPSHOT_SOURCE_DB=' . $sourceName);
    putenv('SNAPSHOT_TARGET_DB=' . $targetName);
    require __DIR__ . '/full-snapshot-package.php';
} finally {
    foreach ([$targetName, $sourceName] as $name) {
        $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    }
}
