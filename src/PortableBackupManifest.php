<?php

require_once __DIR__ . '/CurrentBackupEnvelope.php';
require_once __DIR__ . '/CurrentBaselineSql.php';

/** Canonical inventory for a portable Syndicatum installation package. */
final class PortableBackupManifest
{
    const CONTRACT_NAME = 'syndicatum-portable-backup';
    const FORMAT_VERSION = '2.0';
    const LEGACY_FORMAT_VERSION = '1.0';
    const SQL_PATH = 'database/baseline.sql';
    const SCHEMA_PATH = 'database/schema.sql';
    const TRIGGERS_PATH = 'database/triggers.sql';

    public static function encode(array $document)
    {
        self::validate($document);
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) { throw new RuntimeException('Portable-backup manifest could not be encoded.'); }
        return $json;
    }

    public static function parse($json)
    {
        if (!is_string($json) || $json === '' || strlen($json) > CurrentBackupEnvelope::MAX_MANIFEST_BYTES) {
            throw new InvalidArgumentException('Portable-backup manifest size is invalid.');
        }
        $document = json_decode($json, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($document) || json_last_error() !== JSON_ERROR_NONE || self::encode($document) !== $json) {
            throw new InvalidArgumentException('Portable-backup manifest is not canonical JSON.');
        }
        return $document;
    }

    public static function validate(array $document)
    {
        self::keys($document, ['contract_name','format_version','created_at','operation_id','package_type','include_data',
            'source_database','source_mysql_version','source_application_version','source_commit','source_installation_id',
            'sql','runtime','persistent_files','configuration']);
        $full = $document['package_type'] === 'full_clone' && $document['include_data'] === true;
        $clean = $document['package_type'] === 'clean_installation' && $document['include_data'] === false;
        if ($document['contract_name'] !== self::CONTRACT_NAME
            || !in_array($document['format_version'], [self::FORMAT_VERSION, self::LEGACY_FORMAT_VERSION], true)
            || (!$full && !$clean)) {
            throw new InvalidArgumentException('Portable-backup artifact contract is invalid.');
        }
        if (!is_string($document['created_at']) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $document['created_at'])
            || !is_string($document['operation_id']) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $document['operation_id'])
            || !is_string($document['source_database']) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $document['source_database'])) {
            throw new InvalidArgumentException('Portable-backup source metadata is invalid.');
        }
        CurrentBaselineSql::assertSupportedSourceVersion($document['source_mysql_version']);
        foreach (['source_application_version','source_commit','source_installation_id'] as $field) {
            if ($document[$field] !== null && (!is_string($document[$field]) || trim($document[$field]) === '')) {
                throw new InvalidArgumentException('Portable-backup optional provenance is invalid: ' . $field . '.');
            }
        }
        if ($document['format_version'] === self::LEGACY_FORMAT_VERSION) {
            self::keys($document['sql'], ['path','sha256','bytes','table_count','trigger_count','row_counts','row_hashes']);
            if ($document['sql']['path'] !== self::SQL_PATH) { throw new InvalidArgumentException('Portable-backup SQL path is invalid.'); }
            self::member($document['sql'], '#\Adatabase/baseline\.sql\z#');
        } else {
            self::keys($document['sql'], ['schema','data','triggers','table_count','trigger_count','row_counts','row_hashes']);
            self::keys($document['sql']['schema'], ['path','sha256','bytes']);
            self::keys($document['sql']['triggers'], ['path','sha256','bytes']);
            self::member($document['sql']['schema'], '#\Adatabase/schema\.sql\z#');
            self::member($document['sql']['triggers'], '#\Adatabase/triggers\.sql\z#');
        }
        if (!is_int($document['sql']['table_count']) || $document['sql']['table_count'] < 1
            || !is_int($document['sql']['trigger_count']) || $document['sql']['trigger_count'] < 0
            || !is_array($document['sql']['row_counts']) || count($document['sql']['row_counts']) !== $document['sql']['table_count']) {
            throw new InvalidArgumentException('Portable-backup SQL inventory is invalid.');
        }
        $tables = array_keys($document['sql']['row_counts']); $sorted = $tables; sort($sorted, SORT_STRING);
        if ($tables !== $sorted || !is_array($document['sql']['row_hashes']) || array_keys($document['sql']['row_hashes']) !== $tables) {
            throw new InvalidArgumentException('Portable-backup table inventory is invalid.');
        }
        foreach ($tables as $table) {
            if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $table)
                || !is_int($document['sql']['row_counts'][$table]) || $document['sql']['row_counts'][$table] < 0
                || !is_string($document['sql']['row_hashes'][$table]) || !preg_match('/\A[a-f0-9]{64}\z/', $document['sql']['row_hashes'][$table])) {
                throw new InvalidArgumentException('Portable-backup table entry is invalid.');
            }
        }
        if ($document['format_version'] === self::FORMAT_VERSION) {
            self::dataMembers($document['sql']['data'], $document['sql']['row_counts']);
        }
        self::members($document['runtime'], '#\Aruntime/(?!.*(?:^|/)\.\.?(?:/|$))[A-Za-z0-9_.@/+\-]+\z#', 'runtime');
        self::members($document['persistent_files'], '#\Apersistent/avatars/[a-f0-9]{40}\.(?:jpg|png|webp)\z#', 'persistent file');
        self::members($document['configuration'], '#\Aconfiguration/[a-z0-9_.-]+\.json\z#', 'configuration');
        if ($document['runtime'] === [] || $document['configuration'] === []) {
            throw new InvalidArgumentException('Portable-backup runtime or configuration inventory is empty.');
        }
        return $document;
    }

    public static function entries(array $document)
    {
        self::validate($document);
        if ($document['format_version'] === self::LEGACY_FORMAT_VERSION) {
            $entries = [$document['sql']['path'] => $document['sql']];
        } else {
            $entries = [
                $document['sql']['schema']['path'] => $document['sql']['schema'],
                $document['sql']['triggers']['path'] => $document['sql']['triggers'],
            ];
            foreach ($document['sql']['data'] as $entry) { $entries[$entry['path']] = $entry; }
        }
        foreach (['runtime','persistent_files','configuration'] as $group) {
            foreach ($document[$group] as $entry) { $entries[$entry['path']] = $entry; }
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }

    private static function dataMembers($value, array $rowCounts)
    {
        if (!is_array($value) || array_values($value) !== $value) {
            throw new InvalidArgumentException('Portable-backup SQL batch inventory is invalid.');
        }
        $previous = '';
        $sequences = []; $totals = array_fill_keys(array_keys($rowCounts), 0);
        foreach ($value as $entry) {
            self::keys($entry, ['path','sha256','bytes','table','sequence','rows']);
            self::member($entry, '#\Adatabase/data/[a-z][a-z0-9_]{0,63}/[0-9]{6}\.sql\z#');
            if (!is_string($entry['table']) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $entry['table'])
                || !is_int($entry['sequence']) || $entry['sequence'] < 1
                || !is_int($entry['rows']) || $entry['rows'] < 1 || !array_key_exists($entry['table'], $totals)
                || $entry['path'] !== 'database/data/' . $entry['table'] . '/' . str_pad((string) $entry['sequence'], 6, '0', STR_PAD_LEFT) . '.sql'
                || strcmp($entry['path'], $previous) <= 0
                || $entry['sequence'] !== (($sequences[$entry['table']] ?? 0) + 1)) {
                throw new InvalidArgumentException('Portable-backup SQL batch entry is invalid.');
            }
            $sequences[$entry['table']] = $entry['sequence'];
            $totals[$entry['table']] += $entry['rows'];
            $previous = $entry['path'];
        }
        if ($totals !== $rowCounts) { throw new InvalidArgumentException('Portable-backup SQL batch row totals are invalid.'); }
    }

    private static function members($value, $pattern, $label)
    {
        if (!is_array($value) || array_values($value) !== $value) { throw new InvalidArgumentException('Portable-backup ' . $label . ' inventory is invalid.'); }
        $previous = '';
        foreach ($value as $entry) {
            self::keys($entry, ['path','sha256','bytes']);
            self::member($entry, $pattern);
            if (strcmp($entry['path'], $previous) <= 0) { throw new InvalidArgumentException('Portable-backup member path is duplicated or unsorted.'); }
            $previous = $entry['path'];
        }
    }

    private static function member(array $entry, $pattern)
    {
        if (!is_string($entry['path']) || !preg_match($pattern, $entry['path'])
            || !is_string($entry['sha256']) || !preg_match('/\A[a-f0-9]{64}\z/', $entry['sha256'])
            || !is_int($entry['bytes']) || $entry['bytes'] < 0) {
            throw new InvalidArgumentException('Portable-backup member identity is invalid.');
        }
    }

    private static function keys($value, array $keys)
    {
        if (!is_array($value) || array_keys($value) !== $keys) {
            throw new InvalidArgumentException('Portable-backup manifest field layout is invalid.');
        }
    }
}
