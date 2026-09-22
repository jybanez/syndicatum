<?php

require_once dirname(__DIR__) . '/src/FullSnapshotProducer.php';
require_once dirname(__DIR__) . '/src/FullSnapshotRestore.php';
require_once dirname(__DIR__) . '/src/BackupProducer.php';
require_once dirname(__DIR__) . '/src/StagedBackupRestore.php';

function fullPackagePdo($name)
{
    $host = getenv('PBB_AGENTCHAT_DB_HOST');
    if (!is_string($host) || $host === '' || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name)) {
        throw new RuntimeException('Isolated full-snapshot database configuration is missing.');
    }
    return new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', getenv('PBB_AGENTCHAT_DB_USER') ?: 'root',
        getenv('PBB_AGENTCHAT_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function fullPackageRemove($root)
{
    foreach (scandir($root) as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path) && !is_link($path)) { fullPackageRemove($path); }
        else { unlink($path); }
    }
    rmdir($root);
}

$sourceName = getenv('SNAPSHOT_SOURCE_DB');
$targetName = getenv('SNAPSHOT_TARGET_DB');
if (!is_string($sourceName) || !is_string($targetName) || $sourceName === $targetName) {
    throw new RuntimeException('Provide distinct disposable source and target database names.');
}
$baselineArray = json_decode(file_get_contents(dirname(__DIR__) . '/schema/mysql84/baseline.json'), true);
$baseline = BaselineMetadata::fromArray($baselineArray);
$source = fullPackagePdo($sourceName); $target = fullPackagePdo($targetName);
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'full-snapshot-package-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('Private package test root could not be created.'); }
try {
    foreach (['staging','source-assets','target-assets','output'] as $name) {
        if (!mkdir($root . DIRECTORY_SEPARATOR . $name, 0700)) { throw new RuntimeException('Private package test directory could not be created.'); }
    }
    $avatarName = str_repeat('a', 40) . '.png';
    $avatarBytes = 'private-fixture-avatar';
    file_put_contents($root . '/source-assets/' . $avatarName, $avatarBytes);
    $source->prepare('INSERT INTO users (display_name, avatar_url, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute(["Proof; O'Brien 🌿", 'api/v1/avatar.php?file=' . $avatarName]);
    if ((int) $source->query('SELECT COUNT(*) FROM syndicatum_installation_identity')->fetchColumn() === 0) {
        $source->prepare('INSERT INTO syndicatum_installation_identity (singleton_id, application_version, schema_baseline, schema_head, baseline_source_commit, release_source_commit, package_sha256, package_format_version, installation_id, installed_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$baselineArray['application_version'], $baselineArray['baseline_id'], $baselineArray['schema_head'],
                $baselineArray['source_commit'], $baselineArray['source_commit'], str_repeat('0', 64), '1.0',
                '123e4567-e89b-42d3-a456-426614174000']);
    }
    $key = random_bytes(32);
    $secrets = ['PBB_AGENTCHAT_SECRET' => str_repeat('s', 32), 'SYNDICATUM_MASTER_KEY' => str_repeat('m', 32)];
    $envelope = $root . '/output/snapshot.syndicatum-backup';
    $producer = new FullSnapshotProducer($source, $baseline, $root . '/staging', $root . '/source-assets', dirname(__DIR__));
    $heldAvatar = $root . '/source-assets/' . $avatarName . '.held';
    if (!rename($root . '/source-assets/' . $avatarName, $heldAvatar)) { throw new RuntimeException('Avatar failure fixture could not be prepared.'); }
    try {
        try { $producer->produce($root . '/output/failed.syndicatum-backup', $key, $secrets);
            throw new RuntimeException('Producer accepted a missing referenced avatar.'); }
        catch (RuntimeException $expected) {
            if (strpos($expected->getMessage(), 'avatar') === false) { throw $expected; }
        }
    } finally {
        if (!rename($heldAvatar, $root . '/source-assets/' . $avatarName)) {
            throw new RuntimeException('Avatar fixture could not be restored.');
        }
    }
    if (file_exists($root . '/output/failed.syndicatum-backup')
        || array_values(array_diff(scandir($root . '/staging'), ['.', '..'])) !== []) {
        throw new RuntimeException('Producer failure left an artifact or plaintext stage.');
    }
    $made = $producer->produce($envelope, $key, $secrets);
    if (!$made['plaintext_cleanup_verified'] || $made['asset_count'] !== 1
        || array_values(array_diff(scandir($root . '/staging'), ['.', '..'])) !== []) {
        throw new RuntimeException('Full-snapshot producer left plaintext or omitted the avatar.');
    }
    $tampered = $root . '/output/tampered.syndicatum-backup';
    $bytes = file_get_contents($envelope);
    $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
    file_put_contents($tampered, $bytes);
    $restore = new FullSnapshotRestore($target, $baseline, $root . '/staging', $root . '/target-assets', dirname(__DIR__), $targetName);
    $legacyRestore = new StagedBackupRestore(new PdoBackupRestoreTarget($target), $baseline, $root . '/staging', dirname(__DIR__));
    try { $legacyRestore->inspect($envelope, $key, $secrets); throw new RuntimeException('Full snapshot was accepted by legacy NDJSON importer.'); }
    catch (InvalidArgumentException $expected) { /* distinct manifest discriminator required */ }
    $legacyEnvelope = $root . '/output/legacy.syndicatum-backup';
    (new BackupProducer(new PdoBackupDatabaseSource($source), $baseline, $root . '/staging', $root . '/source-assets', dirname(__DIR__)))
        ->produce($legacyEnvelope, $key, ['source_commit' => $baselineArray['source_commit'],
            'application_version' => $baselineArray['application_version'], 'schema_baseline' => $baselineArray['baseline_id'],
            'schema_head' => $baselineArray['schema_head']], $secrets);
    try { $restore->restore($legacyEnvelope, $key, $secrets); throw new RuntimeException('Legacy NDJSON was accepted by full-snapshot importer.'); }
    catch (InvalidArgumentException $expected) { /* distinct manifest discriminator required */ }
    if (array_values(array_diff(scandir($root . '/staging'), ['.', '..'])) !== []) {
        throw new RuntimeException('Cross-format refusal left decrypted staging behind.');
    }
    try { $restore->restore($tampered, $key, $secrets); throw new RuntimeException('Tampered envelope was accepted.'); }
    catch (InvalidArgumentException $expected) { /* authenticated refusal */ }
    $wrongTarget = new FullSnapshotRestore($target, $baseline, $root . '/staging', $root . '/target-assets', dirname(__DIR__), 'wrong_target');
    try { $wrongTarget->restore($envelope, $key, $secrets); throw new RuntimeException('Wrong target name was accepted.'); }
    catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), 'approved target') === false) { throw $expected; }
    }
    $wrongBaselineArray = $baselineArray;
    $wrongBaselineArray['baseline_id'] = 'incompatible-baseline';
    $wrongBaseline = new FullSnapshotRestore($target, BaselineMetadata::fromArray($wrongBaselineArray), $root . '/staging', $root . '/target-assets', dirname(__DIR__), $targetName);
    try { $wrongBaseline->restore($envelope, $key, $secrets); throw new RuntimeException('Wrong baseline was accepted.'); }
    catch (InvalidArgumentException $expected) { /* identity mismatch required */ }
    $private = BackupEnvelope::decryptToPrivateStage($envelope, $root . '/staging', $key);
    try {
        $zip = new ZipArchive();
        if ($zip->open($private['archive_path']) !== true) { throw new RuntimeException('Test archive could not be opened.'); }
        $zip->addFromString(FullSnapshotManifest::SQL_PATH, "USE mysql;\n");
        $zip->close();
        $badSqlEnvelope = $root . '/output/bad-sql.syndicatum-backup';
        BackupEnvelope::encrypt($private['archive_path'], $private['manifest_json'], $badSqlEnvelope, $key,
            ['application_version' => $baselineArray['application_version'], 'schema_baseline' => $baselineArray['baseline_id'],
                'schema_head' => $baselineArray['schema_head'], 'source_commit' => $baselineArray['source_commit']]);
    } finally { BackupEnvelope::removePrivateStage($private['stage_path'], $root . '/staging'); }
    try { $restore->restore($badSqlEnvelope, $key, $secrets); throw new RuntimeException('Authenticated tampered SQL was accepted.'); }
    catch (InvalidArgumentException $expected) { /* member hash mismatch required */ }
    if (array_values(array_diff(scandir($root . '/staging'), ['.', '..'])) !== []) {
        throw new RuntimeException('Restore validation failure left decrypted staging behind.');
    }
    $private = BackupEnvelope::decryptToPrivateStage($envelope, $root . '/staging', $key);
    try {
        $zip = new ZipArchive();
        if ($zip->open($private['archive_path']) !== true) { throw new RuntimeException('Test archive could not be opened.'); }
        $zip->addFromString(FullSnapshotManifest::SQL_PATH, "USE mysql;\n");
        $zip->setCompressionName(FullSnapshotManifest::SQL_PATH, ZipArchive::CM_STORE);
        $zip->setExternalAttributesName(FullSnapshotManifest::SQL_PATH, ZipArchive::OPSYS_UNIX, (0100000 | 0600) << 16);
        $zip->close();
        $unsafeManifest = FullSnapshotManifest::parse($private['manifest_json']);
        $unsafeManifest['sql']['sha256'] = hash('sha256', "USE mysql;\n");
        $unsafeManifest['sql']['bytes'] = strlen("USE mysql;\n");
        $unsafeEnvelope = $root . '/output/unsafe-sql.syndicatum-backup';
        BackupEnvelope::encrypt($private['archive_path'], FullSnapshotManifest::encode($unsafeManifest), $unsafeEnvelope, $key,
            ['application_version' => $baselineArray['application_version'], 'schema_baseline' => $baselineArray['baseline_id'],
                'schema_head' => $baselineArray['schema_head'], 'source_commit' => $baselineArray['source_commit']]);
    } finally { BackupEnvelope::removePrivateStage($private['stage_path'], $root . '/staging'); }
    try { $restore->restore($unsafeEnvelope, $key, $secrets); throw new RuntimeException('Authenticated unsafe SQL was imported.'); }
    catch (InvalidArgumentException $expected) { /* SQL grammar refusal after validated extraction */ }
    if (array_values(array_diff(scandir($root . '/staging'), ['.', '..'])) !== []) {
        throw new RuntimeException('Restore import failure left decrypted staging behind.');
    }
    $restored = $restore->restore($envelope, $key, $secrets);
    if (!$restored['plaintext_cleanup_verified'] || $restored['cutover_performed'] !== false
        || $restored['table_count'] !== 48 || $restored['asset_count'] !== 1
        || $target->query('SELECT display_name FROM users ORDER BY id LIMIT 1')->fetchColumn() !== "Proof; O'Brien 🌿"
        || file_get_contents($root . '/target-assets/' . $avatarName) !== $avatarBytes
        || array_values(array_diff(scandir($root . '/staging'), ['.', '..'])) !== []) {
        throw new RuntimeException('Full-snapshot restore identity, asset, or cleanup proof failed.');
    }
    try { $restore->restore($envelope, $key, $secrets); throw new RuntimeException('Nonempty target was accepted.'); }
    catch (RuntimeException $expected) {
        if (strpos($expected->getMessage(), 'not empty') === false) { throw $expected; }
    }
    echo json_encode(['status' => 'ok', 'format' => $restored['format'], 'tables' => $restored['table_count'],
        'assets' => $restored['asset_count'], 'envelope_sha256' => $made['envelope_sha256'],
        'plaintext_cleanup_verified' => true, 'cutover_performed' => false], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally { fullPackageRemove($root); }
