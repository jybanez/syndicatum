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
        self::assertAllowedKeys($metadata, [
            'contract_name', 'format_version', 'baseline_id', 'application_version', 'schema_head',
            'migration_cutover', 'source_commit', 'schema_sha256', 'mysql',
            'post_baseline_migrations', 'tables',
        ], 'baseline metadata');
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
        if (!preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/i', $commit)) {
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

    public function tablePolicies()
    {
        return array_values($this->tablePolicies);
    }

    public function tablesWithBackupPolicy($policy)
    {
        if (!in_array($policy, ['durable', 'reset', 'excluded'], true)) {
            throw new InvalidArgumentException('Unknown baseline table backup policy.');
        }
        $tables = [];
        foreach ($this->tablePolicies as $table) {
            if ($table['backup_policy'] === $policy) { $tables[] = $table; }
        }
        if ($policy !== 'excluded') {
            usort($tables, function ($left, $right) {
                if ($left['restore_order'] === $right['restore_order']) { return strcmp($left['name'], $right['name']); }
                return $left['restore_order'] < $right['restore_order'] ? -1 : 1;
            });
        }
        return $tables;
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

    public function assertBaselineTables(array $tableNames)
    {
        self::requireList($tableNames, 'Baseline schema table inventory');
        $declared = array_keys($this->tablePolicies);
        sort($declared, SORT_STRING);
        $actual = [];
        foreach ($tableNames as $tableName) {
            if (!is_string($tableName) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $tableName)) {
                throw new InvalidArgumentException('Baseline schema table inventory contains an invalid table.');
            }
            $actual[] = $tableName;
        }
        if (count($actual) !== count(array_unique($actual))) {
            throw new InvalidArgumentException('Baseline schema table inventory contains duplicate tables.');
        }
        sort($actual, SORT_STRING);
        if ($actual !== $declared) {
            throw new InvalidArgumentException('Baseline table policy map must exactly match the trusted schema inventory.');
        }
        return true;
    }

    public function assertRestoreOrderSupportsForeignKeys(array $foreignKeys)
    {
        self::requireList($foreignKeys, 'Baseline foreign-key inventory');
        foreach ($foreignKeys as $index => $foreignKey) {
            if (!is_array($foreignKey)) {
                throw new InvalidArgumentException('Baseline foreign key #' . $index . ' is invalid.');
            }
            self::assertAllowedKeys($foreignKey, ['parent', 'child'], 'foreign_keys[' . $index . ']');
            $parent = self::requiredString($foreignKey, 'parent');
            $child = self::requiredString($foreignKey, 'child');
            if (!isset($this->tablePolicies[$parent]) || !isset($this->tablePolicies[$child])) {
                throw new InvalidArgumentException('Baseline foreign key references an unclassified table.');
            }
            $parentPolicy = $this->tablePolicies[$parent];
            $childPolicy = $this->tablePolicies[$child];
            if ($parentPolicy['backup_policy'] === 'excluded' || $childPolicy['backup_policy'] === 'excluded') {
                continue;
            }
            if ($parentPolicy['restore_order'] >= $childPolicy['restore_order']) {
                throw new InvalidArgumentException('Baseline restore order violates a parent-before-child foreign-key dependency.');
            }
        }
        return true;
    }

    private static function validateMysql(array $metadata)
    {
        if (!isset($metadata['mysql']) || !is_array($metadata['mysql'])) {
            throw new InvalidArgumentException('Baseline MySQL compatibility is required.');
        }
        $mysql = $metadata['mysql'];
        self::assertAllowedKeys($mysql, ['minimum', 'maximum_exclusive', 'reference_version', 'charset', 'collation', 'sql_modes'], 'baseline mysql');
        foreach (['minimum', 'maximum_exclusive', 'reference_version', 'charset', 'collation'] as $field) {
            self::requiredString($mysql, $field);
        }
        if (!isset($mysql['sql_modes']) || !is_array($mysql['sql_modes'])) {
            throw new InvalidArgumentException('Baseline MySQL SQL modes are required.');
        }
        self::requireList($mysql['sql_modes'], 'Baseline MySQL SQL modes');
        self::uniqueStringList($mysql['sql_modes'], 'Baseline MySQL SQL modes');
        if (version_compare($mysql['minimum'], $mysql['maximum_exclusive'], '>=')) {
            throw new InvalidArgumentException('Baseline MySQL version range is invalid.');
        }
        if (version_compare($mysql['reference_version'], $mysql['minimum'], '<')
            || version_compare($mysql['reference_version'], $mysql['maximum_exclusive'], '>=')) {
            throw new InvalidArgumentException('Baseline MySQL reference version must satisfy the hard compatibility range.');
        }
    }

    private static function validateMigrations(array $metadata)
    {
        if (!preg_match('/\A\d{12}\z/', $metadata['migration_cutover'])
            || !preg_match('/\A\d{12}\z/', $metadata['schema_head'])) {
            throw new InvalidArgumentException('Baseline migration identifiers must be 12 decimal digits.');
        }
        if (!isset($metadata['post_baseline_migrations']) || !is_array($metadata['post_baseline_migrations'])) {
            throw new InvalidArgumentException('Post-baseline migration inventory is required.');
        }
        self::requireList($metadata['post_baseline_migrations'], 'Post-baseline migration inventory');
        $previous = null;
        $hashes = [];
        foreach ($metadata['post_baseline_migrations'] as $index => $migration) {
            if (!is_array($migration)) {
                throw new InvalidArgumentException('Post-baseline migration #' . $index . ' is invalid.');
            }
            self::assertAllowedKeys($migration, ['id', 'sha256'], 'post_baseline_migrations[' . $index . ']');
            $id = self::requiredString($migration, 'id');
            if (!preg_match('/\A\d{12}\z/', $id)) {
                throw new InvalidArgumentException('Post-baseline migration identifiers must be 12 decimal digits.');
            }
            self::validateSha256(self::requiredString($migration, 'sha256'), 'post_baseline_migrations[' . $index . '].sha256');
            if ($previous !== null && strcmp($previous, $id) >= 0) {
                throw new InvalidArgumentException('Post-baseline migrations must be unique and ordered.');
            }
            if (strcmp($id, $metadata['migration_cutover']) <= 0) {
                throw new InvalidArgumentException('Post-baseline migration must be after the declared cutover.');
            }
            $previous = $id;
            $hash = strtolower($migration['sha256']);
            if (isset($hashes[$hash])) {
                throw new InvalidArgumentException('Post-baseline migrations cannot reuse a content digest.');
            }
            $hashes[$hash] = true;
        }
        $expectedHead = $previous === null ? $metadata['migration_cutover'] : $previous;
        if ($metadata['schema_head'] !== $expectedHead) {
            throw new InvalidArgumentException('Baseline schema head must equal the final declared migration or the cutover when no migrations exist.');
        }
    }

    private static function validateTables(array $metadata)
    {
        if (!isset($metadata['tables']) || !is_array($metadata['tables']) || !$metadata['tables']) {
            throw new InvalidArgumentException('Baseline table classification is required.');
        }
        self::requireList($metadata['tables'], 'Baseline table classification');
        $previous = null;
        $restoreOrders = [];
        foreach ($metadata['tables'] as $index => $table) {
            if (!is_array($table)) {
                throw new InvalidArgumentException('Baseline table classification #' . $index . ' is invalid.');
            }
            self::assertAllowedKeys($table, [
                'name', 'backup_policy', 'restore_order', 'columns', 'identity_columns', 'sequence_state',
                'integrity_checks', 'reset_strategy', 'excluded_reason', 'target_expectation',
            ], 'tables[' . $index . ']');
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
            self::requireList($table['identity_columns'], 'Baseline identity columns');
            self::uniqueStringList($table['identity_columns'], 'Baseline identity columns');
            if (!isset($table['columns']) || !is_array($table['columns']) || !$table['columns']) {
                throw new InvalidArgumentException('Baseline table column inventory is required.');
            }
            self::requireList($table['columns'], 'Baseline table columns');
            self::uniqueStringList($table['columns'], 'Baseline table columns');
            if (count(array_diff($table['identity_columns'], $table['columns'])) > 0) {
                throw new InvalidArgumentException('Baseline identity columns must be present in the exact table column inventory.');
            }
            if (!isset($table['integrity_checks']) || !is_array($table['integrity_checks'])) {
                throw new InvalidArgumentException('Baseline table integrity-check policy is required.');
            }
            self::requireList($table['integrity_checks'], 'Baseline integrity checks');
            self::closedStringList($table['integrity_checks'], [
                'row_count', 'identity_uniqueness', 'foreign_key_consistency', 'checksum_sample',
            ], 'Baseline integrity checks');
            if ($policy === 'excluded') {
                if (isset($table['restore_order']) && $table['restore_order'] !== null) {
                    throw new InvalidArgumentException('Excluded tables cannot have a restore order.');
                }
                if ($table['identity_columns'] || $table['integrity_checks']) {
                    throw new InvalidArgumentException('Excluded tables cannot declare portable verification rules.');
                }
                $reason = self::requiredString($table, 'excluded_reason');
                if (!in_array($reason, ['environment_local', 'security_local', 'non_portable', 'deprecated'], true)) {
                    throw new InvalidArgumentException('Unknown excluded-table reason.');
                }
                $expectation = self::requiredString($table, 'target_expectation');
                if (!in_array($expectation, ['absent', 'empty', 'locally_initialized', 'operator_supplied'], true)) {
                    throw new InvalidArgumentException('Unknown excluded-table target expectation.');
                }
                if ((isset($table['sequence_state']) && $table['sequence_state'] !== null)
                    || (isset($table['reset_strategy']) && $table['reset_strategy'] !== null)) {
                    throw new InvalidArgumentException('Excluded tables cannot declare durable or reset behavior.');
                }
            } else {
                if ((isset($table['excluded_reason']) && $table['excluded_reason'] !== null)
                    || (isset($table['target_expectation']) && $table['target_expectation'] !== null)) {
                    throw new InvalidArgumentException('Only excluded tables may declare exclusion behavior.');
                }
                if (!isset($table['restore_order']) || !is_int($table['restore_order']) || $table['restore_order'] < 0) {
                    throw new InvalidArgumentException('Included baseline tables require a non-negative restore order.');
                }
                if (isset($restoreOrders[$table['restore_order']])) {
                    throw new InvalidArgumentException('Baseline table restore orders must be unique.');
                }
                $restoreOrders[$table['restore_order']] = true;
            }
            if ($policy === 'durable') {
                if (!$table['identity_columns']) {
                    throw new InvalidArgumentException('Durable tables require identity columns for restore verification.');
                }
                $sequenceState = self::requiredString($table, 'sequence_state');
                if (!in_array($sequenceState, ['preserve', 'recompute'], true)) {
                    throw new InvalidArgumentException('Unknown durable-table sequence-state policy.');
                }
                if (isset($table['reset_strategy']) && $table['reset_strategy'] !== null) {
                    throw new InvalidArgumentException('Durable tables cannot declare a reset strategy.');
                }
            } elseif ($policy === 'reset') {
                if (isset($table['sequence_state']) && $table['sequence_state'] !== null) {
                    throw new InvalidArgumentException('Reset tables cannot declare durable sequence behavior.');
                }
                $strategy = self::requiredString($table, 'reset_strategy');
                if (!in_array($strategy, ['truncate', 'recreate_default_row', 'regenerate_on_start', 'rebuild_from_durable_state', 'reissue_credentials', 'reauthorize', 'rebind_after_reauthorization'], true)) {
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

    private static function closedStringList(array $values, array $allowed, $label)
    {
        self::uniqueStringList($values, $label);
        foreach ($values as $value) {
            if (!in_array($value, $allowed, true)) {
                throw new InvalidArgumentException($label . ' contains an unsupported value.');
            }
        }
    }

    private static function validateSha256($value, $field)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/i', $value)) {
            throw new InvalidArgumentException($field . ' must be a SHA-256 digest.');
        }
    }

    private static function requireList(array $values, $label)
    {
        $index = 0;
        foreach ($values as $key => $_value) {
            if ($key !== $index) {
                throw new InvalidArgumentException($label . ' must be a JSON list.');
            }
            $index++;
        }
    }

    private static function assertAllowedKeys(array $source, array $allowed, $label)
    {
        $lookup = array_fill_keys($allowed, true);
        foreach ($source as $key => $_value) {
            if (!is_string($key) || !isset($lookup[$key])) {
                throw new InvalidArgumentException($label . ' contains an unsupported field: ' . (string) $key);
            }
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
