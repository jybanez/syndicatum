<?php

require_once dirname(__DIR__) . '/src/PortableBackupManifest.php';
require_once dirname(__DIR__) . '/src/PortableBackupArchive.php';
require_once dirname(__DIR__) . '/src/CurrentBackupEnvelope.php';
require_once dirname(__DIR__) . '/src/ProjectFileBackupSource.php';
require_once dirname(__DIR__) . '/src/ProjectFileRestoreTarget.php';
require_once dirname(__DIR__) . '/src/ProjectFileBackupEligibility.php';
require_once dirname(__DIR__) . '/src/CurrentBackupJobStore.php';

function projectBackupAssert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function projectBackupEntry($path, $relative) { return ['path' => $relative, 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path)]; }
function projectBackupRemove($path) {
    if (!is_dir($path) || is_link($path)) { @unlink($path); return; }
    foreach (scandir($path) as $name) { if ($name !== '.' && $name !== '..') { projectBackupRemove($path . DIRECTORY_SEPARATOR . $name); } }
    @rmdir($path);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-project-backup-' . bin2hex(random_bytes(8));
$payload = $root . DIRECTORY_SEPARATOR . 'payload';
$external = $root . DIRECTORY_SEPARATOR . 'object';
try {
    foreach (['database', 'runtime', 'configuration'] as $directory) {
        if (!mkdir($payload . DIRECTORY_SEPARATOR . $directory, 0700, true)) { throw new RuntimeException('Fixture directory failed.'); }
    }
    file_put_contents($payload . '/database/schema.sql', 'schema');
    file_put_contents($payload . '/database/triggers.sql', 'triggers');
    file_put_contents($payload . '/runtime/index.php', 'runtime');
    file_put_contents($payload . '/configuration/system.json', '{}');
    file_put_contents($external, str_repeat('project-file', 1024));
    $publicId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $projectKey = 'objects/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb/cc/' . str_repeat('c', 64);
    $projectPath = 'persistent/project-files/' . $publicId;
    $manifest = [
        'contract_name' => PortableBackupManifest::CONTRACT_NAME, 'format_version' => PortableBackupManifest::FORMAT_VERSION,
        'created_at' => '2026-10-04T00:00:00Z', 'operation_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'package_type' => 'full_clone', 'include_data' => true, 'source_database' => 'syndicatum',
        'source_mysql_version' => '8.4.7', 'source_application_version' => 'fixture', 'source_commit' => str_repeat('e', 40),
        'source_installation_id' => 'fixture',
        'sql' => [
            'schema' => projectBackupEntry($payload . '/database/schema.sql', 'database/schema.sql'),
            'data' => [], 'triggers' => projectBackupEntry($payload . '/database/triggers.sql', 'database/triggers.sql'),
            'table_count' => 1, 'trigger_count' => 0, 'row_counts' => ['projects' => 0],
            'row_hashes' => ['projects' => hash('sha256', '')],
        ],
        'runtime' => [projectBackupEntry($payload . '/runtime/index.php', 'runtime/index.php')],
        'persistent_files' => [],
        'project_files' => [[
            'path' => $projectPath, 'provider' => 'local', 'key' => $projectKey, 'public_id' => $publicId,
            'mime_type' => 'application/octet-stream', 'sha256' => hash_file('sha256', $external), 'bytes' => filesize($external),
        ]],
        'configuration' => [projectBackupEntry($payload . '/configuration/system.json', 'configuration/system.json')],
    ];
    $encoded = PortableBackupManifest::encode($manifest);
    projectBackupAssert(PortableBackupManifest::parse($encoded) === $manifest, 'V3 project-file manifest did not round-trip canonically.');
    $archive = $root . DIRECTORY_SEPARATOR . 'backup.zip';
    PortableBackupArchive::create($payload, $manifest, $archive, [$projectPath => [
        'path' => $external, 'bytes' => filesize($external), 'sha256' => hash_file('sha256', $external),
    ]]);
    PortableBackupArchive::verify($archive, $manifest);
    $zip = new ZipArchive(); $zip->open($archive, ZipArchive::RDONLY);
    projectBackupAssert($zip->getFromName($projectPath) === file_get_contents($external), 'Project file bytes did not enter the archive directly.');
    $zip->close();

    $restoreApplication = $root . DIRECTORY_SEPARATOR . 'restore-application';
    $restoreStorage = $root . DIRECTORY_SEPARATOR . 'restore-storage';
    $restoreStage = $root . DIRECTORY_SEPARATOR . 'restore-stage';
    mkdir($restoreApplication, 0700); mkdir($restoreStorage, 0700); mkdir($restoreStage, 0700);
    $zip = new ZipArchive(); $zip->open($archive, ZipArchive::RDONLY);
    $restoreTarget = new ProjectFileRestoreTarget($restoreApplication, $restoreStorage);
    $staged = $restoreTarget->stage($zip, $manifest['project_files'], $restoreStage);
    $zip->close();
    $restoreTarget->install($staged); $restoreTarget->verify($staged); $restoreTarget->commit();
    $restoredPath = $restoreStorage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $projectKey);
    projectBackupAssert(is_file($restoredPath) && hash_file('sha256', $restoredPath) === hash_file('sha256', $external),
        'Verified project file restore did not preserve the provider-neutral key and bytes.');
    $interruptedStage = $root . DIRECTORY_SEPARATOR . 'interrupted-stage'; mkdir($interruptedStage, 0700);
    $interruptedEntry = $manifest['project_files'][0]; $interruptedEntry['bytes']++;
    $zip = new ZipArchive(); $zip->open($archive, ZipArchive::RDONLY);
    $interruptedRejected = false;
    try { $restoreTarget->stage($zip, [$interruptedEntry], $interruptedStage); }
    catch (RuntimeException $expected) { $interruptedRejected = true; }
    finally { $zip->close(); }
    projectBackupAssert($interruptedRejected && !file_exists($interruptedStage . DIRECTORY_SEPARATOR . 'project-files'),
        'An interrupted or truncated Project File stream did not fail closed and remove partial staging bytes.');

    $repeatStage = $root . DIRECTORY_SEPARATOR . 'repeat-stage'; mkdir($repeatStage, 0700);
    $zip = new ZipArchive(); $zip->open($archive, ZipArchive::RDONLY);
    $repeatStaged = $restoreTarget->stage($zip, $manifest['project_files'], $repeatStage); $zip->close();
    $beforeRepeat = hash_file('sha256', $restoredPath);
    $restoreTarget->install($repeatStaged); $restoreTarget->verify($repeatStaged); $restoreTarget->commit();
    projectBackupAssert(hash_file('sha256', $restoredPath) === $beforeRepeat,
        'A repeated identical Project File restore replaced or changed existing verified content.');
    file_put_contents($restoredPath, 'conflicting target bytes');
    $conflictRejected = false;
    try { $restoreTarget->install($repeatStaged); } catch (RuntimeException $expected) { $conflictRejected = true; }
    projectBackupAssert($conflictRejected && file_get_contents($restoredPath) === 'conflicting target bytes',
        'A repeated conflicting Project File restore replaced target content instead of failing closed.');
    if (DIRECTORY_SEPARATOR === '/') {
        $unwritableRestoreStorage = $root . DIRECTORY_SEPARATOR . 'unwritable-restore-storage';
        mkdir($unwritableRestoreStorage, 0500); chmod($unwritableRestoreStorage, 0500);
        $unwritableRestoreRejected = false;
        try { new ProjectFileRestoreTarget($restoreApplication, $unwritableRestoreStorage); }
        catch (RuntimeException $expected) { $unwritableRestoreRejected = true; }
        projectBackupAssert($unwritableRestoreRejected,
            'An unwritable Project File restore target was not rejected before staging or cutover.');
        chmod($unwritableRestoreStorage, 0700);
    }
    $rollbackStorage = $root . DIRECTORY_SEPARATOR . 'rollback-storage'; mkdir($rollbackStorage, 0700);
    $rollbackTarget = new ProjectFileRestoreTarget($restoreApplication, $rollbackStorage);
    $rollbackTarget->install($staged);
    $rollbackPath = $rollbackStorage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $projectKey);
    projectBackupAssert(is_file($rollbackPath), 'Rollback fixture was not installed.');
    $rollbackTarget->rollbackCreated();
    projectBackupAssert(!file_exists($rollbackPath), 'A failed restore did not remove its newly installed project file.');

    $previous = $manifest; $previous['format_version'] = PortableBackupManifest::PREVIOUS_FORMAT_VERSION; unset($previous['project_files']);
    projectBackupAssert(PortableBackupManifest::parse(PortableBackupManifest::encode($previous)) === $previous,
        'V2 portable backups must remain readable.');

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('SQLite is required for configured local-storage backup acceptance.');
    }
    {
        $application = $root . DIRECTORY_SEPARATOR . 'application';
        $storage = $root . DIRECTORY_SEPARATOR . 'storage';
        mkdir($application, 0700); mkdir($storage, 0700);
        $object = str_repeat('c', 64);
        $objectDirectory = $storage . DIRECTORY_SEPARATOR . 'objects' . DIRECTORY_SEPARATOR
            . 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' . DIRECTORY_SEPARATOR . 'cc';
        mkdir($objectDirectory, 0700, true);
        $sourcePath = $objectDirectory . DIRECTORY_SEPARATOR . $object;
        file_put_contents($sourcePath, 'verified bytes');
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE project_files (public_id TEXT, storage_driver TEXT, storage_key TEXT, mime_type TEXT, size_bytes INTEGER, sha256 TEXT, state TEXT, deleted_at TEXT)');
        $insert = $pdo->prepare('INSERT INTO project_files VALUES (?, ?, ?, ?, ?, ?, ?, NULL)');
        $insert->execute([$publicId, 'local', $projectKey, 'text/plain', filesize($sourcePath), hash_file('sha256', $sourcePath), 'available']);
        $source = new ProjectFileBackupSource($application, $storage);
        $inventory = $source->inventory($pdo, true);
        projectBackupAssert(count($inventory) === 1 && $inventory[0]['key'] === $projectKey,
            'Snapshot inventory did not capture the referenced local object.');
        $backupDestination = $root . DIRECTORY_SEPARATOR . 'backup-destination';
        mkdir($backupDestination, 0700); @chmod($backupDestination, 0700);
        $healthy = ProjectFileBackupEligibility::snapshot($pdo, $application, $storage, $backupDestination);
        projectBackupAssert($healthy['state'] === 'ready' && $healthy['full_clone_backup_eligible'] === true
            && $healthy['verified_file_count'] === 1 && $healthy['available_bytes'] === filesize($sourcePath),
            'Healthy Project File storage was not reported as full-clone eligible.');
        $repeated = ProjectFileBackupEligibility::snapshot($pdo, $application, $storage, $backupDestination);
        projectBackupAssert($repeated === $healthy, 'Repeated Project File backup preflight was not deterministic.');
        $insufficient = ProjectFileBackupEligibility::snapshot($pdo, $application, $storage, $backupDestination,
            function () use ($healthy) { return max(0, $healthy['available_bytes'] - 1); });
        projectBackupAssert($insufficient['full_clone_backup_eligible'] === false
            && $insufficient['unavailable_reason'] === 'insufficient_backup_space',
            'Insufficient backup capacity was not marked full-clone ineligible.');
        $jobStore = new CurrentBackupJobStore(new CurrentBackupStorage($application, $backupDestination));
        $requestKey = 'project-file-health-idempotency-key';
        projectBackupAssert($jobStore->findByRequest(7, $requestKey, true) === null,
            'A new backup request unexpectedly reconciled to an existing job.');
        $createdJob = $jobStore->create(7, 'Backup Operator', $requestKey, true);
        $reconciledJob = $jobStore->findByRequest(7, $requestKey, true);
        projectBackupAssert($reconciledJob['operation_id'] === $createdJob['operation_id'],
            'An accepted backup request could not be reconciled before a repeated preflight.');
        $configuredManifest = $manifest;
        $configuredManifest['project_files'] = $inventory;
        $configuredArchive = $root . DIRECTORY_SEPARATOR . 'configured-local-storage.zip';
        PortableBackupArchive::create($payload, $configuredManifest, $configuredArchive, $source->archiveSources());
        $configuredEnvelope = $root . DIRECTORY_SEPARATOR . 'configured-local-storage.syndicatum-backup';
        $configuredStage = $root . DIRECTORY_SEPARATOR . 'configured-stage';
        mkdir($configuredStage, 0700); @chmod($configuredStage, 0700);
        $configuredKey = random_bytes(32);
        $configuredJson = PortableBackupManifest::encode($configuredManifest);
        CurrentBackupEnvelope::encrypt($configuredArchive, $configuredJson, $configuredEnvelope, $configuredKey);
        $authenticated = CurrentBackupEnvelope::decryptToPrivateStage($configuredEnvelope, $configuredStage, $configuredKey);
        try {
            $authenticatedManifest = PortableBackupManifest::parse($authenticated['manifest_json']);
            PortableBackupArchive::verify($authenticated['archive_path'], $authenticatedManifest);
            projectBackupAssert($authenticatedManifest['project_files'] === $inventory,
                'Authenticated Windows-compatible backup did not preserve configured local-storage inventory.');
        } finally {
            CurrentBackupEnvelope::removePrivateStage($authenticated['stage_path'], $configuredStage);
        }
        file_put_contents($sourcePath, 'tampered bytes');
        $changedRejected = false;
        try { $source->verifyUnchanged(); } catch (RuntimeException $expected) { $changedRejected = true; }
        projectBackupAssert($changedRejected, 'A project file changed after snapshot was not rejected.');
        $corrupt = ProjectFileBackupEligibility::snapshot($pdo, $application, $storage, $backupDestination);
        projectBackupAssert($corrupt['full_clone_backup_eligible'] === false
            && $corrupt['unavailable_reason'] === 'project_file_integrity_failed',
            'Checksum-mismatched Project File bytes were not marked backup-ineligible.');
        file_put_contents($sourcePath, 'verified bytes');
        unlink($sourcePath);
        $missing = ProjectFileBackupEligibility::snapshot($pdo, $application, $storage, $backupDestination);
        projectBackupAssert($missing['full_clone_backup_eligible'] === false
            && $missing['unavailable_reason'] === 'project_file_integrity_failed',
            'A missing Project File object was not marked backup-ineligible.');
        $changedRoot = ProjectFileBackupEligibility::snapshot($pdo, $application,
            $root . DIRECTORY_SEPARATOR . 'moved-storage', $backupDestination);
        projectBackupAssert($changedRoot['full_clone_backup_eligible'] === false,
            'A changed or missing Project File storage root was not marked backup-ineligible.');
        if (DIRECTORY_SEPARATOR === '/') {
            file_put_contents($sourcePath, 'verified bytes');
            chmod($backupDestination, 0500);
            $unwritable = ProjectFileBackupEligibility::snapshot($pdo, $application, $storage, $backupDestination);
            projectBackupAssert($unwritable['full_clone_backup_eligible'] === false
                && $unwritable['unavailable_reason'] === 'backup_storage_unwritable',
                'An unwritable backup target was not marked backup-ineligible.');
            chmod($backupDestination, 0700);
        }
        $pdo->exec('DELETE FROM project_files');
        $empty = ProjectFileBackupEligibility::snapshot($pdo, $application, '', $backupDestination);
        projectBackupAssert($empty['full_clone_backup_eligible'] === true && $empty['available_file_count'] === 0,
            'An empty Project File inventory incorrectly required configured object storage for backup.');
    }
    echo "Portable project-file backup and configured-storage envelope contract passed.\n";
} finally { projectBackupRemove($root); }
