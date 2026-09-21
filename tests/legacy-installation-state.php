<?php

require_once dirname(__DIR__) . '/src/InstallationState.php';

class LegacyStateStatement extends PDOStatement
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function execute($params = null)
    {
        return true;
    }

    public function fetchAll($mode = null, $arg1 = null, $arg2 = null)
    {
        return $this->rows;
    }

    public function fetchColumn($column = 0)
    {
        return $this->rows[0][0];
    }
}

class LegacyStatePdo extends PDO
{
    public $tables;
    public $identity;
    public $ledger;
    public $hasCheck = true;

    public function __construct(array $tables, array $identity, array $ledger)
    {
        $this->tables = $tables;
        $this->identity = $identity;
        $this->ledger = $ledger;
    }

    public function query($sql)
    {
        if (strpos($sql, 'information_schema.tables') !== false) {
            return new LegacyStateStatement($this->tables);
        }
        if (strpos($sql, 'FROM syndicatum_installation_identity') !== false) {
            return new LegacyStateStatement([$this->identity]);
        }
        if (strpos($sql, 'FROM syndicatum_schema_migrations') !== false) {
            return new LegacyStateStatement($this->ledger);
        }
        throw new RuntimeException('Unexpected readiness query.');
    }

    public function prepare($sql, $options = [])
    {
        if (strpos($sql, 'chk_project_participants_identity') === false) {
            throw new RuntimeException('Unexpected readiness preflight.');
        }
        return new LegacyStateStatement([[$this->hasCheck ? 1 : 0]]);
    }
}

function legacyStateAssert($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

$baseline = json_decode(file_get_contents(dirname(__DIR__) . '/schema/mysql84/baseline.json'), true);
$tables = array_column($baseline['tables'], 'name');
$plan = new LegacyMigrationPlan();
$ledger = [];
foreach (array_merge($plan->historicalMigrations(), $plan->forwardMigrations()) as $migration) {
    $ledger[] = ['version' => $migration['id'], 'checksum' => $migration['sha256']];
}
$identity = [
    'application_version' => $baseline['application_version'],
    'schema_baseline' => $baseline['baseline_id'],
    'schema_head' => $baseline['schema_head'],
    'baseline_source_commit' => $baseline['source_commit'],
    'release_source_commit' => str_repeat('a', 40),
    'package_sha256' => str_repeat('b', 64),
    'package_format_version' => '1.0',
    'installation_id' => '220eb019-f36d-41e7-9849-41a726b85855',
    'installed_at' => '2026-09-21 00:00:00',
    'last_upgrade_id' => 'legacy-v1:220eb019-f36d-41e7-9849-41a726b85856',
    'last_upgrade_from_version' => $plan->metadata()['source_migration_head'],
    'last_upgrade_to_version' => $baseline['application_version'],
    'last_upgraded_at' => '2026-09-21 00:00:01',
];
$pdo = new LegacyStatePdo($tables, $identity, $ledger);
$state = new InstallationState($pdo);
$status = $state->inspect();
legacyStateAssert($status['state'] === 'legacy_upgraded_ready' && $status['ready'] === true, 'Exact upgraded lineage was not ready.');

$pdo->ledger = array_slice($ledger, 0, -1);
legacyStateAssert($state->inspect()['state'] === 'legacy_upgrade_incomplete', 'Missing forward row was accepted.');
$pdo->ledger = $ledger;
$pdo->ledger[0]['checksum'] = str_repeat('0', 64);
legacyStateAssert($state->inspect()['state'] === 'legacy_upgrade_incomplete', 'Tampered historical row was accepted.');
$pdo->ledger = $ledger;
$pdo->hasCheck = false;
legacyStateAssert($state->inspect()['state'] === 'legacy_upgrade_incomplete', 'Missing participant CHECK was accepted.');
$pdo->hasCheck = true;
$pdo->identity['last_upgrade_from_version'] = 'wrong';
legacyStateAssert($state->inspect()['state'] === 'legacy_upgrade_mismatch', 'Wrong upgrade source was accepted.');
$pdo->identity = $identity;
$pdo->identity['last_upgrade_id'] = null;
legacyStateAssert($state->inspect()['state'] === 'legacy_upgrade_incomplete', 'Partial lineage marker was accepted.');
$pdo->identity = $identity;
$pdo->identity['last_upgrade_id'] = null;
$pdo->identity['last_upgrade_from_version'] = null;
$pdo->identity['last_upgrade_to_version'] = null;
$pdo->identity['last_upgraded_at'] = null;
$pdo->ledger = [];
legacyStateAssert($state->inspect()['state'] === 'ready', 'Fresh baseline readiness regressed.');
$pdo->tables = array_values(array_diff($tables, ['syndicatum_installation_identity']));
$pdo->ledger = array_slice($ledger, 0, 26);
legacyStateAssert($state->inspect()['state'] === 'legacy_upgrade_incomplete', 'Unstamped forward prefix was not classified incomplete.');
$pdo->ledger[0]['checksum'] = str_repeat('0', 64);
legacyStateAssert($state->inspect()['state'] === 'legacy_or_partial', 'Tampered unstamped ledger was classified as permitted upgrade.');

echo "Legacy installation readiness checks passed.\n";
