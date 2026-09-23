<?php

require_once __DIR__ . '/CurrentBackupEnvelope.php';
require_once __DIR__ . '/CurrentBaselineSql.php';

/** Capture-only metadata; this contract makes no restore-compatibility promise. */
final class CurrentBackupManifest
{
    const CONTRACT_NAME = 'syndicatum-current-baseline-backup';
    const FORMAT_VERSION = '1.0';
    const SQL_PATH = 'database/snapshot.sql';

    public static function encode(array $document)
    {
        self::validate($document);
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) { throw new RuntimeException('Current-backup manifest could not be encoded.'); }
        return $json;
    }

    public static function parse($json)
    {
        if (!is_string($json) || $json === '' || strlen($json) > CurrentBackupEnvelope::MAX_MANIFEST_BYTES) {
            throw new InvalidArgumentException('Current-backup manifest size is invalid.');
        }
        $document = json_decode($json, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($document) || json_last_error() !== JSON_ERROR_NONE || self::encode($document) !== $json) {
            throw new InvalidArgumentException('Current-backup manifest is not canonical JSON.');
        }
        return $document;
    }

    public static function validate(array $document)
    {
        self::keys($document, ['contract_name','format_version','created_at','operation_id','include_data',
            'source_database','source_mysql_version','source_application_version','source_commit',
            'source_installation_id','sql','assets']);
        if ($document['contract_name'] !== self::CONTRACT_NAME || $document['format_version'] !== self::FORMAT_VERSION
            || $document['include_data'] !== true) {
            throw new InvalidArgumentException('Current-backup artifact contract is invalid.');
        }
        if (!is_string($document['created_at']) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $document['created_at'])
            || !is_string($document['operation_id']) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $document['operation_id'])
            || !is_string($document['source_database']) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $document['source_database'])) {
            throw new InvalidArgumentException('Current-backup source metadata is invalid.');
        }
        CurrentBaselineSql::assertSupportedSourceVersion($document['source_mysql_version']);
        foreach (['source_application_version','source_commit','source_installation_id'] as $field) {
            if ($document[$field] !== null && (!is_string($document[$field]) || trim($document[$field]) === '')) {
                throw new InvalidArgumentException('Current-backup optional provenance is invalid: ' . $field . '.');
            }
        }
        self::keys($document['sql'], ['path','sha256','bytes','table_count','trigger_count','row_counts','row_hashes']);
        if ($document['sql']['path'] !== self::SQL_PATH) { throw new InvalidArgumentException('Current-backup SQL path is invalid.'); }
        self::digestAndSize($document['sql']);
        if (!is_int($document['sql']['table_count']) || $document['sql']['table_count'] < 1
            || !is_int($document['sql']['trigger_count']) || $document['sql']['trigger_count'] < 0
            || !is_array($document['sql']['row_counts']) || count($document['sql']['row_counts']) !== $document['sql']['table_count']) {
            throw new InvalidArgumentException('Current-backup SQL inventory is invalid.');
        }
        $tables = array_keys($document['sql']['row_counts']);
        $sorted = $tables; sort($sorted, SORT_STRING);
        if ($tables !== $sorted || !is_array($document['sql']['row_hashes'])
            || array_keys($document['sql']['row_hashes']) !== $tables) {
            throw new InvalidArgumentException('Current-backup table inventory is invalid.');
        }
        foreach ($tables as $table) {
            if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $table)
                || !is_int($document['sql']['row_counts'][$table]) || $document['sql']['row_counts'][$table] < 0
                || !preg_match('/\A[a-f0-9]{64}\z/', $document['sql']['row_hashes'][$table])) {
                throw new InvalidArgumentException('Current-backup table entry is invalid.');
            }
        }
        if (!is_array($document['assets']) || array_values($document['assets']) !== $document['assets']) {
            throw new InvalidArgumentException('Current-backup asset inventory is invalid.');
        }
        $previous = '';
        foreach ($document['assets'] as $asset) {
            self::keys($asset, ['path','sha256','bytes']);
            if (!is_string($asset['path']) || !preg_match('#\Aassets/avatars/[a-f0-9]{40}\.(?:jpg|png|webp)\z#', $asset['path'])
                || strcmp($asset['path'], $previous) <= 0) {
                throw new InvalidArgumentException('Current-backup asset path is invalid or duplicated.');
            }
            self::digestAndSize($asset);
            $previous = $asset['path'];
        }
        return $document;
    }

    public static function archivePaths(array $document)
    {
        self::validate($document);
        $paths = [self::SQL_PATH];
        foreach ($document['assets'] as $asset) { $paths[] = $asset['path']; }
        sort($paths, SORT_STRING);
        return $paths;
    }

    private static function keys($value, array $keys)
    {
        if (!is_array($value) || array_keys($value) !== $keys) {
            throw new InvalidArgumentException('Current-backup manifest field layout is invalid.');
        }
    }

    private static function digestAndSize(array $entry)
    {
        if (!is_string($entry['sha256']) || !preg_match('/\A[a-f0-9]{64}\z/', $entry['sha256'])
            || !is_int($entry['bytes']) || $entry['bytes'] < 0) {
            throw new InvalidArgumentException('Current-backup member identity is invalid.');
        }
    }
}
