<?php

class BaselineMetadata
{
    const CONTRACT_NAME = 'syndicatum-baseline';
    const FORMAT_VERSION = '1.0';

    private $metadata;
    private $tablePolicies = [];

    private function __construct(array $metadata)
    {
        $this->metadata = $metadata;
        foreach ($metadata['tables'] as $table) {
            $this->tablePolicies[$table['name']] = $table;
        }
    }

    public static function fromArray(array $metadata)
    {
        if (self::requiredString($metadata, 'contract_name') !== self::CONTRACT_NAME) {
            throw new InvalidArgumentException('Unknown baseline metadata contract.');
        }
        if (self::requiredString($metadata, 'format_version') !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Unsupported baseline metadata format.');
        }
        foreach (['baseline_id', 'application_version', 'schema_head', 'migration_cutover'] as $field) {
            self::requiredString($metadata, $field);
        }
        $commit = self::requiredString($metadata, 'source_commit');
        if (!preg_match('/\A[a-f0-9]{40,64}\z/i', $commit)) {
            throw new InvalidArgumentException('Baseline source commit must be a full hexadecimal identifier.');
        }
        self::validateSha256(self::requiredString($metadata, 'schema_sha256'), 'schema_sha256');
        self::validateMysql($metadata);
        self::validateMigrations($metadata);
        self::validateTables($metadata);
        return new self($metadata);
    }

    public function toArray()
    {
        return $this->metadata;
    }

    public function supportsMysqlVersion($version)
    {
        if (!is_string($version) || trim($version) === '') {
            return false;
        }
        $mysql = $this->metadata['mysql'];
        return version_compare($version, $mysql['minimum'], '>=')
            && version_compare($version, $mysql['maximum_exclusive'], '<');
    }

    public function tablePolicy($tableName)
    {
        return isset($this->tablePolicies[$tableName]) ? $this->tablePolicies[$tableName] : null;
    }

    public function assertKnownTables(array $tableNames)
    {
        $unknown = [];
        foreach ($tableNames as $tableName) {
            if (!is_string($tableName) || !isset($this->tablePolicies[$tableName])) {
                $unknown[] = is_scalar($tableName) ? (string) $tableName : gettype($tableName);
            }
        }
        if ($unknown) {
            sort($unknown, SORT_STRING);
            throw new InvalidArgumentException('Baseline table classification is missing: ' . implode(', ', $unknown));
        }
        return true;
    }

    private static function validateMysql(array $metadata)
    {
        if (!isset($metadata['mysql']) || !is_array($metadata['mysql'])) {
            throw new InvalidArgumentException('Baseline MySQL compatibility is required.');
        }
        $mysql = $metadata['mysql'];
        foreach (['minimum', 'maximum_exclusive', 'charset', 'collation'] as $field) {
            self::requiredString($mysql, $field);
        }
        if (!isset($mysql['sql_modes']) || !is_array($mysql['sql_modes'])) {
            throw new InvalidArgumentException('Baseline MySQL SQL modes are required.');
        }
        self::uniqueStringList($mysql['sql_modes'], 'Baseline MySQL SQL modes');
        if (version_compare($mysql['minimum'], $mysql['maximum_exclusive'], '>=')) {
            throw new InvalidArgumentException('Baseline MySQL version range is invalid.');
        }
    }

    private static function validateMigrations(array $metadata)
    {
        if (!isset($metadata['post_baseline_migrations']) || !is_array($metadata['post_baseline_migrations'])) {
            throw new InvalidArgumentException('Post-baseline migration inventory is required.');
        }
        $previous = null;
        foreach ($metadata['post_baseline_migrations'] as $index => $migration) {
            if (!is_array($migration)) {
                throw new InvalidArgumentException('Post-baseline migration #' . $index . ' is invalid.');
            }
            $id = self::requiredString($migration, 'id');
            self::validateSha256(self::requiredString($migration, 'sha256'), 'post_baseline_migrations[' . $index . '].sha256');
            if ($previous !== null && strcmp($previous, $id) >= 0) {
                throw new InvalidArgumentException('Post-baseline migrations must be unique and ordered.');
            }
            if (strcmp($id, $metadata['migration_cutover']) <= 0) {
                throw new InvalidArgumentException('Post-baseline migration must be after the declared cutover.');
            }
            $previous = $id;
        }
    }

    private static function validateTables(array $metadata)
    {
        if (!isset($metadata['tables']) || !is_array($metadata['tables']) || !$metadata['tables']) {
            throw new InvalidArgumentException('Baseline table classification is required.');
        }
        $previous = null;
        $restoreOrders = [];
        foreach ($metadata['tables'] as $index => $table) {
            if (!is_array($table)) {
                throw new InvalidArgumentException('Baseline table classification #' . $index . ' is invalid.');
            }
            $name = self::requiredString($table, 'name');
            if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name)) {
                throw new InvalidArgumentException('Baseline table name is invalid.');
            }
            if ($previous !== null && strcmp($previous, $name) >= 0) {
                throw new InvalidArgumentException('Baseline tables must be unique and lexicographically ordered.');
            }
            $policy = self::requiredString($table, 'backup_policy');
            if (!in_array($policy, ['durable', 'reset', 'excluded'], true)) {
                throw new InvalidArgumentException('Unknown baseline table backup policy.');
            }
            if (!isset($table['identity_columns']) || !is_array($table['identity_columns'])) {
                throw new InvalidArgumentException('Baseline table identity-column policy is required.');
            }
            self::uniqueStringList($table['identity_columns'], 'Baseline identity columns');
            if ($policy === 'excluded') {
                if (isset($table['restore_order']) && $table['restore_order'] !== null) {
                    throw new InvalidArgumentException('Excluded tables cannot have a restore order.');
                }
            } else {
                if (!isset($table['restore_order']) || !is_int($table['restore_order']) || $table['restore_order'] < 0) {
                    throw new InvalidArgumentException('Included baseline tables require a non-negative restore order.');
                }
                if (isset($restoreOrders[$table['restore_order']])) {
                    throw new InvalidArgumentException('Baseline table restore orders must be unique.');
                }
                $restoreOrders[$table['restore_order']] = true;
            }
            if ($policy === 'reset') {
                $strategy = self::requiredString($table, 'reset_strategy');
                if (!in_array($strategy, ['clear', 'invalidate', 'pause_for_reconciliation'], true)) {
                    throw new InvalidArgumentException('Unknown baseline reset strategy.');
                }
            } elseif (isset($table['reset_strategy']) && $table['reset_strategy'] !== null) {
                throw new InvalidArgumentException('Only reset tables may declare a reset strategy.');
            }
            $previous = $name;
        }
    }

    private static function uniqueStringList(array $values, $label)
    {
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || !preg_match('/\A[A-Za-z0-9_]+\z/', $value)) {
                throw new InvalidArgumentException($label . ' contains an invalid value.');
            }
            $key = strtolower($value);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException($label . ' contains a duplicate value.');
            }
            $seen[$key] = true;
        }
    }

    private static function validateSha256($value, $field)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/i', $value)) {
            throw new InvalidArgumentException($field . ' must be a SHA-256 digest.');
        }
    }

    private static function requiredString(array $source, $field)
    {
        if (!isset($source[$field]) || !is_string($source[$field]) || trim($source[$field]) === '') {
            throw new InvalidArgumentException($field . ' is required.');
        }
        return $source[$field];
    }
}
