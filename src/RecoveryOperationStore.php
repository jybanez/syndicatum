<?php

final class RecoveryOperationStore
{
    private $root;

    public function __construct($root)
    {
        $this->root = self::privateDirectory($root);
        foreach (['operations', 'tickets', 'inspections'] as $directory) {
            $path = $this->root . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($path) && !@mkdir($path, 0700)) {
                throw new RuntimeException('Recovery operation storage could not be initialized.');
            }
            @chmod($path, 0700);
        }
        $this->cleanupExpired(50);
    }

    public function operationForKey($actorId, $sessionId, $kind, $idempotencyKey, $fingerprint)
    {
        self::assertKind($kind);
        self::assertIdempotencyKey($idempotencyKey);
        $path = $this->operationPath(hash('sha256', $actorId . "\0" . $sessionId . "\0" . $kind . "\0" . $idempotencyKey));
        if (!is_file($path)) { return null; }
        $receipt = $this->readJson($path);
        if (!isset($receipt['fingerprint']) || !hash_equals((string) $receipt['fingerprint'], (string) $fingerprint)) {
            throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
        }
        if ((isset($receipt['status']) ? $receipt['status'] : null) === 'started'
            && strtotime((string) (isset($receipt['updated_at']) ? $receipt['updated_at'] : '')) !== false
            && strtotime((string) $receipt['updated_at']) < time() - 900) {
            $receipt['status'] = 'uncertain';
            $receipt['error_code'] = 'RECOVERY_OUTCOME_UNKNOWN';
            $receipt['error_message'] = 'The server restarted before it could record a terminal recovery receipt. Inspect the staging target or artifact state before retrying.';
            $receipt['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $this->replaceJson($path, $receipt);
        }
        return $receipt;
    }

    public function createOperation($actorId, $sessionId, $kind, $idempotencyKey, $fingerprint)
    {
        self::assertKind($kind);
        self::assertIdempotencyKey($idempotencyKey);
        $keyHash = hash('sha256', $actorId . "\0" . $sessionId . "\0" . $kind . "\0" . $idempotencyKey);
        $operationId = self::uuidV4();
        $receipt = [
            'operation_id' => $operationId,
            'actor_id' => (int) $actorId,
            'session_id' => (int) $sessionId,
            'kind' => $kind,
            'idempotency_key_hash' => hash('sha256', $idempotencyKey),
            'fingerprint' => (string) $fingerprint,
            'status' => 'started',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $this->writeNewJson($this->operationPath($keyHash), $receipt);
        $this->writeNewJson($this->operationIdPath($operationId), ['key_hash' => $keyHash]);
        return $receipt;
    }

    public function updateOperation(array $receipt, array $changes)
    {
        foreach (['operation_id', 'actor_id', 'session_id', 'kind', 'idempotency_key_hash', 'fingerprint', 'created_at'] as $field) {
            if (!array_key_exists($field, $receipt)) { throw new InvalidArgumentException('Recovery receipt is incomplete.'); }
        }
        $allowed = ['status', 'result', 'private_result', 'error_code', 'error_message'];
        foreach ($changes as $field => $_value) {
            if (!in_array($field, $allowed, true)) { throw new InvalidArgumentException('Recovery receipt update is not allowed.'); }
        }
        $receipt = array_merge($receipt, $changes, ['updated_at' => gmdate('Y-m-d\TH:i:s\Z')]);
        $lookup = $this->readJson($this->operationIdPath($receipt['operation_id']));
        $this->replaceJson($this->operationPath($lookup['key_hash']), $receipt);
        return $receipt;
    }

    public function operationById($operationId, $actorId, $sessionId)
    {
        self::assertOpaqueId($operationId, 'operation');
        $lookupPath = $this->operationIdPath($operationId);
        if (!is_file($lookupPath)) { return null; }
        $lookup = $this->readJson($lookupPath);
        $receipt = $this->readJson($this->operationPath($lookup['key_hash']));
        if ((int) $receipt['actor_id'] !== (int) $actorId || (int) $receipt['session_id'] !== (int) $sessionId) {
            return null;
        }
        return $receipt;
    }

    public function createTicket($actorId, $sessionId, $artifactPath, $downloadName, $sha256, $mediaType)
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ticket = [
            'actor_id' => (int) $actorId,
            'session_id' => (int) $sessionId,
            'artifact_path' => (string) $artifactPath,
            'download_name' => (string) $downloadName,
            'sha256' => (string) $sha256,
            'media_type' => (string) $mediaType,
            'expires_at' => time() + 300,
        ];
        $this->writeNewJson($this->ticketPath(hash('sha256', $token)), $ticket);
        return ['token' => $token, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $ticket['expires_at'])];
    }

    public function consumeTicket($token, $actorId, $sessionId)
    {
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9_-]{32,128}\z/', $token)) { return null; }
        $path = $this->ticketPath(hash('sha256', $token));
        $handle = @fopen($path, 'r+b');
        if (!is_resource($handle)) { return null; }
        try {
            if (!flock($handle, LOCK_EX)) { return null; }
            $json = stream_get_contents($handle);
            $ticket = is_string($json) ? json_decode($json, true) : null;
            if (!is_array($ticket)) {
                @unlink($path);
                return null;
            }
            if ((int) $ticket['actor_id'] !== (int) $actorId || (int) $ticket['session_id'] !== (int) $sessionId) { return null; }
            if ((int) $ticket['expires_at'] < time()) { @unlink($path); return null; }
            @unlink($path);
            return $ticket;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function retainInspection($actorId, $sessionId, $sourcePath, array $metadata)
    {
        $inspectionId = self::uuidV4();
        $envelopePath = $this->root . DIRECTORY_SEPARATOR . 'inspections' . DIRECTORY_SEPARATOR . $inspectionId . '.syndicatum-backup';
        if (!@rename($sourcePath, $envelopePath)) { throw new RuntimeException('Verified backup could not be retained for staged restore.'); }
        @chmod($envelopePath, 0600);
        $receipt = [
            'inspection_id' => $inspectionId,
            'actor_id' => (int) $actorId,
            'session_id' => (int) $sessionId,
            'envelope_path' => $envelopePath,
            'expires_at' => time() + 3600,
            'metadata' => $metadata,
        ];
        try {
            $this->writeNewJson($this->inspectionPath($inspectionId), $receipt);
        } catch (Throwable $exception) {
            @unlink($envelopePath);
            throw $exception;
        }
        return $receipt;
    }

    public function claimInspection($inspectionId, $actorId, $sessionId, $operationId)
    {
        $receipt = $this->inspection($inspectionId, $actorId, $sessionId);
        if (!is_array($receipt)) { throw new RuntimeException('RESTORE_INSPECTION_EXPIRED'); }
        if (isset($receipt['claimed_operation_id']) && !hash_equals((string) $receipt['claimed_operation_id'], (string) $operationId)) {
            throw new RuntimeException('RESTORE_INSPECTION_ALREADY_CLAIMED');
        }
        $receipt['claimed_operation_id'] = (string) $operationId;
        $this->replaceJson($this->inspectionPath($inspectionId), $receipt);
        return $receipt;
    }

    public function activeTicketArtifactPaths()
    {
        $paths = [];
        $root = $this->root . DIRECTORY_SEPARATOR . 'tickets';
        foreach (scandir($root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || substr($name, -5) !== '.json') { continue; }
            try { $ticket = $this->readJson($root . DIRECTORY_SEPARATOR . $name); }
            catch (Throwable $exception) { continue; }
            if (isset($ticket['expires_at'], $ticket['artifact_path']) && (int) $ticket['expires_at'] >= time()) {
                $paths[(string) $ticket['artifact_path']] = true;
            }
        }
        return $paths;
    }

    public function inspectionUsage()
    {
        $this->cleanupExpired(50);
        $usage = ['count' => 0, 'bytes' => 0];
        $root = $this->root . DIRECTORY_SEPARATOR . 'inspections';
        foreach (scandir($root) ?: [] as $name) {
            if (preg_match('/\Aupload-[a-f0-9]{32}\.partial\z/', $name)) {
                $partial = $root . DIRECTORY_SEPARATOR . $name;
                if (!is_file($partial) || is_link($partial)) { continue; }
                if ((int) filemtime($partial) < time() - 3600) { @unlink($partial); continue; }
                $usage['count']++;
                $usage['bytes'] += (int) filesize($partial);
                continue;
            }
            if ($name === '.' || $name === '..' || substr($name, -5) !== '.json') { continue; }
            try { $receipt = $this->readJson($root . DIRECTORY_SEPARATOR . $name); }
            catch (Throwable $exception) { continue; }
            if (!isset($receipt['envelope_path']) || !is_file($receipt['envelope_path']) || is_link($receipt['envelope_path'])) { continue; }
            $usage['count']++;
            $usage['bytes'] += (int) filesize($receipt['envelope_path']);
        }
        return $usage;
    }

    public function inspection($inspectionId, $actorId, $sessionId)
    {
        self::assertOpaqueId($inspectionId, 'inspection');
        $path = $this->inspectionPath($inspectionId);
        if (!is_file($path)) { return null; }
        $receipt = $this->readJson($path);
        if ((int) $receipt['actor_id'] !== (int) $actorId || (int) $receipt['session_id'] !== (int) $sessionId) {
            return null;
        }
        if ((int) $receipt['expires_at'] < time() || !is_file($receipt['envelope_path'])) {
            $this->deleteInspection($receipt);
            return null;
        }
        return $receipt;
    }

    public function deleteInspection(array $receipt)
    {
        if (isset($receipt['envelope_path']) && is_file($receipt['envelope_path'])) { @unlink($receipt['envelope_path']); }
        if (isset($receipt['inspection_id'])) { @unlink($this->inspectionPath($receipt['inspection_id'])); }
    }

    public function withExclusiveLock(callable $callback)
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'recovery.lock';
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) { throw new RuntimeException('RECOVERY_LOCK_UNAVAILABLE'); }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) { throw new RuntimeException('RECOVERY_OPERATION_IN_PROGRESS'); }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function cleanupExpired($limit = 50)
    {
        $limit = max(1, min(50, (int) $limit));
        foreach (['tickets', 'inspections'] as $directory) {
            $root = $this->root . DIRECTORY_SEPARATOR . $directory;
            $names = array_values(array_filter(scandir($root) ?: [], function ($name) { return $name !== '.' && $name !== '..' && substr($name, -5) === '.json'; }));
            sort($names, SORT_STRING);
            foreach (array_slice($names, 0, $limit) as $name) {
                $path = $root . DIRECTORY_SEPARATOR . $name;
                try { $receipt = $this->readJson($path); }
                catch (Throwable $exception) { continue; }
                if (!isset($receipt['expires_at']) || (int) $receipt['expires_at'] >= time()) { continue; }
                if ($directory === 'inspections') { $this->deleteInspection($receipt); }
                else { @unlink($path); }
            }
        }
    }

    private function operationPath($hash) { return $this->root . DIRECTORY_SEPARATOR . 'operations' . DIRECTORY_SEPARATOR . $hash . '.json'; }
    private function operationIdPath($id) { return $this->root . DIRECTORY_SEPARATOR . 'operations' . DIRECTORY_SEPARATOR . 'id-' . $id . '.json'; }
    private function ticketPath($hash) { return $this->root . DIRECTORY_SEPARATOR . 'tickets' . DIRECTORY_SEPARATOR . $hash . '.json'; }
    private function inspectionPath($id) { return $this->root . DIRECTORY_SEPARATOR . 'inspections' . DIRECTORY_SEPARATOR . $id . '.json'; }

    private function readJson($path)
    {
        $json = @file_get_contents($path);
        $value = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($value) || json_last_error() !== JSON_ERROR_NONE) { throw new RuntimeException('Recovery operation receipt is invalid.'); }
        return $value;
    }

    private function writeNewJson($path, array $value)
    {
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) { throw new RuntimeException('RECOVERY_OPERATION_ALREADY_EXISTS'); }
        @chmod($path, 0600);
        try { self::writeAll($handle, self::encode($value)); }
        finally { fclose($handle); }
    }

    private function replaceJson($path, array $value)
    {
        $temporary = $path . '.partial-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) { throw new RuntimeException('Recovery receipt update could not begin.'); }
        @chmod($temporary, 0600);
        try {
            self::writeAll($handle, self::encode($value));
            fflush($handle);
        } finally { fclose($handle); }
        if (!@rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('Recovery receipt update could not be committed.'); }
    }

    private static function privateDirectory($path)
    {
        if (!is_string($path) || trim($path) === '') { throw new InvalidArgumentException('Recovery operation root is required.'); }
        if (!is_dir($path) && !@mkdir($path, 0700, true)) { throw new RuntimeException('Recovery operation root could not be created.'); }
        @chmod($path, 0700);
        $real = realpath($path);
        if ($real === false) { throw new RuntimeException('Recovery operation root could not be resolved.'); }
        if (DIRECTORY_SEPARATOR === '/' && ((fileperms($real) & 0077) !== 0)) { throw new RuntimeException('Recovery operation root must be private.'); }
        return $real;
    }

    private static function assertKind($kind)
    {
        if (!in_array($kind, ['backup', 'staged_restore'], true)) { throw new InvalidArgumentException('Recovery operation kind is invalid.'); }
    }

    private static function assertIdempotencyKey($key)
    {
        if (!is_string($key) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{15,190}\z/', $key)) { throw new InvalidArgumentException('A valid Idempotency-Key is required.'); }
    }

    private static function assertOpaqueId($value, $label)
    {
        if (!is_string($value) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value)) {
            throw new InvalidArgumentException('A valid ' . $label . ' id is required.');
        }
    }

    private static function uuidV4()
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private static function encode(array $value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { throw new RuntimeException('Recovery receipt could not be encoded.'); }
        return $json;
    }

    private static function writeAll($handle, $bytes)
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) { throw new RuntimeException('Recovery receipt could not be written.'); }
            $offset += $written;
        }
    }
}
