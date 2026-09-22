<?php

require_once __DIR__ . '/FullSnapshotSql.php';
require_once __DIR__ . '/FullSnapshotArchive.php';
require_once __DIR__ . '/BackupSecrets.php';

/** Produces only the new full-snapshot format; never reinterprets NDJSON backups. */
final class FullSnapshotProducer
{
    private $pdo;
    private $baseline;
    private $stageRoot;
    private $assetRoot;
    private $publicRoot;

    public function __construct(PDO $pdo, BaselineMetadata $baseline, $stageRoot, $assetRoot, $publicRoot)
    {
        $this->pdo = $pdo;
        $this->baseline = $baseline;
        $this->stageRoot = self::directory($stageRoot, 'staging');
        $this->assetRoot = self::directory($assetRoot, 'avatar');
        $this->publicRoot = self::directory($publicRoot, 'public');
        if (self::inside($this->stageRoot, $this->publicRoot)) {
            throw new InvalidArgumentException('Full-snapshot staging must be outside the public web root.');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($this->stageRoot) & 0077) !== 0) {
            throw new InvalidArgumentException('Full-snapshot staging directory must be private.');
        }
    }

    public function produce($outputPath, $encryptionKey, array $exportedSecrets)
    {
        if (!is_string($outputPath) || $outputPath === '' || file_exists($outputPath)) {
            throw new InvalidArgumentException('Full-snapshot output must be a new file.');
        }
        $parent = self::directory(dirname($outputPath), 'output');
        if (self::inside($parent, $this->publicRoot) || self::inside($parent, $this->assetRoot)
            || self::inside($parent, $this->stageRoot)
            || self::inside($this->stageRoot, $parent)) {
            throw new InvalidArgumentException('Full-snapshot output must be outside public and staging roots.');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($parent) & 0077) !== 0) {
            throw new InvalidArgumentException('Full-snapshot output directory must be private.');
        }
        BackupEnvelope::keyId($encryptionKey);
        $identity = $this->pdo->query('SELECT application_version, schema_baseline, schema_head, release_source_commit AS source_commit, installation_id FROM syndicatum_installation_identity WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($identity)) { throw new RuntimeException('Full-snapshot source installation identity is missing.'); }
        $baseline = $this->baseline->toArray();
        foreach (['application_version' => 'application_version', 'schema_baseline' => 'baseline_id', 'schema_head' => 'schema_head'] as $source => $trusted) {
            if (!hash_equals((string) $baseline[$trusted], (string) $identity[$source])) {
                throw new RuntimeException('Full-snapshot source identity differs from trusted baseline: ' . $source . '.');
            }
        }
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $database)) { throw new RuntimeException('Full-snapshot source database name is invalid.'); }
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        if (!preg_match('/\A(8\.4\.\d+)/', $version, $versionMatch)) { throw new RuntimeException('Full-snapshot source requires MySQL 8.4.x.'); }
        $stage = $this->stageRoot . DIRECTORY_SEPARATOR . 'full-snapshot-' . bin2hex(random_bytes(12));
        if (!mkdir($stage, 0700)) { throw new RuntimeException('Full-snapshot private stage could not be created.'); }
        @chmod($stage, 0700);
        $result = null;
        try {
            $payload = $stage . DIRECTORY_SEPARATOR . 'payload';
            foreach (['database','assets/avatars','secrets'] as $name) {
                if (!mkdir($payload . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name), 0700, true)) {
                    throw new RuntimeException('Full-snapshot payload directory could not be created.');
                }
            }
            $sql = FullSnapshotSql::export($this->pdo, $this->baseline, $payload . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'snapshot.sql');
            $assets = $this->copyAvatars($payload, $sql['referenced_avatars']);
            $secretDocument = BackupSecrets::create($exportedSecrets);
            $secretPath = $payload . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'recovery.json';
            self::writePrivate($secretPath, BackupSecrets::encode($secretDocument));
            $manifest = [
                'contract_name' => FullSnapshotManifest::CONTRACT_NAME, 'format_version' => FullSnapshotManifest::FORMAT_VERSION,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'application_version' => $identity['application_version'],
                'schema_baseline' => $identity['schema_baseline'], 'schema_head' => $identity['schema_head'],
                'source_commit' => strtolower($identity['source_commit']), 'source_installation_id' => $identity['installation_id'],
                'source_database' => $database, 'mysql_version' => $versionMatch[1],
                'sql' => ['path' => FullSnapshotManifest::SQL_PATH, 'sha256' => $sql['sha256'], 'bytes' => $sql['bytes'],
                    'table_count' => $sql['table_count'], 'trigger_count' => $sql['trigger_count'],
                    'row_counts' => $sql['row_counts'], 'row_hashes' => $sql['row_hashes']],
                'assets' => $assets, 'secrets' => ['path' => FullSnapshotManifest::SECRET_PATH,
                    'sha256' => hash_file('sha256', $secretPath), 'bytes' => filesize($secretPath)],
            ];
            $manifestJson = FullSnapshotManifest::encode($manifest);
            $archivePath = $stage . DIRECTORY_SEPARATOR . 'archive.zip';
            $archiveSha = FullSnapshotArchive::create($payload, $manifest, $archivePath);
            $envelope = BackupEnvelope::encrypt($archivePath, $manifestJson, $outputPath, $encryptionKey, $identity);
            $result = ['path' => $outputPath, 'envelope_sha256' => $envelope['envelope_sha256'],
                'archive_sha256' => $archiveSha, 'manifest_sha256' => hash('sha256', $manifestJson),
                'sql_sha256' => $sql['sha256'], 'row_counts' => $sql['row_counts'], 'asset_count' => count($assets),
                'format' => FullSnapshotManifest::CONTRACT_NAME, 'plaintext_cleanup_verified' => false];
        } finally {
            BackupEnvelope::removePrivateStage($stage, $this->stageRoot);
            if (file_exists($stage)) { throw new RuntimeException('Full-snapshot plaintext stage could not be removed.'); }
        }
        $result['plaintext_cleanup_verified'] = true;
        return $result;
    }

    private function copyAvatars($payload, array $required)
    {
        $missing = array_fill_keys($required, true);
        $assets = [];
        $names = scandir($this->assetRoot);
        if (!is_array($names)) { throw new RuntimeException('Full-snapshot avatar directory could not be read.'); }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') { continue; }
            if (!preg_match('/\A[a-f0-9]{40}\.(?:jpg|png|webp)\z/', $name)) {
                throw new RuntimeException('Full-snapshot avatar filename is outside the supported inventory.');
            }
            $source = $this->assetRoot . DIRECTORY_SEPARATOR . $name;
            if (!is_file($source) || is_link($source)) { throw new RuntimeException('Full-snapshot avatar is not a regular file.'); }
            $before = stat($source);
            $bytes = file_get_contents($source);
            $after = stat($source);
            if ($bytes === false || !is_array($before) || !is_array($after) || $before['ino'] !== $after['ino']
                || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] || strlen($bytes) !== $after['size']) {
                throw new RuntimeException('Full-snapshot avatar changed during capture.');
            }
            $path = 'assets/avatars/' . $name;
            self::writePrivate($payload . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path), $bytes);
            $assets[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
            unset($missing[$name]);
        }
        if ($missing) { throw new RuntimeException('Full-snapshot source references missing avatars.'); }
        usort($assets, function ($a, $b) { return strcmp($a['path'], $b['path']); });
        return $assets;
    }

    private static function directory($path, $label)
    {
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real) || is_link($path)) { throw new InvalidArgumentException('Full-snapshot ' . $label . ' directory is invalid.'); }
        return $real;
    }

    private static function inside($path, $root)
    {
        return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
    }

    private static function writePrivate($path, $bytes)
    {
        $stream = @fopen($path, 'xb');
        if (!is_resource($stream)) { throw new RuntimeException('Full-snapshot private file could not be created.'); }
        @chmod($path, 0600);
        try {
            for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
                $written = fwrite($stream, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) { throw new RuntimeException('Full-snapshot private file write failed.'); }
                $offset += $written;
            }
        } finally { fclose($stream); }
    }
}
