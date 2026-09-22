<?php

require_once __DIR__ . '/BaselineMetadata.php';

/** A directly importable SQL snapshot. Never use this class against a serving restore target. */
final class FullSnapshotSql
{
    public static function export(PDO $pdo, BaselineMetadata $baseline, $path)
    {
        if (!is_string($path) || $path === '' || file_exists($path)) {
            throw new InvalidArgumentException('SQL snapshot destination must be a new private file.');
        }
        self::assertMySql84($pdo);
        self::assertNoUnsupportedObjects($pdo);
        $tables = self::tableNames($pdo);
        $baseline->assertBaselineTables($tables);
        $metadata = $baseline->toArray();
        $policies = [];
        foreach ($metadata['tables'] as $table) { $policies[$table['name']] = $table; }

        $out = @fopen($path, 'xb');
        if (!is_resource($out)) { throw new RuntimeException('Private SQL snapshot file could not be created.'); }
        @chmod($path, 0600);
        $snapshotActive = false;
        try {
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $snapshotActive = true;
            self::write($out, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\nSET SESSION time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\n");
            $counts = [];
            $rowHashes = [];
            $referencedAvatars = [];
            foreach ($tables as $table) {
                self::assertIdentifier($table);
                $columns = self::columns($pdo, $table);
                if ($columns !== $policies[$table]['columns']) {
                    throw new RuntimeException('SQL snapshot source columns differ from trusted baseline: ' . $table . '.');
                }
                $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
                if (!is_array($create) || !isset($create['Create Table']) || stripos($create['Create Table'], 'CREATE TABLE') !== 0) {
                    throw new RuntimeException('SQL snapshot table definition could not be read: ' . $table . '.');
                }
                self::write($out, str_replace("\r\n", "\n", rtrim($create['Create Table'])) . ";\n");
            }
            foreach ($tables as $table) {
                $policy = $policies[$table];
                $binary = self::binaryColumns($pdo, $table);
                $quotedColumns = array_map(function ($column) { return '`' . $column . '`'; }, $policy['columns']);
                $order = array_map(function ($column) { self::assertIdentifier($column); return '`' . $column . '`'; }, self::primaryKeyColumns($pdo, $table));
                if (!$order) { throw new RuntimeException('SQL snapshot table lacks a stable row order: ' . $table . '.'); }
                $query = 'SELECT * FROM `' . $table . '` ORDER BY ' . implode(', ', $order);
                $rows = $pdo->query($query);
                $counts[$table] = 0;
                $rowHash = hash_init('sha256');
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    if (array_keys($row) !== $policy['columns']) {
                        throw new RuntimeException('SQL snapshot row columns changed: ' . $table . '.');
                    }
                    $values = [];
                    foreach ($policy['columns'] as $column) {
                        if (is_resource($row[$column])) { $row[$column] = stream_get_contents($row[$column]); }
                        self::hashCell($rowHash, $row[$column]);
                        if (is_string($row[$column]) && preg_match_all('#api/v1/avatar\.php\?file=([a-f0-9]{40}\.(?:jpg|png|webp))#', $row[$column], $avatarMatches)) {
                            foreach ($avatarMatches[1] as $avatarName) { $referencedAvatars[$avatarName] = true; }
                        }
                        $values[] = self::sqlValue($row[$column], isset($binary[$column]));
                    }
                    self::write($out, 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $values) . ");\n");
                    $counts[$table]++;
                }
                $rowHashes[$table] = hash_final($rowHash);
            }
            $triggers = $pdo->query("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE() ORDER BY trigger_name")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($triggers as $trigger) {
                self::assertIdentifier($trigger);
                $row = $pdo->query('SHOW CREATE TRIGGER `' . $trigger . '`')->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || !isset($row['SQL Original Statement'])) {
                    throw new RuntimeException('SQL snapshot trigger definition could not be read: ' . $trigger . '.');
                }
                $create = str_replace("\r\n", "\n", trim($row['SQL Original Statement']));
                $create = preg_replace('/\ACREATE\s+DEFINER=`(?:``|[^`])+`@`(?:``|[^`])+`\s+/i', 'CREATE ', $create);
                if (!is_string($create) || stripos($create, 'CREATE TRIGGER') !== 0 || strpos($create, ';') !== false) {
                    throw new RuntimeException('SQL snapshot requires a single-statement trigger: ' . $trigger . '.');
                }
                self::write($out, $create . ";\n");
            }
            self::write($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
            if (!fflush($out)) { throw new RuntimeException('SQL snapshot could not be flushed.'); }
            $pdo->commit();
            $snapshotActive = false;
            fclose($out);
            $avatarNames = array_keys($referencedAvatars); sort($avatarNames, SORT_STRING);
            return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path),
                'row_counts' => $counts, 'row_hashes' => $rowHashes,
                'table_count' => count($tables), 'trigger_count' => count($triggers),
                'referenced_avatars' => $avatarNames];
        } catch (Throwable $error) {
            if ($snapshotActive) { $pdo->rollBack(); }
            if (is_resource($out)) { fclose($out); }
            @unlink($path);
            throw $error;
        }
    }

    public static function importIntoEmpty(PDO $pdo, BaselineMetadata $baseline, $path, array $expectedCounts, array $expectedHashes = [])
    {
        self::assertMySql84($pdo);
        if (!is_file($path) || is_link($path)) { throw new InvalidArgumentException('SQL snapshot must be a regular file.'); }
        if (self::schemaObjectCount($pdo) !== 0) { throw new RuntimeException('SQL snapshot target database is not empty.'); }
        $input = fopen($path, 'rb');
        if (!is_resource($input)) { throw new RuntimeException('SQL snapshot could not be opened.'); }
        $statement = '';
        $quote = null;
        $escaped = false;
        $executed = 0;
        $trustedTables = array_fill_keys(array_map(function ($table) { return $table['name']; }, $baseline->toArray()['tables']), true);
        $originalForeignKeyChecks = (int) $pdo->query('SELECT @@SESSION.FOREIGN_KEY_CHECKS')->fetchColumn();
        try {
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) { throw new RuntimeException('SQL snapshot read failed.'); }
                if ($chunk === '' && !feof($input)) { throw new RuntimeException('SQL snapshot read stalled.'); }
                for ($i = 0, $length = strlen($chunk); $i < $length; $i++) {
                    $char = $chunk[$i];
                    if ($quote !== null) {
                        $statement .= $char;
                        if ($escaped) { $escaped = false; }
                        elseif ($char === '\\') { $escaped = true; }
                        elseif ($char === $quote) { $quote = null; }
                        continue;
                    }
                    if ($char === "'" || $char === '"' || $char === '`') { $quote = $char; $statement .= $char; }
                    elseif ($char === ';') {
                        if (trim($statement) !== '') {
                            self::assertImportStatement(trim($statement), $trustedTables);
                            $pdo->exec(trim($statement)); $executed++;
                        }
                        $statement = '';
                    } else { $statement .= $char; }
                }
            }
            if ($quote !== null || trim($statement) !== '' || $executed < 1) {
                throw new RuntimeException('SQL snapshot ended with an incomplete statement.');
            }
            $tables = self::tableNames($pdo);
            $baseline->assertBaselineTables($tables);
            if (array_keys($expectedCounts) !== $tables) { throw new RuntimeException('SQL snapshot row-count inventory does not match the baseline.'); }
            foreach ($expectedCounts as $table => $count) {
                self::assertIdentifier($table);
                if (!is_int($count) || $count < 0 || (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() !== $count) {
                    throw new RuntimeException('SQL snapshot restored row count differs: ' . $table . '.');
                }
            }
            if ($expectedHashes) {
                if (array_keys($expectedHashes) !== $tables) { throw new RuntimeException('SQL snapshot row-hash inventory does not match the baseline.'); }
                $policies = [];
                foreach ($baseline->toArray()['tables'] as $table) { $policies[$table['name']] = $table; }
                foreach ($expectedHashes as $table => $digest) {
                    if (!is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)
                        || !hash_equals($digest, self::tableRowHash($pdo, $table, $policies[$table]['columns']))) {
                        throw new RuntimeException('SQL snapshot restored row digest differs: ' . $table . '.');
                    }
                }
            }
            return ['table_count' => count($tables), 'row_counts' => $expectedCounts, 'statements' => $executed,
                'cutover_performed' => false];
        } finally {
            fclose($input);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = ' . ($originalForeignKeyChecks ? '1' : '0'));
        }
    }

    private static function sqlValue($value, $binary)
    {
        if ($value === null) { return 'NULL'; }
        if (is_resource($value)) { $value = stream_get_contents($value); }
        if (!is_scalar($value)) { throw new RuntimeException('SQL snapshot row contains a non-scalar value.'); }
        $hex = bin2hex((string) $value);
        if ($hex === '') { return "''"; }
        return $binary ? '0x' . $hex : 'CONVERT(0x' . $hex . ' USING utf8mb4)';
    }

    private static function hashCell($hash, $value)
    {
        if ($value === null) { hash_update($hash, "\x00"); return; }
        if (!is_scalar($value)) { throw new RuntimeException('SQL snapshot hash encountered a non-scalar value.'); }
        $bytes = (string) $value;
        hash_update($hash, "\x01" . pack('N', strlen($bytes)) . $bytes);
    }

    private static function tableRowHash(PDO $pdo, $table, array $columns)
    {
        $order = array_map(function ($column) { self::assertIdentifier($column); return '`' . $column . '`'; }, self::primaryKeyColumns($pdo, $table));
        if (!$order) { throw new RuntimeException('SQL snapshot restored table lacks a primary key: ' . $table . '.'); }
        $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY ' . implode(', ', $order));
        $hash = hash_init('sha256');
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            if (array_keys($row) !== $columns) { throw new RuntimeException('SQL snapshot restored table columns differ: ' . $table . '.'); }
            foreach ($columns as $column) {
                $value = $row[$column];
                if (is_resource($value)) { $value = stream_get_contents($value); }
                self::hashCell($hash, $value);
            }
        }
        return hash_final($hash);
    }

    private static function tableNames(PDO $pdo)
    {
        return $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function columns(PDO $pdo, $table)
    {
        $statement = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
        $statement->execute([$table]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function binaryColumns(PDO $pdo, $table)
    {
        $statement = $pdo->prepare('SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
        $binary = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawColumn) {
            $column = array_change_key_case($rawColumn, CASE_LOWER);
            if (in_array(strtolower($column['data_type']), ['binary','varbinary','tinyblob','blob','mediumblob','longblob','bit'], true)) {
                $binary[$column['column_name']] = true;
            }
        }
        return $binary;
    }

    private static function primaryKeyColumns(PDO $pdo, $table)
    {
        $statement = $pdo->prepare("SELECT column_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = 'PRIMARY' ORDER BY ordinal_position");
        $statement->execute([$table]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function assertNoUnsupportedObjects(PDO $pdo)
    {
        foreach (['views' => 'information_schema.views', 'routines' => 'information_schema.routines', 'events' => 'information_schema.events'] as $name => $catalog) {
            $column = $name === 'views' ? 'table_schema' : ($name === 'routines' ? 'routine_schema' : 'event_schema');
            if ((int) $pdo->query('SELECT COUNT(*) FROM ' . $catalog . ' WHERE ' . $column . ' = DATABASE()')->fetchColumn() !== 0) {
                throw new RuntimeException('SQL snapshot cannot omit database ' . $name . '.');
            }
        }
    }

    private static function schemaObjectCount(PDO $pdo)
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        foreach (['routines' => 'routine_schema', 'events' => 'event_schema', 'triggers' => 'trigger_schema'] as $catalog => $column) {
            $count += (int) $pdo->query('SELECT COUNT(*) FROM information_schema.' . $catalog . ' WHERE ' . $column . ' = DATABASE()')->fetchColumn();
        }
        return $count;
    }

    private static function assertMySql84(PDO $pdo)
    {
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        if (!preg_match('/\A(\d+\.\d+\.\d+)/', $version, $match) || version_compare($match[1], '8.4.0', '<') || version_compare($match[1], '9.0.0', '>=')) {
            throw new RuntimeException('SQL snapshot requires MySQL 8.4.x.');
        }
    }

    private static function assertIdentifier($name)
    {
        if (!is_string($name) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name)) {
            throw new RuntimeException('SQL snapshot contains an invalid database identifier.');
        }
    }

    private static function assertImportStatement($sql, array $trustedTables)
    {
        if (in_array($sql, [
            'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            "SET SESSION time_zone = '+00:00'",
            'SET FOREIGN_KEY_CHECKS = 0',
            'SET FOREIGN_KEY_CHECKS = 1',
        ], true)) { return; }
        if (preg_match('/\ACREATE TABLE `([a-z][a-z0-9_]{0,63})`\s*\(/s', $sql, $match)
            || preg_match('/\AINSERT INTO `([a-z][a-z0-9_]{0,63})`\s*\(/s', $sql, $match)) {
            if (isset($trustedTables[$match[1]])) { return; }
        }
        if (preg_match('/\ACREATE TRIGGER `([a-z][a-z0-9_]{0,63})`\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON `([a-z][a-z0-9_]{0,63})`\s+FOR EACH ROW\s+/is', $sql, $match)
            && isset($trustedTables[$match[2]])) { return; }
        throw new InvalidArgumentException('SQL snapshot contains a statement outside the trusted import grammar.');
    }

    private static function write($stream, $bytes)
    {
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length;) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) { throw new RuntimeException('SQL snapshot write failed.'); }
            $offset += $written;
        }
    }
}
