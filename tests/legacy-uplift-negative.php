<?php

/** One negative case per newly restored network-isolated disposable clone. */
require_once __DIR__ . '/legacy-candidate-package.php';
require_once dirname(__DIR__) . '/src/LegacyForwardUpgrader.php';
require_once dirname(__DIR__) . '/src/InstallationState.php';

if ($argc !== 3 || !in_array($argv[2], [
    'historical_checksum', 'missing_historical', 'unknown_extra', 'skipped_forward',
    'participant_check_mismatch', 'schema_drift', 'partial_identity',
], true)) {
    fwrite(STDERR, "Usage: legacy-uplift-negative.php PACKAGE_DIR CASE\n");
    exit(2);
}
$case = $argv[2];
$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbb_agentchat;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'pbb_agentchat') {
    throw new RuntimeException('Negative fixture is not the intended isolated clone.');
}
$package = legacyCandidateOpen(
    $argv[1] . '/syndicatum-v1.0.0.zip',
    $argv[1] . '/syndicatum-v1.0.0.manifest.json',
    $argv[1] . '/syndicatum-v1.0.0.provenance.json',
    '/stage', '/repo'
);
$upgrader = new LegacyForwardUpgrader($pdo, $package, 'pbb_agentchat');
$source = $upgrader->preflight();
if ($source['prefix'] !== 0 || count($source['pending']) !== 5) {
    throw new RuntimeException('Negative fixture did not begin at the preserved 25-row source.');
}
$first = $package->plan()->historicalMigrations()[0]['id'];
switch ($case) {
    case 'historical_checksum':
        $pdo->prepare('UPDATE syndicatum_schema_migrations SET checksum = ? WHERE version = ?')
            ->execute([str_repeat('0', 64), $first]);
        break;
    case 'missing_historical':
        $pdo->prepare('DELETE FROM syndicatum_schema_migrations WHERE version = ?')->execute([$first]);
        break;
    case 'unknown_extra':
        $pdo->prepare('INSERT INTO syndicatum_schema_migrations
            (version, description, checksum, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
            ->execute(['999999999999_unknown', 'negative fixture', str_repeat('0', 64)]);
        break;
    case 'skipped_forward':
        $entry = $source['pending'][1];
        $pdo->prepare('INSERT INTO syndicatum_schema_migrations
            (version, description, checksum, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$entry['id'], 'negative fixture', $entry['sha256']]);
        break;
    case 'participant_check_mismatch':
        $pdo->exec("ALTER TABLE project_participants ADD CONSTRAINT chk_project_participants_identity
            CHECK (kind IN ('human', 'agent'))");
        break;
    case 'schema_drift':
        $pdo->exec('ALTER TABLE agents ADD negative_probe INT NULL');
        break;
    case 'partial_identity':
        $schema = file_get_contents($package->baselineSchemaPath());
        if (!preg_match('/CREATE TABLE `syndicatum_installation_identity` \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/s', $schema, $match)) {
            throw new RuntimeException('Candidate identity DDL is unavailable.');
        }
        $pdo->exec($match[0]);
        $state = (new InstallationState($pdo, '/repo/schema/mysql84/baseline.json'))->inspect();
        if (!empty($state['ready'])) {
            throw new RuntimeException('Partial identity was reported ready.');
        }
        break;
}
$before = [
    'schema' => LegacySchemaFingerprint::sha256($pdo),
    'ledger' => hash('sha256', json_encode($pdo->query(
        'SELECT version, description, checksum, applied_at FROM syndicatum_schema_migrations ORDER BY version'
    )->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES)),
];
$rejection = null;
try {
    $upgrader->execute();
} catch (RuntimeException $exception) {
    $rejection = $exception->getMessage();
}
if ($rejection === null) {
    throw new RuntimeException('Invalid clone state was accepted: ' . $case);
}
$after = [
    'schema' => LegacySchemaFingerprint::sha256($pdo),
    'ledger' => hash('sha256', json_encode($pdo->query(
        'SELECT version, description, checksum, applied_at FROM syndicatum_schema_migrations ORDER BY version'
    )->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES)),
];
if ($before !== $after) {
    throw new RuntimeException('Invalid clone state was mutated by upgrader preflight: ' . $case);
}
echo json_encode(['case' => $case, 'rejected' => true, 'zero_upgrade_mutation' => true,
    'reason' => $rejection], JSON_UNESCAPED_SLASHES) . "\n";
