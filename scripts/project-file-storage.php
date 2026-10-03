<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/LocalFileStorage.php';

$command = isset($argv[1]) ? (string) $argv[1] : 'status';
if (!in_array($command, ['status', 'group-by-project'], true)) {
    fwrite(STDERR, "Usage: php scripts/project-file-storage.php [status|group-by-project]\n");
    exit(2);
}

$pdo = Db::pdo();
$settings = new SettingsService($pdo);
$base = trim((string) $settings->get('storage.local_base_path'));
if ($base === '') { throw new RuntimeException('Project file storage is not configured.'); }
$storage = new LocalFileStorage(dirname(__DIR__), $base);
$statement = $pdo->query(
    "SELECT f.id, f.storage_key, f.sha256, p.public_id AS project_public_id
     FROM project_files f JOIN projects p ON p.id = f.project_id
     WHERE f.storage_driver = 'local' AND f.state <> 'deleted' AND f.deleted_at IS NULL ORDER BY f.id"
);
$rows = $statement->fetchAll();
$legacy = array_values(array_filter($rows, function ($row) {
    return preg_match('#\Aobjects/[a-f0-9]{2}/[a-f0-9]{64}\z#', (string) $row['storage_key']) === 1;
}));

if ($command === 'status') {
    echo json_encode(['local_objects' => count($rows), 'legacy_ungrouped' => count($legacy),
        'project_grouped' => count($rows) - count($legacy)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$moved = 0;
foreach ($legacy as $row) {
    $oldKey = (string) $row['storage_key'];
    $newKey = $storage->relocateObject($oldKey, (string) $row['project_public_id']);
    try {
        if (!hash_equals((string) $row['sha256'], $storage->checksum($newKey))) {
            throw new RuntimeException('Relocated object checksum mismatch for file ' . (int) $row['id'] . '.');
        }
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE project_files SET storage_key = ?, updated_at = ? WHERE id = ? AND storage_key = ?');
        $update->execute([$newKey, Db::now(), (int) $row['id'], $oldKey]);
        if ($update->rowCount() !== 1) { throw new RuntimeException('Project file metadata changed during relocation.'); }
        $pdo->commit();
        $moved++;
    } catch (Exception $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        try { $storage->relocateObject($newKey, null); }
        catch (Exception $compensation) { error_log('Project file relocation compensation failed: ' . $compensation->getMessage()); }
        throw $exception;
    }
}

echo json_encode(['moved' => $moved, 'remaining_legacy_ungrouped' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
