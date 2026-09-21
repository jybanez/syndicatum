<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/BaselineMetadata.php';
require_once __DIR__ . '/LegacyMigrationPlan.php';

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
            if (in_array('syndicatum_schema_migrations', $tables, true)) {
                try {
                    $plan = new LegacyMigrationPlan();
                    $plan->verifyFiles();
                    $rows = $this->pdo->query(
                        'SELECT version, checksum FROM syndicatum_schema_migrations ORDER BY version'
                    )->fetchAll(PDO::FETCH_ASSOC);
                    $plan->pendingForwardMigrations($rows);
                    return ['state' => 'legacy_upgrade_incomplete', 'ready' => false,
                        'table_count' => count($tables), 'migration_rows' => count($rows)];
                } catch (Exception $ignored) {
                    // Unknown or tampered legacy state is not classified as a permitted upgrade.
                }
            }
            return ['state' => 'legacy_or_partial', 'ready' => false, 'table_count' => count($tables)];
        }

        try {
            $identityRows = $this->pdo->query(
                'SELECT application_version, schema_baseline, schema_head, baseline_source_commit,
                        release_source_commit, package_sha256, package_format_version, installation_id, installed_at,
                        last_upgrade_id, last_upgrade_from_version, last_upgrade_to_version, last_upgraded_at
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
            $ledger = $this->pdo->query(
                'SELECT version, checksum FROM syndicatum_schema_migrations ORDER BY version'
            )->fetchAll(PDO::FETCH_ASSOC);
            $migrationRows = count($ledger);
            $upgradeFields = ['last_upgrade_id', 'last_upgrade_from_version', 'last_upgrade_to_version', 'last_upgraded_at'];
            $upgradeCount = 0;
            foreach ($upgradeFields as $field) {
                if ($identity[$field] !== null) {
                    $upgradeCount++;
                }
            }
            if ($upgradeCount !== 0 && $upgradeCount !== count($upgradeFields)) {
                return ['state' => 'legacy_upgrade_incomplete', 'ready' => false, 'table_count' => count($tables)];
            }
            if ($upgradeCount === count($upgradeFields)) {
                $plan = new LegacyMigrationPlan();
                $plan->verifyFiles();
                $planMetadata = $plan->metadata();
                if (!preg_match('/\Alegacy-v1:[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', (string) $identity['last_upgrade_id'])
                    || !hash_equals($planMetadata['source_migration_head'], (string) $identity['last_upgrade_from_version'])
                    || !hash_equals((string) $metadataArray['application_version'], (string) $identity['last_upgrade_to_version'])
                    || !hash_equals($planMetadata['target_schema_head'], (string) $identity['schema_head'])
                    || !hash_equals($planMetadata['target_baseline_id'], (string) $identity['schema_baseline'])
                    || $identity['last_upgraded_at'] < $identity['installed_at']) {
                    return ['state' => 'legacy_upgrade_mismatch', 'ready' => false, 'table_count' => count($tables)];
                }
                try {
                    $plan->verifyLedgerRows($ledger, 'target');
                } catch (RuntimeException $exception) {
                    return ['state' => 'legacy_upgrade_incomplete', 'ready' => false, 'table_count' => count($tables), 'migration_rows' => $migrationRows];
                }
                if (!$this->hasParticipantIdentityCheck()) {
                    return ['state' => 'legacy_upgrade_incomplete', 'ready' => false, 'table_count' => count($tables), 'migration_rows' => $migrationRows];
                }
                return [
                    'state' => 'legacy_upgraded_ready',
                    'ready' => true,
                    'table_count' => count($tables),
                    'migration_rows' => $migrationRows,
                    'identity' => $identity,
                ];
            }
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

    private function hasParticipantIdentityCheck()
    {
        $query = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE() AND table_name = 'project_participants'
               AND constraint_name = 'chk_project_participants_identity' AND constraint_type = 'CHECK'"
        );
        $query->execute();
        return (int) $query->fetchColumn() === 1;
    }
}
