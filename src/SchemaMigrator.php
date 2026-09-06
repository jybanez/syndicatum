<?php

require_once __DIR__ . '/Db.php';

class SchemaMigrator
{
    private $pdo;
    private $migrationDirectory;

    public function __construct(PDO $pdo, $migrationDirectory = null)
    {
        $this->pdo = $pdo;
        $this->migrationDirectory = $migrationDirectory ?: dirname(__DIR__) . '/migrations';
    }

    public function migrate()
    {
        $this->acquireLock();

        try {
            $this->ensureMigrationTable();
            $applied = $this->appliedMigrations();
            $executed = [];

            foreach ($this->migrationFiles() as $file) {
                $migration = $this->loadMigration($file);
                $version = $migration['version'];
                $checksum = hash_file('sha256', $file);

                if (isset($applied[$version])) {
                    if (!hash_equals($applied[$version], $checksum)) {
                        throw new RuntimeException('Applied migration ' . $version . ' has been modified.');
                    }
                    continue;
                }

                foreach ($migration['statements'] as $statement) {
                    if (is_array($statement)) {
                        if (isset($statement['unless_column']) && is_array($statement['unless_column'])
                            && count($statement['unless_column']) === 2
                            && Db::columnExists($this->pdo, $statement['unless_column'][0], $statement['unless_column'][1])) {
                            continue;
                        }
                        $statement = isset($statement['sql']) ? $statement['sql'] : '';
                    }
                    if (!is_string($statement) || trim($statement) === '') {
                        throw new RuntimeException('Invalid migration statement in ' . $version . '.');
                    }
                    $this->pdo->exec($statement);
                }

                $record = $this->pdo->prepare(
                    'INSERT INTO syndicatum_schema_migrations (version, description, checksum, applied_at) VALUES (?, ?, ?, ?)'
                );
                $record->execute([$version, $migration['description'], $checksum, date('Y-m-d H:i:s')]);
                $executed[] = $version;
            }

            return $executed;
        } finally {
            $this->releaseLock();
        }
    }

    public function status()
    {
        $this->ensureMigrationTable();
        $applied = $this->appliedMigrations();
        $status = [];

        foreach ($this->migrationFiles() as $file) {
            $migration = $this->loadMigration($file);
            $version = $migration['version'];
            $status[] = [
                'version' => $version,
                'description' => $migration['description'],
                'applied' => isset($applied[$version]),
                'checksum_valid' => !isset($applied[$version]) || hash_equals($applied[$version], hash_file('sha256', $file)),
            ];
        }

        return $status;
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
        $rows = $this->pdo->query('SELECT version, checksum FROM syndicatum_schema_migrations')->fetchAll();
        $applied = [];
        foreach ($rows as $row) {
            $applied[$row['version']] = $row['checksum'];
        }
        return $applied;
    }

    private function migrationFiles()
    {
        $files = glob($this->migrationDirectory . '/*.php');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function loadMigration($file)
    {
        $migration = require $file;
        if (!is_array($migration)
            || empty($migration['version'])
            || empty($migration['description'])
            || !isset($migration['statements'])
            || !is_array($migration['statements'])) {
            throw new RuntimeException('Invalid migration definition: ' . basename($file));
        }

        if (basename($file, '.php') !== $migration['version']) {
            throw new RuntimeException('Migration version must match its filename: ' . basename($file));
        }

        return $migration;
    }

    private function acquireLock()
    {
        $result = $this->pdo->query(
            "SELECT GET_LOCK(CONCAT('syndicatum:migrations:', DATABASE()), 30)"
        )->fetchColumn();
        if ((int) $result !== 1) {
            throw new RuntimeException('Could not acquire the Syndicatum schema migration lock.');
        }
    }

    private function releaseLock()
    {
        try {
            $this->pdo->query("SELECT RELEASE_LOCK(CONCAT('syndicatum:migrations:', DATABASE()))");
        } catch (Exception $ignored) {
            // Preserve the original migration error. MySQL also releases the lock when this connection closes.
        }
    }
}
