<?php

require_once __DIR__ . '/CurrentBaselineSql.php';
require_once __DIR__ . '/CurrentBackupEnvelope.php';
require_once __DIR__ . '/PortableBackupArchive.php';
require_once __DIR__ . '/PortableBackupConfiguration.php';
require_once __DIR__ . '/PortableBackupRuntime.php';

/** Produces a portable, encrypted package of the running Syndicatum baseline. */
final class CurrentBackupProducer
{
    private $pdo;
    private $stageRoot;
    private $assetRoot;
    private $publicRoot;

    public function __construct(PDO $pdo, $stageRoot, $assetRoot, $publicRoot)
    {
        $this->pdo = $pdo;
        $this->stageRoot = self::directory($stageRoot, 'staging');
        $this->assetRoot = self::directory($assetRoot, 'avatar');
        $this->publicRoot = self::directory($publicRoot, 'application');
        if (self::inside($this->stageRoot, $this->publicRoot)) { throw new InvalidArgumentException('Backup staging must be outside the application root.'); }
    }

    public function produce($outputPath, $encryptionKey, $operationId, $onProgress = null, $includeData = true)
    {
        if ($onProgress !== null && !is_callable($onProgress)) { throw new InvalidArgumentException('Backup progress callback must be callable.'); }
        if (!is_bool($includeData)) { throw new InvalidArgumentException('Backup package type is invalid.'); }
        $emit = function ($stage, $percent) use ($onProgress) { if ($onProgress !== null) { call_user_func($onProgress, $stage, $percent); } };
        $emit('Preparing backup', 0);
        if (!is_string($outputPath) || $outputPath === '' || file_exists($outputPath)) { throw new InvalidArgumentException('Backup output must be a new file.'); }
        $parent = self::directory(dirname($outputPath), 'output');
        if (self::inside($parent, $this->publicRoot) || self::inside($parent, $this->assetRoot)
            || self::inside($parent, $this->stageRoot) || self::inside($this->stageRoot, $parent)) {
            throw new InvalidArgumentException('Backup output must be outside application and staging roots.');
        }
        CurrentBackupEnvelope::keyId($encryptionKey);
        $version = CurrentBaselineSql::assertSupportedSourceVersion((string) $this->pdo->query('SELECT VERSION()')->fetchColumn());
        $identity = self::optionalIdentity($this->pdo);
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $database)) { throw new RuntimeException('Backup source database name is invalid.'); }
        $stage = $this->stageRoot . DIRECTORY_SEPARATOR . 'portable-backup-' . bin2hex(random_bytes(12));
        if (!mkdir($stage, 0700)) { throw new RuntimeException('Backup private stage could not be created.'); }
        @chmod($stage, 0700);
        $committedOutput = false;
        try {
            $payload = $stage . DIRECTORY_SEPARATOR . 'payload';
            foreach (['database','runtime','persistent/avatars','configuration'] as $name) {
                if (!mkdir($payload . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name), 0700, true)) {
                    throw new RuntimeException('Backup payload directory could not be created.');
                }
            }
            $emit('Preparing backup', 100);

            $emit('Collecting production runtime', 0);
            $runtime = (new PortableBackupRuntime($this->publicRoot))->collect($payload, function ($percent) use ($emit) {
                $emit('Collecting production runtime', $percent);
            });
            $emit('Collecting production runtime', 100);

            $emit('Exporting database', 0);
            $sql = CurrentBaselineSql::exportPortable($this->pdo, $payload . DIRECTORY_SEPARATOR . 'database',
                function ($done, $total) use ($emit) { $emit('Exporting database', (int) floor(100 * $done / max(1, $total))); }, $includeData);
            $emit('Exporting database', 100);

            $emit('Collecting persistent files', 0);
            $files = $this->copyAvatars($payload, $sql['referenced_avatars'], $emit);
            $emit('Collecting persistent files', 100);

            $emit('Securing configuration', 0);
            $configuration = PortableBackupConfiguration::collect($payload);
            $emit('Securing configuration', 100);

            $manifest = [
                'contract_name' => PortableBackupManifest::CONTRACT_NAME, 'format_version' => PortableBackupManifest::FORMAT_VERSION,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'operation_id' => $operationId,
                'package_type' => $includeData ? 'full_clone' : 'clean_installation', 'include_data' => $includeData,
                'source_database' => $database, 'source_mysql_version' => $version,
                'source_application_version' => $identity['application_version'], 'source_commit' => $identity['source_commit'],
                'source_installation_id' => $identity['installation_id'],
                'sql' => ['schema' => $sql['schema'], 'data' => $sql['data'], 'triggers' => $sql['triggers'],
                    'table_count' => $sql['table_count'], 'trigger_count' => $sql['trigger_count'],
                    'row_counts' => $sql['row_counts'], 'row_hashes' => $sql['row_hashes']],
                'runtime' => $runtime, 'persistent_files' => $files, 'configuration' => $configuration,
            ];
            $manifestJson = PortableBackupManifest::encode($manifest);
            $archivePath = $stage . DIRECTORY_SEPARATOR . 'archive.zip';
            $emit('Building encrypted package', 0);
            $archiveSha = PortableBackupArchive::create($payload, $manifest, $archivePath);
            $envelope = CurrentBackupEnvelope::encrypt($archivePath, $manifestJson, $outputPath, $encryptionKey);
            $committedOutput = true;
            $emit('Building encrypted package', 100);

            $emit('Verifying package', 0);
            $verified = CurrentBackupEnvelope::decryptToPrivateStage($outputPath, $this->stageRoot, $encryptionKey);
            try {
                if (!hash_equals($archiveSha, $verified['archive_sha256'])
                    || !hash_equals(hash('sha256', $manifestJson), $verified['manifest_sha256'])
                    || !hash_equals($envelope['envelope_sha256'], hash_file('sha256', $outputPath))) {
                    throw new RuntimeException('Completed backup verification failed.');
                }
                PortableBackupArchive::verify($verified['archive_path'], PortableBackupManifest::parse($verified['manifest_json']));
            } finally { CurrentBackupEnvelope::removePrivateStage($verified['stage_path'], $this->stageRoot); }
            $emit('Verifying package', 100);
            $inspectionPath = dirname($this->stageRoot) . DIRECTORY_SEPARATOR . 'inspection' . DIRECTORY_SEPARATOR
                . strtolower($operationId) . '.zip';
            self::copyInspectionArchive($archivePath, $inspectionPath, $archiveSha);
            $result = ['path' => $outputPath, 'envelope_sha256' => $envelope['envelope_sha256'], 'archive_sha256' => $archiveSha,
                'manifest_sha256' => hash('sha256', $manifestJson),
                'sql_sha256' => hash('sha256', json_encode([$sql['schema'], $sql['data'], $sql['triggers']], JSON_UNESCAPED_SLASHES)),
                'row_counts' => $sql['row_counts'],
                'runtime_count' => count($runtime), 'persistent_file_count' => count($files),
                'configuration_count' => count($configuration), 'format' => PortableBackupManifest::CONTRACT_NAME,
                'inspection_path' => $inspectionPath, 'plaintext_cleanup_verified' => false];
        } catch (Throwable $exception) {
            if ($committedOutput && is_file($outputPath)) { @unlink($outputPath); }
            throw $exception;
        } finally {
            CurrentBackupEnvelope::removePrivateStage($stage, $this->stageRoot);
            if (file_exists($stage)) { throw new RuntimeException('Backup plaintext stage could not be removed.'); }
        }
        $result['plaintext_cleanup_verified'] = true;
        $emit('Complete', 100);
        return $result;
    }

    private function copyAvatars($payload, array $required, $emit)
    {
        $entries = []; $required = array_values(array_unique($required)); sort($required, SORT_STRING); $count = count($required);
        foreach ($required as $index => $name) {
            if (!preg_match('/\A[a-f0-9]{40}\.(?:jpg|png|webp)\z/', $name)) { throw new RuntimeException('Backup avatar filename is outside the supported inventory.'); }
            $source = $this->assetRoot . DIRECTORY_SEPARATOR . $name;
            if (!is_file($source) || is_link($source)) { throw new RuntimeException('A referenced avatar is missing or invalid; retry the backup.'); }
            clearstatcache(true, $source); $before = @stat($source);
            $bytes = @file_get_contents($source); $sourceHash = @hash_file('sha256', $source);
            if (!is_array($before) || !is_string($bytes) || !is_string($sourceHash)) { throw new RuntimeException('A referenced avatar could not be read; retry the backup.'); }
            $path = 'persistent/avatars/' . $name;
            $staged = $payload . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            PortableBackupRuntime::writePrivate($staged, $bytes);
            $emit('Collecting persistent files', (int) floor((($index + 0.5) * 100) / max(1, $count)));
            clearstatcache(true, $source); $after = @stat($source); $afterHash = @hash_file('sha256', $source);
            if (!is_array($after) || !is_string($afterHash) || $before['size'] !== $after['size']
                || strlen($bytes) !== $before['size'] || !hash_equals($sourceHash, $afterHash)
                || !hash_equals($sourceHash, hash_file('sha256', $staged))) {
                throw new RuntimeException('A referenced avatar changed during capture; retry the backup.');
            }
            $entries[] = ['path' => $path, 'sha256' => $sourceHash, 'bytes' => strlen($bytes)];
            $emit('Collecting persistent files', (int) floor((($index + 1) * 100) / max(1, $count)));
        }
        return $entries;
    }

    private static function optionalIdentity(PDO $pdo)
    {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'syndicatum_installation_identity'")->fetchColumn();
        if ($count === 0) { return ['application_version' => null, 'source_commit' => null, 'installation_id' => null]; }
        $row = $pdo->query('SELECT application_version, release_source_commit AS source_commit, installation_id FROM syndicatum_installation_identity WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { return ['application_version' => null, 'source_commit' => null, 'installation_id' => null]; }
        return ['application_version' => $row['application_version'] ?: null, 'source_commit' => $row['source_commit'] ?: null, 'installation_id' => $row['installation_id'] ?: null];
    }

    private static function directory($path, $label)
    {
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real) || is_link($path)) { throw new InvalidArgumentException('Portable-backup ' . $label . ' directory is invalid.'); }
        return $real;
    }

    private static function inside($path, $root) { return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0; }

    private static function copyInspectionArchive($source, $destination, $expectedSha256)
    {
        $root = dirname($destination);
        if (!is_dir($root) && !mkdir($root, 0700, true)) { throw new RuntimeException('Backup inspection directory could not be created.'); }
        if (is_link($root) || !is_dir($root) || file_exists($destination)) {
            throw new RuntimeException('Backup inspection destination is unavailable.');
        }
        $temporary = $destination . '.tmp-' . bin2hex(random_bytes(6));
        if (!copy($source, $temporary)) { throw new RuntimeException('Backup inspection ZIP could not be copied.'); }
        @chmod($temporary, 0600);
        if (!hash_equals($expectedSha256, hash_file('sha256', $temporary)) || !rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Backup inspection ZIP failed verification.');
        }
        @chmod($destination, 0600);
    }
}
