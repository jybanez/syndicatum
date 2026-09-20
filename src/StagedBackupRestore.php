<?php

require_once __DIR__ . '/ArchiveSafetyReader.php';
require_once __DIR__ . '/BackupEnvelope.php';
require_once __DIR__ . '/BackupSecrets.php';
require_once __DIR__ . '/BaselineMetadata.php';
require_once __DIR__ . '/PackageManifest.php';

interface BackupRestoreTarget
{
    public function tableNames();
    public function columns($table);
    public function rowCount($table);
    public function selectedRows($table, array $columns, array $orderBy);
    public function nextSequenceValue($table);
    public function setNextSequenceValue($table, $value);
    public function begin();
    public function insert($table, array $columns, array $values);
    public function commit();
    public function rollBack();
}

final class PdoBackupRestoreTarget implements BackupRestoreTarget
{
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function tableNames() { return $this->pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN); }
    public function columns($table) {
        self::assertIdentifier($table);
        $statement = $this->pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
        $statement->execute([$table]); return $statement->fetchAll(PDO::FETCH_COLUMN);
    }
    public function rowCount($table) { self::assertIdentifier($table); return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(); }
    public function selectedRows($table, array $columns, array $orderBy) {
        self::assertIdentifier($table);
        foreach (array_merge($columns, $orderBy) as $column) { self::assertIdentifier($column); }
        if (!$columns || !$orderBy) { throw new InvalidArgumentException('Selected target rows require explicit columns and ordering.'); }
        $select = implode(', ', array_map(function ($column) { return '`' . $column . '`'; }, $columns));
        $order = implode(', ', array_map(function ($column) { return '`' . $column . '`'; }, $orderBy));
        $rows = $this->pdo->query('SELECT ' . $select . ' FROM `' . $table . '` ORDER BY ' . $order)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach ($row as $column => $value) { $row[$column] = $value === null ? null : (string) $value; }
        }
        unset($row);
        return $rows;
    }
    public function nextSequenceValue($table) {
        self::assertIdentifier($table);
        $statement = $this->pdo->prepare('SELECT auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = ?');
        $statement->execute([$table, 'BASE TABLE']);
        $value = $statement->fetchColumn();
        if ($value === false || $value === null) { return null; }
        if (!is_numeric($value) || (int) $value < 1 || (string) (int) $value !== (string) $value) {
            throw new RuntimeException('Restore target sequence state is outside the supported integer range: ' . $table . '.');
        }
        return (int) $value;
    }
    public function setNextSequenceValue($table, $value) {
        self::assertIdentifier($table);
        if (!is_int($value) || $value < 1) { throw new InvalidArgumentException('Restore sequence value must be a positive integer.'); }
        $this->pdo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = ' . $value);
    }
    public function begin() { if (!$this->pdo->beginTransaction()) { throw new RuntimeException('Staged restore transaction could not begin.'); } }
    public function insert($table, array $columns, array $values) {
        self::assertIdentifier($table); foreach ($columns as $column) { self::assertIdentifier($column); }
        $quoted = array_map(function ($column) { return '`' . $column . '`'; }, $columns);
        $statement = $this->pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $statement->execute($values);
    }
    public function commit() { if (!$this->pdo->commit()) { throw new RuntimeException('Staged restore transaction could not commit.'); } }
    public function rollBack() { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }
    private static function assertIdentifier($value) { if (!is_string($value) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $value)) { throw new InvalidArgumentException('Restore database identifier is invalid.'); } }
}

final class StagedBackupRestore
{
    private $target;
    private $baseline;
    private $stagingRoot;
    private $publicWebRoot;

    public function __construct(BackupRestoreTarget $target, BaselineMetadata $baseline, $stagingRoot, $publicWebRoot)
    {
        $this->target = $target;
        $this->baseline = $baseline;
        $this->stagingRoot = self::privateRoot($stagingRoot);
        $this->publicWebRoot = self::existingDirectory($publicWebRoot, 'public web root');
        if (self::within($this->stagingRoot, $this->publicWebRoot)) { throw new InvalidArgumentException('Restore staging root must not be under the public web root.'); }
    }

    public function restore($envelopePath, $encryptionKey, array $targetSecrets)
    {
        $opened = null; $extracted = null; $assetStage = null; $transaction = false;
        try {
            $opened = BackupEnvelope::decryptToPrivateStage($envelopePath, $this->stagingRoot, $encryptionKey);
            $manifest = PackageManifest::parse($opened['manifest_json']);
            $baselineArray = $this->baseline->toArray();
            foreach (['application_version' => 'application_version','schema_baseline' => 'baseline_id','schema_head' => 'schema_head'] as $manifestField => $baselineField) {
                if (!hash_equals((string) $baselineArray[$baselineField], (string) $manifest[$manifestField])) {
                    throw new InvalidArgumentException('Authenticated backup does not match trusted target ' . $baselineField . '.');
                }
            }

            $expectedData = [];
            foreach ($this->baseline->tablesWithBackupPolicy('durable') as $table) { $expectedData[] = 'data/' . $table['name'] . '.ndjson'; }
            sort($expectedData, SORT_STRING);
            $actualData = []; $roles = []; $required = ['metadata/recovery.json','secrets/recovery.json'];
            foreach ($manifest['files'] as $file) {
                $roles[$file['path']] = $file['role'];
                if ($file['role'] === 'logical_data') { $actualData[] = $file['path']; }
                elseif ($file['role'] === 'persistent_asset' && !preg_match('#\Aassets/avatars/[a-f0-9]{40}\.(?:jpg|png|webp)\z#', $file['path'])) {
                    throw new InvalidArgumentException('Authenticated backup contains an unsupported persistent asset path.');
                } elseif ($file['role'] === 'portable_secret' && $file['path'] !== 'secrets/recovery.json') {
                    throw new InvalidArgumentException('Authenticated backup contains an unsupported portable-secret path.');
                }
            }
            sort($actualData, SORT_STRING);
            if ($actualData !== $expectedData) { throw new InvalidArgumentException('Authenticated backup durable-table payload does not match trusted BaselineMetadata.'); }
            $required = array_merge($required, $expectedData);
            $context = ArchiveValidationContext::forBackup($opened['archive_sha256'], $opened['manifest_sha256'],
                [$baselineArray['baseline_id'] => [$baselineArray['schema_head']]], $roles, $required);
            $extracted = (new ArchiveSafetyReader())->extractToNewStage($opened['archive_path'], $opened['manifest_json'], $context, $this->stagingRoot, $this->publicWebRoot);

            $secretJson = file_get_contents($extracted->path() . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'recovery.json');
            if (!is_string($secretJson)) { throw new RuntimeException('Authenticated portable recovery secrets could not be read.'); }
            $secretClassifications = BackupSecrets::assertTargetMatches(BackupSecrets::parse($secretJson), $targetSecrets);
            $recoveryJson = file_get_contents($extracted->path() . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'recovery.json');
            $sequences = $this->parseSequences($recoveryJson);
            $this->assertStagedTarget();

            $assetStage = self::createPrivateStage($this->stagingRoot, 'restored-assets-');
            $assetCount = 0;
            foreach ($manifest['files'] as $file) {
                if ($file['role'] !== 'persistent_asset') { continue; }
                $name = basename($file['path']);
                $source = $extracted->path() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file['path']);
                $destination = $assetStage . DIRECTORY_SEPARATOR . $name;
                $bytes = file_get_contents($source);
                if (!is_string($bytes)) { throw new RuntimeException('Restored asset could not be read from private extraction.'); }
                self::writePrivate($destination, $bytes);
                if (!hash_equals($file['sha256'], hash_file('sha256', $destination))) { throw new RuntimeException('Restored asset digest changed during staging.'); }
                $assetCount++;
            }

            foreach ($sequences as $table => $value) {
                if ($value !== null) { $this->target->setNextSequenceValue($table, $value); }
            }

            $this->target->begin(); $transaction = true;
            $restoredCounts = [];
            foreach ($this->baseline->tablesWithBackupPolicy('durable') as $table) {
                $columns = $table['columns'];
                $path = $extracted->path() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $table['name'] . '.ndjson';
                $stream = fopen($path, 'rb');
                if (!is_resource($stream)) { throw new RuntimeException('Durable table payload could not be opened: ' . $table['name'] . '.'); }
                $count = 0;
                try {
                    while (($line = fgets($stream)) !== false) {
                        if (substr($line, -1) !== "\n") { throw new InvalidArgumentException('Durable table NDJSON record lacks a final LF.'); }
                        $json = substr($line, 0, -1);
                        PackageManifest::assertNoDuplicateJsonObjectKeys($json);
                        $wire = json_decode($json, false, 32, JSON_BIGINT_AS_STRING);
                        $row = json_decode($json, true, 32, JSON_BIGINT_AS_STRING);
                        if (!($wire instanceof stdClass) || !is_array($row) || json_last_error() !== JSON_ERROR_NONE || array_keys($row) !== $columns) {
                            throw new InvalidArgumentException('Durable table row does not match target columns: ' . $table['name'] . '.');
                        }
                        foreach ($row as $value) { if ($value !== null && !is_string($value)) { throw new InvalidArgumentException('Durable table values must be strings or null.'); } }
                        $this->target->insert($table['name'], $columns, array_values($row));
                        $count++;
                    }
                    if (!feof($stream)) { throw new RuntimeException('Durable table payload could not be read completely.'); }
                } finally { fclose($stream); }
                $restoredCounts[$table['name']] = $count;
            }
            foreach ($restoredCounts as $table => $count) {
                if ($this->target->rowCount($table) !== $count) { throw new RuntimeException('Staged restore row-count verification failed: ' . $table . '.'); }
            }
            foreach ($this->baseline->tablesWithBackupPolicy('reset') as $table) {
                if ($this->target->rowCount($table['name']) !== 0) { throw new RuntimeException('Reset table became non-empty during staged restore: ' . $table['name'] . '.'); }
            }
            foreach ($sequences as $table => $value) {
                if ($value !== null && $this->target->nextSequenceValue($table) !== $value) {
                    throw new RuntimeException('Staged restore sequence-state verification failed: ' . $table . '.');
                }
            }
            $this->target->commit(); $transaction = false;
            return [
                'source_commit' => $manifest['source_commit'], 'schema_baseline' => $manifest['schema_baseline'],
                'schema_head' => $manifest['schema_head'], 'archive_sha256' => $opened['archive_sha256'],
                'manifest_sha256' => $opened['manifest_sha256'], 'content_tree_sha256' => $manifest['content_tree_sha256'],
                'restored_row_counts' => $restoredCounts, 'asset_stage_path' => $assetStage, 'asset_count' => $assetCount,
                'secret_classifications' => $secretClassifications, 'cutover_performed' => false,
            ];
        } catch (Throwable $exception) {
            if ($transaction) { $this->target->rollBack(); }
            if ($assetStage !== null && is_dir($assetStage)) { BackupEnvelope::removePrivateStage($assetStage, $this->stagingRoot); }
            throw $exception;
        } finally {
            if ($extracted instanceof ArchiveExtractionStage && is_dir($extracted->path())) { BackupEnvelope::removePrivateStage($extracted->path(), $this->stagingRoot); }
            if (is_array($opened) && isset($opened['stage_path']) && is_dir($opened['stage_path'])) { BackupEnvelope::removePrivateStage($opened['stage_path'], $this->stagingRoot); }
        }
    }

    private function assertStagedTarget()
    {
        $names = $this->target->tableNames();
        $this->baseline->assertBaselineTables(array_values($names));
        foreach ($this->baseline->tablePolicies() as $table) {
            if ($this->target->columns($table['name']) !== $table['columns']) {
                throw new InvalidArgumentException('Restore target schema columns differ from trusted BaselineMetadata: ' . $table['name'] . '.');
            }
        }
        foreach (array_merge($this->baseline->tablesWithBackupPolicy('durable'), $this->baseline->tablesWithBackupPolicy('reset')) as $table) {
            if ($this->target->rowCount($table['name']) !== 0) { throw new InvalidArgumentException('Staged restore requires empty durable/reset target tables: ' . $table['name'] . '.'); }
        }
        foreach ($this->baseline->tablesWithBackupPolicy('excluded') as $table) {
            $count = $this->target->rowCount($table['name']);
            if ($table['target_expectation'] === 'empty' && $count !== 0) { throw new InvalidArgumentException('Excluded target table must be empty: ' . $table['name'] . '.'); }
            if ($table['target_expectation'] === 'locally_initialized' && $count < 1 && $table['name'] !== 'syndicatum_schema_migrations') {
                throw new InvalidArgumentException('Target-local table is not initialized: ' . $table['name'] . '.');
            }
        }
        $expectedRoles = [
            ['id' => '1', 'code' => 'user', 'name' => 'User'],
            ['id' => '2', 'code' => 'administrator', 'name' => 'Administrator'],
        ];
        if ($this->target->selectedRows('system_roles', ['id','code','name'], ['id']) !== $expectedRoles) {
            throw new InvalidArgumentException('Target-local system roles do not match the trusted V1 seed identities.');
        }
        $baseline = $this->baseline->toArray();
        $identities = $this->target->selectedRows('syndicatum_installation_identity',
            ['application_version','schema_baseline','schema_head'], ['singleton_id']);
        $expectedIdentity = [[
            'application_version' => (string) $baseline['application_version'],
            'schema_baseline' => (string) $baseline['baseline_id'],
            'schema_head' => (string) $baseline['schema_head'],
        ]];
        if ($identities !== $expectedIdentity) {
            throw new InvalidArgumentException('Target-local installation identity does not match the trusted baseline.');
        }
        if (!$baseline['post_baseline_migrations'] && $this->target->rowCount('syndicatum_schema_migrations') !== 0) {
            throw new InvalidArgumentException('Target migration ledger is not empty for a baseline with no forward migrations.');
        }
    }

    private function parseSequences($json)
    {
        if (!is_string($json) || $json === '') { throw new InvalidArgumentException('Authenticated backup recovery metadata is missing.'); }
        PackageManifest::assertNoDuplicateJsonObjectKeys($json);
        $wire = json_decode($json, false, 32, JSON_BIGINT_AS_STRING);
        $decoded = json_decode($json, true, 32, JSON_BIGINT_AS_STRING);
        if (!($wire instanceof stdClass) || !isset($wire->sequences) || !($wire->sequences instanceof stdClass)
            || !is_array($decoded) || json_last_error() !== JSON_ERROR_NONE
            || !isset($decoded['contract_name'], $decoded['format_version'], $decoded['sequences'])
            || $decoded['contract_name'] !== 'syndicatum-backup-metadata' || $decoded['format_version'] !== '1.0'
            || !is_array($decoded['sequences'])) {
            throw new InvalidArgumentException('Authenticated backup recovery sequence metadata contract is invalid.');
        }
        $expected = array_map(function ($table) { return $table['name']; }, $this->baseline->tablesWithBackupPolicy('durable'));
        if (array_keys($decoded['sequences']) !== $expected) {
            throw new InvalidArgumentException('Authenticated backup sequence metadata does not exactly cover durable tables.');
        }
        foreach ($decoded['sequences'] as $table => $value) {
            if ($value !== null && (!is_int($value) || $value < 1)) {
                throw new InvalidArgumentException('Authenticated backup sequence value is invalid: ' . $table . '.');
            }
        }
        return $decoded['sequences'];
    }

    private static function createPrivateStage($root, $prefix)
    {
        for ($i=0;$i<16;$i++) { $path=$root.DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(12)); if (@mkdir($path,0700)) { @chmod($path,0700); return $path; } }
        throw new RuntimeException('Private restore asset stage could not be created.');
    }
    private static function writePrivate($path, $bytes) { $stream=@fopen($path,'xb'); if(!is_resource($stream)){throw new RuntimeException('Private restored asset could not be created.');} @chmod($path,0600); try{$offset=0;while($offset<strlen($bytes)){$written=fwrite($stream,substr($bytes,$offset));if(!is_int($written)||$written<1){throw new RuntimeException('Restored asset write failed.');}$offset+=$written;}}finally{fclose($stream);} }
    private static function privateRoot($path) { $real=self::existingDirectory($path,'restore staging root'); if(DIRECTORY_SEPARATOR==='/' && ((fileperms($real)&0077)!==0)){throw new InvalidArgumentException('Restore staging root must be private (0700).');} return $real; }
    private static function existingDirectory($path,$label) { if(!is_string($path)||!is_dir($path)){throw new InvalidArgumentException(ucfirst($label).' must exist.');}$real=realpath($path);if($real===false){throw new InvalidArgumentException(ucfirst($label).' could not be resolved.');}return $real; }
    private static function within($path,$root) { $path=rtrim(str_replace('\\','/',$path),'/');$root=rtrim(str_replace('\\','/',$root),'/');return $path===$root||strpos($path,$root.'/')===0; }
}
