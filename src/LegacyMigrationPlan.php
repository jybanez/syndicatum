<?php

/**
 * Read-only verifier for the pinned pre-V1 -> V1 migration ledger.
 * The enclosing release package must be authenticated before this plan is used.
 */
class LegacyMigrationPlan
{
    private $plan;
    private $migrationDirectory;

    public function __construct($planPath = null, $migrationDirectory = null)
    {
        $planPath = $planPath ?: dirname(__DIR__) . '/schema/mysql84/legacy-upgrade-plan.json';
        $this->migrationDirectory = $migrationDirectory ?: dirname(__DIR__) . '/migrations';
        $json = file_get_contents($planPath);
        $plan = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($plan) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Trusted legacy upgrade plan is unavailable.');
        }
        $this->validatePlan($plan);
        $this->plan = $plan;
    }

    public function metadata()
    {
        return [
            'source_migration_head' => $this->plan['source_migration_head'],
            'target_schema_head' => $this->plan['target_schema_head'],
            'target_baseline_id' => $this->plan['target_baseline_id'],
        ];
    }

    public function historicalMigrations()
    {
        return $this->plan['historical_migrations'];
    }

    public function forwardMigrations()
    {
        return $this->plan['forward_migrations'];
    }

    public function verifyFiles()
    {
        foreach (array_merge($this->historicalMigrations(), $this->forwardMigrations()) as $migration) {
            $path = $this->migrationDirectory . '/' . $migration['id'] . '.php';
            if (!is_file($path) || !hash_equals($migration['sha256'], self::canonicalSha256($path))) {
                throw new RuntimeException('Migration content does not match the trusted plan: ' . $migration['id']);
            }
        }
    }

    public function verifyDatabaseLedger(PDO $pdo, $phase)
    {
        $rows = $pdo->query('SELECT version, checksum FROM syndicatum_schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
        $this->verifyLedgerRows($rows, $phase);
    }

    /**
     * Return the unapplied forward suffix after exact historical-ledger preflight.
     * A partial forward prefix is accepted only so an interrupted upgrade can
     * resume; no historical or forward row may be rewritten or skipped.
     */
    public function pendingForwardMigrations(array $rows)
    {
        $history = $this->historicalMigrations();
        $forward = $this->forwardMigrations();
        if (count($rows) < count($history) || count($rows) > count($history) + count($forward)) {
            throw new RuntimeException('Legacy migration ledger has a missing or extra row.');
        }
        $expected = array_merge($history, $forward);
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !isset($row['version'], $row['checksum'])
                || !is_string($row['version']) || !is_string($row['checksum'])
                || !hash_equals($expected[$index]['id'], $row['version'])
                || !hash_equals($expected[$index]['sha256'], strtolower($row['checksum']))) {
                throw new RuntimeException('Legacy migration ledger is not an exact source plus forward prefix.');
            }
        }
        return array_slice($forward, count($rows) - count($history));
    }

    public function verifyLedgerRows(array $rows, $phase)
    {
        if ($phase !== 'source' && $phase !== 'target') {
            throw new InvalidArgumentException('Legacy ledger phase must be source or target.');
        }
        $expected = $this->historicalMigrations();
        if ($phase === 'target') {
            $expected = array_merge($expected, $this->forwardMigrations());
        }
        $expectedById = [];
        foreach ($expected as $migration) {
            $expectedById[$migration['id']] = $migration['sha256'];
        }
        if (count($rows) !== count($expectedById)) {
            throw new RuntimeException('Legacy migration ledger has a missing or extra row.');
        }
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['version'], $row['checksum'])
                || !is_string($row['version']) || !is_string($row['checksum'])) {
                throw new RuntimeException('Legacy migration ledger row is malformed.');
            }
            $id = $row['version'];
            if (!isset($expectedById[$id]) || isset($seen[$id])
                || !hash_equals($expectedById[$id], strtolower($row['checksum']))) {
                throw new RuntimeException('Legacy migration ledger is not the declared ' . $phase . ' lineage.');
            }
            $seen[$id] = true;
        }
    }

    public static function canonicalSha256($path)
    {
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new RuntimeException('Migration bytes are unavailable.');
        }
        // Only checkout line endings may vary. A lone CR or any content edit fails.
        $withoutCrlf = str_replace("\r\n", '', $raw);
        if (strpos($withoutCrlf, "\r") !== false || preg_match('//u', $raw) !== 1) {
            throw new RuntimeException('Migration bytes violate canonical UTF-8/LF serialization.');
        }
        return hash('sha256', str_replace("\r\n", "\n", $raw));
    }

    private function validatePlan(array $plan)
    {
        $keys = ['contract_name', 'format_version', 'source_migration_head', 'target_schema_head',
            'target_baseline_id', 'canonicalization', 'historical_migrations', 'forward_migrations'];
        $actualKeys = array_keys($plan);
        sort($keys);
        sort($actualKeys);
        if ($keys !== $actualKeys || $plan['contract_name'] !== 'syndicatum-legacy-v1-upgrade-plan'
            || $plan['format_version'] !== '1.0'
            || $plan['canonicalization'] !== 'UTF-8 bytes; CRLF to LF only; lone CR rejected'
            || $plan['source_migration_head'] !== '202609170001_message_request_fingerprint'
            || $plan['target_schema_head'] !== '202609180004'
            || $plan['target_baseline_id'] !== 'syndicatum-mysql84-1.0.0-baseline.1') {
            throw new RuntimeException('Legacy upgrade plan metadata is not supported.');
        }
        if (!is_array($plan['historical_migrations']) || count($plan['historical_migrations']) !== 25
            || !is_array($plan['forward_migrations']) || count($plan['forward_migrations']) !== 5) {
            throw new RuntimeException('Legacy upgrade plan migration inventory is incomplete.');
        }
        $seen = [];
        foreach (array_merge($plan['historical_migrations'], $plan['forward_migrations']) as $migration) {
            if (!is_array($migration) || count($migration) !== 2
                || !isset($migration['id'], $migration['sha256'])
                || !is_string($migration['id']) || !is_string($migration['sha256'])
                || !preg_match('/\A\d{12}_[a-z0-9_]+\z/', $migration['id'])
                || !preg_match('/\A[a-f0-9]{64}\z/', $migration['sha256'])
                || isset($seen[$migration['id']])) {
                throw new RuntimeException('Legacy upgrade plan contains an invalid or duplicate migration.');
            }
            $seen[$migration['id']] = true;
        }
        if (end($plan['historical_migrations'])['id'] !== $plan['source_migration_head']) {
            throw new RuntimeException('Legacy upgrade plan source head does not match its inventory.');
        }
    }
}
