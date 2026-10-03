<?php

require_once __DIR__ . '/ProjectFileStorage.php';

/** Private, retry-safe assembly of browser project-file uploads. */
final class ProjectFileChunkUploadStore
{
    const CHUNK_BYTES = 1048576;
    const MAX_CHUNKS = 1024;
    const SESSION_TTL_SECONDS = 86400;

    private $root;

    public function __construct($applicationRoot, $configuredBase)
    {
        $base = (new ProjectFileStorage($applicationRoot, $configuredBase))->base();
        $temporary = $this->ensureDirectory($base . DIRECTORY_SEPARATOR . '.tmp');
        $this->root = $this->ensureDirectory($temporary . DIRECTORY_SEPARATOR . 'project-uploads');
        $this->removeExpiredSessions();
    }

    /**
     * Append one ordered chunk and finalize the assembled stream exactly through the
     * canonical ProjectFileService callback. Duplicate chunk retries are accepted.
     */
    public function receive(array $owner, array $input, $uploadedPath, $uploadedBytes, $maximumBytes, callable $finalize)
    {
        $request = $this->validatedRequest($owner, $input, $uploadedBytes, $maximumBytes);
        $paths = $this->paths($request['upload_id']);
        $lock = @fopen($paths['lock'], 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new RuntimeException('FILE_CHUNK_LOCK_FAILED');
        }
        @chmod($paths['lock'], 0600);

        try {
            $state = $this->readState($paths['state']);
            if ($state === null) {
                if ($request['chunk_index'] !== 0 || file_exists($paths['partial'])) {
                    throw new RuntimeException('FILE_UPLOAD_SESSION_NOT_FOUND');
                }
                $state = $this->newState($request);
                $this->createPartial($paths['partial']);
                $this->writeState($paths['state'], $state);
            } else {
                $this->assertSameSession($state, $request);
            }

            if (isset($state['completed_result']) && is_array($state['completed_result'])) {
                return ['complete' => true, 'replayed' => true, 'result' => $state['completed_result']];
            }
            if (!is_file($paths['partial']) || is_link($paths['partial'])) {
                throw new RuntimeException('FILE_UPLOAD_SESSION_NOT_FOUND');
            }

            $chunkHash = hash_file('sha256', $uploadedPath);
            if (!is_string($chunkHash)) { throw new RuntimeException('FILE_UPLOAD_READ_FAILED'); }
            $next = (int) $state['next_index'];
            if ($request['chunk_index'] < $next) {
                $known = isset($state['chunk_hashes'][(string) $request['chunk_index']])
                    ? (string) $state['chunk_hashes'][(string) $request['chunk_index']] : '';
                if ($known === '' || !hash_equals($known, $chunkHash)) {
                    throw new RuntimeException('FILE_UPLOAD_CHUNK_CONFLICT');
                }
            } elseif ($request['chunk_index'] > $next) {
                throw new RuntimeException('FILE_UPLOAD_CHUNK_OUT_OF_ORDER');
            } else {
                $previousBytes = (int) $state['received_bytes'];
                try {
                    $this->appendFile($uploadedPath, $paths['partial']);
                    $state['next_index'] = $next + 1;
                    $state['received_bytes'] = $previousBytes + $request['chunk_bytes'];
                    $state['chunk_hashes'][(string) $request['chunk_index']] = $chunkHash;
                    $state['updated_at'] = time();
                    clearstatcache(true, $paths['partial']);
                    $actual = filesize($paths['partial']);
                    if ($actual === false || (int) $actual !== (int) $state['received_bytes']
                        || (int) $state['received_bytes'] > (int) $state['total_size']) {
                        throw new RuntimeException('FILE_UPLOAD_SESSION_CORRUPT');
                    }
                    $this->writeState($paths['state'], $state);
                } catch (Exception $exception) {
                    $this->truncateFile($paths['partial'], $previousBytes);
                    throw $exception;
                }
            }

            if ((int) $state['next_index'] < (int) $state['chunk_count']) {
                return ['complete' => false, 'replayed' => $request['chunk_index'] < $next,
                    'received_chunks' => (int) $state['next_index'], 'chunk_count' => (int) $state['chunk_count'],
                    'received_bytes' => (int) $state['received_bytes']];
            }
            if ((int) $state['received_bytes'] !== (int) $state['total_size']) {
                throw new RuntimeException('FILE_UPLOAD_SESSION_CORRUPT');
            }

            $stream = @fopen($paths['partial'], 'rb');
            if (!is_resource($stream)) { throw new RuntimeException('FILE_UPLOAD_READ_FAILED'); }
            try { $result = $finalize($stream, $state); }
            finally { fclose($stream); }
            if (!is_array($result)) { throw new RuntimeException('FILE_UPLOAD_FINALIZE_FAILED'); }
            $state['completed_result'] = $result;
            $state['updated_at'] = time();
            $this->writeState($paths['state'], $state);
            @unlink($paths['partial']);
            return ['complete' => true, 'replayed' => false, 'result' => $result];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function validatedRequest(array $owner, array $input, $uploadedBytes, $maximumBytes)
    {
        $uploadId = strtolower(trim((string) (isset($input['upload_id']) ? $input['upload_id'] : '')));
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uploadId)) {
            throw new InvalidArgumentException('A valid upload_id is required.');
        }
        $index = filter_var(isset($input['chunk_index']) ? $input['chunk_index'] : null, FILTER_VALIDATE_INT);
        $count = filter_var(isset($input['chunk_count']) ? $input['chunk_count'] : null, FILTER_VALIDATE_INT);
        $total = filter_var(isset($input['total_size']) ? $input['total_size'] : null, FILTER_VALIDATE_INT);
        $bytes = (int) $uploadedBytes;
        $maximum = (int) $maximumBytes;
        if ($index === false || $count === false || $total === false || $index < 0 || $count < 1
            || $count > self::MAX_CHUNKS || $index >= $count || $total < 1 || $total > $maximum) {
            throw new InvalidArgumentException('Upload chunk metadata is invalid.');
        }
        $expectedCount = (int) ceil($total / self::CHUNK_BYTES);
        $expectedBytes = $index === $count - 1 ? $total - ($index * self::CHUNK_BYTES) : self::CHUNK_BYTES;
        if ($count !== $expectedCount || $bytes < 1 || $bytes > self::CHUNK_BYTES || $bytes !== $expectedBytes) {
            throw new InvalidArgumentException('Upload chunk size does not match the declared file size.');
        }
        $operation = trim((string) (isset($input['target_operation']) ? $input['target_operation'] : ''));
        if (!in_array($operation, ['upload', 'replace_file'], true)) {
            throw new InvalidArgumentException('Unsupported chunked file operation.');
        }
        $name = trim((string) (isset($input['original_name']) ? $input['original_name'] : ''));
        $idempotencyKey = trim((string) (isset($input['idempotency_key']) ? $input['idempotency_key'] : ''));
        if ($name === '' || !preg_match('/\A[\x20-\x7E]{16,160}\z/', $idempotencyKey)) {
            throw new InvalidArgumentException('File name and idempotency key are required.');
        }
        return [
            'upload_id' => $uploadId, 'chunk_index' => (int) $index, 'chunk_count' => (int) $count,
            'chunk_bytes' => $bytes, 'total_size' => (int) $total, 'target_operation' => $operation,
            'original_name' => $name, 'idempotency_key' => $idempotencyKey,
            'folder_id' => trim((string) (isset($input['folder_id']) ? $input['folder_id'] : 'root')),
            'file_id' => trim((string) (isset($input['file_id']) ? $input['file_id'] : '')),
            'version' => (int) (isset($input['version']) ? $input['version'] : 0),
            'project_id' => (int) $owner['project_id'], 'participant_id' => (int) $owner['participant_id'],
        ];
    }

    private function newState(array $request)
    {
        $state = $request;
        unset($state['chunk_index'], $state['chunk_bytes']);
        $state['next_index'] = 0;
        $state['received_bytes'] = 0;
        $state['chunk_hashes'] = [];
        $state['created_at'] = time();
        $state['updated_at'] = time();
        return $state;
    }

    private function assertSameSession(array $state, array $request)
    {
        foreach (['upload_id', 'chunk_count', 'total_size', 'target_operation', 'original_name', 'idempotency_key',
            'folder_id', 'file_id', 'version', 'project_id', 'participant_id'] as $key) {
            if (!array_key_exists($key, $state) || (string) $state[$key] !== (string) $request[$key]) {
                throw new RuntimeException('FILE_UPLOAD_SESSION_CONFLICT');
            }
        }
    }

    private function appendFile($sourcePath, $destinationPath)
    {
        $input = @fopen($sourcePath, 'rb');
        $output = @fopen($destinationPath, 'ab');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
            throw new RuntimeException('FILE_STORAGE_WRITE_FAILED');
        }
        try {
            while (!feof($input)) {
                $buffer = fread($input, 65536);
                if ($buffer === false) { throw new RuntimeException('FILE_UPLOAD_READ_FAILED'); }
                if ($buffer === '') { continue; }
                $offset = 0;
                while ($offset < strlen($buffer)) {
                    $written = fwrite($output, substr($buffer, $offset));
                    if (!is_int($written) || $written < 1) { throw new RuntimeException('FILE_STORAGE_WRITE_FAILED'); }
                    $offset += $written;
                }
            }
            if (!fflush($output)) { throw new RuntimeException('FILE_STORAGE_WRITE_FAILED'); }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function createPartial($path)
    {
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) { throw new RuntimeException('FILE_STORAGE_WRITE_FAILED'); }
        fclose($handle);
        @chmod($path, 0600);
    }

    private function truncateFile($path, $bytes)
    {
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle) || !ftruncate($handle, (int) $bytes)) {
            if (is_resource($handle)) { fclose($handle); }
            throw new RuntimeException('FILE_UPLOAD_SESSION_CORRUPT');
        }
        fflush($handle);
        fclose($handle);
        clearstatcache(true, $path);
    }

    private function readState($path)
    {
        if (!is_file($path) || is_link($path)) { return null; }
        $json = file_get_contents($path);
        $state = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($state)) { throw new RuntimeException('FILE_UPLOAD_SESSION_CORRUPT'); }
        return $state;
    }

    private function writeState($path, array $state)
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        $temporary = $path . '.new';
        if (!is_string($json) || file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('FILE_UPLOAD_STATE_WRITE_FAILED');
        }
        @chmod($path, 0600);
    }

    private function paths($uploadId)
    {
        $base = $this->root . DIRECTORY_SEPARATOR . 'upload-' . $uploadId;
        return ['partial' => $base . '.partial', 'state' => $base . '.json', 'lock' => $base . '.lock'];
    }

    private function removeExpiredSessions()
    {
        $cutoff = time() - self::SESSION_TTL_SECONDS;
        $entries = scandir($this->root);
        if (!is_array($entries)) { return; }
        foreach ($entries as $entry) {
            if (!preg_match('/\Aupload-[0-9a-f-]{36}\.(partial|json|lock|json\.new)\z/', $entry)) { continue; }
            $path = $this->root . DIRECTORY_SEPARATOR . $entry;
            $modified = @filemtime($path);
            if (is_int($modified) && $modified < $cutoff && !is_link($path)) { @unlink($path); }
        }
    }

    private function ensureDirectory($path)
    {
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700, true))) {
            throw new RuntimeException('FILE_STORAGE_DIRECTORY_FAILED');
        }
        @chmod($path, 0700);
        $resolved = realpath($path);
        if (!is_string($resolved)) { throw new RuntimeException('FILE_STORAGE_PATH_UNSAFE'); }
        return $resolved;
    }
}
