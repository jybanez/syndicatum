<?php

declare(strict_types=1);

// This is a CI environment assertion, not a general application health check.
// The supported V1 Docker baseline is MySQL 8.4 with strict SQL behavior.
$pdo = new PDO(
    'mysql:host=127.0.0.1;charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$row = $pdo->query('SELECT VERSION() AS version, @@SESSION.sql_mode AS sql_mode')
    ->fetch(PDO::FETCH_ASSOC);

if (!is_array($row)) {
    throw new RuntimeException('Could not read the CI database version and SQL mode.');
}

$version = (string) $row['version'];
$sqlMode = (string) $row['sql_mode'];
$modes = array_map('trim', explode(',', $sqlMode));

if (!preg_match('/^8\.4(?:\.|-)/', $version)) {
    throw new RuntimeException('CI must use the supported MySQL 8.4 series; got ' . $version . '.');
}
if (!in_array('STRICT_TRANS_TABLES', $modes, true)
    && !in_array('STRICT_ALL_TABLES', $modes, true)) {
    throw new RuntimeException('CI MySQL must enforce strict SQL mode; got ' . $sqlMode . '.');
}

echo 'MySQL version: ' . $version . PHP_EOL;
echo 'SQL mode: ' . $sqlMode . PHP_EOL;
echo 'PASS  Supported production-representative CI database mode.' . PHP_EOL;
