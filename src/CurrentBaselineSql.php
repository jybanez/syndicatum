<?php

/** Current baseline SQL export. Clean packages retain only explicit system-owned records. */
final class CurrentBaselineSql
{
    const PORTABLE_BATCH_BYTES = 2097152;
    const PORTABLE_BATCH_ROWS = 500;
    private static $cleanInstallationDataTables = [
        'mcp_service_tokens', 'oauth_clients', 'syndicatum_installation_identity', 'system_roles', 'system_settings',
    ];

    public static function export(PDO $pdo, $path, $onTableExported = null, $includeData = true)
    {
        if ($onTableExported !== null && !is_callable($onTableExported)) {
            throw new InvalidArgumentException('SQL export progress callback must be callable.');
        }
        if (!is_bool($includeData)) { throw new InvalidArgumentException('SQL export data policy is invalid.'); }
        if (!is_string($path) || $path === '' || file_exists($path)) {
            throw new InvalidArgumentException('SQL snapshot destination must be a new private file.');
        }
        self::assertSupportedSourceVersion((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        self::assertNoUnsupportedObjects($pdo);
        $tables = self::tableNames($pdo);
        self::assertTransactionalTables($pdo, $tables);

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
            $exportedTables = 0;
            foreach ($tables as $table) {
                self::assertIdentifier($table);
                $columns = self::columns($pdo, $table);
                $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
                if (!is_array($create) || !isset($create['Create Table']) || stripos($create['Create Table'], 'CREATE TABLE') !== 0) {
                    throw new RuntimeException('SQL snapshot table definition could not be read: ' . $table . '.');
                }
                self::write($out, str_replace("\r\n", "\n", rtrim($create['Create Table'])) . ";\n");
            }
            foreach ($tables as $table) {
                $columns = self::columns($pdo, $table);
                $binary = self::binaryColumns($pdo, $table);
                $quotedColumns = array_map(function ($column) { return '`' . $column . '`'; }, $columns);
                $order = array_map(function ($column) { self::assertIdentifier($column); return '`' . $column . '`'; }, self::primaryKeyColumns($pdo, $table));
                if (!$order) { throw new RuntimeException('SQL snapshot table lacks a stable row order: ' . $table . '.'); }
                $exportRows = $includeData || in_array($table, self::$cleanInstallationDataTables, true);
                $rows = $exportRows ? $pdo->query('SELECT * FROM `' . $table . '` ORDER BY ' . implode(', ', $order)) : null;
                $counts[$table] = 0;
                $rowHash = hash_init('sha256');
                while ($rows !== null && ($row = $rows->fetch(PDO::FETCH_ASSOC))) {
                    if (array_keys($row) !== $columns) {
                        throw new RuntimeException('SQL snapshot row columns changed: ' . $table . '.');
                    }
                    $values = [];
                    foreach ($columns as $column) {
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
                $exportedTables++;
                if ($onTableExported !== null) {
                    call_user_func($onTableExported, $exportedTables, count($tables));
                }
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

    /** Export an ordered, bounded SQL inventory for checkpointed restoration. */
    public static function exportPortable(PDO $pdo, $directory, $onTableExported = null, $includeData = true)
    {
        if ($onTableExported !== null && !is_callable($onTableExported)) {
            throw new InvalidArgumentException('SQL export progress callback must be callable.');
        }
        if (!is_bool($includeData)) { throw new InvalidArgumentException('SQL export data policy is invalid.'); }
        if (!is_string($directory) || !is_dir($directory) || is_link($directory)) {
            throw new InvalidArgumentException('Portable SQL destination must be a private directory.');
        }
        self::assertSupportedSourceVersion((string) $pdo->query('SELECT VERSION()')->fetchColumn());
        self::assertNoUnsupportedObjects($pdo);
        $tables = self::tableNames($pdo);
        self::assertTransactionalTables($pdo, $tables);
        $schemaPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'schema.sql';
        $triggersPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'triggers.sql';
        $schema = @fopen($schemaPath, 'xb');
        if (!is_resource($schema)) { throw new RuntimeException('Portable schema file could not be created.'); }
        @chmod($schemaPath, 0600);
        $created = [$schemaPath]; $snapshotActive = false;
        try {
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $snapshotActive = true;
            self::write($schema, "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\nSET SESSION time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\n");
            foreach ($tables as $table) {
                self::assertIdentifier($table);
                $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
                if (!is_array($create) || !isset($create['Create Table']) || stripos($create['Create Table'], 'CREATE TABLE') !== 0) {
                    throw new RuntimeException('SQL snapshot table definition could not be read: ' . $table . '.');
                }
                self::write($schema, str_replace("\r\n", "\n", rtrim($create['Create Table'])) . ";\n");
            }
            self::write($schema, "SET FOREIGN_KEY_CHECKS = 1;\n");
            if (!fflush($schema)) { throw new RuntimeException('Portable schema file could not be flushed.'); }
            fclose($schema); $schema = null;

            $counts = []; $rowHashes = []; $referencedAvatars = []; $batches = []; $exportedTables = 0;
            foreach ($tables as $table) {
                $columns = self::columns($pdo, $table);
                $binary = self::binaryColumns($pdo, $table);
                $quotedColumns = array_map(function ($column) { return '`' . $column . '`'; }, $columns);
                $order = array_map(function ($column) { self::assertIdentifier($column); return '`' . $column . '`'; }, self::primaryKeyColumns($pdo, $table));
                if (!$order) { throw new RuntimeException('SQL snapshot table lacks a stable row order: ' . $table . '.'); }
                $exportRows = $includeData || in_array($table, self::$cleanInstallationDataTables, true);
                $rows = $exportRows ? $pdo->query('SELECT * FROM `' . $table . '` ORDER BY ' . implode(', ', $order)) : null;
                $counts[$table] = 0; $rowHash = hash_init('sha256'); $sequence = 0;
                $batch = null; $batchPath = null; $batchRows = 0; $batchBytes = 0;
                while ($rows !== null && ($row = $rows->fetch(PDO::FETCH_ASSOC))) {
                    if (array_keys($row) !== $columns) { throw new RuntimeException('SQL snapshot row columns changed: ' . $table . '.'); }
                    $values = [];
                    foreach ($columns as $column) {
                        if (is_resource($row[$column])) { $row[$column] = stream_get_contents($row[$column]); }
                        self::hashCell($rowHash, $row[$column]);
                        if (is_string($row[$column]) && preg_match_all('#api/v1/avatar\.php\?file=([a-f0-9]{40}\.(?:jpg|png|webp))#', $row[$column], $avatarMatches)) {
                            foreach ($avatarMatches[1] as $avatarName) { $referencedAvatars[$avatarName] = true; }
                        }
                        $values[] = self::sqlValue($row[$column], isset($binary[$column]));
                    }
                    $statement = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $values) . ");\n";
                    if (is_resource($batch) && $batchRows > 0
                        && ($batchRows >= self::PORTABLE_BATCH_ROWS || $batchBytes + strlen($statement) > self::PORTABLE_BATCH_BYTES)) {
                        self::finishPortableBatch($batch, $batchPath, $table, $sequence, $batchRows, $batches);
                        $batch = null; $batchPath = null; $batchRows = 0; $batchBytes = 0;
                    }
                    if (!is_resource($batch)) {
                        $sequence++;
                        $relative = 'data/' . $table . '/' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT) . '.sql';
                        $batchPath = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                        if (!is_dir(dirname($batchPath)) && !mkdir(dirname($batchPath), 0700, true)) { throw new RuntimeException('Portable SQL batch directory could not be created.'); }
                        $batch = @fopen($batchPath, 'xb');
                        if (!is_resource($batch)) { throw new RuntimeException('Portable SQL batch could not be created.'); }
                        @chmod($batchPath, 0600); $created[] = $batchPath;
                    }
                    self::write($batch, $statement); $batchRows++; $batchBytes += strlen($statement); $counts[$table]++;
                }
                if (is_resource($batch)) { self::finishPortableBatch($batch, $batchPath, $table, $sequence, $batchRows, $batches); }
                $rowHashes[$table] = hash_final($rowHash); $exportedTables++;
                if ($onTableExported !== null) { call_user_func($onTableExported, $exportedTables, count($tables)); }
            }

            $triggers = @fopen($triggersPath, 'xb');
            if (!is_resource($triggers)) { throw new RuntimeException('Portable trigger file could not be created.'); }
            @chmod($triggersPath, 0600); $created[] = $triggersPath;
            $triggerNames = $pdo->query("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE() ORDER BY trigger_name")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($triggerNames as $trigger) {
                self::assertIdentifier($trigger);
                $row = $pdo->query('SHOW CREATE TRIGGER `' . $trigger . '`')->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || !isset($row['SQL Original Statement'])) { throw new RuntimeException('SQL snapshot trigger definition could not be read: ' . $trigger . '.'); }
                $create = str_replace("\r\n", "\n", trim($row['SQL Original Statement']));
                $create = preg_replace('/\ACREATE\s+DEFINER=`(?:``|[^`])+`@`(?:``|[^`])+`\s+/i', 'CREATE ', $create);
                if (!is_string($create) || stripos($create, 'CREATE TRIGGER') !== 0 || strpos($create, ';') !== false) {
                    throw new RuntimeException('SQL snapshot requires a single-statement trigger: ' . $trigger . '.');
                }
                self::write($triggers, $create . ";\n");
            }
            if (!fflush($triggers)) { throw new RuntimeException('Portable trigger file could not be flushed.'); }
            fclose($triggers); $triggers = null;
            $pdo->commit(); $snapshotActive = false;
            $avatarNames = array_keys($referencedAvatars); sort($avatarNames, SORT_STRING);
            return [
                'schema' => self::portableEntry($schemaPath, 'database/schema.sql'),
                'data' => $batches,
                'triggers' => self::portableEntry($triggersPath, 'database/triggers.sql'),
                'table_count' => count($tables), 'trigger_count' => count($triggerNames),
                'row_counts' => $counts, 'row_hashes' => $rowHashes,
                'referenced_avatars' => $avatarNames,
            ];
        } catch (Throwable $error) {
            if ($snapshotActive) { $pdo->rollBack(); }
            if (is_resource($schema)) { fclose($schema); }
            if (isset($triggers) && is_resource($triggers)) { fclose($triggers); }
            if (isset($batch) && is_resource($batch)) { fclose($batch); }
            foreach (array_reverse($created) as $path) { @unlink($path); }
            throw $error;
        }
    }

    private static function finishPortableBatch($stream, $path, $table, $sequence, $rows, array &$entries)
    {
        if (!fflush($stream)) { fclose($stream); throw new RuntimeException('Portable SQL batch could not be flushed.'); }
        fclose($stream);
        $relative = 'database/data/' . $table . '/' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT) . '.sql';
        $entry = self::portableEntry($path, $relative);
        $entries[] = ['path' => $entry['path'], 'sha256' => $entry['sha256'], 'bytes' => $entry['bytes'],
            'table' => $table, 'sequence' => $sequence, 'rows' => $rows];
    }

    private static function portableEntry($path, $relative)
    {
        $bytes = filesize($path); $hash = hash_file('sha256', $path);
        if (!is_int($bytes) || !is_string($hash)) { throw new RuntimeException('Portable SQL member could not be inventoried.'); }
        return ['path' => $relative, 'sha256' => $hash, 'bytes' => $bytes];
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

    private static function tableNames(PDO $pdo)
    {
        $tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        sort($tables, SORT_STRING);
        return $tables;
    }

    private static function assertTransactionalTables(PDO $pdo, array $tables)
    {
        $engines = $pdo->query("SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_KEY_PAIR);
        ksort($engines, SORT_STRING);
        if (array_keys($engines) !== $tables) {
            throw new RuntimeException('SQL snapshot table inventory changed before the consistent read.');
        }
        foreach ($engines as $name => $engine) {
            if ($engine !== 'InnoDB') {
                throw new RuntimeException('SQL snapshot requires transactional InnoDB tables: ' . $name . '.');
            }
        }
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

    public static function assertSupportedSourceVersion($version)
    {
        if (!is_string($version) || stripos($version, 'mariadb') !== false
            || !preg_match('/\\A(\\d+\\.\\d+\\.\\d+)/', $version, $match)
            || !(version_compare($match[1], '5.7.44', '>=') && version_compare($match[1], '5.8.0', '<')
                || version_compare($match[1], '8.4.0', '>=') && version_compare($match[1], '9.0.0', '<'))) {
            throw new RuntimeException('Current-baseline backup requires tested MySQL 5.7.44+ or MySQL 8.4.x source.');
        }
        return $version;
    }

    private static function assertIdentifier($name)
    {
        if (!is_string($name) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name)) {
            throw new RuntimeException('SQL snapshot contains an invalid database identifier.');
        }
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
