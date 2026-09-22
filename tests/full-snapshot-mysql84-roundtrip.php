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
    putenv('SNAPSHOT_SOURCE_DB=' . $sourceName);
    putenv('SNAPSHOT_TARGET_DB=' . $targetName);
    require __DIR__ . '/full-snapshot-package.php';
} finally {
    foreach ([$targetName, $sourceName] as $name) {
        $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    }
}
