<?php

require_once __DIR__ . '/LegacyUpgradePackage.php';
require_once __DIR__ . '/LegacyClaimColumnOrder.php';
require_once __DIR__ . '/LegacySchemaFingerprint.php';
require_once __DIR__ . '/InstallationIdentity.php';

/** Executes only the five authenticated forward definitions on a verified legacy clone. */
final class LegacyForwardUpgrader
{
    const TARGET_SCHEMA_SHA256 = '5b4623004957317a7c395dbcca34b6b186ed20a304f2ad6c577a74bb3834e86e';
    private $pdo;
    private $package;
    private $database;

    // Schema-object fingerprints from the preserved source plus each approved
    // contiguous forward prefix, before the CHECK/order/identity bridge.
    private $prefixSha256 = [
        '12484feb17b559002d200630391a537fc9af6281ddb011f7c10471a2f9ad4445',
        '174f15dabc1c15ccc754ba2b3781b9b44d05cc0b5280e578871858cf73231aaa',
        '30b8c032711fa18677a3104ba6d6f478e081a7f2ba6b4b0555ce64827e34cb38',
        '73798b9b86b41b6d6d34c98173be36c13cde1f802b88c72fe3a256072f3dc392',
        'c8a69971be65aeb040887106c7436b85174474eaed4c94e8a79563abfbc7c10c',
        'ee76b3cbe9969031141613e24fc345777983817be132d4b78950e2a5fa86754a',
    ];

    public function __construct(PDO $pdo, LegacyUpgradePackage $package, $expectedDatabase)
    {
        if (!is_string($expectedDatabase) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $expectedDatabase)) {
            throw new InvalidArgumentException('Expected clone database name is invalid.');
        }
        $this->pdo = $pdo;
        $this->package = $package;
        $this->database = $expectedDatabase;
    }

    /** No database writes. Must pass in full before execute() may begin. */
    public function preflight()
    {
        if ($this->pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->database) {
            throw new RuntimeException('Connected database is not the explicitly selected legacy clone.');
        }
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        if (!preg_match('/\A8\.4\./', $version)) {
            throw new RuntimeException('Legacy upgrade requires MySQL 8.4.');
        }
        $plan = $this->package->plan();
        $plan->verifyFiles();
        $rows = $this->pdo->query('SELECT version, checksum FROM syndicatum_schema_migrations ORDER BY version')
            ->fetchAll(PDO::FETCH_ASSOC);
        $pending = $plan->pendingForwardMigrations($rows);
        $prefix = count($plan->forwardMigrations()) - count($pending);
        $identityExists = $this->tableExists('syndicatum_installation_identity');
        if ($identityExists) {
            if ($pending) {
                throw new RuntimeException('Installation identity exists before forward completion.');
            }
            $this->assertComplete();
            return ['complete' => true, 'pending' => [], 'prefix' => 5];
        }
        if (!hash_equals($this->prefixSha256[$prefix], LegacySchemaFingerprint::sha256($this->pdo))) {
            throw new RuntimeException('Legacy schema differs from the exact verified forward prefix.');
        }
        $bridge = new LegacyClaimColumnOrder($this->package->baselineSchemaPath());
        if ($bridge->preflight($this->pdo) !== ['agents', 'chat_agents']) {
            throw new RuntimeException('Legacy claim-column order changed before uplift.');
        }
        $violations = (int) $this->pdo->query("SELECT COUNT(*) FROM project_participants WHERE NOT (
            (kind = 'human' AND user_id IS NOT NULL AND agent_id IS NULL)
            OR (kind = 'agent' AND agent_id IS NOT NULL AND user_id IS NULL))")->fetchColumn();
        if ($violations !== 0) {
            throw new RuntimeException('Existing participant identities violate the target CHECK.');
        }
        return ['complete' => false, 'pending' => $pending, 'prefix' => $prefix];
    }

    public function execute()
    {
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute(['syndicatum_legacy_v1_uplift']);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Another legacy uplift is in progress.');
        }
        try {
            $state = $this->preflight();
            if ($state['complete']) {
                return ['executed' => [], 'changed' => false];
            }
            $executed = [];
            $prefix = $state['prefix'];
            foreach ($state['pending'] as $entry) {
                if (!hash_equals($this->prefixSha256[$prefix], LegacySchemaFingerprint::sha256($this->pdo))) {
                    throw new RuntimeException('Schema changed before a forward migration.');
                }
                $path = $this->package->forwardMigrationPath($entry['id']);
                if (!hash_equals($entry['sha256'], LegacyMigrationPlan::canonicalSha256($path))) {
                    throw new RuntimeException('Authenticated forward migration bytes changed.');
                }
                $migration = require $path;
                if (!is_array($migration) || !isset($migration['version'], $migration['description'], $migration['statements'])
                    || $migration['version'] !== $entry['id'] || !is_string($migration['description'])
                    || !is_array($migration['statements'])) {
                    throw new RuntimeException('Authenticated forward migration structure is invalid.');
                }
                foreach ($migration['statements'] as $statement) {
                    if (is_array($statement)) {
                        if (!isset($statement['unless_column'], $statement['sql'])
                            || !is_array($statement['unless_column']) || count($statement['unless_column']) !== 2
                            || !is_string($statement['sql'])) {
                            throw new RuntimeException('Unsupported conditional forward migration.');
                        }
                        if ($this->columnExists($statement['unless_column'][0], $statement['unless_column'][1])) {
                            throw new RuntimeException('A pending forward migration is already partially applied.');
                        }
                        $statement = $statement['sql'];
                    }
                    if (!is_string($statement) || trim($statement) === '') {
                        throw new RuntimeException('Empty forward migration statement.');
                    }
                    $this->pdo->exec($statement);
                }
                $prefix++;
                if (!hash_equals($this->prefixSha256[$prefix], LegacySchemaFingerprint::sha256($this->pdo))) {
                    throw new RuntimeException('Forward migration schema result differs from the approved prefix.');
                }
                $record = $this->pdo->prepare('INSERT INTO syndicatum_schema_migrations
                    (version, description, checksum, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())');
                $record->execute([$entry['id'], $migration['description'], $entry['sha256']]);
                $executed[] = $entry['id'];
            }
            $this->package->plan()->verifyDatabaseLedger($this->pdo, 'target');
            $bridge = new LegacyClaimColumnOrder($this->package->baselineSchemaPath());
            $bridge->apply($this->pdo, ['agents', 'chat_agents']);
            $this->addParticipantCheck();
            $this->createIdentity();
            $this->assertComplete();
            return ['executed' => $executed, 'changed' => true];
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute(['syndicatum_legacy_v1_uplift']);
        }
    }

    private function tableExists($name)
    {
        $query = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ? AND table_type = 'BASE TABLE'");
        $query->execute([$name]);
        return (int) $query->fetchColumn() === 1;
    }

    private function columnExists($table, $column)
    {
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $query->execute([$table, $column]);
        return (int) $query->fetchColumn() === 1;
    }

    private function canonicalSchema()
    {
        $sql = @file_get_contents($this->package->baselineSchemaPath());
        if (!is_string($sql) || !hash_equals($this->package->baseline()['schema_sha256'], hash('sha256', $sql))) {
            throw new RuntimeException('Authenticated baseline SQL changed after package validation.');
        }
        return $sql;
    }

    private function addParticipantCheck()
    {
        $sql = $this->canonicalSchema();
        if (!preg_match('/^  (CONSTRAINT `chk_project_participants_identity` CHECK \(.+\))\r?$/m', $sql, $match)) {
            throw new RuntimeException('Canonical participant CHECK is unavailable.');
        }
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE constraint_schema = DATABASE() AND table_name = 'project_participants'
              AND constraint_name = 'chk_project_participants_identity'")->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException('Participant CHECK already exists before controlled bridge.');
        }
        $this->pdo->exec('ALTER TABLE `project_participants` ADD ' . $match[1]);
        $clause = $this->pdo->query("SELECT cc.check_clause FROM information_schema.table_constraints tc
            JOIN information_schema.check_constraints cc
              ON cc.constraint_schema = tc.constraint_schema AND cc.constraint_name = tc.constraint_name
            WHERE tc.constraint_schema = DATABASE() AND tc.table_name = 'project_participants'
              AND tc.constraint_name = 'chk_project_participants_identity'")->fetchColumn();
        $expected = substr($match[1], strpos($match[1], 'CHECK (') + 7, -1);
        // MySQL 8.4 quotes literal apostrophes in information_schema output.
        $expected = str_replace("'", "\\'", $expected);
        if (!is_string($clause) || $clause !== $expected) {
            throw new RuntimeException('Participant CHECK differs from canonical baseline normalization.');
        }
    }

    private function createIdentity()
    {
        if ($this->tableExists('syndicatum_installation_identity')) {
            throw new RuntimeException('Installation identity already exists.');
        }
        $schema = $this->canonicalSchema();
        if (!preg_match('/CREATE TABLE `syndicatum_installation_identity` \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/s', $schema, $match)) {
            throw new RuntimeException('Canonical installation identity DDL is unavailable.');
        }
        $metadata = $this->package->baseline();
        $time = gmdate('Y-m-d\TH:i:s\Z');
        $values = [
            'application_version' => $metadata['application_version'],
            'schema_baseline' => $metadata['baseline_id'],
            'schema_head' => $metadata['schema_head'],
            'baseline_source_commit' => $metadata['source_commit'],
            'release_source_commit' => $this->package->sourceCommit(),
            'package_sha256' => $this->package->archiveSha256(),
            'package_format_version' => $this->package->formatVersion(),
            'installation_id' => $this->uuid(),
            'installed_at' => $time,
            'last_upgrade_id' => 'legacy-v1:' . $this->uuid(),
            'last_upgrade_from_version' => $this->package->plan()->metadata()['source_migration_head'],
            'last_upgrade_to_version' => $metadata['application_version'],
            'last_upgraded_at' => $time,
        ];
        InstallationIdentity::fromArray($values);
        $this->pdo->exec($match[0]);
        $fields = array_keys($values);
        $columns = array_map(function ($field) { return '`' . $field . '`'; }, $fields);
        $insert = $this->pdo->prepare('INSERT INTO syndicatum_installation_identity
            (`singleton_id`, ' . implode(', ', $columns) . ') VALUES (1, '
            . implode(', ', array_fill(0, count($values), '?')) . ')');
        $dbValues = [];
        foreach ($values as $field => $value) {
            $dbValues[] = $field === 'installed_at' || $field === 'last_upgraded_at'
                ? str_replace(['T', 'Z'], [' ', ''], $value) : $value;
        }
        $insert->execute($dbValues);
    }

    private function assertComplete()
    {
        if (!hash_equals(self::TARGET_SCHEMA_SHA256, LegacySchemaFingerprint::sha256($this->pdo))) {
            throw new RuntimeException('Upgraded schema is not the exact protected V1 baseline.');
        }
        $this->package->plan()->verifyDatabaseLedger($this->pdo, 'target');
        $rows = $this->pdo->query('SELECT * FROM syndicatum_installation_identity')->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || (int) $rows[0]['singleton_id'] !== 1) {
            throw new RuntimeException('Upgraded installation identity is not a singleton.');
        }
        $identity = $rows[0];
        $baseline = $this->package->baseline();
        $required = [
            'application_version' => $baseline['application_version'],
            'schema_baseline' => $baseline['baseline_id'],
            'schema_head' => $baseline['schema_head'],
            'baseline_source_commit' => $baseline['source_commit'],
            'release_source_commit' => $this->package->sourceCommit(),
            'package_sha256' => $this->package->archiveSha256(),
            'package_format_version' => $this->package->formatVersion(),
            'last_upgrade_from_version' => $this->package->plan()->metadata()['source_migration_head'],
            'last_upgrade_to_version' => $baseline['application_version'],
        ];
        foreach ($required as $field => $expected) {
            if (!hash_equals((string) $expected, (string) $identity[$field])) {
                throw new RuntimeException('Upgraded installation identity differs from the accepted package: ' . $field);
            }
        }
        if (!preg_match('/\Alegacy-v1:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
            (string) $identity['last_upgrade_id'])
            || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
                (string) $identity['installation_id'])
            || $identity['installed_at'] === null || $identity['last_upgraded_at'] === null
            || $identity['last_upgraded_at'] < $identity['installed_at']) {
            throw new RuntimeException('Upgraded installation identity has invalid lineage or timestamps.');
        }
    }

    private function uuid()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
