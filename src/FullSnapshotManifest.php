<?php

require_once __DIR__ . '/PackageManifest.php';
require_once __DIR__ . '/BackupEnvelope.php';

/** Closed, versioned contract for encrypted full-database snapshots. */
final class FullSnapshotManifest
{
    const CONTRACT_NAME = 'syndicatum-full-snapshot';
    const FORMAT_VERSION = '1.0';
    const SQL_PATH = 'database/snapshot.sql';
    const SECRET_PATH = 'secrets/recovery.json';

    public static function encode(array $document)
    {
        self::validate($document);
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) { throw new RuntimeException('Full-snapshot manifest could not be encoded.'); }
        return $json;
    }

    public static function parse($json)
    {
        if (!is_string($json) || $json === '' || strlen($json) > BackupEnvelope::MAX_MANIFEST_BYTES) {
            throw new InvalidArgumentException('Full-snapshot manifest size is invalid.');
        }
        PackageManifest::assertNoDuplicateJsonObjectKeys($json);
        $document = json_decode($json, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($document) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Full-snapshot manifest JSON is invalid.');
        }
        self::validate($document);
        if (!hash_equals(self::encode($document), $json)) {
            throw new InvalidArgumentException('Full-snapshot manifest is not canonical JSON.');
        }
        return $document;
    }

    public static function validate(array $document)
    {
        self::keys($document, ['contract_name','format_version','created_at','application_version','schema_baseline',
            'schema_head','source_commit','source_installation_id','source_database','mysql_version','sql','assets','secrets']);
        if ($document['contract_name'] !== self::CONTRACT_NAME || $document['format_version'] !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Full-snapshot format is unsupported.');
        }
        foreach (['created_at','application_version','schema_baseline','schema_head','source_commit',
            'source_installation_id','source_database','mysql_version'] as $field) {
            if (!is_string($document[$field]) || trim($document[$field]) === '') {
                throw new InvalidArgumentException('Full-snapshot identity is incomplete: ' . $field . '.');
            }
        }
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $document['created_at'])
            || !preg_match('/\A[a-f0-9]{40}\z/', $document['source_commit'])
            || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $document['source_installation_id'])
            || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $document['source_database'])
            || !preg_match('/\A8\.4\.\d+\z/', $document['mysql_version'])) {
            throw new InvalidArgumentException('Full-snapshot provenance is invalid.');
        }
        self::keys($document['sql'], ['path','sha256','bytes','table_count','trigger_count','row_counts','row_hashes']);
        if ($document['sql']['path'] !== self::SQL_PATH) { throw new InvalidArgumentException('Full-snapshot SQL path is not canonical.'); }
        self::digestAndSize($document['sql']);
        if (!is_int($document['sql']['table_count']) || $document['sql']['table_count'] < 1
            || !is_int($document['sql']['trigger_count']) || $document['sql']['trigger_count'] < 0
            || !is_array($document['sql']['row_counts']) || count($document['sql']['row_counts']) !== $document['sql']['table_count']) {
            throw new InvalidArgumentException('Full-snapshot SQL inventory is invalid.');
        }
        $names = array_keys($document['sql']['row_counts']);
        $sorted = $names; sort($sorted, SORT_STRING);
        if ($names !== $sorted) { throw new InvalidArgumentException('Full-snapshot row-count inventory is not sorted.'); }
        foreach ($document['sql']['row_counts'] as $name => $count) {
            if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name) || !is_int($count) || $count < 0) {
                throw new InvalidArgumentException('Full-snapshot row-count entry is invalid.');
            }
        }
        if (!is_array($document['sql']['row_hashes']) || array_keys($document['sql']['row_hashes']) !== $names) {
            throw new InvalidArgumentException('Full-snapshot row-hash inventory differs from row counts.');
        }
        foreach ($document['sql']['row_hashes'] as $digest) {
            if (!is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
                throw new InvalidArgumentException('Full-snapshot row digest is invalid.');
            }
        }
        if (!is_array($document['assets']) || array_values($document['assets']) !== $document['assets']) {
            throw new InvalidArgumentException('Full-snapshot asset list is invalid.');
        }
        $previous = '';
        foreach ($document['assets'] as $asset) {
            self::keys($asset, ['path','sha256','bytes']);
            if (!is_string($asset['path']) || !preg_match('#\Aassets/avatars/[a-f0-9]{40}\.(?:jpg|png|webp)\z#', $asset['path']) || strcmp($asset['path'], $previous) <= 0) {
                throw new InvalidArgumentException('Full-snapshot asset path is invalid or duplicated.');
            }
            self::digestAndSize($asset);
            $previous = $asset['path'];
        }
        self::keys($document['secrets'], ['path','sha256','bytes']);
        if ($document['secrets']['path'] !== self::SECRET_PATH) { throw new InvalidArgumentException('Full-snapshot secret path is not canonical.'); }
        self::digestAndSize($document['secrets']);
        return $document;
    }

    public static function archivePaths(array $document)
    {
        self::validate($document);
        $paths = [self::SQL_PATH, self::SECRET_PATH];
        foreach ($document['assets'] as $asset) { $paths[] = $asset['path']; }
        sort($paths, SORT_STRING);
        return $paths;
    }

    private static function keys($value, array $keys)
    {
        if (!is_array($value) || array_keys($value) !== $keys) {
            throw new InvalidArgumentException('Full-snapshot manifest field layout is invalid.');
        }
    }

    private static function digestAndSize(array $member)
    {
        if (!is_string($member['sha256']) || !preg_match('/\A[a-f0-9]{64}\z/', $member['sha256'])
            || !is_int($member['bytes']) || $member['bytes'] < 1) {
            throw new InvalidArgumentException('Full-snapshot member digest or size is invalid.');
        }
    }
}
