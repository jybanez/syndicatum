<?php

require_once dirname(__DIR__) . '/src/FullSnapshotArchive.php';

function snapshotTestFile($path, $bytes)
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true)) { throw new RuntimeException('Fixture directory failed.'); }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) { throw new RuntimeException('Fixture write failed.'); }
    @chmod($path, 0600);
}

function snapshotTestTreeRemove($root)
{
    $items = scandir($root);
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (is_dir($path) && !is_link($path)) { snapshotTestTreeRemove($path); }
        else { unlink($path); }
    }
    rmdir($root);
}

/** Write a minimal stored ZIP without libzip's duplicate-name normalization. */
function snapshotTestZipWithMembers($path, array $members)
{
    $local = ''; $central = '';
    foreach ($members as $member) {
        $name = $member['path']; $bytes = $member['bytes'];
        $crc = crc32($bytes); $length = strlen($bytes); $offset = strlen($local);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $length, $length, strlen($name), 0)
            . $name . $bytes;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0314, 20, 0, 0, 0, 0, $crc,
            $length, $length, strlen($name), 0, 0, 0, 0, (0100000 | 0600) << 16, $offset) . $name;
    }
    $count = count($members);
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
    snapshotTestFile($path, $local . $central . $end);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'full-snapshot-format-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('Private fixture root failed.'); }
try {
    $payload = $root . '/payload';
    $avatar = 'assets/avatars/' . str_repeat('a', 40) . '.png';
    snapshotTestFile($payload . '/database/snapshot.sql', "CREATE TABLE `proof` (`id` INT NOT NULL);\n");
    snapshotTestFile($payload . '/secrets/recovery.json', '{"secret":"fixture-only"}');
    snapshotTestFile($payload . '/' . $avatar, 'fixture-png-bytes');
    $member = function ($path) use ($payload) {
        return ['path' => $path, 'sha256' => hash_file('sha256', $payload . '/' . $path), 'bytes' => filesize($payload . '/' . $path)];
    };
    $sql = $member('database/snapshot.sql');
    $sql['table_count'] = 1; $sql['trigger_count'] = 0; $sql['row_counts'] = ['proof' => 0];
    $sql['row_hashes'] = ['proof' => hash('sha256', '')];
    $manifest = [
        'contract_name' => FullSnapshotManifest::CONTRACT_NAME, 'format_version' => FullSnapshotManifest::FORMAT_VERSION,
        'created_at' => '2026-09-22T00:00:00Z', 'application_version' => '1.0.0', 'schema_baseline' => 'test-baseline',
        'schema_head' => '202609180004', 'source_commit' => str_repeat('a', 40),
        'source_installation_id' => '123e4567-e89b-42d3-a456-426614174000', 'source_database' => 'test_database', 'mysql_version' => '8.4.11',
        'sql' => $sql, 'assets' => [$member($avatar)], 'secrets' => $member('secrets/recovery.json'),
    ];
    $encoded = FullSnapshotManifest::encode($manifest);
    if (FullSnapshotManifest::parse($encoded) !== $manifest) { throw new RuntimeException('Full-snapshot manifest round trip failed.'); }
    $archive = $root . '/snapshot.zip';
    FullSnapshotArchive::create($payload, $manifest, $archive);
    $extracted = FullSnapshotArchive::extractVerified($archive, $manifest, $root . '/restored');
    if (hash_file('sha256', $extracted['sql_path']) !== $sql['sha256'] || $extracted['asset_count'] !== 1) {
        throw new RuntimeException('Full-snapshot archive round trip failed.');
    }
    $legacy = json_encode(['contract_name' => PackageManifest::CONTRACT_NAME, 'package_kind' => 'backup']);
    try { FullSnapshotManifest::parse($legacy); throw new RuntimeException('Legacy manifest was accepted as full snapshot.'); }
    catch (InvalidArgumentException $expected) { /* format mismatch required */ }
    try { PackageManifest::parse($encoded); throw new RuntimeException('Full snapshot was accepted as legacy package.'); }
    catch (InvalidArgumentException $expected) { /* cross-format refusal required */ }
    $tampered = $manifest; $tampered['sql']['sha256'] = str_repeat('0', 64);
    try { FullSnapshotArchive::extractVerified($archive, $tampered, $root . '/tampered'); throw new RuntimeException('Tampered SQL digest was accepted.'); }
    catch (InvalidArgumentException $expected) { /* digest mismatch required */ }
    $badAsset = $manifest; $badAsset['assets'][0]['sha256'] = str_repeat('0', 64);
    try { FullSnapshotArchive::extractVerified($archive, $badAsset, $root . '/bad-asset'); throw new RuntimeException('Tampered asset digest was accepted.'); }
    catch (InvalidArgumentException $expected) { /* digest mismatch required */ }
    $missingSql = $root . '/missing-sql.zip';
    $zip = new ZipArchive(); $zip->open($missingSql, ZipArchive::CREATE);
    $zip->addFile($payload . '/secrets/recovery.json', 'secrets/recovery.json');
    $zip->addFile($payload . '/' . $avatar, $avatar);
    $zip->close();
    try { FullSnapshotArchive::extractVerified($missingSql, $manifest, $root . '/missing'); throw new RuntimeException('Missing SQL was accepted.'); }
    catch (InvalidArgumentException $expected) { /* exact inventory required */ }
    $unsafe = $root . '/unsafe.zip';
    $zip = new ZipArchive(); $zip->open($unsafe, ZipArchive::CREATE);
    $zip->addFile($payload . '/database/snapshot.sql', '../snapshot.sql');
    $zip->addFile($payload . '/secrets/recovery.json', 'secrets/recovery.json');
    $zip->addFile($payload . '/' . $avatar, $avatar);
    $zip->close();
    try { FullSnapshotArchive::extractVerified($unsafe, $manifest, $root . '/unsafe-extracted'); throw new RuntimeException('Unsafe SQL member was accepted.'); }
    catch (InvalidArgumentException $expected) { /* unsafe path required */ }
    foreach (['database/snapshot.sql', $avatar] as $duplicatePath) {
        $other = $duplicatePath === 'database/snapshot.sql' ? $avatar : 'database/snapshot.sql';
        $duplicate = $root . '/duplicate-' . ($other === $avatar ? 'sql' : 'asset') . '.zip';
        snapshotTestZipWithMembers($duplicate, [
            ['path' => $duplicatePath, 'bytes' => file_get_contents($payload . '/' . $duplicatePath)],
            ['path' => $duplicatePath, 'bytes' => file_get_contents($payload . '/' . $duplicatePath)],
            ['path' => 'secrets/recovery.json', 'bytes' => file_get_contents($payload . '/secrets/recovery.json')],
        ]);
        try { FullSnapshotArchive::extractVerified($duplicate, $manifest, $root . '/duplicate-extracted');
            throw new RuntimeException('Duplicate full-snapshot member was accepted: ' . $duplicatePath); }
        catch (InvalidArgumentException $expected) {
            if (strpos($expected->getMessage(), 'duplicate member') === false) { throw $expected; }
        }
    }
    echo json_encode(['status' => 'ok', 'archive_sha256' => hash_file('sha256', $archive), 'members' => 3]) . PHP_EOL;
} finally { snapshotTestTreeRemove($root); }
