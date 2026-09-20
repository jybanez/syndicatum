<?php

require_once __DIR__ . '/ArchiveSafetyReader.php';
require_once __DIR__ . '/BackupKeyFile.php';
require_once __DIR__ . '/BackupProducer.php';
require_once __DIR__ . '/BaselineMetadata.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/RecoveryOperationStore.php';
require_once __DIR__ . '/StagedBackupRestore.php';

final class AdminRecoveryService
{
    const MAX_UPLOAD_BYTES = 268435456;
    const BACKUP_RETENTION_SECONDS = 604800;
    const BACKUP_MAX_COUNT = 10;
    const BACKUP_MAX_BYTES = 10737418240;
    const INSPECTION_MAX_COUNT = 4;
    const INSPECTION_MAX_BYTES = 1073741824;

    private $pdo;
    private $root;
    private $publicRoot;
    private $backupRoot;
    private $stagingRoot;
    private $assetRoot;
    private $baseline;
    private $store;

    public function __construct(PDO $pdo, $root = null)
    {
        $this->pdo = $pdo;
        $this->root = $root === null ? dirname(__DIR__) : $root;
        $this->publicRoot = $this->resolvedDirectory($this->root, 'application root');
        $this->backupRoot = $this->resolvedDirectory(self::environmentPath('SYNDICATUM_BACKUP_DIR', '/var/lib/syndicatum/backups'), 'backup root', true);
        $this->stagingRoot = $this->resolvedDirectory(self::environmentPath('SYNDICATUM_STAGING_DIR', '/var/lib/syndicatum/staging'), 'restore staging root');
        $this->assetRoot = $this->resolvedDirectory(self::environmentPath('SYNDICATUM_AVATAR_DIR', '/var/lib/syndicatum/avatars'), 'avatar asset root');
        $baselineJson = @file_get_contents($this->root . '/schema/mysql84/baseline.json');
        $baselineArray = is_string($baselineJson) ? json_decode($baselineJson, true) : null;
        if (!is_array($baselineArray) || json_last_error() !== JSON_ERROR_NONE) { throw new RuntimeException('Trusted baseline metadata could not be loaded.'); }
        $this->baseline = BaselineMetadata::fromArray($baselineArray);
        $this->store = new RecoveryOperationStore($this->backupRoot . DIRECTORY_SEPARATOR . '.operations');
    }

    public function status()
    {
        $identity = null;
        $identityMessage = 'The installed release identity is unavailable. Backup creation remains disabled until a trusted baseline installation identity exists.';
        try {
            $identity = $this->installedIdentity();
            $identityMessage = 'The installed release identity is verified.';
        } catch (Throwable $exception) {
            $identity = null;
        }
        $release = $this->releaseMetadata();
        unset($release['_path']);
        $target = ['configured' => false, 'ready' => false, 'message' => 'A separate staged-restore database is not configured.'];
        if ($this->hasTargetConfiguration()) {
            try {
                $restore = $this->restoreEngine();
                $target = $restore->targetStatus();
                $target['configured'] = true;
            } catch (Throwable $exception) {
                $target = ['configured' => true, 'ready' => false, 'message' => self::safeFailure($exception, 'The staged-restore target is unavailable.')];
            }
        }
        return [
            'installation' => [
                'available' => is_array($identity),
                'message' => $identityMessage,
                'installation_id' => is_array($identity) ? $identity['installation_id'] : null,
                'application_version' => is_array($identity) ? $identity['application_version'] : null,
                'schema_baseline' => is_array($identity) ? $identity['schema_baseline'] : null,
                'schema_head' => is_array($identity) ? $identity['schema_head'] : null,
                'release_source_commit' => is_array($identity) ? $identity['release_source_commit'] : null,
                'package_sha256' => is_array($identity) ? $identity['package_sha256'] : null,
            ],
            'release' => $release,
            'backup' => ['available' => is_array($identity), 'encrypted' => true, 'executable' => false],
            'restore_target' => $target,
            'constraints' => [
                'live_overwrite' => false,
                'automatic_cutover' => false,
                'restore_target' => 'separate-empty-staging-database',
                'reset_tables' => count($this->baseline->tablesWithBackupPolicy('reset')),
                'excluded_tables' => count($this->baseline->tablesWithBackupPolicy('excluded')),
            ],
        ];
    }

    public function releaseDownloadTicket($actorId, $sessionId)
    {
        $metadata = $this->releaseMetadata();
        if (!$metadata['available']) { throw new RuntimeException('RELEASE_PACKAGE_UNAVAILABLE'); }
        $ticket = $this->store->createTicket($actorId, $sessionId, $metadata['_path'], $metadata['filename'], $metadata['sha256'], 'application/zip');
        unset($metadata['_path']);
        return ['release' => $metadata, 'download' => $ticket];
    }

    public function buildBackup($actorId, $sessionId, $idempotencyKey)
    {
        $fingerprint = hash('sha256', 'backup:v1');
        $existing = $this->store->operationForKey($actorId, $sessionId, 'backup', $idempotencyKey, $fingerprint);
        if (is_array($existing)) { return $this->publicOperation($existing); }
        $receipt = $this->store->createOperation($actorId, $sessionId, 'backup', $idempotencyKey, $fingerprint);
        try {
            $result = $this->store->withExclusiveLock(function () use ($receipt, $actorId, $sessionId) {
                $this->pruneBackupArtifacts(true);
                $destination = $this->backupRoot . DIRECTORY_SEPARATOR . 'backup-' . $receipt['operation_id'] . '.syndicatum-backup';
                $identity = $this->installedIdentity();
                $producerIdentity = [
                    'application_version' => $identity['application_version'],
                    'source_commit' => $identity['release_source_commit'],
                    'schema_baseline' => $identity['schema_baseline'],
                    'schema_head' => $identity['schema_head'],
                ];
                $exportedSecrets = $this->portableSecrets();
                $produced = (new BackupProducer(new PdoBackupDatabaseSource($this->pdo), $this->baseline, $this->stagingRoot, $this->assetRoot, $this->publicRoot))
                    ->produce($destination, BackupKeyFile::loadFromEnvironment(), $producerIdentity, $exportedSecrets);
                if ((int) filesize($destination) > self::BACKUP_MAX_BYTES) {
                    @unlink($destination);
                    throw new RuntimeException('BACKUP_STORAGE_QUOTA_EXCEEDED');
                }
                $this->pruneBackupArtifacts(false);
                if (!is_file($destination)) { throw new RuntimeException('BACKUP_STORAGE_QUOTA_EXCEEDED'); }
                $ticket = $this->store->createTicket($actorId, $sessionId, $destination, basename($destination), $produced['envelope_sha256'], 'application/octet-stream');
                unset($produced['path']);
                return array_merge($produced, [
                    'filename' => basename($destination),
                    'download' => $ticket,
                    'encrypted' => true,
                    'executable' => false,
                    'cutover_performed' => false,
                ]);
            });
            $receipt = $this->store->updateOperation($receipt, [
                'status' => 'succeeded',
                'result' => $result,
                'private_result' => ['artifact_path' => $this->backupRoot . DIRECTORY_SEPARATOR . 'backup-' . $receipt['operation_id'] . '.syndicatum-backup'],
            ]);
        } catch (Throwable $exception) {
            $receipt = $this->store->updateOperation($receipt, [
                'status' => 'failed',
                'error_code' => self::safeErrorCode($exception),
                'error_message' => self::safeFailure($exception, 'Encrypted backup creation failed.'),
            ]);
        }
        return $this->publicOperation($receipt);
    }

    public function operation($operationId, $actorId, $sessionId)
    {
        $receipt = $this->store->operationById($operationId, $actorId, $sessionId);
        return is_array($receipt) ? $this->publicOperation($receipt) : null;
    }

    public function reissueBackupDownload($operationId, $actorId, $sessionId)
    {
        return $this->store->withExclusiveLock(function () use ($operationId, $actorId, $sessionId) {
            $receipt = $this->store->operationById($operationId, $actorId, $sessionId);
            if (!is_array($receipt) || (isset($receipt['kind']) ? $receipt['kind'] : null) !== 'backup'
                || (isset($receipt['status']) ? $receipt['status'] : null) !== 'succeeded'
                || !isset($receipt['result']['envelope_sha256'], $receipt['result']['filename'], $receipt['private_result']['artifact_path'])) {
                throw new RuntimeException('BACKUP_ARTIFACT_NOT_AVAILABLE');
            }
            $path = (string) $receipt['private_result']['artifact_path'];
            $expected = (string) $receipt['result']['envelope_sha256'];
            if (!is_file($path) || is_link($path) || !self::within((string) realpath($path), $this->backupRoot)) {
                throw new RuntimeException('BACKUP_ARTIFACT_NOT_AVAILABLE');
            }
            $actual = hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals($expected, $actual)) { throw new RuntimeException('BACKUP_ARTIFACT_NOT_AVAILABLE'); }
            return $this->store->createTicket($actorId, $sessionId, $path, (string) $receipt['result']['filename'], $expected, 'application/octet-stream');
        });
    }

    public function inspectUploadedBackup($actorId, $sessionId, $uploadedPath, $originalName, $size)
    {
        if (!$this->hasTargetConfiguration()) { throw new RuntimeException('RESTORE_TARGET_NOT_CONFIGURED'); }
        if (!is_string($originalName) || !preg_match('/\.syndicatum-backup\z/i', $originalName)) {
            throw new InvalidArgumentException('Select a .syndicatum-backup encrypted backup file.');
        }
        if (!is_int($size) || $size < 1 || $size > self::MAX_UPLOAD_BYTES) { throw new InvalidArgumentException('The encrypted backup exceeds the supported upload size.'); }
        $incoming = $this->newIncomingPath();
        try {
            $retained = $this->store->withExclusiveLock(function () use ($uploadedPath, $incoming, $size, $actorId, $sessionId) {
                $this->assertInspectionCapacity($size);
                self::copyNewFile($uploadedPath, $incoming, $size);
                $metadata = $this->restoreEngine()->inspect($incoming, BackupKeyFile::loadFromEnvironment(), $this->portableSecrets());
                return $this->store->retainInspection($actorId, $sessionId, $incoming, $metadata);
            });
            return [
                'inspection_id' => $retained['inspection_id'],
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $retained['expires_at']),
                'metadata' => $retained['metadata'],
            ];
        } catch (Throwable $exception) {
            if (is_file($incoming)) { @unlink($incoming); }
            throw $exception;
        }
    }

    public function stageRestore($actorId, $sessionId, $idempotencyKey, $inspectionId, $envelopeSha256, $confirmation)
    {
        if (!hash_equals('STAGE RESTORE', (string) $confirmation)) { throw new InvalidArgumentException('Type STAGE RESTORE to confirm the staged restore.'); }
        if (!is_string($envelopeSha256) || !preg_match('/\A[a-f0-9]{64}\z/', $envelopeSha256)) { throw new InvalidArgumentException('A verified envelope digest is required.'); }
        $fingerprint = hash('sha256', $inspectionId . "\0" . $envelopeSha256 . "\0STAGE RESTORE");
        $existing = $this->store->operationForKey($actorId, $sessionId, 'staged_restore', $idempotencyKey, $fingerprint);
        if (is_array($existing)) { return $this->publicOperation($existing); }
        $inspection = $this->store->inspection($inspectionId, $actorId, $sessionId);
        if (!is_array($inspection)) { throw new RuntimeException('RESTORE_INSPECTION_EXPIRED'); }
        if (!isset($inspection['metadata']['envelope_sha256']) || !hash_equals($inspection['metadata']['envelope_sha256'], $envelopeSha256)
            || !hash_equals($envelopeSha256, hash_file('sha256', $inspection['envelope_path']))) {
            throw new RuntimeException('RESTORE_INSPECTION_CHANGED');
        }
        $receipt = $this->store->createOperation($actorId, $sessionId, 'staged_restore', $idempotencyKey, $fingerprint);
        try {
            $privateResult = [];
            $result = $this->store->withExclusiveLock(function () use ($inspection, $receipt, $actorId, $sessionId, &$privateResult) {
                $inspection = $this->store->claimInspection($inspection['inspection_id'], $actorId, $sessionId, $receipt['operation_id']);
                if (!hash_equals((string) $inspection['metadata']['envelope_sha256'], hash_file('sha256', $inspection['envelope_path']))) {
                    throw new RuntimeException('RESTORE_INSPECTION_CHANGED');
                }
                $restored = $this->restoreEngine()->restore($inspection['envelope_path'], BackupKeyFile::loadFromEnvironment(), $this->portableSecrets());
                $privateResult = ['asset_stage_path' => $restored['asset_stage_path']];
                unset($restored['asset_stage_path']);
                $restored['assets_staged'] = true;
                $restored['live_overwrite'] = false;
                $restored['automatic_cutover'] = false;
                return $restored;
            });
            $receipt = $this->store->updateOperation($receipt, ['status' => 'succeeded', 'result' => $result, 'private_result' => $privateResult]);
            $this->store->deleteInspection($inspection);
        } catch (Throwable $exception) {
            $receipt = $this->store->updateOperation($receipt, [
                'status' => 'failed',
                'error_code' => self::safeErrorCode($exception),
                'error_message' => 'Staged restore failed. Treat the staging target as contaminated and reprovision it before another attempt.',
            ]);
        }
        return $this->publicOperation($receipt);
    }

    public function streamDownloadTicket($token, $actorId, $sessionId, callable $streamer)
    {
        return $this->store->withExclusiveLock(function () use ($token, $actorId, $sessionId, $streamer) {
            $ticket = $this->store->consumeTicket($token, $actorId, $sessionId);
            if (!is_array($ticket) || !is_file($ticket['artifact_path']) || is_link($ticket['artifact_path'])) { return false; }
            $actual = hash_file('sha256', $ticket['artifact_path']);
            if (!is_string($actual) || !hash_equals($ticket['sha256'], $actual)) { return false; }
            $stream = @fopen($ticket['artifact_path'], 'rb');
            if (!is_resource($stream)) { return false; }
            try { $streamer($ticket, $stream); }
            finally { fclose($stream); }
            return true;
        });
    }

    private function releaseMetadata()
    {
        $path = getenv('SYNDICATUM_RELEASE_PACKAGE_PATH');
        $expected = strtolower(trim((string) getenv('SYNDICATUM_PACKAGE_SHA256')));
        $commit = strtolower(trim((string) getenv('SYNDICATUM_RELEASE_SOURCE_COMMIT')));
        $tag = trim((string) getenv('SYNDICATUM_RELEASE_TAG'));
        $provenanceExpected = strtolower(trim((string) getenv('SYNDICATUM_RELEASE_PROVENANCE_SHA256')));
        $repositoryExpected = trim((string) getenv('SYNDICATUM_RELEASE_REPOSITORY'));
        $base = [
            'available' => false,
            'source' => 'ci-built-immutable-release',
            'runtime_build_allowed' => false,
            'sha256' => preg_match('/\A[a-f0-9]{64}\z/', $expected) ? $expected : null,
            'source_commit' => preg_match('/\A[a-f0-9]{40}\z/', $commit) ? $commit : null,
            'source_tag' => $tag !== '' ? $tag : null,
            'message' => 'No verified CI-built release package is mounted on this instance.',
        ];
        if ($path === false || trim((string) $path) === '' || !preg_match('/\.zip\z/i', (string) $path) || !is_file($path) || is_link($path)) { return $base; }
        if (!preg_match('/\A[a-f0-9]{64}\z/', $expected) || !preg_match('/\A[a-f0-9]{40}\z/', $commit) || $tag === ''
            || !preg_match('/\A[a-f0-9]{64}\z/', $provenanceExpected) || $repositoryExpected === '') {
            $base['message'] = 'The mounted CI-built release is missing a pinned package/provenance digest, repository, full source commit, or protected tag.';
            return $base;
        }
        $real = realpath($path);
        if ($real === false || self::within($real, $this->publicRoot)) { return $base; }
        $actual = hash_file('sha256', $real);
        if (!is_string($actual) || $expected === '' || !hash_equals($expected, strtolower($actual))) {
            $base['message'] = 'The mounted CI-built release does not match its pinned SHA-256 digest.';
            return $base;
        }
        $stem = preg_replace('/\.zip\z/i', '', $real);
        $manifestPath = $stem . '.manifest.json';
        $provenancePath = $stem . '.provenance.json';
        $manifestJson = @file_get_contents($manifestPath);
        $provenanceJson = @file_get_contents($provenancePath);
        $provenance = is_string($provenanceJson) ? json_decode($provenanceJson, true) : null;
        $expectedWorkflowRef = $repositoryExpected . '/.github/workflows/contract-ci.yml@refs/tags/' . $tag;
        $baseline = $this->baseline->toArray();
        $policyJson = @file_get_contents($this->root . '/release/canonical-release-policy-v1.json');
        $policy = is_string($policyJson) ? json_decode($policyJson, true) : null;
        if (!is_string($manifestJson) || !is_string($provenanceJson) || !hash_equals($provenanceExpected, hash('sha256', $provenanceJson))
            || !is_array($provenance) || json_last_error() !== JSON_ERROR_NONE || !is_array($policy)
            || !isset($provenance['contract_name'], $provenance['format_version'], $provenance['canonical'], $provenance['source_commit'], $provenance['source_tag'],
                $provenance['archive_sha256'], $provenance['manifest_sha256'], $provenance['content_tree_sha256'], $provenance['baseline_id'], $provenance['schema_head'],
                $provenance['producer_version'], $provenance['repository'], $provenance['workflow_ref'], $provenance['workflow_sha'], $provenance['run_id'],
                $provenance['run_attempt'], $provenance['toolchain'], $provenance['baseline_schema_sha256'], $provenance['baseline_source_commit'])
            || $provenance['contract_name'] !== 'syndicatum-release-provenance' || $provenance['format_version'] !== '1.0' || $provenance['canonical'] !== true
            || !hash_equals($commit, (string) $provenance['source_commit']) || !hash_equals($tag, (string) $provenance['source_tag'])
            || !hash_equals($expected, (string) $provenance['archive_sha256']) || !hash_equals(hash('sha256', $manifestJson), (string) $provenance['manifest_sha256'])
            || !hash_equals((string) (isset($policy['producer_version']) ? $policy['producer_version'] : ''), (string) $provenance['producer_version'])
            || !hash_equals($repositoryExpected, (string) $provenance['repository']) || !hash_equals($expectedWorkflowRef, (string) $provenance['workflow_ref'])
            || !hash_equals($commit, (string) $provenance['workflow_sha']) || !preg_match('/\A[1-9][0-9]*\z/', (string) $provenance['run_id'])
            || !preg_match('/\A[1-9][0-9]*\z/', (string) $provenance['run_attempt'])
            || !preg_match('/\Apython-[0-9]+\.[0-9]+\.[0-9]+-zip-stored\z/', (string) $provenance['toolchain'])) {
            $base['message'] = 'The mounted release sidecars do not prove a canonical CI publication.';
            return $base;
        }
        if (!hash_equals((string) $baseline['baseline_id'], (string) $provenance['baseline_id'])
            || !hash_equals((string) $baseline['schema_head'], (string) $provenance['schema_head'])
            || !hash_equals((string) $baseline['schema_sha256'], (string) $provenance['baseline_schema_sha256'])
            || !hash_equals((string) $baseline['source_commit'], (string) $provenance['baseline_source_commit'])) {
            $base['message'] = 'The mounted release provenance does not match the trusted baseline.';
            return $base;
        }
        try {
            $report = (new ArchiveSafetyReader())->validate($real, $manifestJson, ArchiveValidationContext::forRelease(
                $expected, hash('sha256', $manifestJson), [
                    'source_commit' => $commit,
                    'source_tag' => $tag,
                    'schema_baseline' => $baseline['baseline_id'],
                    'schema_head' => $baseline['schema_head'],
                ]
            ));
            $manifest = $report->manifest();
            if (!hash_equals((string) $provenance['content_tree_sha256'], (string) $manifest['content_tree_sha256'])) {
                throw new InvalidArgumentException('Release content-tree identity differs from canonical provenance.');
            }
        } catch (Throwable $exception) {
            $base['message'] = 'The mounted release failed the ordinary trusted archive-reader contract.';
            return $base;
        }
        return [
            'available' => true,
            'source' => 'ci-built-immutable-release',
            'runtime_build_allowed' => false,
            'filename' => basename($real),
            'sha256' => strtolower($actual),
            'source_commit' => $commit,
            'source_tag' => $tag,
            'size' => filesize($real),
            'manifest_sha256' => hash('sha256', $manifestJson),
            'content_tree_sha256' => $manifest['content_tree_sha256'],
            'verified' => true,
            'message' => 'Pinned CI-built release is available and verified.',
            '_path' => $real,
        ];
    }

    private function installedIdentity()
    {
        $row = $this->pdo->query('SELECT installation_id, application_version, release_source_commit, schema_baseline, schema_head, package_sha256 FROM syndicatum_installation_identity WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { throw new RuntimeException('Installed release identity is missing.'); }
        return $row;
    }

    private function restoreEngine()
    {
        $target = $this->targetPdo();
        return new StagedBackupRestore(new PdoBackupRestoreTarget($target), $this->baseline, $this->stagingRoot, $this->publicRoot);
    }

    private function targetPdo()
    {
        if (!$this->hasTargetConfiguration()) { throw new RuntimeException('RESTORE_TARGET_NOT_CONFIGURED'); }
        $host = trim((string) getenv('SYNDICATUM_RESTORE_DB_HOST'));
        $port = trim((string) getenv('SYNDICATUM_RESTORE_DB_PORT')) ?: '3306';
        $database = trim((string) getenv('SYNDICATUM_RESTORE_DB_NAME'));
        $user = trim((string) getenv('SYNDICATUM_RESTORE_DB_USER'));
        $password = (string) getenv('SYNDICATUM_RESTORE_DB_PASS');
        $serving = Db::config();
        if (strcasecmp($host, (string) $serving['host']) === 0 && strcasecmp($database, (string) $serving['database']) === 0) {
            throw new RuntimeException('RESTORE_TARGET_IS_SERVING_DATABASE');
        }
        $target = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4', $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $servingIdentity = $this->pdo->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
        $targetIdentity = $target->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS database_name')->fetch(PDO::FETCH_ASSOC);
        if (is_array($servingIdentity) && is_array($targetIdentity)
            && hash_equals((string) $servingIdentity['server_uuid'], (string) $targetIdentity['server_uuid'])
            && strcasecmp((string) $servingIdentity['database_name'], (string) $targetIdentity['database_name']) === 0) {
            throw new RuntimeException('RESTORE_TARGET_IS_SERVING_DATABASE');
        }
        return $target;
    }

    private function hasTargetConfiguration()
    {
        foreach (['SYNDICATUM_RESTORE_DB_HOST','SYNDICATUM_RESTORE_DB_NAME','SYNDICATUM_RESTORE_DB_USER'] as $name) {
            $value = getenv($name);
            if ($value === false || trim((string) $value) === '') { return false; }
        }
        return true;
    }

    private function portableSecrets()
    {
        $secrets = [
            'PBB_AGENTCHAT_SECRET' => Db::secretValue('PBB_AGENTCHAT_SECRET'),
            'SYNDICATUM_MASTER_KEY' => Db::secretValue('SYNDICATUM_MASTER_KEY'),
        ];
        $previous = Db::secretValue('PBB_AGENTCHAT_PREVIOUS_SECRET');
        if (is_string($previous) && trim($previous) !== '') { $secrets['PBB_AGENTCHAT_PREVIOUS_SECRET'] = $previous; }
        return $secrets;
    }

    private function publicOperation(array $receipt)
    {
        $public = [
            'operation_id' => $receipt['operation_id'],
            'kind' => $receipt['kind'],
            'status' => $receipt['status'],
            'created_at' => $receipt['created_at'],
            'updated_at' => $receipt['updated_at'],
        ];
        if (isset($receipt['result'])) { $public['result'] = $receipt['result']; }
        if (isset($receipt['error_code'])) { $public['error_code'] = $receipt['error_code']; }
        if (isset($receipt['error_message'])) { $public['error_message'] = $receipt['error_message']; }
        return $public;
    }

    private function newIncomingPath()
    {
        $directory = $this->backupRoot . DIRECTORY_SEPARATOR . '.operations' . DIRECTORY_SEPARATOR . 'inspections';
        return $directory . DIRECTORY_SEPARATOR . 'upload-' . bin2hex(random_bytes(16)) . '.partial';
    }

    private function resolvedDirectory($path, $label, $mustBePrivate = false)
    {
        if (!is_dir($path) || ($mustBePrivate && is_link($path))) { throw new RuntimeException(ucfirst($label) . ' is unavailable.'); }
        $real = realpath($path);
        if ($real === false) { throw new RuntimeException(ucfirst($label) . ' could not be resolved.'); }
        if ($mustBePrivate && DIRECTORY_SEPARATOR === '/' && ((fileperms($real) & 0077) !== 0)) {
            throw new RuntimeException(ucfirst($label) . ' must be a private directory.');
        }
        return $real;
    }

    private function pruneBackupArtifacts($reserveSlot)
    {
        $protected = $this->store->activeTicketArtifactPaths();
        $artifacts = [];
        foreach (scandir($this->backupRoot) ?: [] as $name) {
            if (!preg_match('/\Abackup-[0-9a-f-]{36}\.syndicatum-backup\z/i', $name)) { continue; }
            $path = $this->backupRoot . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) || is_link($path)) { continue; }
            $artifacts[] = ['path' => $path, 'mtime' => (int) filemtime($path), 'size' => (int) filesize($path)];
        }
        usort($artifacts, function ($left, $right) { return $left['mtime'] === $right['mtime'] ? strcmp($left['path'], $right['path']) : $left['mtime'] - $right['mtime']; });
        $total = array_sum(array_map(function ($item) { return $item['size']; }, $artifacts));
        $targetCount = self::BACKUP_MAX_COUNT - ($reserveSlot ? 1 : 0);
        foreach ($artifacts as $index => $artifact) {
            $expired = $artifact['mtime'] < time() - self::BACKUP_RETENTION_SECONDS;
            $overLimit = count($artifacts) > $targetCount || $total > self::BACKUP_MAX_BYTES;
            if ((!$expired && !$overLimit) || isset($protected[$artifact['path']])) { continue; }
            if (@unlink($artifact['path'])) { $total -= $artifact['size']; unset($artifacts[$index]); }
        }
        $free = disk_free_space($this->backupRoot);
        if (count($artifacts) > $targetCount || $total > self::BACKUP_MAX_BYTES
            || !is_numeric($free) || (float) $free < 1073741824) {
            throw new RuntimeException('BACKUP_STORAGE_QUOTA_EXCEEDED');
        }
    }

    private function assertInspectionCapacity($incomingBytes)
    {
        $usage = $this->store->inspectionUsage();
        if ($usage['count'] >= self::INSPECTION_MAX_COUNT
            || $usage['bytes'] + (int) $incomingBytes > self::INSPECTION_MAX_BYTES) {
            throw new RuntimeException('RESTORE_INSPECTION_QUOTA_EXCEEDED');
        }
    }

    private static function copyNewFile($source, $destination, $expectedSize)
    {
        if (!is_file($source) || is_link($source)) { throw new InvalidArgumentException('Uploaded backup must be a regular file.'); }
        $input = @fopen($source, 'rb'); $output = @fopen($destination, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) { fclose($input); } if (is_resource($output)) { fclose($output); }
            throw new RuntimeException('Uploaded backup could not be copied into private storage.');
        }
        @chmod($destination, 0600); $count = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1048576);
                if (!is_string($chunk)) { throw new RuntimeException('Uploaded backup could not be read.'); }
                if ($chunk === '') { break; }
                $count += strlen($chunk);
                if ($count > self::MAX_UPLOAD_BYTES) { throw new InvalidArgumentException('The encrypted backup exceeds the supported upload size.'); }
                $offset = 0;
                while ($offset < strlen($chunk)) { $written = fwrite($output, substr($chunk, $offset)); if (!is_int($written) || $written < 1) { throw new RuntimeException('Uploaded backup could not be stored.'); } $offset += $written; }
            }
        } catch (Throwable $exception) {
            fclose($input); fclose($output); @unlink($destination); throw $exception;
        }
        fclose($input); fclose($output);
        if ($count !== $expectedSize) { @unlink($destination); throw new InvalidArgumentException('Uploaded backup size changed during receipt.'); }
    }

    private static function environmentPath($name, $fallback)
    {
        $value = getenv($name);
        return $value === false || trim((string) $value) === '' ? $fallback : trim((string) $value);
    }

    private static function within($path, $root)
    {
        $path = rtrim(str_replace('\\','/',$path),'/'); $root = rtrim(str_replace('\\','/',$root),'/');
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    private static function safeErrorCode(Throwable $exception)
    {
        $message = $exception->getMessage();
        return preg_match('/\A[A-Z][A-Z0-9_]{2,80}\z/', $message) ? $message : 'RECOVERY_OPERATION_FAILED';
    }

    private static function safeFailure(Throwable $exception, $fallback)
    {
        $message = $exception->getMessage();
        if ($exception instanceof InvalidArgumentException && strlen($message) <= 300 && strpos($message, DIRECTORY_SEPARATOR) === false) { return $message; }
        return $fallback;
    }
}
