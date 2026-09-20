<?php

/**
 * Streaming authenticated envelope for V1 recovery packages.
 *
 * The envelope authenticates its closed JSON header and every ciphertext frame.
 * Archive and manifest digests become trusted only after every frame has been
 * authenticated and the decrypted bytes have been re-hashed successfully.
 */
final class BackupEnvelope
{
    const CONTRACT_NAME = 'syndicatum-backup-envelope';
    const FORMAT_VERSION = '1.0';
    const ALGORITHM = 'aes-256-gcm-chunked-v1';
    const MAGIC = "SYNDICATUM-BACKUP\0";
    const DEFAULT_CHUNK_SIZE = 1048576;
    const MAX_HEADER_BYTES = 65536;
    const MAX_MANIFEST_BYTES = 2097152;
    const MAX_FRAME_COUNT = 4294967295;

    public static function keyFromBase64($encoded)
    {
        if (!is_string($encoded) || trim($encoded) === '') {
            throw new InvalidArgumentException('SYNDICATUM_BACKUP_KEY must be base64-encoded.');
        }
        $key = base64_decode(trim($encoded), true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new InvalidArgumentException('SYNDICATUM_BACKUP_KEY must decode to exactly 32 bytes.');
        }
        return $key;
    }

    public static function keyId($key)
    {
        self::assertKey($key);
        return substr(hash('sha256', $key), 0, 24);
    }

    public static function encrypt($archivePath, $manifestJson, $destinationPath, $key, array $identity, $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        self::requireOpenSsl();
        self::assertKey($key);
        self::assertRegularFile($archivePath, 'Backup archive');
        self::assertDestination($destinationPath);
        if (!is_string($manifestJson) || $manifestJson === '' || strlen($manifestJson) > self::MAX_MANIFEST_BYTES) {
            throw new InvalidArgumentException('Backup manifest bytes are missing or exceed the V1 limit.');
        }
        if (!is_int($chunkSize) || $chunkSize < 4096 || $chunkSize > 8388608) {
            throw new InvalidArgumentException('Backup envelope chunk size is outside the V1 range.');
        }
        foreach (['application_version', 'schema_baseline', 'schema_head', 'source_commit'] as $field) {
            if (!isset($identity[$field]) || !is_string($identity[$field]) || trim($identity[$field]) === '') {
                throw new InvalidArgumentException('Backup envelope identity requires ' . $field . '.');
            }
        }

        $archiveSize = filesize($archivePath);
        if (!is_int($archiveSize) || $archiveSize < 1) {
            throw new InvalidArgumentException('Backup archive must be non-empty.');
        }
        $plaintextSize = 4 + strlen($manifestJson) + $archiveSize;
        $totalFrames = (int) ceil($plaintextSize / $chunkSize);
        if ($totalFrames < 1 || $totalFrames > self::MAX_FRAME_COUNT) {
            throw new InvalidArgumentException('Backup envelope exceeds the V1 frame-count limit.');
        }
        $noncePrefix = random_bytes(8);
        $header = [
            'contract_name' => self::CONTRACT_NAME,
            'format_version' => self::FORMAT_VERSION,
            'algorithm' => self::ALGORITHM,
            'key_id' => self::keyId($key),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'application_version' => $identity['application_version'],
            'schema_baseline' => $identity['schema_baseline'],
            'schema_head' => $identity['schema_head'],
            'source_commit' => $identity['source_commit'],
            'manifest_sha256' => hash('sha256', $manifestJson),
            'archive_sha256' => hash_file('sha256', $archivePath),
            'manifest_size' => strlen($manifestJson),
            'archive_size' => $archiveSize,
            'plaintext_size' => $plaintextSize,
            'chunk_size' => $chunkSize,
            'frame_count' => $totalFrames,
            'nonce_prefix' => base64_encode($noncePrefix),
        ];
        $headerJson = self::encodeJson($header);
        if (strlen($headerJson) > self::MAX_HEADER_BYTES) {
            throw new RuntimeException('Backup envelope header exceeds the V1 limit.');
        }

        $temporary = $destinationPath . '.partial-' . bin2hex(random_bytes(8));
        $output = null;
        $archive = null;
        try {
            $output = self::openExclusivePrivateFile($temporary);
            self::writeAll($output, self::MAGIC . pack('N', strlen($headerJson)) . $headerJson);
            $archive = fopen($archivePath, 'rb');
            if (!is_resource($archive)) {
                throw new RuntimeException('Backup archive could not be opened for encryption.');
            }
            $prefix = pack('N', strlen($manifestJson)) . $manifestJson;
            $prefixOffset = 0;
            $archiveExhausted = false;
            $headerDigest = hash('sha256', $headerJson, true);
            for ($index = 0; $index < $totalFrames; $index++) {
                $plain = '';
                while (strlen($plain) < $chunkSize) {
                    if ($prefixOffset < strlen($prefix)) {
                        $take = min($chunkSize - strlen($plain), strlen($prefix) - $prefixOffset);
                        $plain .= substr($prefix, $prefixOffset, $take);
                        $prefixOffset += $take;
                        continue;
                    }
                    if ($archiveExhausted) {
                        break;
                    }
                    $chunk = fread($archive, $chunkSize - strlen($plain));
                    if ($chunk === false) {
                        throw new RuntimeException('Backup archive could not be read during encryption.');
                    }
                    if ($chunk === '') {
                        if (!feof($archive)) {
                            throw new RuntimeException('Backup archive read stalled during encryption.');
                        }
                        $archiveExhausted = true;
                        break;
                    }
                    $plain .= $chunk;
                }
                if ($plain === '') {
                    throw new RuntimeException('Backup envelope plaintext ended before its declared frame count.');
                }
                $nonce = $noncePrefix . pack('N', $index);
                $aad = $headerDigest . pack('N2', $index, $totalFrames);
                $tag = '';
                $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
                if (!is_string($ciphertext) || strlen($tag) !== 16) {
                    throw new RuntimeException('Backup envelope encryption failed.');
                }
                self::writeAll($output, pack('N', strlen($ciphertext)) . $tag . $ciphertext);
            }
            if ($prefixOffset !== strlen($prefix) || !$archiveExhausted) {
                $extra = fread($archive, 1);
                if ($extra === false || $extra !== '') {
                    throw new RuntimeException('Backup envelope plaintext size changed during encryption.');
                }
            }
            if (!fflush($output)) {
                throw new RuntimeException('Backup envelope could not be flushed.');
            }
            fclose($archive); $archive = null;
            fclose($output); $output = null;
            if (!@link($temporary, $destinationPath)) {
                throw new RuntimeException('Backup envelope destination already exists or could not be committed without replacement.');
            }
            @unlink($temporary);
            @chmod($destinationPath, 0600);
            return ['path' => $destinationPath, 'envelope_sha256' => hash_file('sha256', $destinationPath), 'header' => $header];
        } finally {
            if (is_resource($archive)) { fclose($archive); }
            if (is_resource($output)) { fclose($output); }
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    /**
     * Authenticate and decrypt into a new private directory.
     * Returns exact manifest/archive identities only after full authentication.
     */
    public static function decryptToPrivateStage($envelopePath, $stagingRoot, $key)
    {
        self::requireOpenSsl();
        self::assertKey($key);
        self::assertRegularFile($envelopePath, 'Backup envelope');
        $root = self::assertPrivateDirectory($stagingRoot);
        $input = fopen($envelopePath, 'rb');
        if (!is_resource($input)) {
            throw new InvalidArgumentException('Backup envelope could not be opened.');
        }
        $stage = null;
        try {
            if (self::readExact($input, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new InvalidArgumentException('Backup envelope magic is invalid.');
            }
            $headerLength = self::unpackUint32(self::readExact($input, 4));
            if ($headerLength < 2 || $headerLength > self::MAX_HEADER_BYTES) {
                throw new InvalidArgumentException('Backup envelope header length is invalid.');
            }
            $headerJson = self::readExact($input, $headerLength);
            $header = self::parseHeader($headerJson, $key);
            $stage = self::createPrivateStage($root);
            $combinedPath = $stage . DIRECTORY_SEPARATOR . 'authenticated.plaintext';
            $combined = self::openExclusivePrivateFile($combinedPath);
            try {
                $headerDigest = hash('sha256', $headerJson, true);
                $noncePrefix = base64_decode($header['nonce_prefix'], true);
                $written = 0;
                for ($index = 0; $index < $header['frame_count']; $index++) {
                    $cipherLength = self::unpackUint32(self::readExact($input, 4));
                    $remaining = $header['plaintext_size'] - $written;
                    $expectedLength = min($header['chunk_size'], $remaining);
                    if ($cipherLength !== $expectedLength || $cipherLength < 1) {
                        throw new InvalidArgumentException('Backup envelope frame length is invalid.');
                    }
                    $tag = self::readExact($input, 16);
                    $ciphertext = self::readExact($input, $cipherLength);
                    $nonce = $noncePrefix . pack('N', $index);
                    $aad = $headerDigest . pack('N2', $index, $header['frame_count']);
                    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
                    if (!is_string($plain) || strlen($plain) !== $cipherLength) {
                        throw new InvalidArgumentException('Backup envelope authentication failed.');
                    }
                    self::writeAll($combined, $plain);
                    $written += strlen($plain);
                }
                if ($written !== $header['plaintext_size'] || fread($input, 1) !== '') {
                    throw new InvalidArgumentException('Backup envelope length or frame count is invalid.');
                }
            } finally {
                fclose($combined);
            }

            $combined = fopen($combinedPath, 'rb');
            if (!is_resource($combined)) {
                throw new RuntimeException('Authenticated backup plaintext could not be reopened.');
            }
            try {
                $manifestLength = self::unpackUint32(self::readExact($combined, 4));
                if ($manifestLength !== $header['manifest_size'] || $manifestLength > self::MAX_MANIFEST_BYTES) {
                    throw new InvalidArgumentException('Authenticated backup manifest length is invalid.');
                }
                $manifestJson = self::readExact($combined, $manifestLength);
                if (!hash_equals($header['manifest_sha256'], hash('sha256', $manifestJson))) {
                    throw new InvalidArgumentException('Authenticated backup manifest digest is invalid.');
                }
                $manifestPath = $stage . DIRECTORY_SEPARATOR . 'manifest.json';
                self::writePrivateBytes($manifestPath, $manifestJson);
                $archivePath = $stage . DIRECTORY_SEPARATOR . 'backup.zip';
                $archive = self::openExclusivePrivateFile($archivePath);
                $archiveHash = hash_init('sha256');
                $archiveBytes = 0;
                try {
                    while (!feof($combined)) {
                        $chunk = fread($combined, 1048576);
                        if ($chunk === false) { throw new RuntimeException('Authenticated backup archive could not be read.'); }
                        if ($chunk === '') { break; }
                        self::writeAll($archive, $chunk);
                        hash_update($archiveHash, $chunk);
                        $archiveBytes += strlen($chunk);
                    }
                } finally {
                    fclose($archive);
                }
                if ($archiveBytes !== $header['archive_size'] || !hash_equals($header['archive_sha256'], hash_final($archiveHash))) {
                    throw new InvalidArgumentException('Authenticated backup archive identity is invalid.');
                }
            } finally {
                fclose($combined);
                @unlink($combinedPath);
            }
            fclose($input); $input = null;
            return [
                'stage_path' => $stage,
                'archive_path' => $archivePath,
                'manifest_path' => $manifestPath,
                'manifest_json' => $manifestJson,
                'archive_sha256' => $header['archive_sha256'],
                'manifest_sha256' => $header['manifest_sha256'],
                'header' => $header,
            ];
        } catch (Throwable $exception) {
            if ($stage !== null) { self::removeTree($stage); }
            throw $exception;
        } finally {
            if (is_resource($input)) { fclose($input); }
        }
    }

    public static function removePrivateStage($path, $trustedRoot)
    {
        $root = realpath($trustedRoot);
        $stage = realpath($path);
        if ($root === false || $stage === false || $stage === $root || strpos($stage, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new InvalidArgumentException('Backup stage is outside the trusted staging root.');
        }
        self::removeTree($stage);
    }

    private static function parseHeader($json, $key)
    {
        $header = json_decode($json, true, 32, JSON_BIGINT_AS_STRING);
        if (!is_array($header) || json_last_error() !== JSON_ERROR_NONE || self::encodeJson($header) !== $json) {
            throw new InvalidArgumentException('Backup envelope header is not canonical JSON.');
        }
        $allowed = ['contract_name','format_version','algorithm','key_id','created_at','application_version','schema_baseline','schema_head','source_commit','manifest_sha256','archive_sha256','manifest_size','archive_size','plaintext_size','chunk_size','frame_count','nonce_prefix'];
        if (array_keys($header) !== $allowed || $header['contract_name'] !== self::CONTRACT_NAME || $header['format_version'] !== self::FORMAT_VERSION || $header['algorithm'] !== self::ALGORITHM) {
            throw new InvalidArgumentException('Backup envelope header contract is invalid.');
        }
        foreach (['key_id','created_at','application_version','schema_baseline','schema_head','source_commit','manifest_sha256','archive_sha256','nonce_prefix'] as $field) {
            if (!is_string($header[$field]) || $header[$field] === '') { throw new InvalidArgumentException('Backup envelope header field is invalid: ' . $field); }
        }
        if (!hash_equals(self::keyId($key), $header['key_id'])) { throw new InvalidArgumentException('Backup envelope key identity does not match.'); }
        foreach (['manifest_sha256','archive_sha256'] as $field) {
            if (!preg_match('/\A[a-f0-9]{64}\z/', $header[$field])) { throw new InvalidArgumentException('Backup envelope digest is invalid.'); }
        }
        foreach (['manifest_size','archive_size','plaintext_size','chunk_size','frame_count'] as $field) {
            if (!is_int($header[$field]) || $header[$field] < 1) { throw new InvalidArgumentException('Backup envelope size field is invalid: ' . $field); }
        }
        if ($header['manifest_size'] > self::MAX_MANIFEST_BYTES || $header['chunk_size'] < 4096 || $header['chunk_size'] > 8388608
            || $header['plaintext_size'] !== 4 + $header['manifest_size'] + $header['archive_size']
            || $header['frame_count'] > self::MAX_FRAME_COUNT
            || $header['frame_count'] !== (int) ceil($header['plaintext_size'] / $header['chunk_size'])) {
            throw new InvalidArgumentException('Backup envelope declared sizes are inconsistent.');
        }
        $nonce = base64_decode($header['nonce_prefix'], true);
        if (!is_string($nonce) || strlen($nonce) !== 8) { throw new InvalidArgumentException('Backup envelope nonce prefix is invalid.'); }
        return $header;
    }

    private static function encodeJson(array $value)
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { throw new RuntimeException('Backup envelope JSON could not be encoded.'); }
        return $json;
    }

    private static function createPrivateStage($root)
    {
        for ($i = 0; $i < 16; $i++) {
            $path = $root . DIRECTORY_SEPARATOR . 'backup-stage-' . bin2hex(random_bytes(12));
            if (@mkdir($path, 0700)) { @chmod($path, 0700); return $path; }
        }
        throw new RuntimeException('A private backup stage could not be created.');
    }

    private static function assertPrivateDirectory($path)
    {
        if (!is_string($path) || !is_dir($path)) { throw new InvalidArgumentException('Backup staging root must exist.'); }
        $real = realpath($path);
        if ($real === false) { throw new InvalidArgumentException('Backup staging root could not be resolved.'); }
        if (DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($real);
            if (!is_int($mode) || (($mode & 0077) !== 0)) { throw new InvalidArgumentException('Backup staging root must be private (0700).'); }
        }
        return $real;
    }

    private static function assertDestination($path)
    {
        if (!is_string($path) || trim($path) === '' || is_file($path) || is_link($path)) {
            throw new InvalidArgumentException('Backup destination must be a new file.');
        }
        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent)) { throw new InvalidArgumentException('Backup destination directory must exist and be writable.'); }
    }

    private static function assertRegularFile($path, $label)
    {
        if (!is_string($path) || !is_file($path) || is_link($path)) { throw new InvalidArgumentException($label . ' must be a regular file.'); }
    }

    private static function assertKey($key)
    {
        if (!is_string($key) || strlen($key) !== 32) { throw new InvalidArgumentException('Backup encryption key must be exactly 32 bytes.'); }
    }

    private static function requireOpenSsl()
    {
        if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('AES-256-GCM support is required for backup envelopes.');
        }
    }

    private static function openExclusivePrivateFile($path)
    {
        $stream = @fopen($path, 'xb');
        if (!is_resource($stream)) { throw new RuntimeException('Private backup file could not be created exclusively.'); }
        @chmod($path, 0600);
        return $stream;
    }

    private static function writePrivateBytes($path, $bytes)
    {
        $stream = self::openExclusivePrivateFile($path);
        try { self::writeAll($stream, $bytes); } finally { fclose($stream); }
    }

    private static function writeAll($stream, $bytes)
    {
        $offset = 0; $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) { throw new RuntimeException('Backup stream write failed.'); }
            $offset += $written;
        }
    }

    private static function readExact($stream, $length)
    {
        if (!is_int($length) || $length < 0) { throw new InvalidArgumentException('Backup read length is invalid.'); }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($stream, $length - strlen($bytes));
            if ($chunk === false || ($chunk === '' && feof($stream))) { throw new InvalidArgumentException('Backup envelope is truncated.'); }
            if ($chunk === '') { throw new RuntimeException('Backup envelope read stalled.'); }
            $bytes .= $chunk;
        }
        return $bytes;
    }

    private static function unpackUint32($bytes)
    {
        $value = unpack('Nvalue', $bytes);
        return (int) $value['value'];
    }

    private static function removeTree($path)
    {
        if (!is_dir($path) || is_link($path)) { if (is_file($path) || is_link($path)) { @unlink($path); } return; }
        $items = scandir($path);
        if (is_array($items)) {
            foreach ($items as $item) { if ($item !== '.' && $item !== '..') { self::removeTree($path . DIRECTORY_SEPARATOR . $item); } }
        }
        @rmdir($path);
    }
}
