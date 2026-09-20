<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/BaselineMetadata.php';

class InstallationState
{
    private $pdo;
    private $metadataPath;

    public function __construct(PDO $pdo, $metadataPath = null)
    {
        $this->pdo = $pdo;
        $this->metadataPath = $metadataPath ?: dirname(__DIR__) . '/schema/mysql84/baseline.json';
    }

    public function inspect()
    {
        $tables = $this->tableNames();
        if (!$tables) {
            return ['state' => 'empty', 'ready' => false, 'table_count' => 0];
        }
        if (!in_array('syndicatum_installation_identity', $tables, true)) {
            return ['state' => 'legacy_or_partial', 'ready' => false, 'table_count' => count($tables)];
        }

        try {
            $identityRows = $this->pdo->query(
                'SELECT application_version, schema_baseline, schema_head, baseline_source_commit,
                        release_source_commit, package_sha256, package_format_version, installation_id, installed_at
                 FROM syndicatum_installation_identity ORDER BY singleton_id'
            )->fetchAll();
            if (count($identityRows) !== 1) {
                return ['state' => 'partial_identity', 'ready' => false, 'table_count' => count($tables)];
            }
            $metadataArray = $this->metadata();
            $metadata = BaselineMetadata::fromArray($metadataArray);
            $metadata->assertBaselineTables($tables);
            $identity = $identityRows[0];
            foreach ([
                'application_version' => 'application_version',
                'schema_baseline' => 'baseline_id',
                'schema_head' => 'schema_head',
                'baseline_source_commit' => 'source_commit',
            ] as $identityField => $metadataField) {
                if (!hash_equals((string) $metadataArray[$metadataField], (string) $identity[$identityField])) {
                    return ['state' => 'identity_mismatch', 'ready' => false, 'table_count' => count($tables)];
                }
            }
            $migrationRows = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM syndicatum_schema_migrations'
            )->fetchColumn();
            if ($migrationRows !== count($metadataArray['post_baseline_migrations'])) {
                return [
                    'state' => 'migration_state_mismatch',
                    'ready' => false,
                    'table_count' => count($tables),
                    'migration_rows' => $migrationRows,
                ];
            }
        } catch (Exception $exception) {
            return [
                'state' => 'invalid_baseline',
                'ready' => false,
                'table_count' => count($tables),
                'reason' => $exception->getMessage(),
            ];
        }

        return [
            'state' => 'ready',
            'ready' => true,
            'table_count' => count($tables),
            'migration_rows' => $migrationRows,
            'identity' => $identityRows[0],
        ];
    }

    public function assertReady()
    {
        $status = $this->inspect();
        if (empty($status['ready'])) {
            throw new RuntimeException('INSTALLATION_REQUIRED: ' . $status['state']);
        }
        return $status;
    }

    private function tableNames()
    {
        return $this->pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
             ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function metadata()
    {
        $json = file_get_contents($this->metadataPath);
        $metadata = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Trusted baseline metadata is unavailable.');
        }
        return $metadata;
    }
}
