<?php

/** Isolated Docker clone harness; excluded from every release package. */
require_once __DIR__ . '/legacy-candidate-package.php';
require_once dirname(__DIR__) . '/src/LegacyForwardUpgrader.php';

if ($argc !== 3 || !in_array($argv[2], ['preflight', 'execute'], true)) {
    fwrite(STDERR, "Usage: legacy-uplift-clone.php PACKAGE_DIR preflight|execute\n");
    exit(2);
}
$directory = $argv[1];
$package = legacyCandidateOpen(
    $directory . '/syndicatum-v1.0.0.zip',
    $directory . '/syndicatum-v1.0.0.manifest.json',
    $directory . '/syndicatum-v1.0.0.provenance.json',
    '/stage', '/repo'
);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbb_agentchat;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$upgrader = new LegacyForwardUpgrader($pdo, $package, 'pbb_agentchat');
$preflight = $upgrader->preflight();
if ($argv[2] === 'preflight') {
    echo json_encode($preflight, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
$result = $upgrader->execute();
echo json_encode(['preflight' => $preflight, 'result' => $result], JSON_UNESCAPED_SLASHES) . "\n";
