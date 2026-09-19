<?php

class PackageManifest
{
    const CONTRACT_NAME = 'syndicatum-package';
    const SUPPORTED_FORMAT_MAJOR = 1;
    const SUPPORTED_FORMAT_MINOR = 0;

    private static $backupExecutableExtensions = [
        'bat', 'bin', 'cjs', 'cmd', 'com', 'dll', 'dylib', 'exe', 'jar', 'js', 'mjs',
        'phar', 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'ps1', 'sh', 'so',
    ];

    public static function parse($json)
    {
        if (!is_string($json) || trim($json) === '') {
            throw new InvalidArgumentException('Package manifest JSON is required.');
        }
        $decoded = json_decode($json, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Package manifest JSON is invalid.');
        }
        self::assertNoDuplicateObjectKeys($json);
        return self::validate($decoded);
    }

    public static function validate(array $manifest)
    {
        self::requireString($manifest, 'contract_name');
        if ($manifest['contract_name'] !== self::CONTRACT_NAME) {
            throw new InvalidArgumentException('Unknown package contract.');
        }
        self::validateFormatVersion(self::requireString($manifest, 'format_version'));
        $kind = self::requireString($manifest, 'package_kind');
        if (!in_array($kind, ['release', 'backup'], true)) {
            throw new InvalidArgumentException('Unknown package kind.');
        }

        foreach ([
            'application_version', 'source_tag', 'schema_baseline', 'schema_head',
            'minimum_reader_version', 'detached_checksum_reference', 'provenance_reference',
        ] as $field) {
            self::requireString($manifest, $field);
        }
        $commit = self::requireString($manifest, 'source_commit');
        if (!preg_match('/\A[a-f0-9]{40,64}\z/i', $commit)) {
            throw new InvalidArgumentException('Source commit must be a full hexadecimal identifier.');
        }
        self::validateTimestamp(self::requireString($manifest, 'source_timestamp'), 'source_timestamp');
        self::validateSha256(self::requireString($manifest, 'content_tree_sha256'), 'content_tree_sha256');
        if (self::requireString($manifest, 'digest_algorithm') !== 'sha256') {
            throw new InvalidArgumentException('Unsupported package digest algorithm.');
        }
        self::requireBoolean($manifest, 'contains_data');
        self::requireBoolean($manifest, 'contains_persistent_assets');
        if ($kind === 'release' && $manifest['contains_data']) {
            throw new InvalidArgumentException('A release package cannot contain installation data.');
        }
        if ($kind === 'backup' && !$manifest['contains_data']) {
            throw new InvalidArgumentException('A backup package must declare its data payload.');
        }

        self::validateCompatibility($manifest);
        self::validateUpgradeSources($manifest);
        self::validateFiles($manifest, $kind);
        $calculatedContentTree = self::calculateContentTreeSha256($manifest['files']);
        if (!hash_equals(strtolower($manifest['content_tree_sha256']), $calculatedContentTree)) {
            throw new InvalidArgumentException('Package content-tree SHA-256 does not match the canonical file inventory.');
        }
        self::validateSafeRelativePath($manifest['detached_checksum_reference'], 'detached_checksum_reference');
        self::validateSafeRelativePath($manifest['provenance_reference'], 'provenance_reference');
        return $manifest;
    }

    public static function validateSafeRelativePath($path, $field = 'path')
    {
        if (!is_string($path) || $path === '' || strlen($path) > 1024 || strpos($path, "\0") !== false) {
            throw new InvalidArgumentException($field . ' is not a safe relative path.');
        }
        if ($path[0] === '/' || strpos($path, '\\') !== false || preg_match('/\A[A-Za-z]:/', $path)
            || preg_match('/[^\x20-\x7E]/', $path)) {
            throw new InvalidArgumentException($field . ' is not a safe relative path.');
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException($field . ' is not a safe relative path.');
            }
        }
        return $path;
    }

    public static function calculateContentTreeSha256(array $files)
    {
        $canonical = '';
        foreach ($files as $file) {
            $record = [
                'path' => isset($file['path']) ? (string) $file['path'] : '',
                'type' => isset($file['type']) ? (string) $file['type'] : '',
                'mode' => isset($file['mode']) ? (int) $file['mode'] : -1,
                'size' => isset($file['size']) ? (int) $file['size'] : -1,
                'sha256' => isset($file['sha256']) ? strtolower((string) $file['sha256']) : '',
            ];
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                throw new InvalidArgumentException('Package file inventory cannot be canonically serialized.');
            }
            $canonical .= $encoded . "\n";
        }
        return hash('sha256', $canonical);
    }

    private static function validateFormatVersion($version)
    {
        if (!preg_match('/\A(\d+)\.(\d+)(?:\.(\d+))?\z/', $version, $matches)) {
            throw new InvalidArgumentException('Package format version is invalid.');
        }
        if ((int) $matches[1] !== self::SUPPORTED_FORMAT_MAJOR) {
            throw new InvalidArgumentException('Package format major version is not supported.');
        }
        if ((int) $matches[2] > self::SUPPORTED_FORMAT_MINOR) {
            throw new InvalidArgumentException('Package format minor version is not declared backward-compatible by this reader.');
        }
    }

    private static function validateCompatibility(array $manifest)
    {
        if (!isset($manifest['compatibility']) || !is_array($manifest['compatibility'])) {
            throw new InvalidArgumentException('Package compatibility is required.');
        }
        $compatibility = $manifest['compatibility'];
        foreach (['php', 'mysql'] as $runtime) {
            if (!isset($compatibility[$runtime]) || !is_array($compatibility[$runtime])) {
                throw new InvalidArgumentException($runtime . ' compatibility is required.');
            }
            self::requireString($compatibility[$runtime], 'minimum');
            self::requireString($compatibility[$runtime], 'maximum_exclusive');
        }
        if (!isset($compatibility['php']['extensions']) || !is_array($compatibility['php']['extensions'])) {
            throw new InvalidArgumentException('Required PHP extensions are missing.');
        }
        self::validateUniqueStringList($compatibility['php']['extensions'], 'PHP extensions');
        if (!isset($compatibility['mysql']['sql_modes']) || !is_array($compatibility['mysql']['sql_modes'])) {
            throw new InvalidArgumentException('Required MySQL SQL modes are missing.');
        }
        self::validateUniqueStringList($compatibility['mysql']['sql_modes'], 'MySQL SQL modes');
        self::requireString($compatibility['mysql'], 'charset');
        self::requireString($compatibility['mysql'], 'collation');
    }

    private static function validateUpgradeSources(array $manifest)
    {
        if (!isset($manifest['supported_upgrade_sources']) || !is_array($manifest['supported_upgrade_sources'])) {
            throw new InvalidArgumentException('Supported upgrade sources are required.');
        }
        foreach ($manifest['supported_upgrade_sources'] as $index => $source) {
            if (!is_array($source)) {
                throw new InvalidArgumentException('Upgrade source #' . $index . ' is invalid.');
            }
            self::requireString($source, 'application_version');
            self::requireString($source, 'schema_baseline');
        }
    }

    private static function validateFiles(array $manifest, $kind)
    {
        if (!isset($manifest['files']) || !is_array($manifest['files']) || !$manifest['files']) {
            throw new InvalidArgumentException('Package file inventory is required.');
        }
        $previous = null;
        $seen = [];
        $seenCaseFolded = [];
        foreach ($manifest['files'] as $index => $file) {
            if (!is_array($file)) {
                throw new InvalidArgumentException('Package file entry #' . $index . ' is invalid.');
            }
            $path = self::requireString($file, 'path');
            self::validateSafeRelativePath($path, 'files[' . $index . '].path');
            if ($previous !== null && strcmp($previous, $path) >= 0) {
                throw new InvalidArgumentException('Package file inventory must be unique and lexicographically ordered.');
            }
            if (isset($seen[$path])) {
                throw new InvalidArgumentException('Package file inventory contains a duplicate path.');
            }
            $caseFolded = strtolower($path);
            if (isset($seenCaseFolded[$caseFolded])) {
                throw new InvalidArgumentException('Package file inventory contains a case-fold path collision.');
            }
            if (!isset($file['type']) || $file['type'] !== 'file') {
                throw new InvalidArgumentException('V1 package inventories may contain regular files only.');
            }
            if (!isset($file['mode']) || !is_int($file['mode']) || $file['mode'] < 0 || $file['mode'] > 0777) {
                throw new InvalidArgumentException('Package file mode is invalid.');
            }
            if (!isset($file['size']) || !is_int($file['size']) || $file['size'] < 0) {
                throw new InvalidArgumentException('Package file size is invalid.');
            }
            self::validateSha256(self::requireString($file, 'sha256'), 'files[' . $index . '].sha256');
            if ($kind === 'backup' && self::isExecutablePath($path)) {
                throw new InvalidArgumentException('Backup packages cannot contain executable application files.');
            }
            if ($kind === 'backup' && ($file['mode'] & 0111) !== 0) {
                throw new InvalidArgumentException('Backup packages cannot contain executable permission bits.');
            }
            $seen[$path] = true;
            $seenCaseFolded[$caseFolded] = true;
            $previous = $path;
        }
    }

    private static function isExecutablePath($path)
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, self::$backupExecutableExtensions, true);
    }

    private static function validateUniqueStringList(array $values, $label)
    {
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($label . ' must contain non-empty strings.');
            }
            $normalized = strtolower(trim($value));
            if (isset($seen[$normalized])) {
                throw new InvalidArgumentException($label . ' must not contain duplicates.');
            }
            $seen[$normalized] = true;
        }
    }

    private static function validateSha256($value, $field)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/i', $value)) {
            throw new InvalidArgumentException($field . ' must be a SHA-256 digest.');
        }
    }

    private static function validateTimestamp($value, $field)
    {
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value)) {
            throw new InvalidArgumentException($field . ' must be a UTC RFC3339 timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException($field . ' is not a valid timestamp.');
        }
    }

    private static function requireString(array $source, $field)
    {
        if (!isset($source[$field]) || !is_string($source[$field]) || trim($source[$field]) === '') {
            throw new InvalidArgumentException($field . ' is required.');
        }
        return $source[$field];
    }

    private static function requireBoolean(array $source, $field)
    {
        if (!array_key_exists($field, $source) || !is_bool($source[$field])) {
            throw new InvalidArgumentException($field . ' must be boolean.');
        }
    }

    private static function assertNoDuplicateObjectKeys($json)
    {
        $offset = 0;
        self::scanJsonValue($json, $offset);
    }

    private static function scanJsonValue($json, &$offset)
    {
        self::skipJsonWhitespace($json, $offset);
        $length = strlen($json);
        if ($offset >= $length) {
            return;
        }
        $character = $json[$offset];
        if ($character === '{') {
            self::scanJsonObject($json, $offset);
            return;
        }
        if ($character === '[') {
            self::scanJsonArray($json, $offset);
            return;
        }
        if ($character === '"') {
            self::scanJsonString($json, $offset);
            return;
        }
        while ($offset < $length && strpos(",]} \t\r\n", $json[$offset]) === false) {
            $offset++;
        }
    }

    private static function scanJsonObject($json, &$offset)
    {
        $offset++;
        $keys = [];
        self::skipJsonWhitespace($json, $offset);
        if (isset($json[$offset]) && $json[$offset] === '}') {
            $offset++;
            return;
        }
        while (isset($json[$offset])) {
            self::skipJsonWhitespace($json, $offset);
            $rawKey = self::scanJsonString($json, $offset);
            $key = json_decode($rawKey);
            if (isset($keys[$key])) {
                throw new InvalidArgumentException('Package manifest JSON contains a duplicate object key: ' . $key);
            }
            $keys[$key] = true;
            self::skipJsonWhitespace($json, $offset);
            $offset++;
            self::scanJsonValue($json, $offset);
            self::skipJsonWhitespace($json, $offset);
            if (isset($json[$offset]) && $json[$offset] === ',') {
                $offset++;
                continue;
            }
            if (isset($json[$offset]) && $json[$offset] === '}') {
                $offset++;
            }
            return;
        }
    }

    private static function scanJsonArray($json, &$offset)
    {
        $offset++;
        self::skipJsonWhitespace($json, $offset);
        if (isset($json[$offset]) && $json[$offset] === ']') {
            $offset++;
            return;
        }
        while (isset($json[$offset])) {
            self::scanJsonValue($json, $offset);
            self::skipJsonWhitespace($json, $offset);
            if (isset($json[$offset]) && $json[$offset] === ',') {
                $offset++;
                continue;
            }
            if (isset($json[$offset]) && $json[$offset] === ']') {
                $offset++;
            }
            return;
        }
    }

    private static function scanJsonString($json, &$offset)
    {
        $start = $offset;
        $offset++;
        $length = strlen($json);
        while ($offset < $length) {
            if ($json[$offset] === '\\') {
                $offset += 2;
                continue;
            }
            if ($json[$offset] === '"') {
                $offset++;
                return substr($json, $start, $offset - $start);
            }
            $offset++;
        }
        return substr($json, $start);
    }

    private static function skipJsonWhitespace($json, &$offset)
    {
        $length = strlen($json);
        while ($offset < $length && strpos(" \t\r\n", $json[$offset]) !== false) {
            $offset++;
        }
    }
}
