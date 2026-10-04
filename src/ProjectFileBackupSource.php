<?php

require_once __DIR__ . '/ProjectFileStorage.php';

/** Snapshot-time inventory and verified local sources for portable project-file backup. */
final class ProjectFileBackupSource
{
    private $root;
    private $sources = [];

    public function __construct($applicationRoot, $configuredBase)
    {
        $configuredBase = is_string($configuredBase) ? trim($configuredBase) : '';
        $this->root = $configuredBase === '' ? null : (new ProjectFileStorage($applicationRoot, $configuredBase))->base();
    }

    public function inventory(PDO $pdo, $includeData)
    {
        $this->sources = [];
        if (!$includeData || !self::tableExists($pdo, 'project_files')) { return []; }
        $rows = $pdo->query(
            "SELECT public_id, storage_driver, storage_key, mime_type, size_bytes, sha256
             FROM project_files
             WHERE deleted_at IS NULL AND state = 'available'
             ORDER BY public_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { return []; }
        if ($this->root === null) { throw new RuntimeException('Project file storage is not configured for backup.'); }

        $entries = []; $seenKeys = [];
        foreach ($rows as $row) {
            $publicId = strtolower((string) $row['public_id']);
            $driver = (string) $row['storage_driver'];
            $key = (string) $row['storage_key'];
            $mime = strtolower(trim((string) $row['mime_type']));
            $bytes = filter_var($row['size_bytes'], FILTER_VALIDATE_INT);
            $sha256 = strtolower((string) $row['sha256']);
            if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $publicId)
                || $driver !== 'local' || !self::validStorageKey($key)
                || !preg_match('~\A[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}/[a-z0-9][a-z0-9!#$&^_.+\-]{0,126}\z~', $mime)
                || $bytes === false || $bytes < 1 || !preg_match('/\A[a-f0-9]{64}\z/', $sha256)
                || isset($seenKeys[$key])) {
                throw new RuntimeException('Project file metadata is invalid or unsupported for backup.');
            }
            $source = $this->sourcePath($key);
            $this->assertIdentity($source, $bytes, $sha256, 'snapshot');
            $path = 'persistent/project-files/' . $publicId;
            $entries[] = ['path' => $path, 'provider' => $driver, 'key' => $key, 'public_id' => $publicId,
                'mime_type' => $mime, 'sha256' => $sha256, 'bytes' => $bytes];
            $this->sources[$path] = ['path' => $source, 'bytes' => $bytes, 'sha256' => $sha256];
            $seenKeys[$key] = true;
        }
        return $entries;
    }

    public function archiveSources() { return $this->sources; }

    public function verifyUnchanged()
    {
        foreach ($this->sources as $source) {
            $this->assertIdentity($source['path'], $source['bytes'], $source['sha256'], 'archive');
        }
    }

    private function sourcePath($key)
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $key);
        $candidate = $this->root . DIRECTORY_SEPARATOR . $relative;
        if (is_link($candidate) || !is_file($candidate)) { throw new RuntimeException('A referenced project file is missing or unsafe; retry the backup.'); }
        $resolved = realpath($candidate);
        if (!is_string($resolved) || !self::inside($resolved, $this->root)) {
            throw new RuntimeException('A referenced project file escapes the configured storage root.');
        }
        return $resolved;
    }

    private function assertIdentity($path, $bytes, $sha256, $phase)
    {
        clearstatcache(true, $path); $before = @stat($path); $actualHash = @hash_file('sha256', $path);
        clearstatcache(true, $path); $after = @stat($path);
        if (!is_array($before) || !is_array($after) || !is_string($actualHash)
            || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']
            || (int) $after['size'] !== (int) $bytes || !hash_equals($sha256, $actualHash)) {
            throw new RuntimeException('A referenced project file changed or failed verification during backup ' . $phase . '.');
        }
    }

    private static function validStorageKey($key)
    {
        return preg_match('#\Aobjects/(?:[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}/)?([a-f0-9]{2})/([a-f0-9]{64})\z#', $key, $matches)
            && substr($matches[2], 0, 2) === $matches[1];
    }

    private static function tableExists(PDO $pdo, $table)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $statement->execute([$table]);
            return (int) $statement->fetchColumn() === 1;
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private static function inside($path, $root)
    {
        if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
    }
}
