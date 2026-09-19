<?php

require_once dirname(__DIR__) . '/src/PackageManifest.php';
require_once dirname(__DIR__) . '/src/InstallationIdentity.php';
require_once dirname(__DIR__) . '/src/PackageCompatibility.php';

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
    return [
        'contract_name' => 'syndicatum.package',
        'format_version' => '1.0',
        'package_kind' => $kind,
        'application_version' => '1.0.0',
        'source_commit' => str_repeat('a', 40),
        'protected_tag' => 'v1.0.0',
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
            ['path' => $kind === 'backup' ? 'data/records.ndjson' : 'app/index.php', 'size' => 12, 'sha256' => str_repeat('b', 64)],
            ['path' => 'metadata/package.json', 'size' => 8, 'sha256' => str_repeat('c', 64)],
        ],
        'content_tree_sha256' => str_repeat('d', 64),
        'provenance_reference' => 'provenance/build.json',
    ];
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
    $manifest = validManifest();
    $manifest['format_version'] = '2.0';
    PackageManifest::validate($manifest);
}, 'Unknown format major must fail closed.');

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
    $manifest = validManifest('backup');
    $manifest['files'][0]['path'] = 'data/restore.php';
    PackageManifest::validate($manifest);
}, 'Executable files in backups must be rejected.');

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

packageContractThrows(function () {
    InstallationIdentity::fromArray([
        'application_version' => '1.0.0', 'schema_baseline' => 'baseline', 'schema_head' => 'head',
        'package_sha256' => str_repeat('e', 64), 'package_format_version' => '1.0',
        'installation_id' => 'not-a-uuid', 'installed_at' => '2026-09-20T00:00:00Z',
    ]);
}, 'Invalid installation identifiers must be rejected.');

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

echo 'Package manifest, compatibility, and installation identity contract assertions passed' . PHP_EOL;
