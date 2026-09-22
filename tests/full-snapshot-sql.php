<?php

require_once dirname(__DIR__) . '/src/FullSnapshotSql.php';

function snapshotPdo($database)
{
    $host = getenv('PBB_AGENTCHAT_DB_HOST');
    $user = getenv('PBB_AGENTCHAT_DB_USER');
    $password = getenv('PBB_AGENTCHAT_DB_PASS');
    if (!is_string($host) || $host === '' || !is_string($user) || $user === '' || !is_string($database) || $database === '') {
        throw new RuntimeException('Isolated snapshot test database configuration is missing.');
    }
    return new PDO('mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4', $user, $password === false ? '' : $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

$sourceName = getenv('SNAPSHOT_SOURCE_DB');
$targetName = getenv('SNAPSHOT_TARGET_DB');
if (!is_string($sourceName) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $sourceName)
    || !is_string($targetName) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $targetName) || $sourceName === $targetName) {
    throw new RuntimeException('Provide two distinct isolated snapshot test database names.');
}
$baselineArray = json_decode(file_get_contents(dirname(__DIR__) . '/schema/mysql84/baseline.json'), true);
$baseline = BaselineMetadata::fromArray($baselineArray);
$source = snapshotPdo($sourceName);
$target = snapshotPdo($targetName);
$stage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-full-sql-test-' . bin2hex(random_bytes(8));
if (!mkdir($stage, 0700)) { throw new RuntimeException('Snapshot test stage could not be created.'); }
$sql = $stage . DIRECTORY_SEPARATOR . 'snapshot.sql';
try {
    $export = FullSnapshotSql::export($source, $baseline, $sql);
    if ($export['table_count'] !== 48 || $export['trigger_count'] !== 3 || $export['row_counts']['users'] < 1) {
        throw new RuntimeException('SQL snapshot export inventory is incomplete.');
    }
    $import = FullSnapshotSql::importIntoEmpty($target, $baseline, $sql, $export['row_counts']);
    if ($import['cutover_performed'] !== false || $import['table_count'] !== 48) {
        throw new RuntimeException('SQL snapshot import result is invalid.');
    }
    $user = $target->query('SELECT display_name FROM users ORDER BY id LIMIT 1')->fetchColumn();
    if ($user !== "Proof; O'Brien 🌿") { throw new RuntimeException('SQL snapshot changed special-character user data.'); }
    $triggerCount = (int) $target->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()")->fetchColumn();
    if ($triggerCount !== 3) { throw new RuntimeException('SQL snapshot did not restore all triggers.'); }
    $ledgerCount = (int) $target->query('SELECT COUNT(*) FROM syndicatum_schema_migrations')->fetchColumn();
    if ($ledgerCount !== 0) { throw new RuntimeException('SQL snapshot replayed historical migrations.'); }
    try {
        FullSnapshotSql::importIntoEmpty($target, $baseline, $sql, $export['row_counts']);
        throw new RuntimeException('SQL snapshot accepted a nonempty target.');
    } catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), 'not empty') === false) { throw $expected; }
    }
    echo json_encode(['status' => 'ok', 'tables' => 48, 'triggers' => 3, 'sql_sha256' => $export['sha256'],
        'user_count' => $export['row_counts']['users'], 'cutover_performed' => false], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if (is_file($sql)) { @unlink($sql); }
    @rmdir($stage);
}
