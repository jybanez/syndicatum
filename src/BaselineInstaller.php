<?php

require_once __DIR__ . '/BaselineMetadata.php';
require_once __DIR__ . '/InstallationIdentity.php';
require_once __DIR__ . '/PostBaselineMigrator.php';

class BaselineInstaller
{
    private $pdo;
    private $schemaPath;
    private $metadataPath;

    public function __construct(PDO $pdo, $schemaPath, $metadataPath)
    {
        $this->pdo = $pdo;
        $this->schemaPath = $schemaPath;
        $this->metadataPath = $metadataPath;
    }

    public function install(array $identityValues)
    {
        $metadataArray = $this->loadMetadata();
        $metadata = BaselineMetadata::fromArray($metadataArray);
        $identity = InstallationIdentity::fromArray($identityValues)->toArray();
        $this->assertIdentityMatchesBaseline($identity, $metadataArray);
        $this->assertSupportedDatabase($metadataArray, $metadata);
        $this->assertEmptyDatabase();

        $schema = file_get_contents($this->schemaPath);
        if (!is_string($schema) || $schema === '') {
            throw new RuntimeException('Baseline schema SQL is missing or empty.');
        }
        if (!hash_equals(strtolower($metadataArray['schema_sha256']), hash('sha256', $schema))) {
            throw new RuntimeException('Baseline schema digest does not match trusted metadata.');
        }
        $originalForeignKeyChecks = (int) $this->pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
        try {
            foreach ($this->splitStatements($schema) as $statement) {
                $this->pdo->exec($statement);
            }
        } catch (Exception $exception) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = ' . ($originalForeignKeyChecks ? '1' : '0'));
            throw $exception;
        }

        $postMigrations = (new PostBaselineMigrator($this->pdo, $this->metadataPath))->migrate(false);
        $actualTables = $this->tableNames();
        $metadata->assertBaselineTables($actualTables);
        $migrationRows = (int) $this->pdo->query('SELECT COUNT(*) FROM syndicatum_schema_migrations')->fetchColumn();
        if ($migrationRows !== count($metadataArray['post_baseline_migrations'])) {
            throw new RuntimeException('Fresh baseline installation did not apply the declared post-baseline migration suffix.');
        }
        $roles = $this->pdo->query('SELECT code FROM system_roles ORDER BY code')->fetchAll(PDO::FETCH_COLUMN);
        if ($roles !== ['administrator', 'user']) {
            throw new RuntimeException('Fresh baseline installation did not create the fixed V1 system roles.');
        }

        $this->persistIdentity($identity);

        return [
            'application_version' => $identity['application_version'],
            'schema_baseline' => $identity['schema_baseline'],
            'schema_head' => $identity['schema_head'],
            'baseline_source_commit' => $identity['baseline_source_commit'],
            'release_source_commit' => $identity['release_source_commit'],
            'package_sha256' => strtolower($identity['package_sha256']),
            'installation_id' => $identity['installation_id'],
            'table_count' => count($actualTables),
            'historical_migration_rows' => 0,
            'post_baseline_migration_rows' => $migrationRows,
            'post_baseline_migrations_applied' => $postMigrations,
        ];
    }

    private function loadMetadata()
    {
        $json = file_get_contents($this->metadataPath);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Baseline metadata is missing or empty.');
        }
        $metadata = json_decode($json, true);
        if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Baseline metadata is not valid JSON.');
        }
        return $metadata;
    }

    private function assertIdentityMatchesBaseline(array $identity, array $metadata)
    {
        $expected = [
            'application_version' => 'application_version',
            'schema_baseline' => 'baseline_id',
            'schema_head' => 'schema_head',
            'baseline_source_commit' => 'source_commit',
        ];
        foreach ($expected as $identityField => $metadataField) {
            if (!hash_equals((string) $metadata[$metadataField], (string) $identity[$identityField])) {
                throw new RuntimeException('Installation identity does not match baseline field ' . $metadataField . '.');
            }
        }
    }

    private function assertSupportedDatabase(array $metadata, BaselineMetadata $baseline)
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        if (!preg_match('/\A(\d+\.\d+\.\d+)/', $version, $matches) || !$baseline->supportsMysqlVersion($matches[1])) {
            throw new RuntimeException('Target database is outside the supported MySQL baseline range.');
        }
        $schema = $this->pdo->query(
            "SELECT default_character_set_name AS charset_name, default_collation_name AS collation_name
             FROM information_schema.schemata WHERE schema_name = DATABASE()"
        )->fetch();
        if (!is_array($schema)
            || $schema['charset_name'] !== $metadata['mysql']['charset']
            || $schema['collation_name'] !== $metadata['mysql']['collation']) {
            throw new RuntimeException('Target database charset or collation does not match the baseline contract.');
        }
        $activeModes = array_filter(explode(',', (string) $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn()));
        $activeLookup = array_fill_keys($activeModes, true);
        foreach ($metadata['mysql']['sql_modes'] as $requiredMode) {
            if (!isset($activeLookup[$requiredMode])) {
                throw new RuntimeException('Target database is missing required SQL mode ' . $requiredMode . '.');
            }
        }
    }

    private function assertEmptyDatabase()
    {
        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException('Baseline installation requires a proven-empty target database.');
        }
    }

    private function tableNames()
    {
        return $this->pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
             ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function persistIdentity(array $identity)
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO syndicatum_installation_identity
             (singleton_id, application_version, schema_baseline, schema_head,
              baseline_source_commit, release_source_commit, package_sha256,
              package_format_version, installation_id, installed_at,
              last_upgrade_id, last_upgrade_from_version, last_upgrade_to_version, last_upgraded_at)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $identity['application_version'],
            $identity['schema_baseline'],
            $identity['schema_head'],
            strtolower($identity['baseline_source_commit']),
            strtolower($identity['release_source_commit']),
            strtolower($identity['package_sha256']),
            $identity['package_format_version'],
            strtolower($identity['installation_id']),
            $this->mysqlTimestamp($identity['installed_at']),
            isset($identity['last_upgrade_id']) ? $identity['last_upgrade_id'] : null,
            isset($identity['last_upgrade_from_version']) ? $identity['last_upgrade_from_version'] : null,
            isset($identity['last_upgrade_to_version']) ? $identity['last_upgrade_to_version'] : null,
            isset($identity['last_upgraded_at']) ? $this->mysqlTimestamp($identity['last_upgraded_at']) : null,
        ]);
    }

    private function mysqlTimestamp($timestamp)
    {
        return substr($timestamp, 0, 10) . ' ' . substr($timestamp, 11, 8);
    }

    private function splitStatements($sql)
    {
        $lines = preg_split('/\r?\n/', $sql);
        $sql = implode("\n", array_values(array_filter($lines, function ($line) {
            return !preg_match('/\A\s*--/', $line);
        })));
        $statements = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== null) {
                $buffer .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                        $buffer .= $sql[++$index];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                $buffer .= $character;
            } elseif ($character === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
            } else {
                $buffer .= $character;
            }
        }
        if ($quote !== null || trim($buffer) !== '') {
            throw new RuntimeException('Baseline schema contains an unterminated SQL statement.');
        }
        if (!$statements) {
            throw new RuntimeException('Baseline schema contains no executable SQL statements.');
        }
        return $statements;
    }
}
