<?php

/** The one-time, four-column physical-order bridge for a verified legacy uplift. */
final class LegacyClaimColumnOrder
{
    private $targets;

    public function __construct($authenticatedSchemaPath)
    {
        $sql = @file_get_contents($authenticatedSchemaPath);
        if (!is_string($sql)) {
            throw new RuntimeException('Authenticated baseline schema is unavailable.');
        }
        $this->targets = [];
        foreach (['agents', 'chat_agents'] as $table) {
            if (!preg_match('/CREATE TABLE `' . $table . '` \((.*?)\n\) ENGINE=/s', $sql, $match)) {
                throw new RuntimeException('Authenticated baseline table is missing: ' . $table);
            }
            $names = [];
            $definitions = [];
            foreach (explode("\n", $match[1]) as $line) {
                if (preg_match('/^  `([a-z_]+)` (.+),?\r?$/', rtrim($line, "\r"), $column)) {
                    $names[] = $column[1];
                    $definitions[$column[1]] = rtrim($column[2], ',');
                }
            }
            if (count($names) !== 17 || count(array_unique($names)) !== 17
                || !isset($definitions['claim_secret_version'], $definitions['claim_expires_at'])
                || array_search('claim_secret_version', $names, true) !== 9
                || array_search('claim_expires_at', $names, true) !== 10
                || $names[8] !== 'claim_hash') {
                throw new RuntimeException('Authenticated baseline claim-column layout is unexpected.');
            }
            $this->targets[$table] = ['names' => $names, 'definitions' => $definitions];
        }
        if ($this->targets['agents'] !== $this->targets['chat_agents']) {
            throw new RuntimeException('Authenticated baseline claim tables disagree.');
        }
    }

    /** Preflight is read-only. Returns tables needing the single authorized move. */
    public function preflight(PDO $pdo)
    {
        $pending = [];
        foreach ($this->targets as $table => $target) {
            $statement = $pdo->prepare("SELECT column_name AS column_name, column_type AS column_type,
                       is_nullable AS is_nullable, column_default AS column_default,
                       character_set_name AS character_set_name, collation_name AS collation_name,
                       extra AS extra, generation_expression AS generation_expression
                  FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position");
            $statement->execute([$table]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($rows, 'column_name');
            $legacyNames = $target['names'];
            $legacyNames[9] = 'claim_expires_at';
            $legacyNames[10] = 'claim_secret_version';
            if ($names !== $target['names'] && $names !== $legacyNames) {
                throw new RuntimeException('Claim-column table order is neither legacy nor baseline: ' . $table);
            }
            foreach (['claim_secret_version', 'claim_expires_at'] as $name) {
                $row = $rows[array_search($name, $names, true)];
                $expected = $this->definitionMetadata($target['definitions'][$name]);
                foreach ($expected as $field => $value) {
                    if ($row[$field] !== $value) {
                        throw new RuntimeException('Claim-column definition differs from authenticated baseline: ' . $table . '.' . $name);
                    }
                }
            }
            if ($names === $legacyNames) {
                $pending[] = $table;
            }
        }
        return $pending;
    }

    /** Call only after the entire ledger/schema preflight succeeds. */
    public function apply(PDO $pdo, array $pending)
    {
        if ($pending !== $this->preflight($pdo)) {
            throw new RuntimeException('Claim-column order changed after preflight.');
        }
        foreach ($pending as $table) {
            $definition = $this->targets[$table]['definitions']['claim_secret_version'];
            $pdo->exec('ALTER TABLE `' . $table . '` MODIFY COLUMN `claim_secret_version` '
                . $definition . ' AFTER `claim_hash`');
            $this->preflight($pdo);
        }
        if ($this->preflight($pdo)) {
            throw new RuntimeException('Claim-column normalization did not reach the baseline order.');
        }
    }

    private function definitionMetadata($definition)
    {
        if (!preg_match('/\A(varchar\(24\)|datetime)(?: COLLATE ([a-z0-9_]+))? DEFAULT NULL\z/', $definition, $match)) {
            throw new RuntimeException('Unsupported claim-column definition in authenticated baseline.');
        }
        $collation = isset($match[2]) ? $match[2] : null;
        return [
            'column_type' => $match[1], 'is_nullable' => 'YES', 'column_default' => null,
            'character_set_name' => $collation === null ? null : substr($collation, 0, strpos($collation, '_')),
            'collation_name' => $collation, 'extra' => '', 'generation_expression' => '',
        ];
    }
}
