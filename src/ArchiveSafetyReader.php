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
    private $requiredBackupPaths;

    private function __construct($kind, $archiveSha256, $manifestSha256, array $releaseIdentity, array $backupBaselines, array $backupPaths, array $requiredBackupPaths)
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
        $this->requiredBackupPaths = $requiredBackupPaths;
    }

    public static function forRelease($archiveSha256, $manifestSha256, array $identity)
    {
        foreach (['source_commit', 'source_tag', 'schema_baseline', 'schema_head'] as $field) {
            if (!isset($identity[$field]) || !is_string($identity[$field]) || $identity[$field] === '') {
                throw new InvalidArgumentException('Trusted release identity requires ' . $field . '.');
            }
        }
        return new self('release', $archiveSha256, $manifestSha256, $identity, [], [], []);
    }

    public static function forBackup($archiveSha256, $manifestSha256, array $allowedBaselineHeads, array $allowedPayloadRoles, array $requiredPayloadPaths)
    {
        if (!$allowedBaselineHeads || !$allowedPayloadRoles || !$requiredPayloadPaths) {
            throw new InvalidArgumentException('Trusted backup recovery catalog cannot be empty.');
        }
        foreach ($allowedBaselineHeads as $baseline => $heads) {
            if (!is_string($baseline) || $baseline === '' || !is_array($heads) || !$heads) {
                throw new InvalidArgumentException('Trusted backup baseline catalog is invalid.');
            }
        }
        foreach ($allowedPayloadRoles as $path => $role) {
            PackageManifest::validateSafeRelativePath($path, 'trusted backup payload path');
            if (!is_string($role) || PackageManifest::expectedRoleForPath('backup', $path) !== $role) {
                throw new InvalidArgumentException('Trusted backup payload role is invalid.');
            }
        }
        $required = [];
        foreach ($requiredPayloadPaths as $path) {
            PackageManifest::validateSafeRelativePath($path, 'required trusted backup payload path');
            if (!isset($allowedPayloadRoles[$path]) || isset($required[$path])) {
                throw new InvalidArgumentException('Required backup payload must be unique and present in the trusted catalog.');
            }
            $required[$path] = true;
        }
        return new self('backup', $archiveSha256, $manifestSha256, [], $allowedBaselineHeads, $allowedPayloadRoles, $required);
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
        $manifestPaths = [];
        foreach ($manifest['files'] as $file) { $manifestPaths[$file['path']] = true; }
        foreach ($this->requiredBackupPaths as $path => $_required) {
            if (!isset($manifestPaths[$path])) {
                throw new InvalidArgumentException('Backup omits a required payload from the trusted recovery catalog.');
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

/** Staging access only; this object grants no install, cutover, or restore authority. */
final class ArchiveExtractionStage
{
    private $path;
    private $validationReport;

    public function __construct($path, ArchiveValidationReport $validationReport)
    {
        $this->path = $path;
        $this->validationReport = $validationReport;
    }

    public function path() { return $this->path; }
    public function validationReport() { return $this->validationReport; }
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
        try { $this->close(); } catch (Throwable $ignored) {}
    }

    public function close()
    {
        if ($this->archive instanceof ZipArchive) {
            $closed = $this->archive->close();
            $this->archive = null;
            if ($closed === false) {
                throw new RuntimeException('Validated ZIP session could not be closed cleanly.');
            }
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
        $source = null;
        $report = null;
        $failure = null;
        try {
            $inspection = (new RawZipInspector($this->limits))->inspect($snapshot);
            $source = new ZipArchiveEntrySource($inspection);
            $report = $this->validateSource($source, $manifestJson, $context);
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $failure = $this->finalizeSnapshot($source, $snapshot, $failure);
        if ($failure instanceof Throwable) { throw $failure; }
        return $report;
    }

    public function extractToNewStage($archivePath, $manifestJson, ArchiveValidationContext $context, $stagingRoot, $publicWebRoot)
    {
        $trustedStagingRoot = $this->resolveTrustedStagingRoot($stagingRoot, $publicWebRoot);
        $snapshot = $this->snapshotArchive($archivePath, $trustedStagingRoot);
        $source = null;
        $stagePath = null;
        $createdPaths = [];
        $stage = null;
        $failure = null;
        try {
            $inspection = (new RawZipInspector($this->limits))->inspect($snapshot);
            $source = new ZipArchiveEntrySource($inspection);
            $report = $this->validateSource($source, $manifestJson, $context);
            $stagePath = $this->createPrivateStage($trustedStagingRoot);
            $this->extractSourceToStage($source, $report, $stagePath, $createdPaths);
            $context->assertArchiveSha256(hash_file('sha256', $snapshot));
            $stage = new ArchiveExtractionStage($stagePath, $report);
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $failure = $this->finalizeSnapshot($source, $snapshot, $failure);
        if ($failure instanceof Throwable) {
            if (is_string($stagePath)) {
                try {
                    $this->removeCreatedStagePaths($stagePath, $trustedStagingRoot, $createdPaths);
                } catch (Throwable $cleanupFailure) {
                    throw new RuntimeException(
                        'Extraction failed and private-stage cleanup is incomplete at ' . $stagePath . ': ' . $cleanupFailure->getMessage(),
                        0,
                        $failure
                    );
                }
            }
            throw $failure;
        }
        return $stage;
    }

    private function finalizeSnapshot($source, $snapshot, $failure)
    {
        if ($source instanceof ZipArchiveEntrySource) {
            try {
                $source->close();
            } catch (Throwable $closeFailure) {
                $failure = new RuntimeException('Archive operation failed to close its private snapshot session.', 0, $failure ?: $closeFailure);
            }
        }
        return $this->deletePrivateSnapshot($snapshot, $failure);
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
            $verified[] = [
                'path' => $entry['name'], 'size' => $entry['size'], 'sha256' => $result['sha256'],
                'mode' => $entry['mode'], 'role' => $derivedRole, 'index' => $entry['index'],
            ];
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
        if ($kind === 'backup' && $role === 'logical_data' && $lineBuffer !== '') {
            throw new InvalidArgumentException('Backup logical data must use NDJSON records with a final LF.');
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

    private function snapshotArchive($archivePath, $snapshotRoot = null)
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
            $failure = $this->closeStream($source, 'Archive input could not be closed cleanly.', new InvalidArgumentException('Archive input must be a regular file.'));
            throw $failure;
        }
        $snapshotDirectory = is_string($snapshotRoot) ? $snapshotRoot : sys_get_temp_dir();
        $snapshot = tempnam($snapshotDirectory, 'syndicatum-archive-');
        if ($snapshot === false) {
            $failure = $this->closeStream($source, 'Archive input could not be closed cleanly.', new RuntimeException('Private archive snapshot could not be allocated.'));
            throw $failure;
        }
        if (@chmod($snapshot, 0600) === false) {
            $failure = new RuntimeException('Private archive snapshot could not be restricted.');
            $failure = $this->closeStream($source, 'Archive input could not be closed cleanly.', $failure);
            throw $this->deletePrivateSnapshot($snapshot, $failure);
        }
        $target = fopen($snapshot, 'w+b');
        if (!is_resource($target)) {
            $failure = $this->closeStream($source, 'Archive input could not be closed cleanly.', new RuntimeException('Private archive snapshot could not be opened.'));
            throw $this->deletePrivateSnapshot($snapshot, $failure);
        }
        $failure = null;
        try {
            $copied = stream_copy_to_stream($source, $target, $this->limits['maximum_archive_bytes'] + 1);
            $extra = fread($source, 1);
            if (!is_int($copied) || $copied < 1 || $copied > $this->limits['maximum_archive_bytes'] || $extra !== '') {
                throw new InvalidArgumentException('Archive input exceeds the V1 byte limit or changed while being read.');
            }
            if (!fflush($target) || (function_exists('fsync') && !fsync($target))) {
                throw new RuntimeException('Private archive snapshot could not be flushed durably.');
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $failure = $this->closeStream($source, 'Archive input could not be closed cleanly.', $failure);
        $failure = $this->closeStream($target, 'Private archive snapshot could not be closed cleanly.', $failure);
        if ($failure instanceof Throwable) {
            throw $this->deletePrivateSnapshot($snapshot, $failure);
        }
        return $snapshot;
    }

    private function closeStream($stream, $message, $failure)
    {
        if (!is_resource($stream)) { return $failure; }
        try {
            if (!fclose($stream)) {
                $closeFailure = new RuntimeException($message);
                return new RuntimeException($message, 0, $failure ?: $closeFailure);
            }
        } catch (Throwable $closeFailure) {
            return new RuntimeException($message, 0, $failure ?: $closeFailure);
        }
        return $failure;
    }

    private function deletePrivateSnapshot($snapshot, $failure)
    {
        try {
            if (!@unlink($snapshot)) {
                $deleteFailure = new RuntimeException('Private archive snapshot cleanup is incomplete at ' . $snapshot . '.');
                return new RuntimeException($deleteFailure->getMessage(), 0, $failure ?: $deleteFailure);
            }
        } catch (Throwable $deleteFailure) {
            return new RuntimeException('Private archive snapshot cleanup is incomplete at ' . $snapshot . '.', 0, $failure ?: $deleteFailure);
        }
        return $failure;
    }

    private function resolveTrustedStagingRoot($stagingRoot, $publicWebRoot)
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('posix_geteuid')) {
            throw new RuntimeException('V1 controlled extraction requires a POSIX private staging filesystem.');
        }
        $staging = is_string($stagingRoot) ? realpath($stagingRoot) : false;
        $public = is_string($publicWebRoot) ? realpath($publicWebRoot) : false;
        if (!is_string($staging) || !is_dir($staging) || !is_writable($staging)
            || !is_string($public) || !is_dir($public)) {
            throw new InvalidArgumentException('Trusted staging and public-web roots must be existing directories.');
        }
        $stagingStat = @lstat($stagingRoot);
        $permissions = @fileperms($staging);
        $owner = @fileowner($staging);
        if (!is_array($stagingStat) || (($stagingStat['mode'] & 0170000) !== 0040000)
            || !is_int($permissions) || ($permissions & 0077) !== 0
            || !is_int($owner) || $owner !== posix_geteuid()) {
            throw new InvalidArgumentException('Trusted staging root must be a non-symlink directory owned by this process with no group/world access.');
        }
        if ($this->pathIsWithin($staging, $public) || $this->pathIsWithin($public, $staging)) {
            throw new InvalidArgumentException('Archive staging and public web roots must be fully disjoint.');
        }
        return rtrim($staging, DIRECTORY_SEPARATOR);
    }

    private function pathIsWithin($candidate, $parent)
    {
        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        $parent = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $parent), DIRECTORY_SEPARATOR);
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidate = strtolower($candidate);
            $parent = strtolower($parent);
        }
        return $candidate === $parent || strpos($candidate, $parent . DIRECTORY_SEPARATOR) === 0;
    }

    private function createPrivateStage($stagingRoot)
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $path = $stagingRoot . DIRECTORY_SEPARATOR . 'syndicatum-stage-' . bin2hex(random_bytes(16));
            if (@mkdir($path, 0700, false)) {
                @chmod($path, 0700);
                $resolved = realpath($path);
                if (!is_string($resolved) || $resolved !== $path || !$this->pathIsWithin($resolved, $stagingRoot)) {
                    @rmdir($path);
                    throw new RuntimeException('New private stage failed canonical containment verification.');
                }
                return $path;
            }
        }
        throw new RuntimeException('A new private extraction stage could not be created.');
    }

    private function extractSourceToStage(ZipArchiveEntrySource $source, ArchiveValidationReport $report, $stagePath, array &$createdPaths)
    {
        $kind = $report->manifest()['package_kind'];
        foreach ($report->verifiedEntries() as $entry) {
            $segments = explode('/', $entry['path']);
            $fileName = array_pop($segments);
            $directory = $stagePath;
            foreach ($segments as $segment) {
                $directory .= DIRECTORY_SEPARATOR . $segment;
                $stat = @lstat($directory);
                if ($stat === false) {
                    if (!@mkdir($directory, 0700, false)) {
                        throw new RuntimeException('Private staging directory could not be created.');
                    }
                    @chmod($directory, 0700);
                    $createdPaths[] = $directory;
                } elseif (($stat['mode'] & 0170000) !== 0040000) {
                    throw new InvalidArgumentException('A staging path already exists and is not a directory.');
                }
            }
            $target = $directory . DIRECTORY_SEPARATOR . $fileName;
            if (@lstat($target) !== false) {
                throw new InvalidArgumentException('A staging target already exists.');
            }
            $input = $source->openStream($entry['index']);
            $output = @fopen($target, 'xb');
            if (!is_resource($output)) {
                fclose($input);
                throw new RuntimeException('A staging file could not be created exclusively.');
            }
            $createdPaths[] = $target;
            $hash = hash_init('sha256');
            $writtenBytes = 0;
            $failure = null;
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 65536);
                    if (!is_string($chunk)) {
                        throw new RuntimeException('Validated archive stream failed during extraction.');
                    }
                    if ($chunk === '') { continue; }
                    $offset = 0;
                    while ($offset < strlen($chunk)) {
                        $written = fwrite($output, substr($chunk, $offset));
                        if (!is_int($written) || $written < 1) {
                            throw new RuntimeException('Staging file write failed.');
                        }
                        $offset += $written;
                    }
                    $writtenBytes += strlen($chunk);
                    if ($writtenBytes > $entry['size']) {
                        throw new InvalidArgumentException('Extracted bytes exceed the accepted validation report.');
                    }
                    hash_update($hash, $chunk);
                }
                if ($writtenBytes !== $entry['size'] || !hash_equals($entry['sha256'], hash_final($hash))) {
                    throw new InvalidArgumentException('Extracted bytes differ from the accepted validation report.');
                }
                if (!fflush($output) || (function_exists('fsync') && !fsync($output))) {
                    throw new RuntimeException('Staging file could not be flushed durably.');
                }
            } catch (Throwable $exception) {
                $failure = $exception;
            }
            $failure = $this->closeStream($input, 'Validated archive stream could not be closed cleanly.', $failure);
            $failure = $this->closeStream($output, 'Staging file could not be closed cleanly.', $failure);
            if ($failure instanceof Throwable) { throw $failure; }
            $mode = $kind === 'backup' ? 0600 : $entry['mode'];
            if (!@chmod($target, $mode)) {
                throw new RuntimeException('Staging file permissions could not be applied.');
            }
        }
    }

    private function removeCreatedStagePaths($stagePath, $trustedStagingRoot, array $createdPaths)
    {
        if (!$this->pathIsWithin($stagePath, $trustedStagingRoot) || $stagePath === $trustedStagingRoot) {
            throw new RuntimeException('Refusing unsafe staging cleanup.');
        }
        usort($createdPaths, function ($left, $right) { return strlen($right) <=> strlen($left); });
        foreach ($createdPaths as $path) {
            if (!$this->pathIsWithin($path, $stagePath) || $path === $stagePath) {
                throw new RuntimeException('Refusing cleanup of an unrecorded staging path.');
            }
            $stat = @lstat($path);
            if ($stat === false) { continue; }
            if (($stat['mode'] & 0170000) === 0040000) {
                if (!@rmdir($path)) { throw new RuntimeException('Staging cleanup could not remove a recorded directory.'); }
            } elseif (!@unlink($path)) {
                throw new RuntimeException('Staging cleanup could not remove a recorded file.');
            }
        }
        if (!@rmdir($stagePath)) { throw new RuntimeException('Staging cleanup could not remove the private stage.'); }
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
