<?php

require_once dirname(__DIR__) . '/src/BackupProducer.php';
require_once dirname(__DIR__) . '/src/StagedBackupRestore.php';

function backupProducerFail($message) { fwrite(STDERR, 'FAIL  ' . $message . PHP_EOL); exit(1); }
function backupProducerAssert($condition, $message) { if (!$condition) { backupProducerFail($message); } }
function backupProducerThrows(callable $callback, $message) { try { $callback(); } catch (Throwable $exception) { return; } backupProducerFail($message); }
function backupProducerRemoveTree($path) {
    if (is_dir($path) && !is_link($path)) { $items = scandir($path); if (is_array($items)) { foreach ($items as $item) { if ($item !== '.' && $item !== '..') { backupProducerRemoveTree($path . DIRECTORY_SEPARATOR . $item); } } } @rmdir($path); }
    elseif (file_exists($path) || is_link($path)) { @unlink($path); }
}

final class FixtureBackupDatabase implements BackupDatabaseSource
{
    private $baseline;
    public $active = false;
    public $endedSuccessfully = null;
    public $driftTable = null;
    public function __construct(BaselineMetadata $baseline) { $this->baseline = $baseline; }
    public function beginConsistentSnapshot() { if ($this->active) { throw new RuntimeException('duplicate snapshot'); } $this->active = true; }
    public function endConsistentSnapshot($success) { $this->active = false; $this->endedSuccessfully = (bool) $success; }
    public function tableNames() { $names = []; foreach ($this->baseline->tablePolicies() as $table) { $names[] = $table['name']; } return $names; }
    public function columns($table) {
        $columns = $this->baseline->tablePolicy($table)['columns'];
        if ($this->driftTable === $table) { $columns[] = 'unexpected_drift_column'; }
        return $columns;
    }
    public function nextSequenceValue($table) { return $table === 'users' ? 40 : null; }
    public function rows($table, array $identityColumns) {
        if ($table !== 'users') { return []; }
        $row = array_fill_keys($this->columns($table), null);
        $row['id'] = 7;
        $row['avatar_url'] = 'api/v1/avatar.php?file=' . str_repeat('a', 40) . '.png';
        return [$row];
    }
}

final class FixtureRestoreTarget implements BackupRestoreTarget
{
    private $baseline;
    private $source;
    public $counts = [];
    public $rows = [];
    public $transaction = false;
    public $sequences = [];
    private $snapshot = [];
    public function __construct(BaselineMetadata $baseline, FixtureBackupDatabase $source) {
        $this->baseline=$baseline; $this->source=$source;
        foreach($baseline->tablePolicies() as $table){$this->counts[$table['name']]=0;if($table['backup_policy']==='durable'){$this->sequences[$table['name']]=null;}}
        $this->counts['system_roles']=2; $this->counts['syndicatum_installation_identity']=1;
    }
    public function tableNames(){ $names=array_keys($this->counts); sort($names,SORT_STRING); return $names; }
    public function columns($table){ return $this->source->columns($table); }
    public function rowCount($table){ return $this->counts[$table]; }
    public function selectedRows($table,array $columns,array $orderBy){
        if($table==='system_roles'){return [['id'=>'1','code'=>'user','name'=>'User'],['id'=>'2','code'=>'administrator','name'=>'Administrator']];}
        if($table==='syndicatum_installation_identity'){$b=$this->baseline->toArray();return [['application_version'=>$b['application_version'],'schema_baseline'=>$b['baseline_id'],'schema_head'=>$b['schema_head']]];}
        return isset($this->rows[$table])?$this->rows[$table]:[];
    }
    public function nextSequenceValue($table){return $this->sequences[$table];}
    public function setNextSequenceValue($table,$value){$this->sequences[$table]=$value;}
    public function begin(){ if($this->transaction){throw new RuntimeException('duplicate transaction');}$this->snapshot=$this->counts;$this->transaction=true; }
    public function insert($table,array $columns,array $values){ if(!$this->transaction){throw new RuntimeException('insert outside transaction');}$this->rows[$table][]=array_combine($columns,$values);$this->counts[$table]++; }
    public function commit(){ if(!$this->transaction){throw new RuntimeException('missing transaction');}$this->transaction=false;$this->snapshot=[]; }
    public function rollBack(){ $this->counts=$this->snapshot;$this->rows=[];$this->transaction=false;$this->snapshot=[]; }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-producer-test-' . bin2hex(random_bytes(8));
$temporary = $root . DIRECTORY_SEPARATOR . 'temporary';
$avatars = $root . DIRECTORY_SEPARATOR . 'avatars';
$public = $root . DIRECTORY_SEPARATOR . 'public';
$output = $root . DIRECTORY_SEPARATOR . 'output';
foreach ([$root,$temporary,$avatars,$public,$output] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) { backupProducerFail('Unable to create fixture directory.'); }
    @chmod($directory, 0700);
}

try {
    $avatarName = str_repeat('a', 40) . '.png';
    file_put_contents($avatars . DIRECTORY_SEPARATOR . $avatarName, "\x89PNG\x0d\x0a\x1a\x0a" . str_repeat('x', 64));
    $baselineArray = json_decode(file_get_contents(dirname(__DIR__) . '/schema/mysql84/baseline.json'), true);
    $baseline = BaselineMetadata::fromArray($baselineArray);
    $database = new FixtureBackupDatabase($baseline);
    $producer = new BackupProducer($database, $baseline, $temporary, $avatars, $public, function () { return '2026-09-20T08:00:00Z'; });
    $key = random_bytes(32);
    $destination = $output . DIRECTORY_SEPARATOR . 'fixture.syndicatum-backup';
    $result = $producer->produce($destination, $key, [
        'application_version' => $baselineArray['application_version'],
        'source_commit' => str_repeat('b', 40),
        'schema_baseline' => $baselineArray['baseline_id'],
        'schema_head' => $baselineArray['schema_head'],
    ], [
        'PBB_AGENTCHAT_SECRET' => str_repeat('c', 32),
        'SYNDICATUM_MASTER_KEY' => str_repeat('d', 32),
    ]);
    backupProducerAssert($database->endedSuccessfully === true && !$database->active, 'Producer did not close its consistent snapshot successfully.');
    backupProducerAssert(is_file($destination) && hash_file('sha256', $destination) === $result['envelope_sha256'], 'Encrypted backup output identity is invalid.');
    backupProducerAssert($result['asset_count'] === 1 && $result['row_counts']['users'] === 1, 'Producer did not report durable data and assets.');
    backupProducerAssert(count(glob($temporary . DIRECTORY_SEPARATOR . 'backup-producer-*')) === 0, 'Producer left plaintext staging data behind.');
    backupProducerAssert(count(glob($output . DIRECTORY_SEPARATOR . '*.zip')) === 0 && count(glob($output . DIRECTORY_SEPARATOR . '*.json')) === 0, 'Producer emitted a portable plaintext package.');

    $opened = BackupEnvelope::decryptToPrivateStage($destination, $temporary, $key);
    $manifest = PackageManifest::parse($opened['manifest_json']);
    backupProducerAssert($manifest['package_kind'] === 'backup' && $manifest['contains_data'] === true, 'Authenticated backup manifest is not a data-bearing backup.');
    backupProducerAssert($manifest['content_tree_sha256'] === $result['content_tree_sha256'], 'Authenticated content-tree identity changed.');
    $names = array_column($manifest['files'], 'path');
    backupProducerAssert(in_array('data/users.ndjson', $names, true) && in_array('assets/avatars/' . $avatarName, $names, true)
        && in_array('metadata/recovery.json', $names, true)
        && in_array('secrets/recovery.json', $names, true), 'Authenticated backup inventory is incomplete.');
    BackupEnvelope::removePrivateStage($opened['stage_path'], $temporary);

    $restoreTarget = new FixtureRestoreTarget($baseline, $database);
    $restorer = new StagedBackupRestore($restoreTarget, $baseline, $temporary, $public);
    $targetBeforeInspection = serialize([
        $restoreTarget->counts, $restoreTarget->rows, $restoreTarget->transaction, $restoreTarget->sequences,
    ]);
    $inspected = $restorer->inspect($destination, $key, [
        'PBB_AGENTCHAT_SECRET' => str_repeat('c', 32),
        'SYNDICATUM_MASTER_KEY' => str_repeat('d', 32),
    ]);
    backupProducerAssert($inspected['target_ready'] === true && $inspected['cutover_performed'] === false,
        'Inspection must prove target readiness without claiming cutover.');
    backupProducerAssert($inspected['schema_baseline'] === $baselineArray['baseline_id']
        && $inspected['schema_head'] === $baselineArray['schema_head']
        && $inspected['archive_sha256'] === $result['archive_sha256']
        && $inspected['manifest_sha256'] === $result['manifest_sha256']
        && $inspected['content_tree_sha256'] === $result['content_tree_sha256'],
        'Inspection did not return the authenticated compatibility and hash identities.');
    backupProducerAssert($inspected['backup_policy']['durable']['count'] === 28
        && $inspected['backup_policy']['reset']['count'] === 17
        && $inspected['backup_policy']['excluded']['count'] === 3
        && $inspected['file_role_counts']['logical_data'] === 28
        && $inspected['file_role_counts']['persistent_asset'] === 1
        && $inspected['sequence_table_count'] === 28,
        'Inspection did not return the trusted policy and inventory counts.');
    backupProducerAssert(serialize([
        $restoreTarget->counts, $restoreTarget->rows, $restoreTarget->transaction, $restoreTarget->sequences,
    ]) === $targetBeforeInspection, 'Inspection mutated the restore target.');
    $inspectionJson = json_encode($inspected, JSON_UNESCAPED_SLASHES);
    backupProducerAssert(is_string($inspectionJson) && strpos($inspectionJson, str_repeat('c', 32)) === false
        && strpos($inspectionJson, str_repeat('d', 32)) === false
        && strpos($inspectionJson, str_replace('\\', '/', $root)) === false
        && !array_key_exists('asset_stage_path', $inspected),
        'Inspection exposed a secret or private server path.');
    backupProducerAssert(count(glob($temporary . DIRECTORY_SEPARATOR . 'backup-stage-*')) === 0
        && count(glob($temporary . DIRECTORY_SEPARATOR . 'syndicatum-stage-*')) === 0,
        'Successful inspection left authenticated plaintext behind.');

    backupProducerThrows(function () use ($restorer, $destination, $key) {
        $restorer->inspect($destination, $key, [
            'PBB_AGENTCHAT_SECRET' => str_repeat('x', 32),
            'SYNDICATUM_MASTER_KEY' => str_repeat('d', 32),
        ]);
    }, 'Inspection must reject target secrets that do not match the authenticated backup.');
    backupProducerAssert(count(glob($temporary . DIRECTORY_SEPARATOR . 'backup-stage-*')) === 0
        && count(glob($temporary . DIRECTORY_SEPARATOR . 'syndicatum-stage-*')) === 0,
        'Rejected inspection left authenticated plaintext behind.');

    $restored = $restorer->restore($destination, $key, [
        'PBB_AGENTCHAT_SECRET' => str_repeat('c', 32),
        'SYNDICATUM_MASTER_KEY' => str_repeat('d', 32),
    ]);
    backupProducerAssert($restored['restored_row_counts']['users'] === 1 && count($restoreTarget->rows['users']) === 1, 'Staged restore did not import the durable user row.');
    backupProducerAssert($restoreTarget->sequences['users'] === 40, 'Staged restore did not preserve the authenticated next sequence value.');
    backupProducerAssert($restoreTarget->counts['syndicatum_sessions'] === 0 && $restoreTarget->counts['message_events_outbox'] === 0, 'Staged restore replayed reset state.');
    backupProducerAssert($restoreTarget->counts['system_roles'] === 2 && $restoreTarget->counts['syndicatum_installation_identity'] === 1, 'Staged restore overwrote target-local baseline state.');
    backupProducerAssert($restored['cutover_performed'] === false && is_dir($restored['asset_stage_path']) && is_file($restored['asset_stage_path'] . DIRECTORY_SEPARATOR . $avatarName), 'Staged restore must return private assets without live cutover.');
    BackupEnvelope::removePrivateStage($restored['asset_stage_path'], $temporary);

    $nonEmptyTarget = new FixtureRestoreTarget($baseline, $database);
    $nonEmptyTarget->counts['users'] = 1;
    $nonEmptyRestorer = new StagedBackupRestore($nonEmptyTarget, $baseline, $temporary, $public);
    backupProducerThrows(function () use ($nonEmptyRestorer, $destination, $key) {
        $nonEmptyRestorer->inspect($destination, $key, [
            'PBB_AGENTCHAT_SECRET' => str_repeat('c', 32),
            'SYNDICATUM_MASTER_KEY' => str_repeat('d', 32),
        ]);
    }, 'Inspection must reject a non-empty durable target.');
    backupProducerThrows(function () use ($nonEmptyRestorer, $destination, $key) {
        $nonEmptyRestorer->restore($destination, $key, [
            'PBB_AGENTCHAT_SECRET' => str_repeat('c', 32),
            'SYNDICATUM_MASTER_KEY' => str_repeat('d', 32),
        ]);
    }, 'Staged restore must reject a non-empty durable target.');
    backupProducerAssert(count(glob($temporary . DIRECTORY_SEPARATOR . 'backup-stage-*')) === 0
        && count(glob($temporary . DIRECTORY_SEPARATOR . 'syndicatum-stage-*')) === 0,
        'Rejected staged restore left authenticated plaintext behind.');

    backupProducerThrows(function () use ($producer, $key, $baselineArray, $public) {
        $producer->produce($public . DIRECTORY_SEPARATOR . 'public-backup.syndicatum-backup', $key, [
            'application_version' => $baselineArray['application_version'], 'source_commit' => str_repeat('b', 40),
            'schema_baseline' => $baselineArray['baseline_id'], 'schema_head' => $baselineArray['schema_head'],
        ], ['PBB_AGENTCHAT_SECRET' => str_repeat('c', 32), 'SYNDICATUM_MASTER_KEY' => str_repeat('d', 32)]);
    }, 'Producer must reject destinations within the public repository root.');

    $driftDatabase = new FixtureBackupDatabase($baseline);
    $driftDatabase->driftTable = 'users';
    $driftProducer = new BackupProducer($driftDatabase, $baseline, $temporary, $avatars, $public, function () { return '2026-09-20T08:00:00Z'; });
    backupProducerThrows(function () use ($driftProducer, $key, $baselineArray, $output) {
        $driftProducer->produce($output . DIRECTORY_SEPARATOR . 'drift.syndicatum-backup', $key, [
            'application_version' => $baselineArray['application_version'], 'source_commit' => str_repeat('b', 40),
            'schema_baseline' => $baselineArray['baseline_id'], 'schema_head' => $baselineArray['schema_head'],
        ], ['PBB_AGENTCHAT_SECRET' => str_repeat('c', 32), 'SYNDICATUM_MASTER_KEY' => str_repeat('d', 32)]);
    }, 'Producer must reject exact-column schema drift before creating an envelope.');

    echo "Encrypted backup producer assertions passed.\n";
} finally {
    backupProducerRemoveTree($root);
}
