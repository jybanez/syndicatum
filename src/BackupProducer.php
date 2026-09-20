<?php

require_once __DIR__ . '/ArchiveSafetyReader.php';
require_once __DIR__ . '/BackupEnvelope.php';
require_once __DIR__ . '/BackupSecrets.php';
require_once __DIR__ . '/BaselineMetadata.php';
require_once __DIR__ . '/PackageManifest.php';

interface BackupDatabaseSource
{
    public function beginConsistentSnapshot();
    public function endConsistentSnapshot($success);
    public function tableNames();
    public function columns($table);
    public function nextSequenceValue($table);
    public function rows($table, array $identityColumns);
}

final class PdoBackupDatabaseSource implements BackupDatabaseSource
{
    private $pdo;
    private $active = false;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function beginConsistentSnapshot()
    {
        if ($this->active) { throw new RuntimeException('A backup snapshot is already active.'); }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $this->active = true;
    }

    public function endConsistentSnapshot($success)
    {
        if (!$this->active) { return; }
        try { $success ? $this->pdo->commit() : $this->pdo->rollBack(); }
        finally { $this->active = false; }
    }

    public function tableNames()
    {
        return $this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function columns($table)
    {
        self::assertIdentifier($table);
        $statement = $this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
        $statement->execute([$table]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function rows($table, array $identityColumns)
    {
        self::assertIdentifier($table);
        foreach ($identityColumns as $column) { self::assertIdentifier($column); }
        $order = implode(', ', array_map(function ($column) { return '`' . $column . '`'; }, $identityColumns));
        $statement = $this->pdo->query('SELECT * FROM `' . $table . '` ORDER BY ' . $order);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) { yield $row; }
    }

    public function nextSequenceValue($table)
    {
        self::assertIdentifier($table);
        $statement = $this->pdo->prepare('SELECT auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = ?');
        $statement->execute([$table, 'BASE TABLE']);
        $value = $statement->fetchColumn();
        if ($value === false || $value === null) { return null; }
        if (!is_numeric($value) || (int) $value < 1 || (string) (int) $value !== (string) $value) {
            throw new RuntimeException('Backup source sequence state is outside the supported integer range: ' . $table . '.');
        }
        return (int) $value;
    }

    private static function assertIdentifier($value)
    {
        if (!is_string($value) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $value)) { throw new InvalidArgumentException('Backup database identifier is invalid.'); }
    }
}

final class BackupProducer
{
    private $database;
    private $baseline;
    private $temporaryRoot;
    private $assetRoot;
    private $publicWebRoot;
    private $clock;

    public function __construct(BackupDatabaseSource $database, BaselineMetadata $baseline, $temporaryRoot, $assetRoot, $publicWebRoot, callable $clock = null)
    {
        $this->database = $database;
        $this->baseline = $baseline;
        $this->temporaryRoot = self::privateRoot($temporaryRoot, 'backup temporary root');
        $this->assetRoot = self::existingDirectory($assetRoot, 'avatar asset root');
        $this->publicWebRoot = self::existingDirectory($publicWebRoot, 'public web root');
        if (self::within($this->temporaryRoot, $this->publicWebRoot)) { throw new InvalidArgumentException('Backup temporary root must not be under the public web root.'); }
        $this->clock = $clock ?: function () { return gmdate('Y-m-d\TH:i:s\Z'); };
    }

    public function produce($destinationPath, $encryptionKey, array $identity, array $exportedSecrets)
    {
        self::assertDestinationOutsidePublicRoot($destinationPath, $this->publicWebRoot);
        foreach (['application_version','source_commit','schema_baseline','schema_head'] as $field) {
            if (!isset($identity[$field]) || !is_string($identity[$field]) || trim($identity[$field]) === '') {
                throw new InvalidArgumentException('Backup producer identity requires ' . $field . '.');
            }
        }
        $baselineArray = $this->baseline->toArray();
        $matches = [
            'application_version' => 'application_version', 'schema_baseline' => 'baseline_id',
            'schema_head' => 'schema_head',
        ];
        foreach ($matches as $identityField => $baselineField) {
            if (!hash_equals((string) $baselineArray[$baselineField], (string) $identity[$identityField])) {
                throw new InvalidArgumentException('Backup producer identity does not match trusted baseline ' . $baselineField . '.');
            }
        }

        $stage = self::createPrivateStage($this->temporaryRoot, 'backup-producer-');
        $snapshotActive = false;
        try {
            $payloadRoot = $stage . DIRECTORY_SEPARATOR . 'payload';
            foreach (['data','assets','metadata','secrets'] as $directory) {
                if (!mkdir($payloadRoot . DIRECTORY_SEPARATOR . $directory, 0700, true)) { throw new RuntimeException('Backup payload directory could not be created.'); }
            }
            $this->database->beginConsistentSnapshot();
            $snapshotActive = true;
            $schemaNames = $this->database->tableNames();
            $this->baseline->assertBaselineTables(array_values($schemaNames));
            foreach ($this->baseline->tablePolicies() as $table) {
                if ($this->database->columns($table['name']) !== $table['columns']) {
                    throw new RuntimeException('Backup source schema columns differ from trusted BaselineMetadata: ' . $table['name'] . '.');
                }
            }
            $requiredAvatars = [];
            $dataFiles = [];
            $rowCounts = [];
            $sequences = [];
            foreach ($this->baseline->tablesWithBackupPolicy('durable') as $table) {
                $path = 'data/' . $table['name'] . '.ndjson';
                $filePath = self::payloadPath($payloadRoot, $path);
                $columns = $table['columns'];
                $sequences[$table['name']] = $this->database->nextSequenceValue($table['name']);
                $stream = self::newPrivateFile($filePath);
                $rowCount = 0;
                try {
                    foreach ($this->database->rows($table['name'], $table['identity_columns']) as $row) {
                        if (!is_array($row) || array_keys($row) !== $columns) { throw new RuntimeException('Backup row columns differ from the trusted table schema: ' . $table['name'] . '.'); }
                        $canonical = [];
                        foreach ($columns as $column) {
                            $value = $row[$column];
                            if ($value !== null && !is_scalar($value)) { throw new RuntimeException('Backup row contains a non-scalar value.'); }
                            $canonical[$column] = $value === null ? null : (string) $value;
                            if (is_string($value) && preg_match_all('#api/v1/avatar\.php\?file=([a-f0-9]{40}\.(?:jpg|png|webp))#', $value, $matches)) {
                                foreach ($matches[1] as $name) { $requiredAvatars[$name] = true; }
                            }
                        }
                        $line = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if (!is_string($line)) { throw new RuntimeException('Backup row could not be encoded as UTF-8 JSON.'); }
                        self::writeAll($stream, $line . "\n");
                        $rowCount++;
                    }
                } finally { fclose($stream); }
                $dataFiles[] = $path;
                $rowCounts[$table['name']] = $rowCount;
            }

            $assetFiles = $this->copyAssets($payloadRoot, $requiredAvatars);
            $secretPath = 'secrets/recovery.json';
            self::writePrivate(self::payloadPath($payloadRoot, $secretPath), BackupSecrets::encode(BackupSecrets::create($exportedSecrets)));
            sort($dataFiles, SORT_STRING); sort($assetFiles, SORT_STRING);
            $recovery = [
                'contract_name' => 'syndicatum-backup-metadata', 'format_version' => '1.0',
                'baseline_id' => $baselineArray['baseline_id'], 'schema_head' => $baselineArray['schema_head'],
                'data_files' => $dataFiles, 'asset_files' => $assetFiles, 'secret_files' => [$secretPath],
                'sequences' => $sequences,
            ];
            self::writePrivate(self::payloadPath($payloadRoot, 'metadata/recovery.json'), self::encodeJson($recovery));
            $this->database->endConsistentSnapshot(true);
            $snapshotActive = false;

            $files = self::inventory($payloadRoot);
            $timestamp = call_user_func($this->clock);
            if (!is_string($timestamp) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $timestamp)) {
                throw new RuntimeException('Backup producer clock must return a UTC RFC3339 timestamp.');
            }
            $manifest = [
                'contract_name' => PackageManifest::CONTRACT_NAME, 'format_version' => '1.0', 'package_kind' => 'backup',
                'required_capabilities' => ['canonical-inventory-jsonl-v1','regular-files-only-v1','sha256-v1'],
                'application_version' => $identity['application_version'], 'source_commit' => $identity['source_commit'],
                'source_tag' => 'backup-' . preg_replace('/[^0-9]/', '', $timestamp),
                'schema_baseline' => $identity['schema_baseline'], 'schema_head' => $identity['schema_head'],
                'source_timestamp' => $timestamp,
                'compatibility' => [
                    'php' => ['minimum' => '8.2.0', 'maximum_exclusive' => '9.0.0', 'extensions' => ['json','openssl','pdo_mysql','zip']],
                    'mysql' => [
                        'minimum' => $baselineArray['mysql']['minimum'], 'maximum_exclusive' => $baselineArray['mysql']['maximum_exclusive'],
                        'sql_modes' => $baselineArray['mysql']['sql_modes'], 'charset' => $baselineArray['mysql']['charset'],
                        'collation' => $baselineArray['mysql']['collation'],
                    ],
                ],
                'minimum_reader_version' => '1.0.0', 'supported_upgrade_sources' => [],
                'contains_data' => true, 'contains_persistent_assets' => count($assetFiles) > 0,
                'files' => $files, 'digest_algorithm' => 'sha256',
                'content_tree_sha256' => PackageManifest::calculateContentTreeSha256($files),
                'detached_checksum_reference' => 'envelope/archive.sha256', 'provenance_reference' => 'envelope/header.json',
            ];
            PackageManifest::validate($manifest);
            $manifestJson = self::encodeJson($manifest);
            $archivePath = $stage . DIRECTORY_SEPARATOR . 'backup.zip';
            self::createZip($payloadRoot, $files, $archivePath);

            $roles = [];
            foreach ($files as $file) { $roles[$file['path']] = $file['role']; }
            $required = array_merge($dataFiles, ['metadata/recovery.json', $secretPath]);
            $context = ArchiveValidationContext::forBackup(hash_file('sha256', $archivePath), hash('sha256', $manifestJson),
                [$identity['schema_baseline'] => [$identity['schema_head']]], $roles, $required);
            $report = (new ArchiveSafetyReader())->validate($archivePath, $manifestJson, $context);
            if (!hash_equals($manifest['content_tree_sha256'], $report->manifest()['content_tree_sha256'])) {
                throw new RuntimeException('Backup producer reader round-trip changed content identity.');
            }
            $envelope = BackupEnvelope::encrypt($archivePath, $manifestJson, $destinationPath, $encryptionKey, $identity);
            return [
                'path' => $destinationPath, 'envelope_sha256' => $envelope['envelope_sha256'],
                'archive_sha256' => $envelope['header']['archive_sha256'], 'manifest_sha256' => $envelope['header']['manifest_sha256'],
                'content_tree_sha256' => $manifest['content_tree_sha256'], 'row_counts' => $rowCounts,
                'asset_count' => count($assetFiles), 'secret_classifications' => array_map(function ($entry) {
                    return ['name' => $entry['name'], 'disposition' => $entry['disposition'], 'required' => $entry['required']];
                }, BackupSecrets::create($exportedSecrets)['entries']),
            ];
        } finally {
            if ($snapshotActive) { $this->database->endConsistentSnapshot(false); }
            self::removeTree($stage);
        }
    }

    private function copyAssets($payloadRoot, array $required)
    {
        $paths = [];
        $items = scandir($this->assetRoot);
        if (!is_array($items)) { throw new RuntimeException('Avatar asset root could not be read.'); }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') { continue; }
            if (!preg_match('/\A[a-f0-9]{40}\.(?:jpg|png|webp)\z/', $name)) { throw new RuntimeException('Avatar asset root contains an undeclared filename.'); }
            $source = $this->assetRoot . DIRECTORY_SEPARATOR . $name;
            if (!is_file($source) || is_link($source)) { throw new RuntimeException('Avatar backup source must be a regular non-link file.'); }
            $before = stat($source);
            $bytes = file_get_contents($source);
            $after = stat($source);
            if (!is_array($before) || !is_array($after) || $bytes === false || $before['ino'] !== $after['ino'] || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] || strlen($bytes) !== $after['size']) {
                throw new RuntimeException('Avatar changed during backup snapshot: ' . $name . '.');
            }
            $path = 'assets/avatars/' . $name;
            self::writePrivate(self::payloadPath($payloadRoot, $path), $bytes);
            $paths[] = $path;
            unset($required[$name]);
        }
        if ($required) { throw new RuntimeException('A database-referenced local avatar is missing: ' . implode(', ', array_keys($required)) . '.'); }
        return $paths;
    }

    private static function inventory($root)
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->isLink()) { throw new RuntimeException('Backup payload inventory accepts regular files only.'); }
            $path = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            $files[] = ['path' => $path, 'type' => 'file', 'role' => PackageManifest::expectedRoleForPath('backup', $path),
                'mode' => 0600, 'size' => $item->getSize(), 'sha256' => hash_file('sha256', $item->getPathname())];
        }
        usort($files, function ($left, $right) { return strcmp($left['path'], $right['path']); });
        return $files;
    }

    private static function createZip($payloadRoot, array $files, $archivePath)
    {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('The ZIP extension is required for backup production.'); }
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('Backup ZIP could not be created exclusively.'); }
        try {
            foreach ($files as $file) {
                if (!$zip->addFile(self::payloadPath($payloadRoot, $file['path']), $file['path'])) { throw new RuntimeException('Backup ZIP entry could not be added.'); }
                if (method_exists($zip, 'setCompressionName') && !$zip->setCompressionName($file['path'], ZipArchive::CM_STORE)) { throw new RuntimeException('Backup ZIP compression could not be normalized.'); }
                if (method_exists($zip, 'setExternalAttributesName') && !$zip->setExternalAttributesName($file['path'], ZipArchive::OPSYS_UNIX, (0100000 | 0600) << 16)) {
                    throw new RuntimeException('Backup ZIP permissions could not be normalized.');
                }
            }
        } finally {
            if (!$zip->close()) { @unlink($archivePath); throw new RuntimeException('Backup ZIP could not be closed.'); }
        }
        @chmod($archivePath, 0600);
    }

    private static function encodeJson(array $value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) { throw new RuntimeException('Backup JSON could not be encoded.'); }
        return $json;
    }

    private static function payloadPath($root, $relative)
    {
        PackageManifest::validateSafeRelativePath($relative, 'backup payload path');
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($path);
        if (!is_dir($parent) && !mkdir($parent, 0700, true)) { throw new RuntimeException('Backup payload parent could not be created.'); }
        return $path;
    }

    private static function newPrivateFile($path)
    {
        $stream = @fopen($path, 'xb');
        if (!is_resource($stream)) { throw new RuntimeException('Private backup payload file could not be created.'); }
        @chmod($path, 0600);
        return $stream;
    }

    private static function writePrivate($path, $bytes)
    {
        $stream = self::newPrivateFile($path);
        try { self::writeAll($stream, $bytes); } finally { fclose($stream); }
    }

    private static function writeAll($stream, $bytes)
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) { throw new RuntimeException('Backup payload write failed.'); }
            $offset += $written;
        }
    }

    private static function createPrivateStage($root, $prefix)
    {
        for ($i = 0; $i < 16; $i++) {
            $path = $root . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(12));
            if (@mkdir($path, 0700)) { @chmod($path, 0700); return $path; }
        }
        throw new RuntimeException('Private backup producer stage could not be created.');
    }

    private static function privateRoot($path, $label)
    {
        $real = self::existingDirectory($path, $label);
        if (DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($real);
            if (!is_int($mode) || (($mode & 0077) !== 0)) { throw new InvalidArgumentException(ucfirst($label) . ' must be private (0700).'); }
        }
        return $real;
    }

    private static function existingDirectory($path, $label)
    {
        if (!is_string($path) || !is_dir($path)) { throw new InvalidArgumentException(ucfirst($label) . ' must exist.'); }
        $real = realpath($path);
        if ($real === false) { throw new InvalidArgumentException(ucfirst($label) . ' could not be resolved.'); }
        return $real;
    }

    private static function assertDestinationOutsidePublicRoot($path, $publicRoot)
    {
        if (!is_string($path) || trim($path) === '' || file_exists($path) || is_link($path)) { throw new InvalidArgumentException('Backup destination must be a new file.'); }
        $parent = realpath(dirname($path));
        if ($parent === false || self::within($parent, $publicRoot)) { throw new InvalidArgumentException('Backup destination must be outside the public web root.'); }
    }

    private static function within($path, $root)
    {
        $path = rtrim(str_replace('\\','/',$path), '/'); $root = rtrim(str_replace('\\','/',$root), '/');
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    private static function removeTree($path)
    {
        if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) { @unlink($path); } return; }
        $items = scandir($path);
        if (is_array($items)) { foreach ($items as $item) { if ($item !== '.' && $item !== '..') { self::removeTree($path . DIRECTORY_SEPARATOR . $item); } } }
        @rmdir($path);
    }
}
