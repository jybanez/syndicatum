<?php

require_once __DIR__ . '/FullSnapshotArchive.php';
require_once __DIR__ . '/FullSnapshotSql.php';
require_once __DIR__ . '/BackupSecrets.php';

/** Restores only a full-snapshot envelope into a separately approved empty target. */
final class FullSnapshotRestore
{
    private $pdo;
    private $baseline;
    private $stageRoot;
    private $assetRoot;
    private $publicRoot;
    private $expectedDatabase;

    public function __construct(PDO $target, BaselineMetadata $baseline, $stageRoot, $targetAssetRoot, $publicRoot, $expectedDatabase)
    {
        if (!is_string($expectedDatabase) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $expectedDatabase)) {
            throw new InvalidArgumentException('Explicit restore target database name is required.');
        }
        $this->pdo = $target;
        $this->baseline = $baseline;
        $this->stageRoot = self::directory($stageRoot, 'staging');
        $this->assetRoot = self::directory($targetAssetRoot, 'asset');
        $this->publicRoot = self::directory($publicRoot, 'public');
        $this->expectedDatabase = $expectedDatabase;
        if (self::inside($this->stageRoot, $this->publicRoot) || self::inside($this->assetRoot, $this->publicRoot)
            || self::inside($this->stageRoot, $this->assetRoot) || self::inside($this->assetRoot, $this->stageRoot)) {
            throw new InvalidArgumentException('Full-snapshot private roots overlap or are publicly accessible.');
        }
        if (DIRECTORY_SEPARATOR === '/' && ((fileperms($this->stageRoot) & 0077) !== 0 || (fileperms($this->assetRoot) & 0077) !== 0)) {
            throw new InvalidArgumentException('Full-snapshot restore directories must be private.');
        }
    }

    public function restore($envelopePath, $key, array $targetSecrets)
    {
        $actualDatabase = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!hash_equals($this->expectedDatabase, $actualDatabase)) {
            throw new RuntimeException('Full-snapshot connection is not the explicitly approved target database.');
        }
        if ((int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() !== 0) {
            throw new RuntimeException('Full-snapshot target database is not empty.');
        }
        $files = scandir($this->assetRoot);
        if (!is_array($files) || array_values(array_diff($files, ['.', '..'])) !== []) {
            throw new RuntimeException('Full-snapshot target asset directory is not empty.');
        }
        $decrypted = BackupEnvelope::decryptToPrivateStage($envelopePath, $this->stageRoot, $key);
        $stage = $decrypted['stage_path'];
        $result = null;
        try {
            $manifest = FullSnapshotManifest::parse($decrypted['manifest_json']);
            $header = $decrypted['header'];
            foreach (['application_version','schema_baseline','schema_head','source_commit'] as $field) {
                if (!hash_equals($manifest[$field], $header[$field])) {
                    throw new InvalidArgumentException('Full-snapshot envelope and manifest identity differ.');
                }
            }
            $trusted = $this->baseline->toArray();
            foreach (['application_version' => 'application_version','schema_baseline' => 'baseline_id','schema_head' => 'schema_head'] as $manifestField => $baselineField) {
                if (!hash_equals((string) $trusted[$baselineField], $manifest[$manifestField])) {
                    throw new InvalidArgumentException('Full-snapshot identity differs from trusted baseline: ' . $manifestField . '.');
                }
            }
            $names = array_map(function ($table) { return $table['name']; }, $trusted['tables']);
            sort($names, SORT_STRING);
            if (array_keys($manifest['sql']['row_counts']) !== $names || $manifest['sql']['table_count'] !== count($names)) {
                throw new InvalidArgumentException('Full-snapshot table inventory differs from trusted baseline.');
            }
            $extracted = FullSnapshotArchive::extractVerified($decrypted['archive_path'], $manifest, $stage . DIRECTORY_SEPARATOR . 'extracted');
            $secretJson = file_get_contents($extracted['secrets_path']);
            $classifications = BackupSecrets::assertTargetMatches(BackupSecrets::parse($secretJson), $targetSecrets);
            $import = FullSnapshotSql::importIntoEmpty($this->pdo, $this->baseline, $extracted['sql_path'],
                $manifest['sql']['row_counts'], $manifest['sql']['row_hashes']);
            $identity = $this->pdo->query('SELECT application_version, schema_baseline, schema_head, release_source_commit AS source_commit, installation_id FROM syndicatum_installation_identity WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity)) { throw new RuntimeException('Full-snapshot restored installation identity is missing.'); }
            foreach (['application_version','schema_baseline','schema_head','source_commit'] as $field) {
                if (!hash_equals($manifest[$field], (string) $identity[$field])) {
                    throw new RuntimeException('Full-snapshot restored identity differs: ' . $field . '.');
                }
            }
            if (!hash_equals($manifest['source_installation_id'], (string) $identity['installation_id'])) {
                throw new RuntimeException('Full-snapshot restored installation ID differs from source.');
            }
            $triggers = (int) $this->pdo->query('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()')->fetchColumn();
            if ($triggers !== $manifest['sql']['trigger_count']) { throw new RuntimeException('Full-snapshot restored trigger count differs.'); }
            $ledger = (int) $this->pdo->query('SELECT COUNT(*) FROM syndicatum_schema_migrations')->fetchColumn();
            if ($ledger !== $manifest['sql']['row_counts']['syndicatum_schema_migrations']) {
                throw new RuntimeException('Full-snapshot migration ledger differs from snapshot.');
            }
            $this->copyAssets($stage . DIRECTORY_SEPARATOR . 'extracted', $manifest['assets']);
            $result = ['format' => FullSnapshotManifest::CONTRACT_NAME, 'table_count' => $import['table_count'],
                'trigger_count' => $triggers, 'row_counts' => $import['row_counts'], 'asset_count' => count($manifest['assets']),
                'source_installation_id' => $manifest['source_installation_id'], 'secret_classifications' => $classifications,
                'cutover_performed' => false, 'plaintext_cleanup_verified' => false];
        } finally {
            BackupEnvelope::removePrivateStage($stage, $this->stageRoot);
            if (file_exists($stage)) { throw new RuntimeException('Full-snapshot restore plaintext stage could not be removed.'); }
        }
        $result['plaintext_cleanup_verified'] = true;
        return $result;
    }

    private function copyAssets($extractedRoot, array $assets)
    {
        foreach ($assets as $asset) {
            $source = $extractedRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset['path']);
            $destination = $this->assetRoot . DIRECTORY_SEPARATOR . basename($asset['path']);
            if (!is_file($source) || is_link($source) || file_exists($destination)) {
                throw new RuntimeException('Full-snapshot target avatar cannot be created exclusively.');
            }
            $input = fopen($source, 'rb'); $output = @fopen($destination, 'xb');
            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) { fclose($input); }
                if (is_resource($output)) { fclose($output); }
                throw new RuntimeException('Full-snapshot avatar copy could not start.');
            }
            @chmod($destination, 0600);
            $hash = hash_init('sha256'); $size = 0;
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 65536);
                    if ($chunk === false || ($chunk === '' && !feof($input))) { throw new RuntimeException('Full-snapshot avatar source read failed.'); }
                    $size += strlen($chunk); hash_update($hash, $chunk);
                    for ($offset = 0, $length = strlen($chunk); $offset < $length;) {
                        $written = fwrite($output, substr($chunk, $offset));
                        if (!is_int($written) || $written < 1) { throw new RuntimeException('Full-snapshot avatar target write failed.'); }
                        $offset += $written;
                    }
                }
            } finally { fclose($input); fclose($output); }
            if ($size !== $asset['bytes'] || !hash_equals($asset['sha256'], hash_final($hash))) {
                throw new RuntimeException('Full-snapshot restored avatar digest differs.');
            }
        }
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
}
