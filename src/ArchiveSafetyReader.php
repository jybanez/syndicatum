<?php

final class ArchiveInspection
{
    private $archivePath;
    private $archiveSha256;
    private $archiveSize;
    private $entries;

    public function __construct($archivePath, $archiveSha256, $archiveSize, array $entries)
    {
        $this->archivePath = $archivePath;
        $this->archiveSha256 = $archiveSha256;
        $this->archiveSize = $archiveSize;
        $this->entries = $entries;
    }

    public function archivePath() { return $this->archivePath; }
    public function archiveSha256() { return $this->archiveSha256; }
    public function archiveSize() { return $this->archiveSize; }
    public function entries() { return $this->entries; }
}

final class ArchiveValidationContext
{
    private $kind;
    private $archiveSha256;
    private $manifestSha256;
    private $releaseIdentity;
    private $backupBaselines;
    private $backupPaths;

    private function __construct($kind, $archiveSha256, $manifestSha256, array $releaseIdentity, array $backupBaselines, array $backupPaths)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $archiveSha256) || !preg_match('/\A[a-f0-9]{64}\z/', $manifestSha256)) {
            throw new InvalidArgumentException('Trusted archive and sidecar-manifest SHA-256 values are required.');
        }
        $this->kind = $kind;
        $this->archiveSha256 = $archiveSha256;
        $this->manifestSha256 = $manifestSha256;
        $this->releaseIdentity = $releaseIdentity;
        $this->backupBaselines = $backupBaselines;
        $this->backupPaths = $backupPaths;
    }

    public static function forRelease($archiveSha256, $manifestSha256, array $identity)
    {
        foreach (['source_commit', 'source_tag', 'schema_baseline', 'schema_head'] as $field) {
            if (!isset($identity[$field]) || !is_string($identity[$field]) || $identity[$field] === '') {
                throw new InvalidArgumentException('Trusted release identity requires ' . $field . '.');
            }
        }
        return new self('release', $archiveSha256, $manifestSha256, $identity, [], []);
    }

    public static function forBackup($archiveSha256, $manifestSha256, array $allowedBaselineHeads, array $allowedPayloadRoles)
    {
        if (!$allowedBaselineHeads || !$allowedPayloadRoles) {
            throw new InvalidArgumentException('Trusted backup recovery catalog cannot be empty.');
        }
        foreach ($allowedBaselineHeads as $baseline => $heads) {
            if (!is_string($baseline) || $baseline === '' || !is_array($heads) || !$heads) {
                throw new InvalidArgumentException('Trusted backup baseline catalog is invalid.');
            }
        }
        foreach ($allowedPayloadRoles as $path => $role) {
            PackageManifest::validateSafeRelativePath($path, 'trusted backup payload path');
            if (!is_string($role) || $role === '') {
                throw new InvalidArgumentException('Trusted backup payload role is invalid.');
            }
        }
        return new self('backup', $archiveSha256, $manifestSha256, [], $allowedBaselineHeads, $allowedPayloadRoles);
    }

    public function assertArchiveSha256($sha256)
    {
        if (!hash_equals($this->archiveSha256, $sha256)) {
            throw new InvalidArgumentException('Archive bytes do not match the trusted detached digest.');
        }
    }

    public function assertManifestJson($manifestJson)
    {
        if (!hash_equals($this->manifestSha256, hash('sha256', $manifestJson))) {
            throw new InvalidArgumentException('Sidecar manifest bytes do not match trusted provenance.');
        }
    }

    public function assertManifest(array $manifest)
    {
        if ($manifest['package_kind'] !== $this->kind) {
            throw new InvalidArgumentException('Package kind does not match the trusted operation.');
        }
        if ($this->kind === 'release') {
            foreach ($this->releaseIdentity as $field => $value) {
                if (!isset($manifest[$field]) || !is_string($manifest[$field]) || !hash_equals($value, $manifest[$field])) {
                    throw new InvalidArgumentException('Release manifest does not match trusted ' . $field . '.');
                }
            }
            return;
        }
        $baseline = $manifest['schema_baseline'];
        $head = $manifest['schema_head'];
        if (!isset($this->backupBaselines[$baseline]) || !in_array($head, $this->backupBaselines[$baseline], true)) {
            throw new InvalidArgumentException('Backup baseline/head is absent from the trusted recovery catalog.');
        }
        foreach ($manifest['files'] as $file) {
            if (!isset($this->backupPaths[$file['path']]) || $this->backupPaths[$file['path']] !== $file['role']) {
                throw new InvalidArgumentException('Backup payload is absent from the trusted recovery catalog.');
            }
        }
    }

    public function kind() { return $this->kind; }
}

/** Immutable report only; it is never an extraction authorization capability. */
final class ArchiveValidationReport
{
    private $archiveSha256;
    private $archiveSize;
    private $manifest;
    private $verifiedEntries;

    public function __construct($archiveSha256, $archiveSize, array $manifest, array $verifiedEntries)
    {
        $this->archiveSha256 = $archiveSha256;
        $this->archiveSize = $archiveSize;
        $this->manifest = $manifest;
        $this->verifiedEntries = $verifiedEntries;
    }

    public function archiveSha256() { return $this->archiveSha256; }
    public function archiveSize() { return $this->archiveSize; }
    public function manifest() { return $this->manifest; }
    public function verifiedEntries() { return $this->verifiedEntries; }
}

interface ArchiveEntrySource
{
    public function inspection();
    public function openStream($index);
}

final class ZipArchiveEntrySource implements ArchiveEntrySource
{
    private $inspection;
    private $archive;

    public function __construct(ArchiveInspection $inspection)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The ZIP extension is required for package validation.');
        }
        $this->inspection = $inspection;
        $this->archive = new ZipArchive();
        $flags = defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0;
        $flags |= ZipArchive::CHECKCONS;
        if ($this->archive->open($inspection->archivePath(), $flags) !== true) {
            throw new InvalidArgumentException('Package archive cannot be opened consistently.');
        }
    }

    public function __destruct()
    {
        if ($this->archive instanceof ZipArchive) {
            $this->archive->close();
        }
    }

    public function inspection() { return $this->inspection; }

    public function openStream($index)
    {
        $entries = $this->inspection->entries();
        if (!isset($entries[$index])) {
            throw new InvalidArgumentException('Archive entry index is invalid.');
        }
        $stream = $this->archive->getStream($entries[$index]['name']);
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Archive entry stream cannot be opened.');
        }
        return $stream;
    }
}

final class RawZipInspector
{
    private $limits;

    public function __construct(array $limits) { $this->limits = $limits; }

    public function inspect($archivePath)
    {
        if (PHP_INT_SIZE < 8) {
            throw new RuntimeException('V1 archive validation requires 64-bit PHP.');
        }
        if (!is_string($archivePath) || !is_file($archivePath)) {
            throw new InvalidArgumentException('Archive path must identify a regular file.');
        }
        $archiveSize = filesize($archivePath);
        if (!is_int($archiveSize) || $archiveSize < 22 || $archiveSize > $this->limits['maximum_archive_bytes']) {
            throw new InvalidArgumentException('Archive byte size is outside the V1 limit.');
        }
        $handle = fopen($archivePath, 'rb');
        if (!is_resource($handle)) {
            throw new InvalidArgumentException('Archive cannot be opened for raw inspection.');
        }
        try {
            $eocdBytes = $this->readAt($handle, $archiveSize - 22, 22);
            $eocd = unpack('Vsignature/vdisk/vcentral_disk/ventries_disk/ventries_total/Vcentral_size/Vcentral_offset/vcomment_length', $eocdBytes);
            if ($eocd['signature'] !== 0x06054b50 || $eocd['comment_length'] !== 0
                || $eocd['disk'] !== 0 || $eocd['central_disk'] !== 0
                || $eocd['entries_disk'] !== $eocd['entries_total']
                || $eocd['entries_total'] < 1 || $eocd['entries_total'] > $this->limits['maximum_entries']
                || $eocd['entries_total'] === 0xffff || $eocd['central_size'] === 0xffffffff
                || $eocd['central_offset'] === 0xffffffff) {
                throw new InvalidArgumentException('Archive is not a supported single-disk V1 ZIP.');
            }
            if ($eocd['central_size'] > $this->limits['maximum_central_directory_bytes']
                || $eocd['central_offset'] + $eocd['central_size'] !== $archiveSize - 22) {
                throw new InvalidArgumentException('Archive central-directory boundary is inconsistent.');
            }

            $entries = [];
            $cursor = $eocd['central_offset'];
            $totalUncompressed = 0;
            $totalCompressed = 0;
            for ($index = 0; $index < $eocd['entries_total']; $index++) {
                $fixed = $this->readAt($handle, $cursor, 46);
                $central = unpack('Vsignature/vversion_made/vversion_needed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal_attributes/Vexternal_attributes/Vlocal_offset', $fixed);
                if ($central['signature'] !== 0x02014b50 || ($central['version_made'] >> 8) !== 3
                    || $central['disk_start'] !== 0 || $central['extra_length'] !== 0 || $central['comment_length'] !== 0
                    || $central['compressed_size'] === 0xffffffff || $central['uncompressed_size'] === 0xffffffff
                    || $central['local_offset'] === 0xffffffff || !in_array($central['method'], [0, 8], true)
                    || ($central['flags'] & ~0x0800) !== 0) {
                    throw new InvalidArgumentException('Archive central entry uses an unsupported V1 ZIP feature.');
                }
                $name = $this->readAt($handle, $cursor + 46, $central['name_length']);
                PackageManifest::validateSafeRelativePath($name, 'raw archive entry name');
                $modeWithType = ($central['external_attributes'] >> 16) & 0xffff;
                if (($modeWithType & 0170000) !== 0100000 || ($modeWithType & 07000) !== 0) {
                    throw new InvalidArgumentException('Archive entry is not a plain UNIX regular file.');
                }

                $localFixed = $this->readAt($handle, $central['local_offset'], 30);
                $local = unpack('Vsignature/vversion_needed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length', $localFixed);
                if ($local['signature'] !== 0x04034b50 || $local['flags'] !== $central['flags']
                    || $local['method'] !== $central['method'] || $local['crc'] !== $central['crc']
                    || $local['compressed_size'] !== $central['compressed_size']
                    || $local['uncompressed_size'] !== $central['uncompressed_size']
                    || $local['extra_length'] !== 0) {
                    throw new InvalidArgumentException('Archive local header differs from its central entry.');
                }
                $localName = $this->readAt($handle, $central['local_offset'] + 30, $local['name_length']);
                if ($localName !== $name) {
                    throw new InvalidArgumentException('Archive local and central names differ.');
                }
                $dataStart = $central['local_offset'] + 30 + $local['name_length'];
                $dataEnd = $dataStart + $central['compressed_size'];
                if ($dataEnd > $eocd['central_offset']) {
                    throw new InvalidArgumentException('Archive payload overlaps the central directory.');
                }
                $entries[] = [
                    'name' => $name,
                    'size' => (int) $central['uncompressed_size'],
                    'compressed_size' => (int) $central['compressed_size'],
                    'type' => 'file',
                    'mode' => $modeWithType & 0777,
                    'method' => (int) $central['method'],
                    'local_offset' => (int) $central['local_offset'],
                    'data_start' => (int) $dataStart,
                    'data_end' => (int) $dataEnd,
                ];
                $totalUncompressed += $central['uncompressed_size'];
                $totalCompressed += $central['compressed_size'];
                $cursor += 46 + $central['name_length'];
            }
            if ($cursor !== $eocd['central_offset'] + $eocd['central_size']) {
                throw new InvalidArgumentException('Archive central-directory size does not match its records.');
            }
            if ($totalUncompressed > $this->limits['maximum_total_bytes']
                || $totalCompressed > $this->limits['maximum_archive_bytes']
                || ($totalUncompressed > 0 && $totalCompressed === 0)
                || ($totalCompressed > 0 && ($totalUncompressed / $totalCompressed) > $this->limits['maximum_aggregate_compression_ratio'])) {
                throw new InvalidArgumentException('Archive aggregate size or compression ratio exceeds V1 limits.');
            }
            $ranges = $entries;
            usort($ranges, function ($left, $right) { return $left['local_offset'] <=> $right['local_offset']; });
            $rangeCursor = 0;
            foreach ($ranges as $entry) {
                if ($entry['local_offset'] !== $rangeCursor) {
                    throw new InvalidArgumentException('Archive contains a preamble, gap, overlap, or aliased local entry.');
                }
                $rangeCursor = $entry['data_end'];
            }
            if ($rangeCursor !== $eocd['central_offset']) {
                throw new InvalidArgumentException('Archive local records do not end at the central directory.');
            }
        } finally {
            fclose($handle);
        }
        $digest = hash_file('sha256', $archivePath);
        if (!is_string($digest)) {
            throw new InvalidArgumentException('Archive digest could not be calculated.');
        }
        return new ArchiveInspection($archivePath, $digest, $archiveSize, $entries);
    }

    private function readAt($handle, $offset, $length)
    {
        if (!is_int($offset) || !is_int($length) || $offset < 0 || $length < 0 || fseek($handle, $offset) !== 0) {
            throw new InvalidArgumentException('Archive structure contains an invalid offset.');
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($handle, $length - strlen($bytes));
            if (!is_string($chunk) || $chunk === '') {
                throw new InvalidArgumentException('Archive structure is truncated.');
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }
}

final class ArchiveSafetyReader
{
    private $limits;

    public function __construct(array $limits = [])
    {
        $defaults = [
            'maximum_archive_bytes' => 536870912,
            'maximum_entries' => 2048,
            'maximum_central_directory_bytes' => 16777216,
            'maximum_manifest_bytes' => 2097152,
            'maximum_file_bytes' => 268435456,
            'maximum_total_bytes' => 2147483648,
            'maximum_compression_ratio' => 100,
            'maximum_aggregate_compression_ratio' => 20,
            'maximum_recovery_metadata_bytes' => 1048576,
            'maximum_logical_records' => 100000,
        ];
        $unknown = array_diff_key($limits, $defaults);
        if ($unknown) {
            throw new InvalidArgumentException('Unknown archive-reader limit: ' . implode(', ', array_keys($unknown)));
        }
        $this->limits = array_merge($defaults, $limits);
        foreach ($this->limits as $name => $value) {
            if (!is_int($value) || $value < 1) {
                throw new InvalidArgumentException($name . ' must be a positive integer.');
            }
        }
    }

    public function validate($archivePath, $manifestJson, ArchiveValidationContext $context)
    {
        $snapshot = $this->snapshotArchive($archivePath);
        try {
            $inspection = (new RawZipInspector($this->limits))->inspect($snapshot);
            return $this->validateSource(new ZipArchiveEntrySource($inspection), $manifestJson, $context);
        } finally {
            @unlink($snapshot);
        }
    }

    private function validateSource(ArchiveEntrySource $source, $manifestJson, ArchiveValidationContext $context)
    {
        if (!is_string($manifestJson) || strlen($manifestJson) > $this->limits['maximum_manifest_bytes']) {
            throw new InvalidArgumentException('Trusted sidecar manifest exceeds the V1 size limit.');
        }
        $inspection = $source->inspection();
        $context->assertArchiveSha256($inspection->archiveSha256());
        $context->assertManifestJson($manifestJson);
        $manifest = PackageManifest::parse($manifestJson);
        $context->assertManifest($manifest);
        $entries = $inspection->entries();
        if (!$entries || count($entries) > $this->limits['maximum_entries']) {
            throw new InvalidArgumentException('Archive entry count is outside the V1 limit.');
        }

        $actual = [];
        $caseFolded = [];
        $totalBytes = 0;
        foreach ($entries as $index => $entry) {
            $this->validateEntryMetadata($entry, $index);
            $name = $entry['name'];
            if (isset($actual[$name])) {
                throw new InvalidArgumentException('Archive contains a duplicate entry name.');
            }
            $folded = strtolower($name);
            if (isset($caseFolded[$folded])) {
                throw new InvalidArgumentException('Archive entries collide after ASCII case folding.');
            }
            $caseFolded[$folded] = true;
            $actual[$name] = ['index' => $index] + $entry;
            $totalBytes += $entry['size'];
            if ($totalBytes > $this->limits['maximum_total_bytes']) {
                throw new InvalidArgumentException('Archive uncompressed size exceeds the V1 total limit.');
            }
        }

        $expected = [];
        foreach ($manifest['files'] as $file) { $expected[$file['path']] = $file; }
        $actualNames = array_keys($actual);
        $expectedNames = array_keys($expected);
        sort($actualNames, SORT_STRING);
        sort($expectedNames, SORT_STRING);
        if ($actualNames !== $expectedNames) {
            throw new InvalidArgumentException('Archive entries do not exactly equal the sidecar manifest inventory.');
        }

        $verified = [];
        $actualCanonical = [];
        $logicalRecords = 0;
        foreach ($manifest['files'] as $file) {
            $entry = $actual[$file['path']];
            $derivedRole = PackageManifest::expectedRoleForPath($manifest['package_kind'], $entry['name']);
            if ($derivedRole !== $file['role'] || $entry['type'] !== 'file'
                || $entry['size'] !== $file['size'] || $entry['mode'] !== $file['mode']) {
                throw new InvalidArgumentException('Archive entry facts differ from manifest claims.');
            }
            $capture = in_array($derivedRole, ['recovery_metadata', 'portable_secret'], true)
                ? $this->limits['maximum_recovery_metadata_bytes'] : 0;
            $result = $this->hashEntry(
                $source, $entry['index'], $entry['size'], $manifest['package_kind'], $derivedRole, $capture,
                $this->limits['maximum_logical_records'] - $logicalRecords
            );
            $logicalRecords += $result['logical_records'];
            if (!hash_equals($file['sha256'], $result['sha256'])) {
                throw new InvalidArgumentException('Archive entry digest does not match the manifest.');
            }
            if ($derivedRole === 'recovery_metadata') {
                $this->validateRecoveryMetadata($result['captured'], $manifest);
            } elseif ($derivedRole === 'portable_secret') {
                $this->validatePortableSecret($result['captured']);
            } elseif ($derivedRole === 'persistent_asset') {
                $this->validatePersistentAsset($entry['name'], $result['prefix']);
            }
            $actualCanonical[] = [
                'path' => $entry['name'], 'type' => 'file', 'role' => $derivedRole,
                'mode' => $entry['mode'], 'size' => $entry['size'], 'sha256' => $result['sha256'],
            ];
            $verified[] = ['path' => $entry['name'], 'size' => $entry['size'], 'sha256' => $result['sha256']];
        }
        if (!hash_equals($manifest['content_tree_sha256'], PackageManifest::calculateContentTreeSha256($actualCanonical))) {
            throw new InvalidArgumentException('Verified archive facts do not reproduce the manifest content-tree digest.');
        }
        return new ArchiveValidationReport($inspection->archiveSha256(), $inspection->archiveSize(), $manifest, $verified);
    }

    private function validateEntryMetadata(array $entry, $index)
    {
        foreach (['name', 'size', 'compressed_size', 'type', 'mode'] as $field) {
            if (!array_key_exists($field, $entry)) {
                throw new InvalidArgumentException('Archive entry #' . $index . ' lacks ' . $field . '.');
            }
        }
        PackageManifest::validateSafeRelativePath($entry['name'], 'archive entry name');
        if ($entry['type'] !== 'file' || !is_int($entry['mode']) || $entry['mode'] < 0 || $entry['mode'] > 0777) {
            throw new InvalidArgumentException('Archive entry must be a plain regular file with known permissions.');
        }
        if (!is_int($entry['size']) || $entry['size'] < 0 || $entry['size'] > $this->limits['maximum_file_bytes']) {
            throw new InvalidArgumentException('Archive entry size exceeds the V1 per-file limit.');
        }
        if (!is_int($entry['compressed_size']) || $entry['compressed_size'] < 0
            || ($entry['size'] > 0 && ($entry['compressed_size'] === 0
                || ($entry['size'] / $entry['compressed_size']) > $this->limits['maximum_compression_ratio']))) {
            throw new InvalidArgumentException('Archive entry compression ratio exceeds the V1 limit.');
        }
    }

    private function hashEntry(ArchiveEntrySource $source, $index, $expectedSize, $kind, $role, $captureLimit, $recordBudget)
    {
        $stream = $source->openStream($index);
        $hash = hash_init('sha256');
        $readBytes = 0;
        $captured = '';
        $prefix = '';
        $lineBuffer = '';
        $recordCount = 0;
        $emptyReads = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if (!is_string($chunk)) {
                    throw new InvalidArgumentException('Archive entry stream failed.');
                }
                if ($chunk === '') {
                    if (++$emptyReads > 3) {
                        throw new InvalidArgumentException('Archive entry stream stalled.');
                    }
                    continue;
                }
                $emptyReads = 0;
                if ($captureLimit > 0) {
                    $captured .= $chunk;
                    if (strlen($captured) > $captureLimit) {
                        throw new InvalidArgumentException('Recovery metadata exceeds its closed-schema limit.');
                    }
                }
                if (strlen($prefix) < 16) { $prefix .= substr($chunk, 0, 16 - strlen($prefix)); }
                if ($kind === 'backup' && $role === 'logical_data') {
                    $lineBuffer .= $chunk;
                    $lineStart = 0;
                    while (($lineEnd = strpos($lineBuffer, "\n", $lineStart)) !== false) {
                        if (($lineEnd - $lineStart) > 2097152) {
                            throw new InvalidArgumentException('Backup NDJSON record exceeds the V1 line limit.');
                        }
                        $line = substr($lineBuffer, $lineStart, $lineEnd - $lineStart);
                        $this->validateLogicalDataLine($line);
                        $recordCount++;
                        if ($recordCount > $recordBudget) {
                            throw new InvalidArgumentException('Backup logical-data record count exceeds the V1 limit.');
                        }
                        $lineStart = $lineEnd + 1;
                    }
                    if ($lineStart > 0) {
                        $lineBuffer = substr($lineBuffer, $lineStart);
                    }
                    if (strlen($lineBuffer) > 2097152) {
                        throw new InvalidArgumentException('Backup NDJSON record exceeds the V1 line limit.');
                    }
                }
                $readBytes += strlen($chunk);
                if ($readBytes > $expectedSize || $readBytes > $this->limits['maximum_file_bytes']) {
                    throw new InvalidArgumentException('Archive stream expanded beyond declared limits.');
                }
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }
        if ($readBytes !== $expectedSize) {
            throw new InvalidArgumentException('Archive stream size differs from inspected metadata.');
        }
        if ($kind === 'backup' && $role === 'logical_data' && ($lineBuffer !== '' || $recordCount < 1)) {
            throw new InvalidArgumentException('Backup logical data must be nonempty NDJSON with a final LF.');
        }
        return ['sha256' => hash_final($hash), 'captured' => $captured, 'prefix' => $prefix, 'logical_records' => $recordCount];
    }

    private function validateRecoveryMetadata($json, array $manifest)
    {
        PackageManifest::assertNoDuplicateJsonObjectKeys($json);
        $wire = json_decode($json, false, 32, JSON_BIGINT_AS_STRING);
        if (!($wire instanceof stdClass) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Backup recovery metadata must be a JSON object.');
        }
        foreach (['data_files', 'asset_files', 'secret_files'] as $field) {
            if (!property_exists($wire, $field) || !is_array($wire->{$field})) {
                throw new InvalidArgumentException('Backup recovery metadata file lists must be JSON arrays.');
            }
        }
        $metadata = json_decode($json, true, 32, JSON_BIGINT_AS_STRING);
        if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Backup recovery metadata must be valid JSON.');
        }
        $allowed = ['contract_name', 'format_version', 'baseline_id', 'schema_head', 'data_files', 'asset_files', 'secret_files'];
        foreach ($metadata as $key => $_value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Backup recovery metadata contains schema or policy authority.');
            }
        }
        if (!isset($metadata['contract_name'], $metadata['format_version'], $metadata['baseline_id'], $metadata['schema_head'])
            || $metadata['contract_name'] !== 'syndicatum-backup-metadata' || $metadata['format_version'] !== '1.0'
            || $metadata['baseline_id'] !== $manifest['schema_baseline'] || $metadata['schema_head'] !== $manifest['schema_head']) {
            throw new InvalidArgumentException('Backup recovery metadata identity is invalid.');
        }
        $roleFields = ['logical_data' => 'data_files', 'persistent_asset' => 'asset_files', 'portable_secret' => 'secret_files'];
        foreach ($roleFields as $role => $field) {
            if (!isset($metadata[$field]) || !is_array($metadata[$field])) {
                throw new InvalidArgumentException('Backup recovery metadata file lists are required.');
            }
            $expected = [];
            foreach ($manifest['files'] as $file) { if ($file['role'] === $role) { $expected[] = $file['path']; } }
            if ($metadata[$field] !== $expected) {
                throw new InvalidArgumentException('Backup recovery metadata file lists do not match the manifest.');
            }
        }
    }

    private function validatePortableSecret($json)
    {
        PackageManifest::assertNoDuplicateJsonObjectKeys($json);
        $decoded = json_decode($json, false, 32, JSON_BIGINT_AS_STRING);
        if (!($decoded instanceof stdClass) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Portable secret payload must be a JSON object.');
        }
    }

    private function validatePersistentAsset($path, $prefix)
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $valid = ($extension === 'png' && substr($prefix, 0, 8) === "\x89PNG\x0d\x0a\x1a\x0a")
            || (($extension === 'jpg' || $extension === 'jpeg') && substr($prefix, 0, 3) === "\xff\xd8\xff")
            || ($extension === 'gif' && in_array(substr($prefix, 0, 6), ['GIF87a', 'GIF89a'], true))
            || ($extension === 'webp' && substr($prefix, 0, 4) === 'RIFF' && substr($prefix, 8, 4) === 'WEBP');
        if (!$valid) {
            throw new InvalidArgumentException('Persistent asset bytes do not match the allowlisted media type.');
        }
    }

    private function snapshotArchive($archivePath)
    {
        if (!is_string($archivePath) || !is_file($archivePath)) {
            throw new InvalidArgumentException('Archive path must identify a regular file.');
        }
        $source = fopen($archivePath, 'rb');
        if (!is_resource($source)) {
            throw new InvalidArgumentException('Archive cannot be opened for validation.');
        }
        $stat = fstat($source);
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0100000)) {
            fclose($source);
            throw new InvalidArgumentException('Archive input must be a regular file.');
        }
        $snapshot = tempnam(sys_get_temp_dir(), 'syndicatum-archive-');
        if ($snapshot === false || @chmod($snapshot, 0600) === false) {
            fclose($source);
            if (is_string($snapshot)) { @unlink($snapshot); }
            throw new RuntimeException('Private archive snapshot could not be allocated.');
        }
        $target = fopen($snapshot, 'w+b');
        if (!is_resource($target)) {
            fclose($source);
            @unlink($snapshot);
            throw new RuntimeException('Private archive snapshot could not be opened.');
        }
        try {
            $copied = stream_copy_to_stream($source, $target, $this->limits['maximum_archive_bytes'] + 1);
            $extra = fread($source, 1);
            if (!is_int($copied) || $copied < 1 || $copied > $this->limits['maximum_archive_bytes'] || $extra !== '') {
                throw new InvalidArgumentException('Archive input exceeds the V1 byte limit or changed while being read.');
            }
            fflush($target);
            if (function_exists('fsync')) { fsync($target); }
        } catch (Throwable $exception) {
            fclose($source);
            fclose($target);
            @unlink($snapshot);
            throw $exception;
        }
        fclose($source);
        fclose($target);
        return $snapshot;
    }

    private function validateLogicalDataLine($line)
    {
        if ($line === '' || substr($line, -1) === "\r") {
            throw new InvalidArgumentException('Backup logical data contains an empty or non-LF record.');
        }
        PackageManifest::assertNoDuplicateJsonObjectKeys($line);
        $decoded = json_decode($line, false, 32, JSON_BIGINT_AS_STRING);
        if (!($decoded instanceof stdClass) || json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Backup logical data records must be JSON objects.');
        }
    }
}
