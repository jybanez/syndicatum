<?php

require_once dirname(__DIR__) . '/src/Db.php';

function failBaselineGeneration($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function requiredArgument(array $options, $name)
{
    if (!isset($options[$name]) || !is_string($options[$name]) || trim($options[$name]) === '') {
        failBaselineGeneration('Missing required --' . $name . ' argument.');
    }
    return trim($options[$name]);
}

function normalizeCreateTable($sql)
{
    $sql = str_replace("\r\n", "\n", trim($sql));
    $sql = preg_replace('/\sAUTO_INCREMENT=\d+\b/', '', $sql);
    if (!is_string($sql) || stripos($sql, 'CREATE TABLE') !== 0) {
        failBaselineGeneration('Unexpected SHOW CREATE TABLE output.');
    }
    return $sql . ';';
}

function normalizeCreateTrigger($sql)
{
    $sql = str_replace("\r\n", "\n", trim($sql));
    $sql = preg_replace('/\ACREATE\s+DEFINER=`(?:``|[^`])+`@`(?:``|[^`])+`\s+/i', 'CREATE ', $sql);
    if (!is_string($sql) || stripos($sql, 'CREATE TRIGGER') !== 0) {
        failBaselineGeneration('Unexpected SHOW CREATE TRIGGER output.');
    }
    return $sql . ';';
}

function quotedIdentifier($value)
{
    return '`' . str_replace('`', '``', $value) . '`';
}

function topologicalRestoreOrder(array $tables, array $foreignKeys)
{
    $dependencies = [];
    foreach ($tables as $table) {
        $dependencies[$table] = [];
    }
    foreach ($foreignKeys as $foreignKey) {
        $child = $foreignKey['TABLE_NAME'];
        $parent = $foreignKey['REFERENCED_TABLE_NAME'];
        if ($child !== $parent) {
            $dependencies[$child][$parent] = true;
        }
    }

    $orders = [];
    $remaining = array_fill_keys($tables, true);
    $nextOrder = 10;
    while ($remaining) {
        $ready = [];
        foreach (array_keys($remaining) as $table) {
            $unresolved = array_intersect_key($dependencies[$table], $remaining);
            if (!$unresolved) {
                $ready[] = $table;
            }
        }
        sort($ready, SORT_STRING);
        if (!$ready) {
            failBaselineGeneration('Foreign-key graph contains a cross-table cycle; restore order cannot be generated.');
        }
        foreach ($ready as $table) {
            $orders[$table] = $nextOrder;
            $nextOrder += 10;
            unset($remaining[$table]);
        }
    }
    return $orders;
}

$options = getopt('', [
    'output-directory:', 'baseline-id:', 'application-version:', 'schema-head:',
    'migration-cutover:', 'source-commit:',
]);
$outputDirectory = requiredArgument($options, 'output-directory');
$baselineId = requiredArgument($options, 'baseline-id');
$applicationVersion = requiredArgument($options, 'application-version');
$schemaHead = requiredArgument($options, 'schema-head');
$migrationCutover = requiredArgument($options, 'migration-cutover');
$sourceCommit = strtolower(requiredArgument($options, 'source-commit'));
if (!preg_match('/\A[a-f0-9]{40}\z/', $sourceCommit)) {
    failBaselineGeneration('Source commit must be a full 40-character Git commit.');
}
if (!preg_match('/\A\d{12}\z/', $schemaHead) || !preg_match('/\A\d{12}\z/', $migrationCutover)) {
    failBaselineGeneration('Schema head and migration cutover must use 12 decimal digits.');
}
if ($schemaHead !== $migrationCutover) {
    failBaselineGeneration('The initial frozen baseline cannot declare ungenerated post-baseline migrations.');
}

$pdo = Db::pdo();
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
if (!preg_match('/\A(\d+\.\d+\.\d+)/', $version, $versionMatch)) {
    failBaselineGeneration('Could not normalize the MySQL reference version.');
}
$referenceVersion = $versionMatch[1];
if (version_compare($referenceVersion, '8.4.0', '<') || version_compare($referenceVersion, '9.0.0', '>=')) {
    failBaselineGeneration('Baseline generation requires MySQL 8.4.x.');
}

$sourceTables = $pdo->query(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
     ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);
if (!$sourceTables) {
    failBaselineGeneration('Baseline source database contains no tables.');
}
$identityTable = 'syndicatum_installation_identity';
if (in_array($identityTable, $sourceTables, true)) {
    failBaselineGeneration('Legacy source unexpectedly contains the baseline-owned installation identity table.');
}
$tables = $sourceTables;
$tables[] = $identityTable;
sort($tables, SORT_STRING);

$migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM syndicatum_schema_migrations')->fetchColumn();
if ($migrationCount < 1) {
    failBaselineGeneration('Baseline source database has no recorded legacy migrations.');
}
$legacyHead = (string) $pdo->query('SELECT version FROM syndicatum_schema_migrations ORDER BY applied_at DESC, version DESC LIMIT 1')->fetchColumn();
if (strpos($legacyHead, $schemaHead . '_') !== 0 && $legacyHead !== $schemaHead) {
    failBaselineGeneration('Legacy migration head does not match the declared numeric schema head.');
}

$foreignKeys = $pdo->query(
    "SELECT table_name AS TABLE_NAME, referenced_table_name AS REFERENCED_TABLE_NAME
     FROM information_schema.key_column_usage
     WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL
     ORDER BY table_name, constraint_name, ordinal_position"
)->fetchAll();
$restoreOrders = topologicalRestoreOrder($tables, $foreignKeys);

$primaryRows = $pdo->query(
    "SELECT table_name AS TABLE_NAME, column_name AS COLUMN_NAME
     FROM information_schema.key_column_usage
     WHERE table_schema = DATABASE() AND constraint_name = 'PRIMARY'
     ORDER BY table_name, ordinal_position"
)->fetchAll();
$primaryKeys = [];
foreach ($primaryRows as $row) {
    $primaryKeys[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
}

$statements = [
    '-- Syndicatum authoritative MySQL 8.4 baseline.',
    '-- Generated from immutable source commit ' . $sourceCommit . '.',
    '-- Do not edit by hand; regenerate and review the schema digest.',
    'SET @syndicatum_saved_foreign_key_checks = @@FOREIGN_KEY_CHECKS;',
    'SET FOREIGN_KEY_CHECKS = 0;',
];
foreach ($sourceTables as $table) {
    $statement = $pdo->query('SHOW CREATE TABLE ' . quotedIdentifier($table))->fetch();
    if (!is_array($statement) || !isset($statement['Create Table'])) {
        failBaselineGeneration('Could not read table definition for ' . $table . '.');
    }
    $statements[] = normalizeCreateTable($statement['Create Table']);
}
$statements[] = "CREATE TABLE `syndicatum_installation_identity` (\n"
    . "  `singleton_id` tinyint unsigned NOT NULL,\n"
    . "  `application_version` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,\n"
    . "  `schema_baseline` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,\n"
    . "  `schema_head` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,\n"
    . "  `baseline_source_commit` char(40) COLLATE utf8mb4_unicode_ci NOT NULL,\n"
    . "  `release_source_commit` char(40) COLLATE utf8mb4_unicode_ci NOT NULL,\n"
    . "  `package_sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,\n"
    . "  `package_format_version` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,\n"
    . "  `installation_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,\n"
    . "  `installed_at` datetime NOT NULL,\n"
    . "  `last_upgrade_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n"
    . "  `last_upgrade_from_version` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n"
    . "  `last_upgrade_to_version` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n"
    . "  `last_upgraded_at` datetime DEFAULT NULL,\n"
    . "  PRIMARY KEY (`singleton_id`),\n"
    . "  UNIQUE KEY `uq_syndicatum_installation_id` (`installation_id`),\n"
    . "  CONSTRAINT `chk_syndicatum_installation_singleton` CHECK (`singleton_id` = 1)\n"
    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$triggers = $pdo->query(
    "SELECT trigger_name FROM information_schema.triggers
     WHERE trigger_schema = DATABASE() ORDER BY trigger_name"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($triggers as $trigger) {
    $statement = $pdo->query('SHOW CREATE TRIGGER ' . quotedIdentifier($trigger))->fetch();
    if (!is_array($statement) || !isset($statement['SQL Original Statement'])) {
        failBaselineGeneration('Could not read trigger definition for ' . $trigger . '.');
    }
    $statements[] = normalizeCreateTrigger($statement['SQL Original Statement']);
}
$statements[] = "INSERT INTO `system_roles` (`code`, `name`, `created_at`) VALUES\n"
    . "  ('user', 'User', UTC_TIMESTAMP()),\n"
    . "  ('administrator', 'Administrator', UTC_TIMESTAMP());";
$statements[] = 'SET FOREIGN_KEY_CHECKS = @syndicatum_saved_foreign_key_checks;';
$schema = implode("\n\n", $statements) . "\n";

$tablePolicies = [];
foreach ($tables as $table) {
    $identityColumns = $table === $identityTable
        ? ['singleton_id']
        : (isset($primaryKeys[$table]) ? $primaryKeys[$table] : []);
    if (!$identityColumns) {
        failBaselineGeneration('Every V1 baseline table must have a primary key: ' . $table . '.');
    }
    $tablePolicies[] = [
        'name' => $table,
        'backup_policy' => 'durable',
        'restore_order' => $restoreOrders[$table],
        'identity_columns' => $identityColumns,
        'sequence_state' => 'preserve',
        'integrity_checks' => ['row_count', 'identity_uniqueness', 'foreign_key_consistency'],
    ];
}

$sqlModes = array_values(array_filter(explode(',', (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn())));
sort($sqlModes, SORT_STRING);
$metadata = [
    'contract_name' => 'syndicatum-baseline',
    'format_version' => '1.0',
    'baseline_id' => $baselineId,
    'application_version' => $applicationVersion,
    'schema_head' => $schemaHead,
    'migration_cutover' => $migrationCutover,
    'source_commit' => $sourceCommit,
    'schema_sha256' => hash('sha256', $schema),
    'mysql' => [
        'minimum' => '8.4.0',
        'maximum_exclusive' => '9.0.0',
        'reference_version' => $referenceVersion,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'sql_modes' => $sqlModes,
    ],
    'post_baseline_migrations' => [],
    'tables' => $tablePolicies,
];

if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true)) {
    failBaselineGeneration('Could not create baseline output directory.');
}
$schemaPath = rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . 'schema.sql';
$metadataPath = rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . 'baseline.json';
if (file_put_contents($schemaPath, $schema) !== strlen($schema)) {
    failBaselineGeneration('Could not write schema.sql.');
}
$metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (file_put_contents($metadataPath, $metadataJson) !== strlen($metadataJson)) {
    failBaselineGeneration('Could not write baseline.json.');
}

echo json_encode([
    'schema' => $schemaPath,
    'metadata' => $metadataPath,
    'tables' => count($tables),
    'triggers' => count($triggers),
    'legacy_migrations' => $migrationCount,
    'legacy_head' => $legacyHead,
    'schema_sha256' => $metadata['schema_sha256'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
