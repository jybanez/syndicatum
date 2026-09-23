<?php

/**
 * Syndicatum Kickstart
 *
 * Download this protected resource as kickstart.php, place it beside a .syndicatum-backup artifact, open it in a
 * browser, and restore the authenticated portable package into this directory.
 * No installed Syndicatum code or historical migration is required.
 */
final class SyndicatumKickstart
{
    const MAGIC = "SYNDICATUM-CURRENT-BACKUP\0";
    const ENVELOPE_CONTRACT = 'syndicatum-current-baseline-backup-envelope';
    const ENVELOPE_VERSION = '1.0';
    const ALGORITHM = 'aes-256-gcm-chunked-v1';
    const MANIFEST_CONTRACT = 'syndicatum-portable-backup';
    const MANIFEST_VERSION = '2.0';
    const LEGACY_MANIFEST_VERSION = '1.0';
    const MAX_HEADER_BYTES = 65536;
    const MAX_MANIFEST_BYTES = 2097152;

    public static function inspect($artifactPath, $encodedKey)
    {
        $stage = self::stage();
        try {
            $key = self::key($encodedKey); $auth = self::beginAuthentication($artifactPath, $key, $stage);
            $header = $auth['header']; $input = fopen($auth['artifact'], 'rb');
            if (!is_resource($input) || fseek($input, $auth['input_offset']) !== 0) { throw new RuntimeException('Backup inspection could not begin.'); }
            try {
                $plain = ''; $written = 0; $index = 0; $needed = 4 + $header['manifest_size'];
                $digest = hash('sha256', $auth['header_json'], true); $noncePrefix = base64_decode((string) $header['nonce_prefix'], true);
                while (strlen($plain) < $needed && $index < $header['frame_count']) {
                    $length = self::uint32(self::readExact($input, 4)); $expected = min($header['chunk_size'], $header['plaintext_size'] - $written);
                    if ($length !== $expected || $length < 1) { throw new InvalidArgumentException('Backup envelope frame length is invalid.'); }
                    $tag = self::readExact($input, 16); $cipher = self::readExact($input, $length);
                    $value = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $noncePrefix . pack('N', $index), $tag,
                        $digest . pack('N2', $index, $header['frame_count']));
                    if (!is_string($value) || strlen($value) !== $length) { throw new InvalidArgumentException('Backup envelope authentication failed.'); }
                    $plain .= $value; $written += $length; $index++;
                }
                if (strlen($plain) < $needed || self::uint32(substr($plain, 0, 4)) !== $header['manifest_size']) { throw new InvalidArgumentException('Backup manifest is truncated.'); }
                $manifestJson = substr($plain, 4, $header['manifest_size']);
                if (!hash_equals((string) $header['manifest_sha256'], hash('sha256', $manifestJson))) { throw new InvalidArgumentException('Backup manifest digest is invalid.'); }
                return self::manifest($manifestJson);
            } finally { fclose($input); }
        } finally { self::removeTree($stage); }
    }

    public static function restore($artifactPath, $encodedKey, array $database, $privateDirectory, $replaceExisting)
    {
        $state = self::beginRestore($artifactPath, $encodedKey, $database, $privateDirectory, $replaceExisting);
        try {
            do { $progress = self::restoreStep($state); } while (!$progress['done']);
            return $progress['result'];
        } catch (Throwable $error) {
            self::rollbackFiles($state['changes']);
            self::removeTree($state['stage']);
            throw $error;
        }
    }

    public static function beginRestore($artifactPath, $encodedKey, array $database, $privateDirectory, $replaceExisting)
    {
        self::databaseInput($database);
        $privateDirectory = self::absoluteDirectory($privateDirectory, 'Private configuration directory');
        $target = realpath(__DIR__);
        if (!is_string($target) || self::inside($privateDirectory, $target)) {
            throw new InvalidArgumentException('Private configuration directory must be outside the Syndicatum web directory.');
        }
        $stage = self::stage();
        try {
            $opened = self::decrypt($artifactPath, self::key($encodedKey), $stage);
            $manifest = self::manifest($opened['manifest_json']);
            self::extractArchive($opened['archive_path'], $manifest, $stage . DIRECTORY_SEPARATOR . 'payload', false);
            $pdo = self::connect($database);
            $tables = array_keys($manifest['sql']['row_counts']);
            $existing = self::existingTables($pdo, $tables);
            if ($existing !== [] && !$replaceExisting) {
                throw new RuntimeException('The selected database already contains Syndicatum tables. Confirm replacement to continue.');
            }
            $rollback = $stage . DIRECTORY_SEPARATOR . 'rollback';
            if (!mkdir($rollback, 0700, true)) { throw new RuntimeException('Restore rollback directory could not be created.'); }
            $data = $manifest['format_version'] === self::MANIFEST_VERSION ? $manifest['sql']['data'] : [$manifest['sql']];
            return [
                'stage' => $stage, 'archive' => $opened['archive_path'], 'manifest' => $manifest,
                'database' => $database, 'private_directory' => $privateDirectory, 'target' => $target,
                'rollback' => $rollback, 'changes' => [], 'existing' => $existing,
                'phase' => 'prepare', 'cursor' => 0, 'data_count' => count($data), 'done_units' => 0,
                'total_units' => 6 + (int) ceil(count($manifest['runtime']) / 25)
                    + (int) ceil(count($manifest['persistent_files']) / 25) + count($data),
            ];
        } catch (Throwable $error) { self::removeTree($stage); throw $error; }
    }

    public static function beginSteppedRestore($artifactPath, $encodedKey, array $expectedManifest, array $database, $privateDirectory, $replaceExisting)
    {
        self::databaseInput($database);
        $privateDirectory = self::absoluteDirectory($privateDirectory, 'Private configuration directory');
        $target = realpath(__DIR__);
        if (!is_string($target) || self::inside($privateDirectory, $target)) {
            throw new InvalidArgumentException('Private configuration directory must be outside the Syndicatum web directory.');
        }
        $pdo = self::connect($database); $tables = array_keys($expectedManifest['sql']['row_counts']);
        $existing = self::existingTables($pdo, $tables);
        if ($existing !== [] && !$replaceExisting) { throw new RuntimeException('The selected database already contains Syndicatum tables. Confirm replacement to continue.'); }
        $stage = self::stage();
        try {
            $auth = self::beginAuthentication($artifactPath, self::key($encodedKey), $stage);
            $rollback = $stage . DIRECTORY_SEPARATOR . 'rollback';
            if (!mkdir($rollback, 0700, true)) { throw new RuntimeException('Restore rollback directory could not be created.'); }
            $data = $expectedManifest['format_version'] === self::MANIFEST_VERSION ? $expectedManifest['sql']['data'] : [$expectedManifest['sql']];
            return ['stage' => $stage, 'archive' => null, 'manifest' => $expectedManifest, 'database' => $database,
                'private_directory' => $privateDirectory, 'target' => $target, 'rollback' => $rollback, 'changes' => [],
                'existing' => $existing, 'phase' => 'authenticate', 'cursor' => 0, 'data_count' => count($data),
                'auth' => $auth, 'done_units' => 0,
                'total_units' => (int) ceil($auth['header']['frame_count'] / 8) + 6
                    + (int) ceil(count($expectedManifest['runtime']) / 25)
                    + (int) ceil(count($expectedManifest['persistent_files']) / 25) + count($data)];
        } catch (Throwable $error) { self::removeTree($stage); throw $error; }
    }

    public static function restoreStep(array &$state)
    {
        foreach (['stage','archive','manifest','database','private_directory','target','rollback','changes','phase','cursor','done_units','total_units'] as $key) {
            if (!array_key_exists($key, $state)) { throw new InvalidArgumentException('Restore checkpoint is invalid.'); }
        }
        $stageRequired = !in_array($state['phase'], ['cleanup','complete'], true);
        if (($stageRequired && !is_dir($state['stage']))
            || ($stageRequired && $state['phase'] !== 'authenticate' && !is_file($state['archive']))) {
            throw new RuntimeException('Restore checkpoint staging is unavailable.');
        }
        $manifest = $state['manifest'];
        $phase = $state['phase']; $detail = '';
        if ($phase === 'authenticate') {
            $complete = self::authenticationStep($state);
            $detail = 'Authenticated package frame ' . $state['auth']['frame_index'] . ' of ' . $state['auth']['header']['frame_count'] . '.';
            if ($complete) { $state['phase'] = 'prepare'; $state['cursor'] = 0; $detail = 'Backup package authenticated and inventoried.'; }
        } else {
        $pdo = self::connect($state['database']);
        if ($phase === 'prepare') {
            if ($state['existing'] !== []) { self::dropObjects($pdo, array_keys($manifest['sql']['row_counts'])); }
            $state['phase'] = 'schema'; $state['cursor'] = 0; $detail = 'Database destination prepared.';
        } elseif ($phase === 'schema') {
            $entry = $manifest['format_version'] === self::MANIFEST_VERSION ? $manifest['sql']['schema'] : null;
            if ($entry !== null) {
                self::dropObjects($pdo, array_keys($manifest['sql']['row_counts']));
                $pdo->exec('DROP TABLE IF EXISTS `_syndicatum_restore_checkpoints`');
                self::importMemberSql($pdo, $state, $entry, false);
                $pdo->exec("CREATE TABLE IF NOT EXISTS `_syndicatum_restore_checkpoints` (`unit_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `completed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`unit_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
            $state['phase'] = 'runtime'; $state['cursor'] = 0; $detail = 'Database schema installed.';
        } elseif ($phase === 'runtime') {
            self::installMemberBatch($state, $manifest['runtime'], 'runtime', 25);
            $detail = 'Production runtime files ' . min($state['cursor'], count($manifest['runtime'])) . ' of ' . count($manifest['runtime']) . '.';
            if ($state['cursor'] >= count($manifest['runtime'])) { $state['phase'] = 'configuration'; $state['cursor'] = 0; }
        } elseif ($phase === 'configuration') {
            $payload = $state['stage'] . DIRECTORY_SEPARATOR . 'configuration';
            if (!is_dir($payload) && !mkdir($payload, 0700, true)) { throw new RuntimeException('Configuration stage could not be created.'); }
            foreach ($manifest['configuration'] as $entry) { self::extractMember($state['archive'], $entry, $payload . DIRECTORY_SEPARATOR . basename($entry['path'])); }
            self::installConfigurationFlat($payload, $manifest['configuration'], $state['database'], $state['private_directory'], $state['rollback'], $state['changes']);
            $state['phase'] = 'persistent'; $state['cursor'] = 0; $detail = 'Protected configuration installed.';
        } elseif ($phase === 'persistent') {
            self::installMemberBatch($state, $manifest['persistent_files'], 'persistent', 25);
            $detail = 'Persistent files ' . min($state['cursor'], count($manifest['persistent_files'])) . ' of ' . count($manifest['persistent_files']) . '.';
            if ($state['cursor'] >= count($manifest['persistent_files'])) { $state['phase'] = 'data'; $state['cursor'] = 0; }
        } elseif ($phase === 'data') {
            $data = $manifest['format_version'] === self::MANIFEST_VERSION ? $manifest['sql']['data'] : [$manifest['sql']];
            if ($state['cursor'] < count($data)) {
                $checkpoint = $manifest['format_version'] === self::MANIFEST_VERSION ? $data[$state['cursor']]['path'] : null;
                self::importMemberSql($pdo, $state, $data[$state['cursor']], $manifest['format_version'] === self::MANIFEST_VERSION, false, $checkpoint); $state['cursor']++;
                $detail = 'Database batch ' . $state['cursor'] . ' of ' . count($data) . '.';
            }
            if ($state['cursor'] >= count($data)) { $state['phase'] = 'triggers'; $state['cursor'] = 0; }
        } elseif ($phase === 'triggers') {
            if ($manifest['format_version'] === self::MANIFEST_VERSION) {
                self::dropTriggers($pdo, array_keys($manifest['sql']['row_counts']));
                self::importMemberSql($pdo, $state, $manifest['sql']['triggers'], false, true);
            }
            $state['phase'] = 'verify'; $state['cursor'] = 0; $detail = 'Database triggers installed.';
        } elseif ($phase === 'verify') {
            self::verifyRows($pdo, $manifest['sql']['row_counts']);
            if ($manifest['format_version'] === self::MANIFEST_VERSION) { $pdo->exec('DROP TABLE IF EXISTS `_syndicatum_restore_checkpoints`'); }
            $state['phase'] = 'cleanup'; $state['cursor'] = 0; $detail = 'Restored database verified.';
        } elseif ($phase === 'cleanup') {
            $result = ['package_type' => $manifest['package_type'], 'runtime_files' => count($manifest['runtime']),
                'persistent_files' => count($manifest['persistent_files']), 'tables' => count($manifest['sql']['row_counts']),
                'target' => $state['target'], 'private_directory' => $state['private_directory']];
            self::removeTree($state['stage']); $state['phase'] = 'complete'; $state['result'] = $result;
            return ['done' => true, 'phase' => 'Complete', 'phase_percent' => 100, 'overall_percent' => 100, 'detail' => 'Restoration completed and verified.', 'result' => $result];
        } elseif ($phase === 'complete') {
            return ['done' => true, 'phase' => 'Complete', 'phase_percent' => 100, 'overall_percent' => 100, 'detail' => 'Restoration completed and verified.', 'result' => $state['result']];
        } else { throw new InvalidArgumentException('Restore checkpoint phase is invalid.'); }
        }
        $state['done_units']++;
        $percent = min(99, (int) floor(100 * $state['done_units'] / max(1, $state['total_units'])));
        return ['done' => false, 'phase' => self::phaseLabel($state['phase']), 'phase_percent' => self::phasePercent($state), 'overall_percent' => $percent, 'detail' => $detail];
    }

    private static function beginAuthentication($artifactPath, $key, $stage)
    {
        if (!is_file($artifactPath) || is_link($artifactPath)) { throw new InvalidArgumentException('Backup artifact is unavailable.'); }
        $input = fopen($artifactPath, 'rb');
        if (!is_resource($input)) { throw new RuntimeException('Backup artifact could not be opened.'); }
        try {
            if (self::readExact($input, strlen(self::MAGIC)) !== self::MAGIC) { throw new InvalidArgumentException('Backup envelope signature is invalid.'); }
            $headerLength = self::uint32(self::readExact($input, 4));
            if ($headerLength < 2 || $headerLength > self::MAX_HEADER_BYTES) { throw new InvalidArgumentException('Backup envelope header is invalid.'); }
            $headerJson = self::readExact($input, $headerLength); $header = json_decode($headerJson, true, 32, JSON_BIGINT_AS_STRING);
            $keys = ['contract_name','format_version','algorithm','key_id','created_at','manifest_sha256','archive_sha256','manifest_size','archive_size','plaintext_size','chunk_size','frame_count','nonce_prefix'];
            if (!is_array($header) || array_keys($header) !== $keys || json_encode($header, JSON_UNESCAPED_SLASHES) !== $headerJson
                || $header['contract_name'] !== self::ENVELOPE_CONTRACT || $header['format_version'] !== self::ENVELOPE_VERSION
                || $header['algorithm'] !== self::ALGORITHM || !hash_equals(substr(hash('sha256', $key), 0, 24), (string) $header['key_id'])) {
                throw new InvalidArgumentException('Backup recovery key or envelope contract is invalid.');
            }
            foreach (['manifest_size','archive_size','plaintext_size','chunk_size','frame_count'] as $field) {
                if (!is_int($header[$field]) || $header[$field] < 1) { throw new InvalidArgumentException('Backup envelope size is invalid.'); }
            }
            if ($header['manifest_size'] > self::MAX_MANIFEST_BYTES || $header['plaintext_size'] !== 4 + $header['manifest_size'] + $header['archive_size']
                || $header['frame_count'] !== (int) ceil($header['plaintext_size'] / $header['chunk_size'])) { throw new InvalidArgumentException('Backup envelope sizes are inconsistent.'); }
            $noncePrefix = base64_decode((string) $header['nonce_prefix'], true);
            if (!is_string($noncePrefix) || strlen($noncePrefix) !== 8) { throw new InvalidArgumentException('Backup envelope nonce is invalid.'); }
            $manifestPath = $stage . DIRECTORY_SEPARATOR . 'authenticated.manifest';
            $archivePath = $stage . DIRECTORY_SEPARATOR . 'backup.zip';
            $manifestOut = fopen($manifestPath, 'xb'); $archiveOut = fopen($archivePath, 'xb');
            if (!is_resource($manifestOut) || !is_resource($archiveOut)) {
                if (is_resource($manifestOut)) fclose($manifestOut); if (is_resource($archiveOut)) fclose($archiveOut);
                throw new RuntimeException('Restore staging files could not be created.');
            }
            fclose($manifestOut); fclose($archiveOut); @chmod($manifestPath, 0600); @chmod($archivePath, 0600);
            return ['artifact' => realpath($artifactPath), 'key' => base64_encode($key), 'header' => $header, 'header_json' => $headerJson,
                'input_offset' => ftell($input), 'frame_index' => 0, 'written' => 0,
                'manifest_path' => $manifestPath, 'archive_path' => $archivePath];
        } finally { fclose($input); }
    }

    private static function authenticationStep(array &$state)
    {
        $auth =& $state['auth']; $header = $auth['header']; $key = base64_decode($auth['key'], true);
        $noncePrefix = base64_decode((string) $header['nonce_prefix'], true);
        $prefixBytes = 4 + $header['manifest_size']; $manifestWritten = min($auth['written'], $prefixBytes);
        $archiveWritten = max(0, $auth['written'] - $prefixBytes);
        $input = fopen($auth['artifact'], 'rb'); $manifestOut = fopen($auth['manifest_path'], 'c+b'); $archiveOut = fopen($auth['archive_path'], 'c+b');
        if (!is_resource($input) || !is_resource($manifestOut) || !is_resource($archiveOut)
            || fseek($input, $auth['input_offset']) !== 0
            || !ftruncate($manifestOut, $manifestWritten) || fseek($manifestOut, $manifestWritten) !== 0
            || !ftruncate($archiveOut, $archiveWritten) || fseek($archiveOut, $archiveWritten) !== 0) {
            if (is_resource($input)) fclose($input); if (is_resource($manifestOut)) fclose($manifestOut); if (is_resource($archiveOut)) fclose($archiveOut);
            throw new RuntimeException('Restore authentication checkpoint could not be resumed.');
        }
        try {
            $stop = min($header['frame_count'], $auth['frame_index'] + 8); $digest = hash('sha256', $auth['header_json'], true);
            while ($auth['frame_index'] < $stop) {
                $index = $auth['frame_index']; $length = self::uint32(self::readExact($input, 4));
                $expected = min($header['chunk_size'], $header['plaintext_size'] - $auth['written']);
                if ($length !== $expected || $length < 1) { throw new InvalidArgumentException('Backup envelope frame length is invalid.'); }
                $tag = self::readExact($input, 16); $cipher = self::readExact($input, $length);
                $value = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $noncePrefix . pack('N', $index), $tag,
                    $digest . pack('N2', $index, $header['frame_count']));
                if (!is_string($value) || strlen($value) !== $length) { throw new InvalidArgumentException('Backup envelope authentication failed.'); }
                $offset = 0;
                if ($auth['written'] < $prefixBytes) {
                    $take = min(strlen($value), $prefixBytes - $auth['written']);
                    self::writeAll($manifestOut, substr($value, 0, $take)); $offset = $take;
                }
                if ($offset < strlen($value)) { self::writeAll($archiveOut, substr($value, $offset)); }
                $auth['written'] += $length; $auth['frame_index']++;
            }
            $auth['input_offset'] = ftell($input);
            if ($auth['frame_index'] < $header['frame_count']) { return false; }
            if ($auth['written'] !== $header['plaintext_size'] || fread($input, 1) !== '') { throw new InvalidArgumentException('Backup envelope is truncated or has trailing data.'); }
        } finally {
            if (is_resource($input)) fclose($input); if (is_resource($manifestOut)) fclose($manifestOut); if (is_resource($archiveOut)) fclose($archiveOut);
        }
        $manifestIn = fopen($auth['manifest_path'], 'rb');
        if (!is_resource($manifestIn)) { throw new RuntimeException('Authenticated manifest stage could not be read.'); }
        try {
            $manifestLength = self::uint32(self::readExact($manifestIn, 4)); $manifestJson = self::readExact($manifestIn, $manifestLength);
            if ($manifestLength !== $header['manifest_size'] || !hash_equals((string) $header['manifest_sha256'], hash('sha256', $manifestJson))) {
                throw new InvalidArgumentException('Backup manifest digest is invalid.');
            }
            $actual = self::manifest($manifestJson);
            if (json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== json_encode($state['manifest'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) {
                throw new InvalidArgumentException('Backup manifest changed after inspection.');
            }
            clearstatcache(true, $auth['archive_path']);
            if (filesize($auth['archive_path']) !== $header['archive_size']) { throw new InvalidArgumentException('Backup archive size is invalid.'); }
            self::extractArchive($auth['archive_path'], $actual, $state['stage'] . DIRECTORY_SEPARATOR . 'payload', false);
            $state['archive'] = $auth['archive_path'];
        } finally { fclose($manifestIn); @unlink($auth['manifest_path']); }
        unset($auth['key']); return true;
    }

    private static function installMemberBatch(array &$state, array $entries, $kind, $limit)
    {
        $processed = 0; $bytes = 0; $byteLimit = 16777216;
        while ($state['cursor'] < count($entries) && $processed < $limit) {
            $entry = $entries[$state['cursor']];
            if ($processed > 0 && $bytes + $entry['bytes'] > $byteLimit) { break; }
            $temporary = $state['stage'] . DIRECTORY_SEPARATOR . 'member-' . hash('sha256', $entry['path']);
            self::extractMember($state['archive'], $entry, $temporary);
            try {
                if ($kind === 'runtime') {
                    $relative = substr($entry['path'], strlen('runtime/'));
                    $destination = $state['target'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                } else {
                    $destination = $state['private_directory'] . DIRECTORY_SEPARATOR . 'syndicatum-avatars' . DIRECTORY_SEPARATOR . basename($entry['path']);
                }
                self::installFile($temporary, $destination, $state['rollback'], $state['changes']);
            } finally { @unlink($temporary); }
            $bytes += $entry['bytes']; $processed++; $state['cursor']++;
        }
    }

    private static function extractMember($archivePath, array $entry, $destination)
    {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('PHP ZIP support is required.'); }
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) { throw new InvalidArgumentException('Backup ZIP is invalid.'); }
        try {
            $stat = $zip->statName($entry['path']);
            if (!is_array($stat) || (int) $stat['size'] !== $entry['bytes']) { throw new InvalidArgumentException('Backup ZIP member inventory changed.'); }
            $stream = $zip->getStream($entry['path']);
            if (!is_resource($stream)) { throw new RuntimeException('Backup ZIP member could not be read.'); }
            $out = @fopen($destination, 'xb');
            if (!is_resource($out)) { fclose($stream); throw new RuntimeException('Restore member stage could not be created.'); }
            @chmod($destination, 0600); $hash = hash_init('sha256'); $bytes = 0;
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || ($chunk === '' && !feof($stream))) { throw new RuntimeException('Backup ZIP member read failed.'); }
                    $bytes += strlen($chunk); hash_update($hash, $chunk); self::writeAll($out, $chunk);
                }
            } finally { fclose($stream); fclose($out); }
            if ($bytes !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_final($hash))) {
                @unlink($destination); throw new InvalidArgumentException('Backup ZIP member digest is invalid.');
            }
        } finally { $zip->close(); }
    }

    private static function importMemberSql(PDO $pdo, array $state, array $entry, $transaction, $allowEmpty = false, $checkpoint = null)
    {
        if ($checkpoint !== null) {
            $check = $pdo->prepare('SELECT sha256 FROM `_syndicatum_restore_checkpoints` WHERE unit_id = ?');
            $check->execute([$checkpoint]); $completed = $check->fetchColumn();
            if (is_string($completed)) {
                if (!hash_equals($entry['sha256'], $completed)) { throw new RuntimeException('A restore checkpoint conflicts with this backup package.'); }
                return;
            }
        }
        $temporary = $state['stage'] . DIRECTORY_SEPARATOR . 'sql-' . hash('sha256', $entry['path']);
        self::extractMember($state['archive'], $entry, $temporary);
        try { self::importSql($pdo, $temporary, $transaction, $allowEmpty, $checkpoint, $entry['sha256']); } finally { @unlink($temporary); }
    }

    private static function installConfigurationFlat($payload, array $entries, array $database, $privateDirectory, $rollback, array &$changes)
    {
        foreach ($entries as $entry) {
            $source = $payload . DIRECTORY_SEPARATOR . basename($entry['path']);
            $document = json_decode(file_get_contents($source), true);
            if (!is_array($document) || array_keys($document) !== ['values'] || !is_array($document['values'])) { throw new InvalidArgumentException('Protected configuration payload is invalid.'); }
            self::installPhpArray($document['values'], $privateDirectory . DIRECTORY_SEPARATOR . basename($entry['path'], '.json') . '.php', $rollback, $changes);
        }
        self::installPhpArray(['PBB_AGENTCHAT_DB_HOST' => $database['host'], 'PBB_AGENTCHAT_DB_PORT' => (string) $database['port'],
            'PBB_AGENTCHAT_DB_NAME' => $database['name'], 'PBB_AGENTCHAT_DB_USER' => $database['user'], 'PBB_AGENTCHAT_DB_PASS' => $database['password']],
            $privateDirectory . DIRECTORY_SEPARATOR . 'syndicatum-database.php', $rollback, $changes);
        $deny = $payload . DIRECTORY_SEPARATOR . 'deny.htaccess'; file_put_contents($deny, "Require all denied\nDeny from all\n");
        self::installFile($deny, $privateDirectory . DIRECTORY_SEPARATOR . '.htaccess', $rollback, $changes, 0600);
    }

    private static function verifyRows(PDO $pdo, array $expected)
    {
        foreach ($expected as $table => $count) {
            if ((int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn() !== $count) {
                throw new RuntimeException('Restored row count differs for table ' . $table . '.');
            }
        }
    }

    private static function phaseLabel($phase)
    {
        $labels = ['authenticate' => 'Authenticating backup package', 'prepare' => 'Preparing restoration', 'schema' => 'Installing database schema', 'runtime' => 'Restoring production runtime',
            'configuration' => 'Restoring configuration', 'persistent' => 'Restoring persistent files', 'data' => 'Importing database',
            'triggers' => 'Installing database triggers', 'verify' => 'Verifying restoration', 'cleanup' => 'Cleaning temporary files', 'complete' => 'Complete'];
        return $labels[$phase] ?? 'Restoring Syndicatum';
    }

    private static function phasePercent(array $state)
    {
        $manifest = $state['manifest'];
        if ($state['phase'] === 'authenticate') { return (int) floor(100 * $state['auth']['frame_index'] / max(1, $state['auth']['header']['frame_count'])); }
        if ($state['phase'] === 'runtime') { return (int) floor(100 * $state['cursor'] / max(1, count($manifest['runtime']))); }
        if ($state['phase'] === 'persistent') { return (int) floor(100 * $state['cursor'] / max(1, count($manifest['persistent_files']))); }
        if ($state['phase'] === 'data') { return (int) floor(100 * $state['cursor'] / max(1, $state['data_count'])); }
        return 0;
    }

    private static function decrypt($artifactPath, $key, $stage)
    {
        if (!is_file($artifactPath) || is_link($artifactPath)) { throw new InvalidArgumentException('Backup artifact is unavailable.'); }
        $input = fopen($artifactPath, 'rb');
        if (!is_resource($input)) { throw new RuntimeException('Backup artifact could not be opened.'); }
        try {
            if (self::readExact($input, strlen(self::MAGIC)) !== self::MAGIC) { throw new InvalidArgumentException('Backup envelope signature is invalid.'); }
            $headerLength = self::uint32(self::readExact($input, 4));
            if ($headerLength < 2 || $headerLength > self::MAX_HEADER_BYTES) { throw new InvalidArgumentException('Backup envelope header is invalid.'); }
            $headerJson = self::readExact($input, $headerLength);
            $header = json_decode($headerJson, true, 32, JSON_BIGINT_AS_STRING);
            $keys = ['contract_name','format_version','algorithm','key_id','created_at','manifest_sha256','archive_sha256','manifest_size','archive_size','plaintext_size','chunk_size','frame_count','nonce_prefix'];
            if (!is_array($header) || array_keys($header) !== $keys || json_encode($header, JSON_UNESCAPED_SLASHES) !== $headerJson
                || $header['contract_name'] !== self::ENVELOPE_CONTRACT || $header['format_version'] !== self::ENVELOPE_VERSION
                || $header['algorithm'] !== self::ALGORITHM || !hash_equals(substr(hash('sha256', $key), 0, 24), (string) $header['key_id'])) {
                throw new InvalidArgumentException('Backup recovery key or envelope contract is invalid.');
            }
            foreach (['manifest_size','archive_size','plaintext_size','chunk_size','frame_count'] as $field) {
                if (!is_int($header[$field]) || $header[$field] < 1) { throw new InvalidArgumentException('Backup envelope size is invalid.'); }
            }
            if ($header['manifest_size'] > self::MAX_MANIFEST_BYTES
                || $header['plaintext_size'] !== 4 + $header['manifest_size'] + $header['archive_size']
                || $header['frame_count'] !== (int) ceil($header['plaintext_size'] / $header['chunk_size'])) {
                throw new InvalidArgumentException('Backup envelope sizes are inconsistent.');
            }
            $noncePrefix = base64_decode((string) $header['nonce_prefix'], true);
            if (!is_string($noncePrefix) || strlen($noncePrefix) !== 8) { throw new InvalidArgumentException('Backup envelope nonce is invalid.'); }
            $plainPath = $stage . DIRECTORY_SEPARATOR . 'authenticated.plaintext';
            $plain = fopen($plainPath, 'xb');
            if (!is_resource($plain)) { throw new RuntimeException('Restore staging file could not be created.'); }
            @chmod($plainPath, 0600);
            try {
                $digest = hash('sha256', $headerJson, true); $written = 0;
                for ($index = 0; $index < $header['frame_count']; $index++) {
                    $length = self::uint32(self::readExact($input, 4));
                    $expected = min($header['chunk_size'], $header['plaintext_size'] - $written);
                    if ($length !== $expected || $length < 1) { throw new InvalidArgumentException('Backup envelope frame length is invalid.'); }
                    $tag = self::readExact($input, 16); $cipher = self::readExact($input, $length);
                    $value = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                        $noncePrefix . pack('N', $index), $tag, $digest . pack('N2', $index, $header['frame_count']));
                    if (!is_string($value) || strlen($value) !== $length) { throw new InvalidArgumentException('Backup envelope authentication failed.'); }
                    self::writeAll($plain, $value); $written += $length;
                }
                if ($written !== $header['plaintext_size'] || fread($input, 1) !== '') { throw new InvalidArgumentException('Backup envelope is truncated or has trailing data.'); }
            } finally { fclose($plain); }
            $plain = fopen($plainPath, 'rb');
            try {
                $manifestLength = self::uint32(self::readExact($plain, 4));
                if ($manifestLength !== $header['manifest_size']) { throw new InvalidArgumentException('Backup manifest length is invalid.'); }
                $manifestJson = self::readExact($plain, $manifestLength);
                if (!hash_equals((string) $header['manifest_sha256'], hash('sha256', $manifestJson))) { throw new InvalidArgumentException('Backup manifest digest is invalid.'); }
                $archivePath = $stage . DIRECTORY_SEPARATOR . 'backup.zip';
                $archive = fopen($archivePath, 'xb'); $hash = hash_init('sha256'); $bytes = 0;
                try {
                    while (!feof($plain)) {
                        $chunk = fread($plain, 1048576);
                        if ($chunk === false) { throw new RuntimeException('Backup archive could not be read.'); }
                        if ($chunk === '') { break; }
                        self::writeAll($archive, $chunk); hash_update($hash, $chunk); $bytes += strlen($chunk);
                    }
                } finally { fclose($archive); }
                if ($bytes !== $header['archive_size'] || !hash_equals((string) $header['archive_sha256'], hash_final($hash))) {
                    throw new InvalidArgumentException('Backup archive digest is invalid.');
                }
            } finally { fclose($plain); @unlink($plainPath); }
            return ['manifest_json' => $manifestJson, 'archive_path' => $archivePath];
        } finally { fclose($input); }
    }

    private static function manifest($json)
    {
        $manifest = json_decode($json, true, 64, JSON_BIGINT_AS_STRING);
        $keys = ['contract_name','format_version','created_at','operation_id','package_type','include_data','source_database','source_mysql_version','source_application_version','source_commit','source_installation_id','sql','runtime','persistent_files','configuration'];
        if (!is_array($manifest) || array_keys($manifest) !== $keys || json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== $json
            || $manifest['contract_name'] !== self::MANIFEST_CONTRACT
            || !in_array($manifest['format_version'], [self::MANIFEST_VERSION, self::LEGACY_MANIFEST_VERSION], true)
            || !in_array($manifest['package_type'], ['full_clone','clean_installation'], true)
            || $manifest['include_data'] !== ($manifest['package_type'] === 'full_clone')) {
            throw new InvalidArgumentException('Backup portable manifest is invalid.');
        }
        if (!is_array($manifest['sql']) || !isset($manifest['sql']['row_counts'])
            || !is_array($manifest['sql']['row_counts']) || $manifest['sql']['row_counts'] === []) {
            throw new InvalidArgumentException('Backup database baseline is invalid.');
        }
        if (!isset($manifest['sql']['table_count'], $manifest['sql']['trigger_count'], $manifest['sql']['row_hashes'])
            || !is_int($manifest['sql']['table_count']) || $manifest['sql']['table_count'] < 1
            || !is_int($manifest['sql']['trigger_count']) || $manifest['sql']['trigger_count'] < 0
            || count($manifest['sql']['row_counts']) !== $manifest['sql']['table_count']
            || !is_array($manifest['sql']['row_hashes']) || array_keys($manifest['sql']['row_hashes']) !== array_keys($manifest['sql']['row_counts'])) {
            throw new InvalidArgumentException('Backup database inventory is invalid.');
        }
        $tableNames = array_keys($manifest['sql']['row_counts']); $sortedTableNames = $tableNames; sort($sortedTableNames, SORT_STRING);
        if ($tableNames !== $sortedTableNames) { throw new InvalidArgumentException('Backup database table inventory is not canonical.'); }
        foreach ($tableNames as $table) {
            if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $table) || !is_int($manifest['sql']['row_counts'][$table])
                || $manifest['sql']['row_counts'][$table] < 0 || !is_string($manifest['sql']['row_hashes'][$table])
                || !preg_match('/\A[a-f0-9]{64}\z/', $manifest['sql']['row_hashes'][$table])) {
                throw new InvalidArgumentException('Backup database table entry is invalid.');
            }
        }
        if ($manifest['format_version'] === self::LEGACY_MANIFEST_VERSION) {
            if (($manifest['sql']['path'] ?? '') !== 'database/baseline.sql') { throw new InvalidArgumentException('Backup database baseline is invalid.'); }
            self::entry($manifest['sql'], '#\Adatabase/baseline\.sql\z#');
        } else {
            if (array_keys($manifest['sql']) !== ['schema','data','triggers','table_count','trigger_count','row_counts','row_hashes']) {
                throw new InvalidArgumentException('Backup database inventory layout is invalid.');
            }
            self::entry($manifest['sql']['schema'], '#\Adatabase/schema\.sql\z#');
            self::entry($manifest['sql']['triggers'], '#\Adatabase/triggers\.sql\z#');
            if (!is_array($manifest['sql']['data']) || array_values($manifest['sql']['data']) !== $manifest['sql']['data']) {
                throw new InvalidArgumentException('Backup database batch inventory is invalid.');
            }
            $last = ''; $sequences = []; $totals = array_fill_keys(array_keys($manifest['sql']['row_counts']), 0);
            foreach ($manifest['sql']['data'] as $entry) {
                self::entry($entry, '#\Adatabase/data/[a-z][a-z0-9_]{0,63}/[0-9]{6}\.sql\z#');
                $table = (string) ($entry['table'] ?? ''); $sequence = $entry['sequence'] ?? null; $rows = $entry['rows'] ?? null;
                if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $table) || !is_int($sequence) || $sequence < 1
                    || !is_int($rows) || $rows < 1 || !array_key_exists($table, $totals) || strcmp($entry['path'], $last) <= 0
                    || $entry['path'] !== 'database/data/' . $table . '/' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT) . '.sql'
                    || $sequence !== (($sequences[$table] ?? 0) + 1)) {
                    throw new InvalidArgumentException('Backup database batch entry is invalid.');
                }
                $sequences[$table] = $sequence; $totals[$table] += $rows; $last = $entry['path'];
            }
            if ($totals !== $manifest['sql']['row_counts']) { throw new InvalidArgumentException('Backup database batch row totals are invalid.'); }
        }
        foreach (['runtime' => '#\Aruntime/[A-Za-z0-9_.@/+\-]+\z#',
            'persistent_files' => '#\Apersistent/avatars/[a-f0-9]{40}\.(?:jpg|png|webp)\z#',
            'configuration' => '#\Aconfiguration/[a-z0-9_.-]+\.json\z#'] as $group => $pattern) {
            if (!is_array($manifest[$group]) || array_values($manifest[$group]) !== $manifest[$group]) { throw new InvalidArgumentException('Backup member inventory is invalid.'); }
            $last = '';
            foreach ($manifest[$group] as $entry) {
                self::entry($entry, $pattern);
                if (strcmp($entry['path'], $last) <= 0 || strpos('/' . $entry['path'] . '/', '/../') !== false) { throw new InvalidArgumentException('Backup member path is invalid.'); }
                $last = $entry['path'];
            }
        }
        return $manifest;
    }

    private static function entry($entry, $pattern)
    {
        if (!is_array($entry) || !isset($entry['path'], $entry['sha256'], $entry['bytes'])
            || !preg_match($pattern, (string) $entry['path']) || !preg_match('/\A[a-f0-9]{64}\z/', (string) $entry['sha256'])
            || !is_int($entry['bytes']) || $entry['bytes'] < 0) { throw new InvalidArgumentException('Backup member identity is invalid.'); }
    }

    private static function entries(array $manifest)
    {
        if ($manifest['format_version'] === self::LEGACY_MANIFEST_VERSION) {
            $entries = [$manifest['sql']['path'] => $manifest['sql']];
        } else {
            $entries = [$manifest['sql']['schema']['path'] => $manifest['sql']['schema'], $manifest['sql']['triggers']['path'] => $manifest['sql']['triggers']];
            foreach ($manifest['sql']['data'] as $entry) { $entries[$entry['path']] = $entry; }
        }
        foreach (['runtime','persistent_files','configuration'] as $group) { foreach ($manifest[$group] as $entry) { $entries[$entry['path']] = $entry; } }
        ksort($entries, SORT_STRING); return $entries;
    }

    private static function extractArchive($archivePath, array $manifest, $payload, $write)
    {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('PHP ZIP support is required.'); }
        if ($write && !mkdir($payload, 0700, true)) { throw new RuntimeException('Restore payload stage could not be created.'); }
        $expected = self::entries($manifest); $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true || $zip->numFiles !== count($expected)) { throw new InvalidArgumentException('Backup ZIP inventory is invalid.'); }
        try {
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name'], $expected[$stat['name']]) || isset($seen[$stat['name']])) { throw new InvalidArgumentException('Backup ZIP contains an undeclared member.'); }
                $entry = $expected[$stat['name']]; $seen[$stat['name']] = true;
                if ((int) $stat['size'] !== $entry['bytes']) { throw new InvalidArgumentException('Backup ZIP member size is invalid.'); }
                if (!$write) { continue; }
                $stream = $zip->getStream($stat['name']);
                if (!is_resource($stream)) { throw new RuntimeException('Backup ZIP member could not be read.'); }
                $out = null; $hash = hash_init('sha256'); $bytes = 0;
                try {
                    if ($write) {
                        $destination = $payload . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stat['name']);
                        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true)) { throw new RuntimeException('Restore payload directory could not be created.'); }
                        $out = fopen($destination, 'xb');
                    }
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false || ($chunk === '' && !feof($stream))) { throw new RuntimeException('Backup ZIP member read failed.'); }
                        $bytes += strlen($chunk); hash_update($hash, $chunk); if (is_resource($out)) { self::writeAll($out, $chunk); }
                    }
                } finally { fclose($stream); if (is_resource($out)) { fclose($out); } }
                if ($bytes !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_final($hash))) { throw new InvalidArgumentException('Backup ZIP member digest is invalid.'); }
            }
        } finally { $zip->close(); }
    }

    private static function installRuntime($payload, array $entries, $target, $rollback, array &$changes)
    {
        foreach ($entries as $entry) {
            $relative = substr($entry['path'], strlen('runtime/'));
            self::installFile($payload . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['path']),
                $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $rollback, $changes);
        }
    }

    private static function installPersistent($payload, array $entries, $privateDirectory, $rollback, array &$changes)
    {
        $root = $privateDirectory . DIRECTORY_SEPARATOR . 'syndicatum-avatars';
        foreach ($entries as $entry) {
            $name = basename($entry['path']);
            self::installFile($payload . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['path']),
                $root . DIRECTORY_SEPARATOR . $name, $rollback, $changes);
        }
        return count($entries);
    }

    private static function installConfiguration($payload, array $entries, array $database, $privateDirectory, $rollback, array &$changes)
    {
        foreach ($entries as $entry) {
            $source = $payload . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
            $document = json_decode(file_get_contents($source), true);
            if (!is_array($document) || array_keys($document) !== ['values'] || !is_array($document['values'])) { throw new InvalidArgumentException('Protected configuration payload is invalid.'); }
            $filename = basename($entry['path'], '.json') . '.php';
            self::installPhpArray($document['values'], $privateDirectory . DIRECTORY_SEPARATOR . $filename, $rollback, $changes);
        }
        self::installPhpArray([
            'PBB_AGENTCHAT_DB_HOST' => $database['host'],
            'PBB_AGENTCHAT_DB_PORT' => (string) $database['port'],
            'PBB_AGENTCHAT_DB_NAME' => $database['name'],
            'PBB_AGENTCHAT_DB_USER' => $database['user'],
            'PBB_AGENTCHAT_DB_PASS' => $database['password'],
        ], $privateDirectory . DIRECTORY_SEPARATOR . 'syndicatum-database.php', $rollback, $changes);
        $deny = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kickstart-deny-' . bin2hex(random_bytes(8));
        file_put_contents($deny, "Require all denied\nDeny from all\n");
        try { self::installFile($deny, $privateDirectory . DIRECTORY_SEPARATOR . '.htaccess', $rollback, $changes, 0600); }
        finally { @unlink($deny); }
    }

    private static function installPhpArray(array $values, $destination, $rollback, array &$changes)
    {
        $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kickstart-config-' . bin2hex(random_bytes(8));
        file_put_contents($temporary, "<?php\nreturn " . var_export($values, true) . ";\n"); @chmod($temporary, 0600);
        try { self::installFile($temporary, $destination, $rollback, $changes, 0600); } finally { @unlink($temporary); }
    }

    private static function installFile($source, $destination, $rollback, array &$changes, $mode = 0644)
    {
        if (!is_file($source) || is_link($source)) { throw new RuntimeException('Staged restore file is invalid.'); }
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) { throw new RuntimeException('Restore destination directory could not be created.'); }
        $backup = null;
        if (is_file($destination) && !is_link($destination)) {
            $backup = $rollback . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12));
            if (!copy($destination, $backup)) { throw new RuntimeException('Existing file could not be protected for rollback.'); }
        } elseif (file_exists($destination)) { throw new RuntimeException('Restore destination contains an unsupported filesystem object.'); }
        $temporary = $destination . '.kickstart-' . bin2hex(random_bytes(5));
        if (!copy($source, $temporary)) { throw new RuntimeException('Restored file could not be staged.'); }
        @chmod($temporary, $mode);
        if (is_file($destination) && !unlink($destination)) { @unlink($temporary); throw new RuntimeException('Existing file could not be replaced.'); }
        if (!rename($temporary, $destination)) { @unlink($temporary); throw new RuntimeException('Restored file could not be committed.'); }
        $changes[] = ['destination' => $destination, 'backup' => $backup];
    }

    private static function rollbackFiles(array $changes)
    {
        foreach (array_reverse($changes) as $change) {
            if (is_file($change['destination'])) { @unlink($change['destination']); }
            if (is_string($change['backup']) && is_file($change['backup'])) { @copy($change['backup'], $change['destination']); }
        }
    }

    private static function connect(array $database)
    {
        try {
            return new PDO('mysql:host=' . $database['host'] . ';port=' . $database['port'] . ';dbname=' . $database['name'] . ';charset=utf8mb4',
                $database['user'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        } catch (Throwable $error) { throw new RuntimeException('Database connection failed. Check the host, port, database name, username, and password.'); }
    }

    private static function databaseInput(array $database)
    {
        foreach (['host','name','user','password'] as $key) { if (!isset($database[$key]) || !is_string($database[$key])) { throw new InvalidArgumentException('Database details are incomplete.'); } }
        if (trim($database['host']) === '' || trim($database['user']) === '' || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/', $database['name'])
            || !isset($database['port']) || filter_var($database['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new InvalidArgumentException('Database details are invalid.');
        }
    }

    private static function existingTables(PDO $pdo, array $tables)
    {
        $found = [];
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        foreach ($tables as $table) { $statement->execute([$table]); if ((int) $statement->fetchColumn() > 0) { $found[] = $table; } }
        return $found;
    }

    private static function dropObjects(PDO $pdo, array $tables)
    {
        $allowed = array_fill_keys($tables, true);
        $triggers = $pdo->query('SELECT trigger_name, event_object_table FROM information_schema.triggers WHERE trigger_schema = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($triggers as $trigger) { if (isset($allowed[$trigger['event_object_table']])) { $pdo->exec('DROP TRIGGER `' . str_replace('`', '``', $trigger['trigger_name']) . '`'); } }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try { foreach (array_reverse($tables) as $table) { $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`'); } }
        finally { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); }
    }

    private static function dropTriggers(PDO $pdo, array $tables)
    {
        $allowed = array_fill_keys($tables, true);
        $triggers = $pdo->query('SELECT trigger_name, event_object_table FROM information_schema.triggers WHERE trigger_schema = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($triggers as $trigger) {
            if (isset($allowed[$trigger['event_object_table']])) { $pdo->exec('DROP TRIGGER `' . str_replace('`', '``', $trigger['trigger_name']) . '`'); }
        }
    }

    private static function importSql(PDO $pdo, $path, $transaction = false, $allowEmpty = false, $checkpoint = null, $checkpointSha = null)
    {
        $sql = file_get_contents($path);
        if (!is_string($sql) || (!$allowEmpty && $sql === '')) { throw new RuntimeException('Database restore unit is empty.'); }
        if ($transaction) { $pdo->exec('SET FOREIGN_KEY_CHECKS = 0'); $pdo->beginTransaction(); }
        try {
            foreach (explode(";\n", str_replace("\r\n", "\n", $sql)) as $statement) {
                $statement = trim($statement);
                if ($statement !== '') { $pdo->exec($statement); }
            }
            if ($checkpoint !== null) {
                $mark = $pdo->prepare('INSERT INTO `_syndicatum_restore_checkpoints` (unit_id, sha256) VALUES (?, ?)');
                $mark->execute([$checkpoint, $checkpointSha]);
            }
            if ($transaction) { $pdo->commit(); }
        } catch (Throwable $error) {
            if ($transaction && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $error;
        } finally { if ($transaction) { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } }
    }

    private static function key($encoded)
    {
        $key = base64_decode(trim((string) $encoded), true);
        if (!is_string($key) || strlen($key) !== 32) { throw new InvalidArgumentException('Backup recovery key must be valid base64 encoding exactly 32 bytes.'); }
        return $key;
    }

    public static function cleanup($installerPath, $artifactFilename)
    {
        $installerPath = (string) $installerPath;
        $artifactFilename = trim((string) $artifactFilename);
        $artifactSuffix = '.syndicatum-backup';
        if ($artifactFilename === '' || basename($artifactFilename) !== $artifactFilename
            || substr($artifactFilename, -strlen($artifactSuffix)) !== $artifactSuffix
            || strpos($artifactFilename, '/') !== false || strpos($artifactFilename, '\\') !== false) {
            throw new InvalidArgumentException('Cleanup is not authorized for that backup artifact.');
        }
        $directory = realpath(dirname($installerPath));
        $installer = realpath($installerPath);
        if (!is_string($directory) || !is_string($installer) || dirname($installer) !== $directory
            || !is_file($installer) || is_link($installer)) {
            throw new RuntimeException('The Kickstart installer could not be verified for cleanup.');
        }
        $artifact = $directory . DIRECTORY_SEPARATOR . $artifactFilename;
        if ((file_exists($artifact) || is_link($artifact)) && (!is_file($artifact) || is_link($artifact))) {
            throw new RuntimeException('The backup artifact could not be verified for cleanup.');
        }
        if (is_file($artifact) && !@unlink($artifact)) {
            throw new RuntimeException('The backup artifact could not be removed. Check server file permissions and try again.');
        }
        if (!@unlink($installer)) {
            throw new RuntimeException('kickstart.php could not be removed. Check server file permissions and try again.');
        }
        return ['artifact' => $artifactFilename, 'installer' => basename($installer)];
    }

    private static function stage()
    {
        $root = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'syndicatum-kickstart-' . bin2hex(random_bytes(12));
        if (!mkdir($root, 0700)) { throw new RuntimeException('Private restore stage could not be created.'); }
        @chmod($root, 0700); return $root;
    }

    private static function absoluteDirectory($path, $label)
    {
        $path = rtrim(trim((string) $path), '/\\');
        $windowsDrive = strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/');
        $unc = strlen($path) >= 3 && substr($path, 0, 2) === '\\\\';
        if (!$windowsDrive && !$unc && strpos($path, '/') !== 0) { throw new InvalidArgumentException($label . ' must be an absolute server path.'); }
        if (!is_dir($path) && !mkdir($path, 0700, true)) { throw new RuntimeException($label . ' could not be created.'); }
        $real = realpath($path); if (!is_string($real) || is_link($path)) { throw new RuntimeException($label . ' is invalid.'); }
        return $real;
    }

    private static function inside($path, $root)
    {
        $a = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path; $b = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        return $a === $b || strpos($a, $b . DIRECTORY_SEPARATOR) === 0;
    }

    private static function readExact($stream, $length)
    {
        $bytes = '';
        while (strlen($bytes) < $length) { $chunk = fread($stream, $length - strlen($bytes)); if ($chunk === false || $chunk === '') { throw new InvalidArgumentException('Backup envelope is truncated.'); } $bytes .= $chunk; }
        return $bytes;
    }

    private static function writeAll($stream, $bytes)
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) { $written = fwrite($stream, substr($bytes, $offset)); if (!is_int($written) || $written < 1) { throw new RuntimeException('Restore staging write failed.'); } $offset += $written; }
    }

    private static function uint32($bytes) { $value = unpack('Nvalue', $bytes); return (int) $value['value']; }

    private static function removeTree($path)
    {
        if (!file_exists($path) && !is_link($path)) { return; }
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) { self::removeTree($entry->getPathname()); }
        @rmdir($path);
    }
}

if (defined('SYNDICATUM_KICKSTART_LIBRARY_ONLY')) { return; }

if (PHP_SAPI === 'cli') { fwrite(STDERR, "Open kickstart.php in a web browser.\n"); exit(1); }
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']), 'samesite' => 'Strict']);
    session_start();
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

if (!isset($_SESSION['kickstart_csrf'])) { $_SESSION['kickstart_csrf'] = bin2hex(random_bytes(32)); }
$state = isset($_SESSION['kickstart_package']) && is_array($_SESSION['kickstart_package']) ? $_SESSION['kickstart_package'] : null;
$restoreState = isset($_SESSION['kickstart_restore']) && is_array($_SESSION['kickstart_restore']) ? $_SESSION['kickstart_restore'] : null;
$cleanupState = isset($_SESSION['kickstart_cleanup']) && is_array($_SESSION['kickstart_cleanup']) ? $_SESSION['kickstart_cleanup'] : null;
$errors = [];
$success = $cleanupState !== null && isset($cleanupState['summary']) && is_array($cleanupState['summary'])
    ? $cleanupState['summary'] : null;
$artifacts = [];
foreach (glob(__DIR__ . DIRECTORY_SEPARATOR . '*.syndicatum-backup') ?: [] as $path) { if (is_file($path) && !is_link($path)) { $artifacts[] = basename($path); } }
sort($artifacts, SORT_STRING);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['kickstart_csrf'], (string) ($_POST['csrf'] ?? ''))) { throw new InvalidArgumentException('The installer session expired. Reload this page and try again.'); }
        if (isset($_POST['reset'])) {
            unset($_SESSION['kickstart_package']); $state = null;
        } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'step') {
            header('Content-Type: application/json; charset=utf-8');
            if ($restoreState === null) { throw new RuntimeException('No restoration is currently active.'); }
            $progress = SyndicatumKickstart::restoreStep($restoreState);
            if ($progress['done']) {
                $filename = (string) ($_SESSION['kickstart_restore_filename'] ?? '');
                $_SESSION['kickstart_cleanup'] = ['filename' => $filename, 'summary' => $progress['result']];
                unset($_SESSION['kickstart_restore'], $_SESSION['kickstart_restore_filename'], $_SESSION['kickstart_package']);
            } else { $_SESSION['kickstart_restore'] = $restoreState; }
            echo json_encode(['ok' => true] + $progress, JSON_UNESCAPED_SLASHES); exit;
        } elseif ($action === 'cleanup') {
            if ($cleanupState === null || empty($cleanupState['filename']) || $success === null) {
                throw new InvalidArgumentException('Cleanup is available only after a verified restoration.');
            }
            SyndicatumKickstart::cleanup(__FILE__, (string) $cleanupState['filename']);
            unset($_SESSION['kickstart_package'], $_SESSION['kickstart_cleanup'], $_SESSION['kickstart_csrf']);
            session_destroy();
            header('Location: ./', true, 303);
            exit;
        } elseif ($action === 'inspect') {
            $filename = (string) ($_POST['artifact'] ?? '');
            if (!in_array($filename, $artifacts, true)) { throw new InvalidArgumentException('Backup file — choose an artifact beside kickstart.php.'); }
            $key = trim((string) ($_POST['recovery_key'] ?? ''));
            $manifest = SyndicatumKickstart::inspect(__DIR__ . DIRECTORY_SEPARATOR . $filename, $key);
            unset($_SESSION['kickstart_cleanup']); $cleanupState = null; $success = null;
            $_SESSION['kickstart_package'] = ['filename' => $filename, 'key' => $key, 'manifest' => $manifest];
            $state = $_SESSION['kickstart_package'];
        } elseif ($action === 'install') {
            if ($state === null) { throw new InvalidArgumentException('Backup file — inspect and verify a package before restoring it.'); }
            $database = ['host' => trim((string) ($_POST['db_host'] ?? '')), 'port' => (int) ($_POST['db_port'] ?? 0),
                'name' => trim((string) ($_POST['db_name'] ?? '')), 'user' => trim((string) ($_POST['db_user'] ?? '')),
                'password' => (string) ($_POST['db_password'] ?? '')];
            if (!isset($_POST['confirm_restore'])) { throw new InvalidArgumentException('Confirmation — confirm that this package may replace Syndicatum files and database objects.'); }
            $restoredFilename = (string) $state['filename'];
            $restoreState = SyndicatumKickstart::beginSteppedRestore(__DIR__ . DIRECTORY_SEPARATOR . $restoredFilename, $state['key'], $state['manifest'], $database,
                (string) ($_POST['private_directory'] ?? ''), isset($_POST['replace_existing']));
            $_SESSION['kickstart_restore'] = $restoreState;
            $_SESSION['kickstart_restore_filename'] = $restoredFilename;
            header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?'), true, 303); exit;
        } else { throw new InvalidArgumentException('Installer action is invalid.'); }
        }
    } catch (Throwable $error) {
        if (isset($_POST['action']) && $_POST['action'] === 'step') {
            http_response_code(500); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_SLASHES); exit;
        }
        $errors[] = $error->getMessage();
    }
}

function kickstartHtml($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function kickstartDefaultPrivateDirectory()
{
    $cursor = __DIR__;
    while (dirname($cursor) !== $cursor) {
        if (strtolower(basename($cursor)) === 'www') { return dirname($cursor) . DIRECTORY_SEPARATOR . '.syndicatum'; }
        $cursor = dirname($cursor);
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . '.syndicatum';
}
$defaultPrivate = kickstartDefaultPrivateDirectory();
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Syndicatum Kickstart</title>
<style nonce="<?php echo kickstartHtml($nonce); ?>">
:root{color-scheme:dark;--bg:#07101d;--panel:#0d192b;--line:#29405f;--text:#e8eef9;--muted:#9dafc9;--accent:#84a8ff;--danger:#ff6675;--ok:#3ddc97}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top right,#12323a 0,transparent 40%),var(--bg);color:var(--text);font:15px/1.5 Inter,system-ui,sans-serif}.shell{width:min(850px,calc(100% - 28px));margin:0 auto;padding:36px 0}.eyebrow{color:var(--accent);font-weight:700;letter-spacing:.08em;text-transform:uppercase}.panel{margin-top:20px;padding:24px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}h1,h2{margin:0 0 8px}.muted{color:var(--muted)}.alert{margin:18px 0;padding:13px 15px;border:1px solid var(--line);border-radius:10px}.alert.error{border-color:#8c3440;color:#ffdce0}.alert.ok{border-color:#21684d;color:#caffea}.alert ul{margin:8px 0 0;padding-left:22px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.wide{grid-column:1/-1}label{display:grid;gap:6px;color:var(--muted);font-weight:600}input,select{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:9px;background:#101f35;color:var(--text);font:inherit}input.invalid,select.invalid{border-color:var(--danger)}.check{display:flex;align-items:flex-start;gap:9px}.check input{width:auto;margin-top:5px}.actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:18px;border-top:1px solid var(--line)}button{padding:10px 16px;border:1px solid #496b9e;border-radius:9px;background:#1c355c;color:#fff;font:inherit;cursor:pointer}button:disabled{cursor:not-allowed;opacity:.55}button.secondary{background:transparent}button.danger{border-color:#8c3440;background:#5b1f2a}.summary{display:grid;grid-template-columns:max-content 1fr;gap:8px 18px}.summary dt{color:var(--muted)}.summary dd{margin:0;overflow-wrap:anywhere}.restore-form{position:relative}.restore-busy{position:absolute;inset:-10px;z-index:3;display:grid;place-content:center;justify-items:center;gap:12px;padding:24px;border-radius:12px;background:#08111fe8;color:var(--text);text-align:center}.restore-busy[hidden]{display:none}.restore-spinner{width:42px;height:42px;border:4px solid #84a8ff42;border-top-color:var(--accent);border-radius:50%;animation:restore-spin .75s linear infinite}.restore-busy strong,.restore-busy span{display:block}.restore-busy span{margin-top:4px;color:var(--muted);font-size:13px}.progress-track{height:12px;margin-top:8px;overflow:hidden;border:1px solid var(--line);border-radius:999px;background:#08111f}.progress-fill{height:100%;width:0;background:linear-gradient(90deg,#759cff,#9bb7ff);transition:width .2s}.progress-meta{display:flex;justify-content:space-between;margin-top:18px;color:var(--muted)}@keyframes restore-spin{to{transform:rotate(360deg)}}@media(max-width:650px){.grid{grid-template-columns:1fr}.wide{grid-column:auto}.shell{padding:20px 0}}
</style></head><body><main class="shell"><p class="eyebrow">Standalone recovery</p><h1>Syndicatum Kickstart</h1><p class="muted">Restore a verified Syndicatum portable backup directly. Historical migrations are not used.</p>
<?php if ($errors): ?><div class="alert error" id="validation-summary" role="alert"><strong>Please address the following issues before continuing:</strong><ul><?php foreach ($errors as $error): ?><li><?php echo kickstartHtml($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($success): ?><section class="panel"><div class="alert ok"><strong>Restoration completed and verified.</strong></div><h2>Syndicatum is restored</h2><dl class="summary"><dt>Package type</dt><dd><?php echo kickstartHtml($success['package_type']); ?></dd><dt>Runtime files</dt><dd><?php echo (int) $success['runtime_files']; ?></dd><dt>Database tables</dt><dd><?php echo (int) $success['tables']; ?></dd><dt>Persistent files</dt><dd><?php echo (int) $success['persistent_files']; ?></dd><dt>Private configuration</dt><dd><?php echo kickstartHtml($success['private_directory']); ?></dd></dl><p class="muted">After inspecting the restored installation, remove the standalone installer and its backup artifact from this public directory.</p><form method="post" data-cleanup><input type="hidden" name="csrf" value="<?php echo kickstartHtml($_SESSION['kickstart_csrf']); ?>"><input type="hidden" name="action" value="cleanup"><div class="actions"><button class="danger" type="submit">Remove installer and backup</button></div></form></section>
<?php elseif ($restoreState !== null): ?><section class="panel" data-restore-progress data-csrf="<?php echo kickstartHtml($_SESSION['kickstart_csrf']); ?>"><h2>Restoring Syndicatum</h2><p class="muted" data-progress-detail>The verified package is ready. Starting restoration…</p><div class="progress-meta"><strong data-progress-phase>Preparing restoration</strong><span data-progress-percent>0%</span></div><div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-fill" data-progress-fill></div></div><div class="alert error" data-progress-error role="alert" hidden></div><div class="actions"><button type="button" data-progress-retry hidden>Retry current step</button></div></section>
<?php elseif ($state === null): ?><section class="panel"><h2>1. Inspect backup package</h2><p class="muted">Place the encrypted backup beside this installer, then provide its recovery key. The complete artifact is authenticated before restoration begins.</p><form method="post" data-validate><input type="hidden" name="csrf" value="<?php echo kickstartHtml($_SESSION['kickstart_csrf']); ?>"><input type="hidden" name="action" value="inspect"><div class="grid"><label class="wide">Backup file<select name="artifact" required><option value="">Select a backup</option><?php foreach ($artifacts as $artifact): ?><option value="<?php echo kickstartHtml($artifact); ?>"<?php echo count($artifacts) === 1 ? ' selected' : ''; ?>><?php echo kickstartHtml($artifact); ?></option><?php endforeach; ?></select></label><label class="wide">Backup recovery key<input type="password" name="recovery_key" autocomplete="off" required></label></div><div class="actions"><button type="submit">Inspect package</button></div></form></section>
<?php else: $m=$state['manifest']; ?><section class="panel"><h2>2. Restore inspected package</h2><dl class="summary"><dt>File</dt><dd><?php echo kickstartHtml($state['filename']); ?></dd><dt>Package type</dt><dd><?php echo kickstartHtml($m['package_type'] === 'full_clone' ? 'Full clone' : 'Clean installation'); ?></dd><dt>Created</dt><dd><?php echo kickstartHtml($m['created_at']); ?></dd><dt>Runtime files</dt><dd><?php echo count($m['runtime']); ?></dd><dt>Database tables</dt><dd><?php echo count($m['sql']['row_counts']); ?></dd><dt>Persistent files</dt><dd><?php echo count($m['persistent_files']); ?></dd></dl><form method="post" class="restore-form" data-validate data-restore><input type="hidden" name="csrf" value="<?php echo kickstartHtml($_SESSION['kickstart_csrf']); ?>"><input type="hidden" name="action" value="install"><div class="grid"><label>Database host<input name="db_host" value="127.0.0.1" required></label><label>Database port<input name="db_port" type="number" min="1" max="65535" value="3306" required></label><label>Database name<input name="db_name" required></label><label>Database user<input name="db_user" required></label><label class="wide">Database password<input name="db_password" type="password" autocomplete="new-password"></label><label class="wide">Private configuration directory<input name="private_directory" value="<?php echo kickstartHtml($defaultPrivate); ?>" required></label><label class="check wide"><input type="checkbox" name="replace_existing" value="1"><span>Replace existing Syndicatum database tables if present</span></label><label class="check wide"><input type="checkbox" name="confirm_restore" value="1" required><span>I confirm that this package may replace Syndicatum runtime files and the selected database objects.</span></label></div><div class="actions"><button class="secondary" type="submit" name="reset" value="1" formnovalidate>Choose another backup</button><button type="submit">Restore Syndicatum</button></div><div class="restore-busy" data-restore-busy role="status" aria-live="assertive" hidden><span class="restore-spinner" aria-hidden="true"></span><div><strong>Starting restoration…</strong><span>The progress screen will open when the restore checkpoint is ready.</span></div></div></form></section><?php endif; ?>
</main><script nonce="<?php echo kickstartHtml($nonce); ?>">
document.querySelectorAll('form[data-validate]').forEach((form)=>form.addEventListener('submit',(event)=>{if(event.submitter?.hasAttribute('formnovalidate'))return;if(form.dataset.submitting==='true'){event.preventDefault();return}const invalid=[...form.querySelectorAll('[required]')].filter((field)=>field.type==='checkbox'?!field.checked:!field.value.trim()||!field.checkValidity());form.querySelectorAll('.invalid').forEach((field)=>{field.classList.remove('invalid');field.removeAttribute('aria-invalid')});document.querySelector('#client-errors')?.remove();if(invalid.length){event.preventDefault();invalid.forEach((field)=>{field.classList.add('invalid');field.setAttribute('aria-invalid','true')});const box=document.createElement('div');box.id='client-errors';box.className='alert error';box.setAttribute('role','alert');const list=invalid.map((field)=>`<li>${field.closest('label')?.innerText.trim().split('\n')[0]||'Required field'} — required</li>`).join('');box.innerHTML=`<strong>Please address the following issues before continuing:</strong><ul>${list}</ul>`;form.prepend(box);invalid[0].focus();return}if(!form.matches('[data-restore]'))return;form.dataset.submitting='true';form.setAttribute('aria-busy','true');form.querySelectorAll('button').forEach((button)=>{button.disabled=true});const busy=form.querySelector('[data-restore-busy]');if(busy)busy.hidden=false}));
document.querySelector('form[data-cleanup]')?.addEventListener('submit',(event)=>{if(!window.confirm('Remove kickstart.php and the verified backup artifact now? This cannot be undone.'))event.preventDefault()});
const progress=document.querySelector('[data-restore-progress]');if(progress){const phase=progress.querySelector('[data-progress-phase]'),detail=progress.querySelector('[data-progress-detail]'),percent=progress.querySelector('[data-progress-percent]'),fill=progress.querySelector('[data-progress-fill]'),track=progress.querySelector('[role=progressbar]'),error=progress.querySelector('[data-progress-error]'),retry=progress.querySelector('[data-progress-retry]');let running=false;const step=async()=>{if(running)return;running=true;retry.hidden=true;error.hidden=true;try{const body=new URLSearchParams({action:'step',csrf:progress.dataset.csrf});const response=await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body,credentials:'same-origin'});const data=await response.json().catch(()=>({ok:false,message:`Restore request failed with status ${response.status}.`}));if(!response.ok||!data.ok)throw new Error(data.message||`Restore request failed with status ${response.status}.`);phase.textContent=data.phase;detail.textContent=data.detail;percent.textContent=`${data.overall_percent}%`;fill.style.width=`${data.overall_percent}%`;track.setAttribute('aria-valuenow',String(data.overall_percent));if(data.done){location.reload();return}running=false;setTimeout(step,80)}catch(reason){running=false;error.textContent=reason instanceof Error?reason.message:'The current restoration step failed.';error.hidden=false;retry.hidden=false}};retry.addEventListener('click',step);step()}
</script></body></html>
