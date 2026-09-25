<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/BaselineMetadata.php';

/** Apply the signed, ordered migration suffix declared by baseline metadata. */
class PostBaselineMigrator
{
    private $pdo;
    private $metadataPath;
    private $migrationDirectory;

    public function __construct(PDO $pdo, $metadataPath = null, $migrationDirectory = null)
    {
        $this->pdo = $pdo;
        $sourceMetadata = dirname(__DIR__) . '/schema/mysql84/baseline.json';
        $packagedMetadata = dirname(dirname(__DIR__)) . '/schema/baselines/mysql84/baseline.json';
        $this->metadataPath = $metadataPath ?: (is_file($sourceMetadata) ? $sourceMetadata : $packagedMetadata);
        $siblingMigrations = dirname($this->metadataPath) . '/migrations';
        $this->migrationDirectory = $migrationDirectory ?: (is_dir($siblingMigrations)
            ? $siblingMigrations : dirname(__DIR__) . '/migrations');
    }

    public function migrate($updateIdentity = true)
    {
        $metadata = $this->metadata();
        $declared = $metadata['post_baseline_migrations'];
        if (!$declared) { return []; }
        $this->acquireLock();
        try {
            $this->ensureMigrationTable();
            $identityPresent = $updateIdentity && Db::tableExists($this->pdo, 'syndicatum_installation_identity');
            if ($identityPresent) {
                $identity = $this->pdo->query(
                    'SELECT schema_baseline, schema_head FROM syndicatum_installation_identity WHERE singleton_id = 1'
                )->fetch(PDO::FETCH_ASSOC);
                if (!is_array($identity)
                    || !hash_equals((string) $metadata['baseline_id'], (string) $identity['schema_baseline'])
                    || !$this->isPermittedHead((string) $identity['schema_head'], $metadata)) {
                    throw new RuntimeException('Installation identity is not eligible for the declared post-baseline migration suffix.');
                }
            }
            $applied = $this->appliedMigrations();
            $executed = [];
            foreach ($declared as $entry) {
                $migration = $this->verifiedMigration($entry);
                $id = $entry['id'];
                if (isset($applied[$id])) {
                    if (!hash_equals($entry['sha256'], strtolower($applied[$id]))) {
                        throw new RuntimeException('Applied post-baseline migration ' . $id . ' has an invalid checksum.');
                    }
                    continue;
                }
                foreach ($migration['statements'] as $statement) {
                    $this->executeStatement($statement, $id);
                }
                $record = $this->pdo->prepare(
                    'INSERT INTO syndicatum_schema_migrations (version, description, checksum, applied_at) VALUES (?, ?, ?, ?)'
                );
                $record->execute([$id, $migration['description'], $entry['sha256'], Db::now()]);
                $executed[] = $id;
            }
            if ($identityPresent) {
                $this->pdo->prepare('UPDATE syndicatum_installation_identity SET schema_head = ? WHERE singleton_id = 1')
                    ->execute([$metadata['schema_head']]);
            }
            return $executed;
        } finally {
            $this->releaseLock();
        }
    }

    public function status()
    {
        $metadata = $this->metadata();
        $this->ensureMigrationTable();
        $applied = $this->appliedMigrations();
        return array_map(function ($entry) use ($applied) {
            $this->verifiedMigration($entry);
            return [
                'version' => $entry['id'],
                'applied' => isset($applied[$entry['id']]),
                'checksum_valid' => !isset($applied[$entry['id']])
                    || hash_equals($entry['sha256'], strtolower($applied[$entry['id']])),
            ];
        }, $metadata['post_baseline_migrations']);
    }

    private function metadata()
    {
        $json = file_get_contents($this->metadataPath);
        $metadata = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Trusted baseline metadata is unavailable.');
        }
        BaselineMetadata::fromArray($metadata);
        return $metadata;
    }

    private function verifiedMigration(array $entry)
    {
        $path = $this->migrationDirectory . '/' . $entry['id'] . '.php';
        if (!is_file($path) || !hash_equals($entry['sha256'], self::canonicalSha256($path))) {
            throw new RuntimeException('Post-baseline migration content does not match trusted metadata: ' . $entry['id']);
        }
        $migration = require $path;
        if (!is_array($migration) || !isset($migration['version']) || $migration['version'] !== $entry['id']
            || empty($migration['description']) || !isset($migration['statements'])
            || !is_array($migration['statements'])) {
            throw new RuntimeException('Invalid post-baseline migration definition: ' . $entry['id']);
        }
        return $migration;
    }

    private function executeStatement($statement, $id)
    {
        if (is_array($statement)) {
            if (isset($statement['unless_column']) && is_array($statement['unless_column'])
                && count($statement['unless_column']) === 2
                && Db::columnExists($this->pdo, $statement['unless_column'][0], $statement['unless_column'][1])) {
                return;
            }
            $statement = isset($statement['sql']) ? $statement['sql'] : '';
        }
        if (!is_string($statement) || trim($statement) === '') {
            throw new RuntimeException('Invalid migration statement in ' . $id . '.');
        }
        $this->pdo->exec($statement);
    }

    private function isPermittedHead($head, array $metadata)
    {
        if ($head === $metadata['migration_cutover'] || $head === $metadata['schema_head']) { return true; }
        foreach ($metadata['post_baseline_migrations'] as $entry) {
            if ($head === $entry['id']) { return true; }
        }
        return false;
    }

    private function ensureMigrationTable()
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS syndicatum_schema_migrations (
                version VARCHAR(80) PRIMARY KEY,
                description VARCHAR(255) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function appliedMigrations()
    {
        $rows = $this->pdo->query('SELECT version, checksum FROM syndicatum_schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) { $result[$row['version']] = $row['checksum']; }
        return $result;
    }

    private function acquireLock()
    {
        $result = $this->pdo->query("SELECT GET_LOCK(CONCAT('syndicatum:post-migrations:', DATABASE()), 30)")->fetchColumn();
        if ((int) $result !== 1) { throw new RuntimeException('Could not acquire the Syndicatum post-migration lock.'); }
    }

    private function releaseLock()
    {
        try { $this->pdo->query("SELECT RELEASE_LOCK(CONCAT('syndicatum:post-migrations:', DATABASE()))"); }
        catch (Exception $ignored) { }
    }

    public static function canonicalSha256($path)
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) { throw new RuntimeException('Migration bytes are unavailable.'); }
        if (strpos(str_replace("\r\n", '', $raw), "\r") !== false || preg_match('//u', $raw) !== 1) {
            throw new RuntimeException('Migration bytes violate canonical UTF-8/LF serialization.');
        }
        return hash('sha256', str_replace("\r\n", "\n", $raw));
    }
}
