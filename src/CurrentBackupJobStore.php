<?php

require_once __DIR__ . '/CurrentBackupStorage.php';

/** Private filesystem job ledger. Backup state is stored as atomic JSON sidecars, never SQLite. */
final class CurrentBackupJobStore
{
    private $storage;
    const STAGES = ['Preparing backup','Collecting production runtime','Exporting database',
        'Collecting persistent files','Securing configuration','Building encrypted package','Verifying package','Complete'];
    const LEGACY_STAGES = ['Preparing backup','Exporting database','Collecting files','Building package','Verifying backup','Complete'];

    public function __construct(CurrentBackupStorage $storage) { $this->storage = $storage; }

    public function create($userId, $displayName, $idempotencyKey, $includeData = true)
    {
        if (!is_int($userId) || $userId < 1 || !is_string($displayName) || trim($displayName) === ''
            || !is_string($idempotencyKey) || strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 255 || !is_bool($includeData)) {
            throw new InvalidArgumentException('Backup request identity or idempotency key is invalid.');
        }
        $hash = hash('sha256', $userId . "\0" . ($includeData ? 'full' : 'clean') . "\0" . $idempotencyKey);
        return $this->locked(function () use ($userId, $displayName, $hash, $includeData) {
            foreach ($this->rawRows() as $row) {
                if (hash_equals((string) $row['idempotency_hash'], $hash)) { return self::publicRow($row); }
            }
            $operationId = self::uuid();
            $now = self::now();
            $row = [
                'operation_id' => $operationId, 'idempotency_hash' => $hash,
                'initiated_by_user_id' => $userId, 'initiated_by_name' => trim($displayName),
                'include_data' => $includeData, 'status' => 'Queued', 'stage' => 'Preparing backup',
                'stage_progress_percent' => 0, 'overall_progress_percent' => 0, 'revision' => 1,
                'initiated_at' => $now, 'finished_at' => null, 'artifact_created_at' => null,
                'artifact_filename' => null, 'artifact_size_bytes' => null, 'artifact_sha256' => null,
                'failure_summary' => null, 'updated_at' => $now,
            ];
            $this->writeRow($row);
            return self::publicRow($row);
        });
    }

    public function get($operationId)
    {
        $path = $this->storage->job($operationId);
        return is_file($path) && !is_link($path) ? self::publicRow($this->readRow($path)) : null;
    }

    public function recent($limit = 50)
    {
        $rows = $this->rawRows();
        usort($rows, function ($a, $b) {
            $time = strcmp((string) $b['initiated_at'], (string) $a['initiated_at']);
            return $time !== 0 ? $time : strcmp((string) $b['operation_id'], (string) $a['operation_id']);
        });
        return array_map([self::class, 'publicRow'], array_slice($rows, 0, max(1, min(100, (int) $limit))));
    }

    public function running()
    {
        return array_values(array_filter($this->recent(100), function ($row) { return $row['status'] === 'Running'; }));
    }

    public function claimQueued()
    {
        return $this->locked(function () {
            $rows = array_values(array_filter($this->rawRows(), function ($row) { return $row['status'] === 'Queued'; }));
            usort($rows, function ($a, $b) {
                $time = strcmp((string) $a['initiated_at'], (string) $b['initiated_at']);
                return $time !== 0 ? $time : strcmp((string) $a['operation_id'], (string) $b['operation_id']);
            });
            if ($rows === []) { return null; }
            $row = $rows[0];
            $row['status'] = 'Running'; $row['revision']++; $row['updated_at'] = self::now();
            $this->writeRow($row);
            return self::publicRow($row);
        });
    }

    public function progress($operationId, $stage, $percent)
    {
        $index = array_search($stage, self::STAGES, true);
        if ($index === false || !is_int($percent) || $percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('Backup progress event is invalid.');
        }
        return $this->locked(function () use ($operationId, $stage, $percent, $index) {
            $row = $this->requiredRaw($operationId);
            if ($row['status'] !== 'Running') { throw new RuntimeException('Backup job is not running.'); }
            $oldIndex = array_search($row['stage'], self::STAGES, true);
            if ($index < $oldIndex || ($index === $oldIndex && $percent < $row['stage_progress_percent'])) {
                throw new RuntimeException('Backup progress must be monotonic.');
            }
            $row['stage'] = $stage; $row['stage_progress_percent'] = $percent;
            $row['overall_progress_percent'] = min(100, (int) floor((($index * 100) + $percent) / count(self::STAGES)));
            $row['revision']++; $row['updated_at'] = self::now();
            $this->writeRow($row);
            return self::publicRow($row);
        });
    }

    public function ready($operationId, $artifactPath, $sha256)
    {
        $expected = $this->storage->artifact($operationId);
        if ($artifactPath !== $expected || !is_file($expected) || is_link($expected)
            || !is_string($sha256) || !preg_match('/\A[a-f0-9]{64}\z/', $sha256)
            || !hash_equals($sha256, hash_file('sha256', $expected))) {
            throw new RuntimeException('Backup artifact is not verified for Ready.');
        }
        $bytes = filesize($expected);
        if (!is_int($bytes) || $bytes < 1) { throw new RuntimeException('Backup artifact is empty.'); }
        return $this->locked(function () use ($operationId, $expected, $sha256, $bytes) {
            $row = $this->requiredRaw($operationId);
            if ($row['status'] !== 'Running') { throw new RuntimeException('Backup Ready transition was rejected.'); }
            $now = self::now();
            $row['status'] = 'Ready'; $row['stage'] = 'Complete'; $row['stage_progress_percent'] = 100;
            $row['overall_progress_percent'] = 100; $row['revision']++; $row['finished_at'] = $now;
            $row['artifact_created_at'] = $now; $row['artifact_filename'] = basename($expected);
            $row['artifact_size_bytes'] = $bytes; $row['artifact_sha256'] = $sha256; $row['updated_at'] = $now;
            $this->writeRow($row);
            return self::publicRow($row);
        });
    }

    public function fail($operationId, $summary)
    {
        $summary = mb_substr(trim((string) $summary) ?: 'Backup failed; retry the operation.', 0, 240);
        return $this->locked(function () use ($operationId, $summary) {
            $row = $this->requiredRaw($operationId);
            if (!in_array($row['status'], ['Queued', 'Running'], true)) { return self::publicRow($row); }
            $now = self::now();
            $row['status'] = 'Failed'; $row['revision']++; $row['finished_at'] = $now;
            $row['artifact_created_at'] = null; $row['artifact_filename'] = null;
            $row['artifact_size_bytes'] = null; $row['artifact_sha256'] = null;
            $row['failure_summary'] = $summary; $row['updated_at'] = $now;
            $this->writeRow($row);
            return self::publicRow($row);
        });
    }

    public function failInterrupted()
    {
        return $this->locked(function () {
            $failed = [];
            foreach ($this->rawRows() as $row) {
                if ($row['status'] !== 'Running') { continue; }
                $now = self::now();
                $row['status'] = 'Failed'; $row['revision']++; $row['finished_at'] = $now;
                $row['failure_summary'] = 'Backup worker stopped before verification; retry the operation.';
                $row['updated_at'] = $now; $this->writeRow($row); $failed[] = self::publicRow($row);
            }
            return $failed;
        });
    }

    public function issueDownload($operationId, $userId)
    {
        $job = $this->get($operationId);
        if ($job === null || $job['status'] !== 'Ready' || !is_int($userId) || $userId < 1) {
            throw new RuntimeException('Backup is not ready for download.');
        }
        $artifact = $this->storage->artifact($operationId);
        if (!is_file($artifact) || is_link($artifact) || !hash_equals($job['artifact_sha256'], hash_file('sha256', $artifact))) {
            throw new RuntimeException('Backup artifact is unavailable or failed verification.');
        }
        $token = bin2hex(random_bytes(32));
        $this->writeJson($this->storage->downloadAuthorization(hash('sha256', $token)),
            ['operation_id' => $operationId, 'user_id' => $userId, 'expires_at' => time() + 600]);
        return $token;
    }

    public function consumeDownload($token, $userId)
    {
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token) || !is_int($userId) || $userId < 1) { return null; }
        return $this->locked(function () use ($token, $userId) {
            $path = $this->storage->downloadAuthorization(hash('sha256', $token));
            if (!is_file($path) || is_link($path)) { return null; }
            $record = $this->readJson($path);
            if ((int) (isset($record['expires_at']) ? $record['expires_at'] : 0) < time()) {
                @unlink($path); return null;
            }
            if ((int) (isset($record['user_id']) ? $record['user_id'] : 0) !== $userId) { return null; }
            $operationId = (string) (isset($record['operation_id']) ? $record['operation_id'] : '');
            if (!@unlink($path)) { throw new RuntimeException('Backup download authorization could not be consumed.'); }
            return $this->get($operationId);
        });
    }

    public function delete($operationId)
    {
        return $this->locked(function () use ($operationId) {
            $row = $this->requiredRaw($operationId);
            if (!in_array($row['status'], ['Ready', 'Failed'], true)) {
                throw new RuntimeException('Only completed backups can be deleted.');
            }
            $this->deleteFiles($row);
            return self::publicRow($row);
        });
    }

    public function clear($scope)
    {
        if (!in_array($scope, ['failed', 'all'], true)) {
            throw new InvalidArgumentException('Choose Failed backups or all completed backups to clear.');
        }
        return $this->locked(function () use ($scope) {
            $rows = $this->rawRows();
            if ($scope === 'all') {
                foreach ($rows as $row) {
                    if (in_array($row['status'], ['Queued', 'Running'], true)) {
                        throw new InvalidArgumentException('Wait for active backups to finish before clearing all backups.');
                    }
                }
            }
            $targets = array_values(array_filter($rows, function ($row) use ($scope) {
                return $scope === 'failed' ? $row['status'] === 'Failed'
                    : in_array($row['status'], ['Ready', 'Failed'], true);
            }));
            usort($targets, function ($a, $b) { return strcmp($a['operation_id'], $b['operation_id']); });
            $deleted = [];
            foreach ($targets as $row) {
                $this->deleteFiles($row);
                $deleted[] = self::publicRow($row);
            }
            return $deleted;
        });
    }

    private function deleteFiles(array $row)
    {
        $operationId = $row['operation_id'];
        $paths = [$this->storage->artifact($operationId), $this->storage->inspection($operationId)];
        foreach (glob($this->storage->downloads() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $authorization) {
            if (!is_file($authorization) || is_link($authorization)) { continue; }
            $record = $this->readJson($authorization);
            if (isset($record['operation_id']) && hash_equals((string) $operationId, (string) $record['operation_id'])) {
                $paths[] = $authorization;
            }
        }
        $paths[] = $this->storage->job($operationId);
        foreach ($paths as $path) {
            if (!file_exists($path)) { continue; }
            if (!is_file($path) || is_link($path) || !@unlink($path)) {
                throw new RuntimeException('Backup files could not be deleted.');
            }
        }
    }

    private function locked($callback)
    {
        $handle = @fopen($this->storage->jobStoreLock(), 'c+b');
        if (!is_resource($handle)) { throw new RuntimeException('Backup job lock could not be opened.'); }
        @chmod($this->storage->jobStoreLock(), 0600);
        if (!flock($handle, LOCK_EX)) { fclose($handle); throw new RuntimeException('Backup job lock could not be acquired.'); }
        try { return call_user_func($callback); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function rawRows()
    {
        $rows = [];
        foreach (glob($this->storage->jobs() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) { $rows[] = $this->readRow($path); }
        }
        return $rows;
    }

    private function requiredRaw($operationId)
    {
        $path = $this->storage->job($operationId);
        if (!is_file($path) || is_link($path)) { throw new RuntimeException('Backup operation not found.'); }
        return $this->readRow($path);
    }

    private function readRow($path)
    {
        $row = $this->readJson($path);
        $required = ['operation_id','idempotency_hash','initiated_by_user_id','initiated_by_name','include_data','status',
            'stage','stage_progress_percent','overall_progress_percent','revision','initiated_at','finished_at','artifact_created_at',
            'artifact_filename','artifact_size_bytes','artifact_sha256','failure_summary','updated_at'];
        if (array_keys($row) !== $required || !in_array($row['status'], ['Queued','Running','Ready','Failed'], true)
            || (!in_array($row['stage'], self::STAGES, true) && !in_array($row['stage'], self::LEGACY_STAGES, true))) {
            throw new RuntimeException('Backup job metadata is invalid.');
        }
        return $row;
    }

    private function readJson($path)
    {
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) { throw new RuntimeException('Backup metadata could not be read.'); }
        if (!flock($stream, LOCK_SH)) { fclose($stream); throw new RuntimeException('Backup metadata could not be locked.'); }
        try { $contents = stream_get_contents($stream); }
        finally { flock($stream, LOCK_UN); fclose($stream); }
        if (!is_string($contents) || $contents === '' || strlen($contents) > 65536) {
            throw new RuntimeException('Backup metadata could not be read.');
        }
        $value = json_decode($contents, true);
        if (!is_array($value) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Backup metadata is not valid JSON.');
        }
        return $value;
    }

    private function writeRow(array $row) { $this->writeJson($this->storage->job($row['operation_id']), $row); }

    private function writeJson($path, array $value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) { throw new RuntimeException('Backup metadata could not be encoded.'); }
        $stream = @fopen($path, 'c+b');
        if (!is_resource($stream)) { throw new RuntimeException('Backup metadata could not be opened.'); }
        @chmod($path, 0600);
        if (!flock($stream, LOCK_EX)) { fclose($stream); throw new RuntimeException('Backup metadata could not be locked.'); }
        try {
            if (!ftruncate($stream, 0) || fseek($stream, 0) !== 0) {
                throw new RuntimeException('Backup metadata could not be prepared.');
            }
            for ($offset = 0, $length = strlen($json); $offset < $length;) {
                $written = fwrite($stream, substr($json, $offset));
                if (!is_int($written) || $written < 1) { throw new RuntimeException('Backup metadata could not be written.'); }
                $offset += $written;
            }
            if (!fflush($stream)) { throw new RuntimeException('Backup metadata could not be committed.'); }
            if (function_exists('fsync') && !fsync($stream)) { throw new RuntimeException('Backup metadata could not be synchronized.'); }
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private static function publicRow(array $row) { unset($row['idempotency_hash']); $row['include_data'] = (bool) $row['include_data']; return $row; }
    private static function now() { return gmdate('Y-m-d\TH:i:s\Z'); }
    private static function uuid()
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
