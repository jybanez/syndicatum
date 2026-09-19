<?php

require_once dirname(__DIR__) . '/src/PackageManifest.php';
require_once dirname(__DIR__) . '/src/InstallationIdentity.php';
require_once dirname(__DIR__) . '/src/PackageCompatibility.php';
require_once dirname(__DIR__) . '/src/BaselineMetadata.php';

function packageContractFail($message)
{
    fwrite(STDERR, 'FAIL  ' . $message . PHP_EOL);
    exit(1);
}

function packageContractThrows(callable $callback, $message)
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    packageContractFail($message);
}

function validManifest($kind = 'release')
{
    $manifest = [
        'contract_name' => 'syndicatum-package',
        'format_version' => '1.0',
        'package_kind' => $kind,
        'required_capabilities' => ['canonical-inventory-jsonl-v1', 'regular-files-only-v1', 'sha256-v1'],
        'application_version' => '1.0.0',
        'source_commit' => str_repeat('a', 40),
        'source_tag' => 'v1.0.0',
        'schema_baseline' => 'syndicatum-mysql84-1.0.0-baseline.1',
        'schema_head' => '202609200001',
        'source_timestamp' => '2026-09-20T00:00:00Z',
        'compatibility' => [
            'php' => ['minimum' => '8.2.0', 'maximum_exclusive' => '8.5.0', 'extensions' => ['curl', 'json', 'pdo_mysql']],
            'mysql' => ['minimum' => '8.4.0', 'maximum_exclusive' => '9.0.0', 'sql_modes' => ['STRICT_TRANS_TABLES'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
        ],
        'minimum_reader_version' => '1.0.0',
        'supported_upgrade_sources' => [],
        'contains_data' => $kind === 'backup',
        'contains_persistent_assets' => $kind === 'backup',
        'files' => [
            ['path' => $kind === 'backup' ? 'data/records.ndjson' : 'app/index.php', 'type' => 'file', 'role' => $kind === 'backup' ? 'logical_data' : 'application', 'mode' => 0644, 'size' => 12, 'sha256' => str_repeat('b', 64)],
            ['path' => 'metadata/package.json', 'type' => 'file', 'role' => $kind === 'backup' ? 'recovery_metadata' : 'package_metadata', 'mode' => 0644, 'size' => 8, 'sha256' => str_repeat('c', 64)],
        ],
        'digest_algorithm' => 'sha256',
        'content_tree_sha256' => '',
        'detached_checksum_reference' => 'checksums/syndicatum-1.0.0.zip.sha256',
        'provenance_reference' => 'provenance/build.json',
    ];
    $manifest['content_tree_sha256'] = PackageManifest::calculateContentTreeSha256($manifest['files']);
    return $manifest;
}

$release = PackageManifest::validate(validManifest());
if ($release['package_kind'] !== 'release') {
    packageContractFail('Release manifest did not validate.');
}
$backup = PackageManifest::parse(json_encode(validManifest('backup')));
if (!$backup['contains_data']) {
    packageContractFail('Backup manifest lost its data flag.');
}

packageContractThrows(function () {
    PackageManifest::parse('{"contract_name":"syndicatum-package","contract_name":"syndicatum-package"}');
}, 'Duplicate manifest keys must be rejected before decoding can collapse them.');

packageContractThrows(function () {
    PackageManifest::parse('{"contract_name":"syndicatum-package","nested":{"kind":"release","\\u006bind":"backup"}}');
}, 'Escaped duplicate nested manifest keys must be rejected.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['format_version'] = '2.0';
    PackageManifest::validate($manifest);
}, 'Unknown format major must fail closed.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['format_version'] = '1.1';
    PackageManifest::validate($manifest);
}, 'Undeclared compatible format minors must fail closed.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['required_capabilities'][] = 'unrecognized-security-rule';
    PackageManifest::validate($manifest);
}, 'Unknown required capabilities must fail closed.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['source_commit'] .= 'a';
    PackageManifest::validate($manifest);
}, 'Unsupported source commit lengths must be rejected.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['files'][0]['path'] = '../index.php';
    PackageManifest::validate($manifest);
}, 'Parent-directory traversal must be rejected.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['files'] = array_reverse($manifest['files']);
    PackageManifest::validate($manifest);
}, 'Unordered inventory must be rejected.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['files'] = [
        ['path' => 'app/File.txt', 'type' => 'file', 'role' => 'application', 'mode' => 0644, 'size' => 1, 'sha256' => str_repeat('a', 64)],
        ['path' => 'app/file.txt', 'type' => 'file', 'role' => 'application', 'mode' => 0644, 'size' => 1, 'sha256' => str_repeat('b', 64)],
    ];
    PackageManifest::validate($manifest);
}, 'Case-fold path collisions must be rejected.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['files'][0]['path'] = "app/control\nname.php";
    PackageManifest::validate($manifest);
}, 'Control characters in paths must be rejected.');

foreach (['data/payload.php:stream', 'data/payload.php.', 'data/.htaccess', 'data/task.py'] as $unsafeBackupPath) {
    packageContractThrows(function () use ($unsafeBackupPath) {
        $manifest = validManifest('backup');
        $manifest['files'][0]['path'] = $unsafeBackupPath;
        PackageManifest::validate($manifest);
    }, 'Unsafe backup path must be rejected: ' . $unsafeBackupPath);
}

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['files'][0]['path'] = 'data/customer-records.ndjson';
    $manifest['files'][0]['role'] = 'logical_data';
    PackageManifest::validate($manifest);
}, 'Release packages must reject data namespaces and roles even when contains_data is false.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['files'] = ['first' => $manifest['files'][0]];
    PackageManifest::validate($manifest);
}, 'Package files must be a JSON list, not an object.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['compatibility']['php']['extensions'] = ['curl' => true];
    PackageManifest::validate($manifest);
}, 'Compatibility extensions must be a JSON list, not an object.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['files'][0]['size']++;
    PackageManifest::validate($manifest);
}, 'A stale content-tree digest must be rejected after inventory mutation.');

packageContractThrows(function () {
    $manifest = validManifest('backup');
    $manifest['files'][0]['path'] = 'data/restore.php';
    PackageManifest::validate($manifest);
}, 'Executable files in backups must be rejected.');

packageContractThrows(function () {
    $manifest = validManifest('backup');
    $manifest['files'][0]['mode'] = 0755;
    PackageManifest::validate($manifest);
}, 'Executable permission bits in backups must be rejected.');

$identity = InstallationIdentity::fromArray([
    'application_version' => '1.0.0',
    'schema_baseline' => 'syndicatum-mysql84-1.0.0-baseline.1',
    'schema_head' => '202609200001',
    'package_sha256' => str_repeat('e', 64),
    'package_format_version' => '1.0',
    'installation_id' => '550e8400-e29b-41d4-a716-446655440000',
    'installed_at' => '2026-09-20T00:00:00Z',
]);
if ($identity->toArray()['installation_id'] !== '550e8400-e29b-41d4-a716-446655440000') {
    packageContractFail('Installation identity did not preserve its identifier.');
}

$upgradedIdentity = $identity->toArray();
$upgradedIdentity['last_upgrade_id'] = 'upgrade-20260920-1';
$upgradedIdentity['last_upgrade_from_version'] = '1.0.0';
$upgradedIdentity['last_upgrade_to_version'] = '1.1.0';
$upgradedIdentity['last_upgraded_at'] = '2026-09-20T01:00:00Z';
InstallationIdentity::fromArray($upgradedIdentity);

packageContractThrows(function () {
    InstallationIdentity::fromArray([
        'application_version' => '1.0.0', 'schema_baseline' => 'baseline', 'schema_head' => 'head',
        'package_sha256' => str_repeat('e', 64), 'package_format_version' => '1.0',
        'installation_id' => 'not-a-uuid', 'installed_at' => '2026-09-20T00:00:00Z',
    ]);
}, 'Invalid installation identifiers must be rejected.');

packageContractThrows(function () use ($upgradedIdentity) {
    unset($upgradedIdentity['last_upgrade_to_version']);
    InstallationIdentity::fromArray($upgradedIdentity);
}, 'Partial upgrade transitions must be rejected.');

packageContractThrows(function () use ($upgradedIdentity) {
    $upgradedIdentity['last_upgrade_to_version'] = $upgradedIdentity['last_upgrade_from_version'];
    InstallationIdentity::fromArray($upgradedIdentity);
}, 'No-op upgrade transitions must be rejected.');

packageContractThrows(function () use ($upgradedIdentity) {
    $upgradedIdentity['last_upgrade_id'] = ['not-a-string'];
    InstallationIdentity::fromArray($upgradedIdentity);
}, 'Upgrade identifiers must be typed non-empty strings.');

packageContractThrows(function () {
    InstallationIdentity::fromArray([
        'application_version' => '1.0.0', 'schema_baseline' => 'baseline', 'schema_head' => 'head',
        'package_sha256' => str_repeat('e', 64), 'package_format_version' => '2.0',
        'installation_id' => '550e8400-e29b-41d4-a716-446655440000', 'installed_at' => '2026-09-20T00:00:00Z',
    ]);
}, 'Unknown installation package format majors must be rejected.');

$runtime = [
    'php_version' => '8.3.0',
    'php_extensions' => ['PDO_MYSQL', 'json', 'curl', 'mbstring'],
    'mysql_version' => '8.4.11',
    'mysql_sql_modes' => ['STRICT_TRANS_TABLES', 'NO_ZERO_DATE'],
    'mysql_charset' => 'utf8mb4',
    'mysql_collation' => 'utf8mb4_unicode_ci',
    'reader_version' => '1.0.0',
];
$compatibility = PackageCompatibility::evaluate(validManifest(), $runtime);
if (!$compatibility['compatible']) {
    packageContractFail('A supported runtime must pass compatibility evaluation.');
}

$missingExtension = $runtime;
$missingExtension['php_extensions'] = ['json', 'pdo_mysql'];
if (PackageCompatibility::evaluate(validManifest(), $missingExtension)['compatible']) {
    packageContractFail('Missing required PHP extensions must fail compatibility.');
}

$wrongMysql = $runtime;
$wrongMysql['mysql_version'] = '9.0.0';
if (PackageCompatibility::evaluate(validManifest(), $wrongMysql)['compatible']) {
    packageContractFail('The exclusive MySQL maximum must fail closed.');
}

$missingMode = $runtime;
$missingMode['mysql_sql_modes'] = ['NO_ZERO_DATE'];
if (PackageCompatibility::evaluate(validManifest(), $missingMode)['compatible']) {
    packageContractFail('Missing required SQL modes must fail compatibility.');
}

$baseline = BaselineMetadata::fromArray([
    'contract_name' => 'syndicatum-baseline',
    'format_version' => '1.0',
    'baseline_id' => 'syndicatum-mysql84-1.0.0-baseline.1',
    'application_version' => '1.0.0',
    'schema_head' => '202609200002',
    'migration_cutover' => '202609200000',
    'source_commit' => str_repeat('f', 40),
    'schema_sha256' => str_repeat('1', 64),
    'mysql' => [
        'minimum' => '8.4.0', 'maximum_exclusive' => '9.0.0',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'sql_modes' => ['STRICT_TRANS_TABLES'],
    ],
    'post_baseline_migrations' => [
        ['id' => '202609200001', 'sha256' => str_repeat('2', 64)],
        ['id' => '202609200002', 'sha256' => str_repeat('3', 64)],
    ],
    'tables' => [
        ['name' => 'audit_events', 'backup_policy' => 'durable', 'restore_order' => 10, 'identity_columns' => ['id']],
        ['name' => 'oauth_attempts', 'backup_policy' => 'excluded', 'restore_order' => null, 'identity_columns' => []],
        ['name' => 'outbox_events', 'backup_policy' => 'reset', 'restore_order' => 20, 'identity_columns' => ['id'], 'reset_strategy' => 'pause_for_reconciliation'],
        ['name' => 'syndicatum_sessions', 'backup_policy' => 'reset', 'restore_order' => 30, 'identity_columns' => ['id'], 'reset_strategy' => 'invalidate'],
    ],
]);
if (!$baseline->supportsMysqlVersion('8.4.11') || $baseline->supportsMysqlVersion('9.0.0')) {
    packageContractFail('Baseline MySQL compatibility range is not enforced.');
}
$baseline->assertKnownTables(['audit_events', 'oauth_attempts', 'outbox_events', 'syndicatum_sessions']);
if ($baseline->tablePolicy('outbox_events')['reset_strategy'] !== 'pause_for_reconciliation') {
    packageContractFail('Baseline recovery policy was not preserved.');
}

packageContractThrows(function () use ($baseline) {
    $baseline->assertKnownTables(['audit_events', 'unexpected_table']);
}, 'Unknown tables must fail the baseline recovery contract.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['post_baseline_migrations'] = array_reverse($metadata['post_baseline_migrations']);
    BaselineMetadata::fromArray($metadata);
}, 'Unordered post-baseline migrations must be rejected.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['tables'][3]['restore_order'] = 20;
    BaselineMetadata::fromArray($metadata);
}, 'Duplicate restore orders must be rejected.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['source_commit'] .= 'f';
    BaselineMetadata::fromArray($metadata);
}, 'Baseline source commits must use an exact supported digest length.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['tables'] = ['users' => $metadata['tables'][0]];
    BaselineMetadata::fromArray($metadata);
}, 'Baseline tables must be a JSON list, not an object.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['mysql']['sql_modes'] = ['STRICT_TRANS_TABLES' => true];
    BaselineMetadata::fromArray($metadata);
}, 'Baseline SQL modes must be a JSON list, not an object.');

echo 'Package manifest, compatibility, baseline, and installation identity contract assertions passed' . PHP_EOL;
