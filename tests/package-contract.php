<?php

require_once dirname(__DIR__) . '/src/PackageManifest.php';
require_once dirname(__DIR__) . '/src/InstallationIdentity.php';
require_once dirname(__DIR__) . '/src/PackageCompatibility.php';
require_once dirname(__DIR__) . '/src/BaselineMetadata.php';
require_once dirname(__DIR__) . '/src/BackupTablePolicy.php';

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
        'contains_persistent_assets' => false,
        'files' => [
            ['path' => $kind === 'backup' ? 'data/records.ndjson' : 'app/index.php', 'type' => 'file', 'role' => $kind === 'backup' ? 'logical_data' : 'application', 'mode' => 0644, 'size' => 12, 'sha256' => str_repeat('b', 64)],
            ['path' => $kind === 'backup' ? 'metadata/recovery.json' : 'metadata/build.json', 'type' => 'file', 'role' => $kind === 'backup' ? 'recovery_metadata' : 'package_metadata', 'mode' => 0644, 'size' => 8, 'sha256' => str_repeat('c', 64)],
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

$baselineJson = file_get_contents(dirname(__DIR__) . '/schema/mysql84/baseline.json');
$baselineArray = json_decode($baselineJson, true);
if (!is_array($baselineArray) || json_last_error() !== JSON_ERROR_NONE) {
    packageContractFail('Trusted MySQL 8.4 baseline metadata is invalid JSON.');
}
BaselineMetadata::fromArray($baselineArray);
$policyJson = file_get_contents(dirname(__DIR__) . '/schema/mysql84/backup-policy-v1.json');
$policy = BackupTablePolicy::fromJson($policyJson);
$baselineNames = array_column($baselineArray['tables'], 'name');
if ($policy->tableNames() !== $baselineNames) {
    packageContractFail('Backup table policy does not close the trusted baseline table inventory.');
}
$policyCounts = ['durable' => 0, 'reset' => 0, 'excluded' => 0];
$baselineByName = [];
foreach ($baselineArray['tables'] as $table) {
    $policyCounts[$table['backup_policy']]++;
    $baselineByName[$table['name']] = $table;
}
if ($policyCounts !== ['durable' => 33, 'reset' => 17, 'excluded' => 3]) {
    packageContractFail('Reviewed durable/reset/excluded table counts changed unexpectedly.');
}
foreach (['syndicatum_sessions', 'oauth_access_tokens', 'message_events_outbox', 'agent_webhook_deliveries'] as $name) {
    if ($baselineByName[$name]['backup_policy'] !== 'reset' || $baselineByName[$name]['reset_strategy'] !== 'truncate') {
        packageContractFail('Replayable transient table is not reset by the reviewed policy: ' . $name);
    }
}
foreach (['mcp_service_tokens' => 'reissue_credentials', 'connector_devices' => 'reauthorize',
          'connector_device_activation_routes' => 'rebind_after_reauthorization'] as $name => $strategy) {
    if ($baselineByName[$name]['backup_policy'] !== 'reset' || $baselineByName[$name]['reset_strategy'] !== $strategy) {
        packageContractFail('Credential-bearing reset table lacks its reviewed recovery action: ' . $name);
    }
}
foreach (['oauth_clients', 'agent_activation_bindings', 'agent_notification_webhooks', 'system_settings'] as $name) {
    if ($baselineByName[$name]['backup_policy'] !== 'durable') {
        packageContractFail('Reviewed integration configuration is not durable: ' . $name);
    }
}
foreach (['syndicatum_installation_identity', 'syndicatum_schema_migrations', 'system_roles'] as $name) {
    if ($baselineByName[$name]['backup_policy'] !== 'excluded' || $baselineByName[$name]['target_expectation'] !== 'locally_initialized') {
        packageContractFail('Target-local table is not excluded by the reviewed policy: ' . $name);
    }
}
$schemaPolicyInput = [];
foreach ($baselineArray['tables'] as $index => $table) {
    $schemaPolicyInput[$table['name']] = [
        'restore_order' => $table['restore_order'] === null ? (($index + 1) * 10) : $table['restore_order'],
        'columns' => $table['columns'],
        'identity_columns' => $table['identity_columns'] ?: ['id'],
    ];
}
$originalApplied = $policy->apply($schemaPolicyInput);
$mutatedPolicyJson = str_replace(
    '"mcp_service_tokens": {"backup_policy": "reset", "reset_strategy": "reissue_credentials"}',
    '"mcp_service_tokens": {"backup_policy": "reset", "reset_strategy": "truncate"}',
    $policyJson,
    $mutationCount
);
if ($mutationCount !== 1) { packageContractFail('Backup policy mutation fixture did not change exactly one table.'); }
$mutatedApplied = BackupTablePolicy::fromJson($mutatedPolicyJson)->apply($schemaPolicyInput);
$originalPolicyHash = hash('sha256', json_encode($originalApplied, JSON_UNESCAPED_SLASHES));
$mutatedPolicyHash = hash('sha256', json_encode($mutatedApplied, JSON_UNESCAPED_SLASHES));
if ($originalPolicyHash === $mutatedPolicyHash
    || $originalPolicyHash !== hash('sha256', json_encode($policy->apply($schemaPolicyInput), JSON_UNESCAPED_SLASHES))) {
    packageContractFail('Backup policy metadata hashing is not deterministic and classification-sensitive.');
}

$goldenFiles = validManifest()['files'];
$goldenPath = __DIR__ . '/fixtures/canonical-inventory-v1.jsonl';
$goldenBytes = file_get_contents($goldenPath);
if (!is_string($goldenBytes) || substr($goldenBytes, 0, 3) === "\xEF\xBB\xBF" || substr($goldenBytes, -1) !== "\n") {
    packageContractFail('Canonical inventory golden fixture must be UTF-8 without BOM and end in LF.');
}
if (PackageManifest::canonicalContentTreeBytes($goldenFiles) !== $goldenBytes) {
    packageContractFail('Canonical inventory JSON-lines bytes changed.');
}
if (PackageManifest::calculateContentTreeSha256($goldenFiles) !== 'ad20f7949b4e49cc97ae81f304266d12d5a450eaccb2851e4a51ac72ab3a897c') {
    packageContractFail('Canonical inventory golden SHA-256 changed.');
}

$escapedBytes = PackageManifest::canonicalContentTreeBytes([
    ['path' => 'app/portable name-[1].txt', 'type' => 'file', 'role' => 'application', 'mode' => 0644, 'size' => 0, 'sha256' => str_repeat('d', 64)],
]);
$escapedExpected = '{"path":"app/portable name-[1].txt","type":"file","role":"application","mode":"0644","size":0,"sha256":"' . str_repeat('d', 64) . '"}' . "\n";
if ($escapedBytes !== $escapedExpected) {
    packageContractFail('Canonical inventory JSON escaping changed.');
}

packageContractThrows(function () {
    PackageManifest::canonicalContentTreeBytes([]);
}, 'Canonical inventory cannot be empty.');

packageContractThrows(function () use ($goldenFiles) {
    PackageManifest::canonicalContentTreeBytes(array_reverse($goldenFiles));
}, 'Canonical serializer must reject unordered paths directly.');

packageContractThrows(function () use ($goldenFiles) {
    $files = $goldenFiles;
    $files[1] = $files[0];
    PackageManifest::canonicalContentTreeBytes($files);
}, 'Canonical serializer must reject duplicate paths directly.');

packageContractThrows(function () {
    PackageManifest::canonicalContentTreeBytes([
        ['path' => 'app/File.txt', 'type' => 'file', 'role' => 'application', 'mode' => 0644, 'size' => 1, 'sha256' => str_repeat('a', 64)],
        ['path' => 'app/file.txt', 'type' => 'file', 'role' => 'application', 'mode' => 0644, 'size' => 1, 'sha256' => str_repeat('b', 64)],
    ]);
}, 'Canonical serializer must reject case-fold collisions directly.');

packageContractThrows(function () use ($goldenFiles) {
    $files = $goldenFiles;
    $files[0]['extra'] = 'unhashed';
    PackageManifest::canonicalContentTreeBytes($files);
}, 'Canonical serializer must reject undeclared fields directly.');

packageContractThrows(function () use ($goldenFiles) {
    $files = $goldenFiles;
    $files[0]['sha256'] = strtoupper($files[0]['sha256']);
    PackageManifest::canonicalContentTreeBytes($files);
}, 'Canonical serializer must reject uppercase digest representation.');

packageContractThrows(function () {
    PackageManifest::parse('{"contract_name":"syndicatum-package","contract_name":"syndicatum-package"}');
}, 'Duplicate manifest keys must be rejected before decoding can collapse them.');

packageContractThrows(function () {
    PackageManifest::parse('{"contract_name":"syndicatum-package","nested":{"kind":"release","\\u006bind":"backup"}}');
}, 'Escaped duplicate nested manifest keys must be rejected.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['files'] = (object) $manifest['files'];
    PackageManifest::parse(json_encode($manifest));
}, 'Numeric-key JSON objects cannot masquerade as the files list.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['required_capabilities'] = (object) [];
    PackageManifest::parse(json_encode($manifest));
}, 'An empty JSON object cannot masquerade as required_capabilities.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['compatibility']['php']['extensions'] = (object) $manifest['compatibility']['php']['extensions'];
    PackageManifest::parse(json_encode($manifest));
}, 'A JSON object cannot masquerade as compatibility extensions.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['compatibility']['mysql']['sql_modes'] = (object) [];
    PackageManifest::parse(json_encode($manifest));
}, 'An empty JSON object cannot masquerade as SQL modes.');

packageContractThrows(function () {
    $manifest = validManifest();
    $manifest['supported_upgrade_sources'] = (object) [];
    PackageManifest::parse(json_encode($manifest));
}, 'An empty JSON object cannot masquerade as supported upgrade sources.');

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

foreach (['1.0.1', '1.0.999'] as $unsupportedPatchVersion) {
    packageContractThrows(function () use ($unsupportedPatchVersion) {
        $manifest = validManifest();
        $manifest['format_version'] = $unsupportedPatchVersion;
        PackageManifest::validate($manifest);
    }, 'Package format patch variants must fail closed: ' . $unsupportedPatchVersion);
}

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

foreach (['data/a?.txt', 'data/a*.txt', 'data/a|b.txt', 'data/a"b.txt', 'data/a<b.txt', 'data/a>b.txt'] as $unsafePortablePath) {
    packageContractThrows(function () use ($unsafePortablePath) {
        $manifest = validManifest('backup');
        $manifest['files'][0]['path'] = $unsafePortablePath;
        $manifest['content_tree_sha256'] = PackageManifest::calculateContentTreeSha256($manifest['files']);
        PackageManifest::validate($manifest);
    }, 'Windows-invalid portable path must be rejected: ' . $unsafePortablePath);
}

$portableControl = validManifest('backup');
$portableControl['files'][0]['path'] = 'data/customer_records-2026.09.ndjson';
$portableControl['content_tree_sha256'] = PackageManifest::calculateContentTreeSha256($portableControl['files']);
PackageManifest::validate($portableControl);

foreach (['manifest.json', 'metadata/manifest.json', 'metadata/package.json'] as $embeddedManifestPath) {
    packageContractThrows(function () use ($embeddedManifestPath) {
        $manifest = validManifest();
        $manifest['files'][1]['path'] = $embeddedManifestPath;
        $manifest['content_tree_sha256'] = PackageManifest::calculateContentTreeSha256($manifest['files']);
        PackageManifest::validate($manifest);
    }, 'Package manifests must remain trusted sidecars: ' . $embeddedManifestPath);
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
    'baseline_source_commit' => str_repeat('a', 40),
    'release_source_commit' => str_repeat('b', 40),
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
        'baseline_source_commit' => str_repeat('a', 40), 'release_source_commit' => str_repeat('b', 40),
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

packageContractThrows(function () use ($upgradedIdentity) {
    unset($upgradedIdentity['baseline_source_commit']);
    InstallationIdentity::fromArray($upgradedIdentity);
}, 'Baseline source commit must remain distinct and required.');

packageContractThrows(function () use ($upgradedIdentity) {
    $upgradedIdentity['untrusted_extra_identity'] = 'value';
    InstallationIdentity::fromArray($upgradedIdentity);
}, 'Installation identity must reject undeclared fields.');

packageContractThrows(function () {
    InstallationIdentity::fromArray([
        'application_version' => '1.0.0', 'schema_baseline' => 'baseline', 'schema_head' => 'head',
        'baseline_source_commit' => str_repeat('a', 40), 'release_source_commit' => str_repeat('b', 40),
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
        'reference_version' => '8.4.11',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'sql_modes' => ['STRICT_TRANS_TABLES'],
    ],
    'post_baseline_migrations' => [
        ['id' => '202609200001', 'sha256' => str_repeat('2', 64)],
        ['id' => '202609200002', 'sha256' => str_repeat('3', 64)],
    ],
    'tables' => [
        ['name' => 'audit_events', 'backup_policy' => 'durable', 'restore_order' => 10, 'columns' => ['id'], 'identity_columns' => ['id'], 'sequence_state' => 'preserve', 'integrity_checks' => ['row_count', 'identity_uniqueness']],
        ['name' => 'oauth_attempts', 'backup_policy' => 'excluded', 'restore_order' => null, 'columns' => ['id'], 'identity_columns' => [], 'integrity_checks' => [], 'excluded_reason' => 'security_local', 'target_expectation' => 'locally_initialized'],
        ['name' => 'outbox_events', 'backup_policy' => 'reset', 'restore_order' => 20, 'columns' => ['id'], 'identity_columns' => ['id'], 'integrity_checks' => [], 'reset_strategy' => 'rebuild_from_durable_state'],
        ['name' => 'syndicatum_sessions', 'backup_policy' => 'reset', 'restore_order' => 30, 'columns' => ['id'], 'identity_columns' => ['id'], 'integrity_checks' => [], 'reset_strategy' => 'regenerate_on_start'],
    ],
]);
if (!$baseline->supportsMysqlVersion('8.4.11') || $baseline->supportsMysqlVersion('9.0.0')) {
    packageContractFail('Baseline MySQL compatibility range is not enforced.');
}
$baseline->assertKnownTables(['audit_events', 'oauth_attempts', 'outbox_events', 'syndicatum_sessions']);
if ($baseline->tablePolicy('outbox_events')['reset_strategy'] !== 'rebuild_from_durable_state') {
    packageContractFail('Baseline recovery policy was not preserved.');
}
$baseline->assertBaselineTables(['audit_events', 'oauth_attempts', 'outbox_events', 'syndicatum_sessions']);
$baseline->assertRestoreOrderSupportsForeignKeys([
    ['parent' => 'audit_events', 'child' => 'outbox_events'],
]);

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

packageContractThrows(function () use ($baseline) {
    $baseline->assertBaselineTables(['audit_events', 'oauth_attempts', 'outbox_events']);
}, 'Trusted baseline schema tables must exactly match the policy map.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['post_baseline_migrations'][1]['sha256'] = $metadata['post_baseline_migrations'][0]['sha256'];
    BaselineMetadata::fromArray($metadata);
}, 'Post-baseline migrations cannot reuse a content digest.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    unset($metadata['tables'][1]['excluded_reason']);
    BaselineMetadata::fromArray($metadata);
}, 'Excluded tables require a machine-readable reason.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['tables'][0]['identity_columns'] = [];
    BaselineMetadata::fromArray($metadata);
}, 'Durable tables require identity columns.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['tables'][2]['reset_strategy'] = 'invent_at_restore_time';
    BaselineMetadata::fromArray($metadata);
}, 'Reset strategies must use the closed V1 vocabulary.');

packageContractThrows(function () use ($baseline) {
    $baseline->assertRestoreOrderSupportsForeignKeys([
        ['parent' => 'syndicatum_sessions', 'child' => 'outbox_events'],
    ]);
}, 'Restore order must respect parent-before-child foreign-key dependencies.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['post_baseline_migrations'][0]['id'] = 'migration-one';
    BaselineMetadata::fromArray($metadata);
}, 'Migration identifiers must use the frozen V1 format.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['schema_head'] = '202609200003';
    BaselineMetadata::fromArray($metadata);
}, 'Schema head must equal the final declared migration.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['schema_head'] = '202609190000';
    BaselineMetadata::fromArray($metadata);
}, 'Schema head cannot precede the baseline cutover.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['schema_head'] = '202609200001';
    BaselineMetadata::fromArray($metadata);
}, 'Schema head cannot stop before a later declared migration.');

packageContractThrows(function () use ($baseline) {
    $metadata = $baseline->toArray();
    $metadata['post_baseline_migrations'][1]['id'] = $metadata['post_baseline_migrations'][0]['id'];
    BaselineMetadata::fromArray($metadata);
}, 'Duplicate migration identifiers must fail closed.');

$noPostCutover = $baseline->toArray();
$noPostCutover['post_baseline_migrations'] = [];
$noPostCutover['schema_head'] = $noPostCutover['migration_cutover'];
if (BaselineMetadata::fromArray($noPostCutover)->toArray()['schema_head'] !== $noPostCutover['migration_cutover']) {
    packageContractFail('A baseline with no post-cutover migrations must allow head equal to cutover.');
}

$baselineRoot = dirname(__DIR__) . '/schema/mysql84';
$baselineSchema = file_get_contents($baselineRoot . '/schema.sql');
$baselineMetadataArray = json_decode(file_get_contents($baselineRoot . '/baseline.json'), true);
if (!is_string($baselineSchema) || !is_array($baselineMetadataArray)) {
    packageContractFail('Committed MySQL 8.4 baseline artifacts are missing or invalid.');
}
$committedBaseline = BaselineMetadata::fromArray($baselineMetadataArray);
// This test reads a mutable checkout, whose Windows text conversion may be
// CRLF. The signed release package and BaselineInstaller still verify exact
// packaged bytes; checkout line endings are not package authority.
if (strpos(str_replace("\r\n", '', $baselineSchema), "\r") !== false) {
    packageContractFail('Committed baseline SQL contains unsupported lone-CR bytes.');
}
$canonicalBaselineSchema = str_replace("\r\n", "\n", $baselineSchema);
if (!hash_equals($baselineMetadataArray['schema_sha256'], hash('sha256', $canonicalBaselineSchema))) {
    packageContractFail('Canonical baseline SQL digest does not match baseline metadata.');
}
if (!hash_equals(hash('sha256', $canonicalBaselineSchema), hash('sha256', str_replace("\r\n", "\n", str_replace("\n", "\r\n", $canonicalBaselineSchema))))) {
    packageContractFail('CRLF-only baseline checkout variance changed canonical bytes.');
}
if (hash_equals($baselineMetadataArray['schema_sha256'], hash('sha256', $canonicalBaselineSchema . "\n-- true edit\n"))) {
    packageContractFail('True baseline SQL content edit retained its digest.');
}
preg_match_all('/^CREATE TABLE `([a-z0-9_]+)`/m', $baselineSchema, $baselineTableMatches);
$baselineTableNames = $baselineTableMatches[1];
sort($baselineTableNames, SORT_STRING);
$finalBaselineTableNames = array_column($baselineMetadataArray['tables'], 'name');
sort($finalBaselineTableNames, SORT_STRING);
$committedBaseline->assertBaselineTables($finalBaselineTableNames);
$postBaselineTables = array_values(array_diff($finalBaselineTableNames, $baselineTableNames));
sort($postBaselineTables, SORT_STRING);
if (count($baselineTableNames) !== 48
    || count($finalBaselineTableNames) !== 53
    || $postBaselineTables !== [
        'project_task_events', 'project_tasks', 'project_template_agents',
        'project_template_categories', 'project_templates',
    ]
    || substr_count($baselineSchema, 'CREATE TRIGGER') !== 3) {
    packageContractFail('Committed cutover baseline and declared post-baseline table inventory are inconsistent.');
}
if (stripos($baselineSchema, 'DROP TABLE') !== false
    || preg_match('/INSERT\s+INTO\s+`?syndicatum_schema_migrations`?/i', $baselineSchema)
    || stripos($baselineSchema, 'DEFINER=') !== false) {
    packageContractFail('Committed baseline contains destructive, fabricated-history, or environment-bound SQL.');
}
if ($baselineMetadataArray['source_commit'] !== '8d8cfb12aff96ac1a7ce7ce1a8ad05c6c5e5ec9d'
    || $baselineMetadataArray['migration_cutover'] !== '202609180004'
    || count($baselineMetadataArray['post_baseline_migrations']) !== 11
    || $baselineMetadataArray['post_baseline_migrations'][0]['id'] !== '202609240001'
    || $baselineMetadataArray['post_baseline_migrations'][1]['id'] !== '202609240002'
    || $baselineMetadataArray['post_baseline_migrations'][2]['id'] !== '202609250001'
    || $baselineMetadataArray['post_baseline_migrations'][3]['id'] !== '202609250002'
    || $baselineMetadataArray['post_baseline_migrations'][4]['id'] !== '202609250003'
    || $baselineMetadataArray['post_baseline_migrations'][5]['id'] !== '202609250004'
    || $baselineMetadataArray['post_baseline_migrations'][6]['id'] !== '202609250005'
    || $baselineMetadataArray['post_baseline_migrations'][7]['id'] !== '202609250006'
    || $baselineMetadataArray['post_baseline_migrations'][8]['id'] !== '202609250007'
    || $baselineMetadataArray['post_baseline_migrations'][9]['id'] !== '202609250008'
    || $baselineMetadataArray['post_baseline_migrations'][10]['id'] !== '202609250009') {
    packageContractFail('Committed baseline provenance or cutover identity changed unexpectedly.');
}

echo 'Package manifest, compatibility, baseline, and installation identity contract assertions passed' . PHP_EOL;
