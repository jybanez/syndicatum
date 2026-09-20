<?php

final class BackupTablePolicy
{
    const CONTRACT_NAME = 'syndicatum-backup-table-policy';
    const FORMAT_VERSION = '1.0';

    private $tables;

    private function __construct(array $tables) { $this->tables = $tables; }

    public static function fromJson($json)
    {
        if (!is_string($json) || trim($json) === '') { throw new InvalidArgumentException('Backup table policy JSON is required.'); }
        $wire = json_decode($json, false, 32, JSON_BIGINT_AS_STRING);
        if (!($wire instanceof stdClass) || json_last_error() !== JSON_ERROR_NONE || !isset($wire->tables) || !($wire->tables instanceof stdClass)) {
            throw new InvalidArgumentException('Backup table policy must be a JSON object with a table object.');
        }
        $decoded = json_decode($json, true, 32, JSON_BIGINT_AS_STRING);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) { throw new InvalidArgumentException('Backup table policy JSON is invalid.'); }
        if (array_keys($decoded) !== ['contract_name', 'format_version', 'tables']
            || $decoded['contract_name'] !== self::CONTRACT_NAME || $decoded['format_version'] !== self::FORMAT_VERSION
            || !is_array($decoded['tables']) || !$decoded['tables']) {
            throw new InvalidArgumentException('Backup table policy contract is invalid.');
        }
        $previous = null;
        foreach ($decoded['tables'] as $table => $policy) {
            if (!is_string($table) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $table)
                || ($previous !== null && strcmp($previous, $table) >= 0) || !is_array($policy)) {
                throw new InvalidArgumentException('Backup table policy names must be valid, unique, and ordered.');
            }
            $previous = $table;
            self::validateTablePolicy($table, $policy);
        }
        return new self($decoded['tables']);
    }

    public function tableNames() { return array_keys($this->tables); }

    public function apply(array $schemaTables)
    {
        $schemaNames = array_keys($schemaTables);
        sort($schemaNames, SORT_STRING);
        if ($schemaNames !== array_keys($this->tables)) {
            $missing = array_values(array_diff($schemaNames, array_keys($this->tables)));
            $stale = array_values(array_diff(array_keys($this->tables), $schemaNames));
            throw new InvalidArgumentException('Backup table policy must exactly match the schema (missing=' . implode(',', $missing) . '; stale=' . implode(',', $stale) . ').');
        }
        $result = [];
        foreach ($this->tables as $name => $policy) {
            $schema = $schemaTables[$name];
            if (!isset($schema['restore_order'], $schema['columns'], $schema['identity_columns']) || !is_int($schema['restore_order'])
                || !is_array($schema['columns']) || !$schema['columns'] || !is_array($schema['identity_columns']) || !$schema['identity_columns']) {
                throw new InvalidArgumentException('Schema table policy input is incomplete for ' . $name . '.');
            }
            if ($policy['backup_policy'] === 'durable') {
                $result[] = [
                    'name' => $name, 'backup_policy' => 'durable', 'restore_order' => $schema['restore_order'],
                    'columns' => array_values($schema['columns']), 'identity_columns' => array_values($schema['identity_columns']), 'sequence_state' => 'preserve',
                    'integrity_checks' => ['row_count', 'identity_uniqueness', 'foreign_key_consistency'],
                ];
            } elseif ($policy['backup_policy'] === 'reset') {
                $result[] = [
                    'name' => $name, 'backup_policy' => 'reset', 'restore_order' => $schema['restore_order'],
                    'columns' => array_values($schema['columns']), 'identity_columns' => [], 'integrity_checks' => [], 'reset_strategy' => $policy['reset_strategy'],
                ];
            } else {
                $result[] = [
                    'name' => $name, 'backup_policy' => 'excluded', 'restore_order' => null,
                    'columns' => array_values($schema['columns']), 'identity_columns' => [], 'integrity_checks' => [],
                    'excluded_reason' => $policy['excluded_reason'], 'target_expectation' => $policy['target_expectation'],
                ];
            }
        }
        return $result;
    }

    private static function validateTablePolicy($table, array $policy)
    {
        if (!isset($policy['backup_policy']) || !is_string($policy['backup_policy'])) {
            throw new InvalidArgumentException('Backup policy is missing for ' . $table . '.');
        }
        if ($policy['backup_policy'] === 'durable') {
            if (array_keys($policy) !== ['backup_policy']) { throw new InvalidArgumentException('Durable table policy has unsupported fields: ' . $table . '.'); }
            return;
        }
        if ($policy['backup_policy'] === 'reset') {
            if (array_keys($policy) !== ['backup_policy', 'reset_strategy'] || !in_array($policy['reset_strategy'], ['truncate', 'recreate_default_row', 'regenerate_on_start', 'rebuild_from_durable_state', 'reissue_credentials', 'reauthorize', 'rebind_after_reauthorization'], true)) {
                throw new InvalidArgumentException('Reset table policy is invalid: ' . $table . '.');
            }
            return;
        }
        if ($policy['backup_policy'] === 'excluded') {
            if (array_keys($policy) !== ['backup_policy', 'excluded_reason', 'target_expectation']
                || !in_array($policy['excluded_reason'], ['environment_local', 'security_local', 'non_portable', 'deprecated'], true)
                || !in_array($policy['target_expectation'], ['absent', 'empty', 'locally_initialized', 'operator_supplied'], true)) {
                throw new InvalidArgumentException('Excluded table policy is invalid: ' . $table . '.');
            }
            return;
        }
        throw new InvalidArgumentException('Unknown backup policy for ' . $table . '.');
    }
}
