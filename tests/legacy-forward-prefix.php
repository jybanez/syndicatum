<?php

/** Simulates a crash after an exact authenticated forward-ledger prefix. */
require_once __DIR__ . '/legacy-candidate-package.php';
require_once dirname(__DIR__) . '/src/LegacyForwardUpgrader.php';

if ($argc !== 3 || !in_array($argv[2], ['1', '2', '3', '4'], true)) {
    fwrite(STDERR, "Usage: legacy-forward-prefix.php PACKAGE_DIR PREFIX_1_TO_4\n");
    exit(2);
}
$prefix = (int) $argv[2];
$package = legacyCandidateOpen(
    $argv[1] . '/syndicatum-v1.0.0.zip',
    $argv[1] . '/syndicatum-v1.0.0.manifest.json',
    $argv[1] . '/syndicatum-v1.0.0.provenance.json',
    '/stage', '/repo'
);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbb_agentchat;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$upgrader = new LegacyForwardUpgrader($pdo, $package, 'pbb_agentchat');
$source = $upgrader->preflight();
if ($source['prefix'] !== 0 || count($source['pending']) !== 5) {
    throw new RuntimeException('Interrupted-prefix fixture did not begin at preserved source.');
}
$stageHashes = [
    '12484feb17b559002d200630391a537fc9af6281ddb011f7c10471a2f9ad4445',
    '174f15dabc1c15ccc754ba2b3781b9b44d05cc0b5280e578871858cf73231aaa',
    '30b8c032711fa18677a3104ba6d6f478e081a7f2ba6b4b0555ce64827e34cb38',
    '73798b9b86b41b6d6d34c98173be36c13cde1f802b88c72fe3a256072f3dc392',
    'c8a69971be65aeb040887106c7436b85174474eaed4c94e8a79563abfbc7c10c',
];
for ($index = 0; $index < $prefix; $index++) {
    $entry = $source['pending'][$index];
    $path = $package->forwardMigrationPath($entry['id']);
    if (!hash_equals($entry['sha256'], LegacyMigrationPlan::canonicalSha256($path))) {
        throw new RuntimeException('Prefix fixture migration bytes do not match package plan.');
    }
    $migration = require $path;
    if (!is_array($migration) || $migration['version'] !== $entry['id']) {
        throw new RuntimeException('Prefix fixture migration structure differs from plan.');
    }
    foreach ($migration['statements'] as $statement) {
        if (is_array($statement)) {
            if (!isset($statement['unless_column'], $statement['sql'])) {
                throw new RuntimeException('Prefix fixture conditional statement is invalid.');
            }
            $statement = $statement['sql'];
        }
        $pdo->exec($statement);
    }
    if (!hash_equals($stageHashes[$index + 1], LegacySchemaFingerprint::sha256($pdo))) {
        throw new RuntimeException('Prefix fixture schema differs after authenticated migration.');
    }
    $pdo->prepare('INSERT INTO syndicatum_schema_migrations
        (version, description, checksum, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$entry['id'], $migration['description'], $entry['sha256']]);
}
$interrupted = $upgrader->preflight();
if ($interrupted['prefix'] !== $prefix || count($interrupted['pending']) !== 5 - $prefix) {
    throw new RuntimeException('Exact forward prefix was not accepted for resume.');
}
$result = $upgrader->execute();
if (count($result['executed']) !== 5 - $prefix || !$result['changed']) {
    throw new RuntimeException('Resume executed the wrong forward suffix.');
}
$again = $upgrader->execute();
if ($again['changed'] || $again['executed']) {
    throw new RuntimeException('Resumed uplift was not idempotent.');
}
echo json_encode(['prefix' => $prefix, 'resumed_count' => count($result['executed']),
    'final_schema_sha256' => LegacySchemaFingerprint::sha256($pdo),
    'ledger_count' => (int) $pdo->query('SELECT COUNT(*) FROM syndicatum_schema_migrations')->fetchColumn(),
    'second_run_changed' => $again['changed']], JSON_UNESCAPED_SLASHES) . "\n";
