<?php

require_once dirname(__DIR__) . '/src/PackageManifest.php';
require_once dirname(__DIR__) . '/src/ArchiveSafetyReader.php';

function archiveFail($message)
{
    fwrite(STDERR, 'FAIL  ' . $message . PHP_EOL);
    exit(1);
}

function archiveThrows(callable $callback, $message)
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    archiveFail($message);
}

function archiveManifest($kind, array $payloads)
{
    ksort($payloads, SORT_STRING);
    $files = [];
    foreach ($payloads as $path => $details) {
        $files[] = [
            'path' => $path, 'type' => 'file', 'role' => $details['role'],
            'mode' => isset($details['mode']) ? $details['mode'] : 0644,
            'size' => strlen($details['content']), 'sha256' => hash('sha256', $details['content']),
        ];
    }
    $manifest = [
        'contract_name' => 'syndicatum-package', 'format_version' => '1.0', 'package_kind' => $kind,
        'required_capabilities' => ['canonical-inventory-jsonl-v1', 'regular-files-only-v1', 'sha256-v1'],
        'application_version' => '1.0.0', 'source_commit' => str_repeat('a', 40), 'source_tag' => 'v1.0.0',
        'schema_baseline' => 'baseline.1', 'schema_head' => '202609200000',
        'source_timestamp' => '2026-09-20T00:00:00Z',
        'compatibility' => [
            'php' => ['minimum' => '7.4.0', 'maximum_exclusive' => '8.5.0', 'extensions' => ['json', 'zip']],
            'mysql' => ['minimum' => '8.4.0', 'maximum_exclusive' => '9.0.0', 'sql_modes' => ['STRICT_TRANS_TABLES'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
        ],
        'minimum_reader_version' => '1.0.0', 'supported_upgrade_sources' => [],
        'contains_data' => $kind === 'backup', 'contains_persistent_assets' => false,
        'files' => $files, 'digest_algorithm' => 'sha256', 'content_tree_sha256' => '',
        'detached_checksum_reference' => 'checksums/package.zip.sha256',
        'provenance_reference' => 'provenance/build.json',
    ];
    $manifest['content_tree_sha256'] = PackageManifest::calculateContentTreeSha256($files);
    return $manifest;
}

function manifestJson(array $manifest)
{
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) { archiveFail('Unable to encode manifest fixture.'); }
    return $json;
}

function archiveContext($kind, $archiveSha256, $manifestJson)
{
    $manifestSha256 = hash('sha256', $manifestJson);
    if ($kind === 'release') {
        return ArchiveValidationContext::forRelease($archiveSha256, $manifestSha256, [
            'source_commit' => str_repeat('a', 40), 'source_tag' => 'v1.0.0',
            'schema_baseline' => 'baseline.1', 'schema_head' => '202609200000',
        ]);
    }
    $decoded = json_decode($manifestJson, true);
    $roles = [];
    if (is_array($decoded) && isset($decoded['files']) && is_array($decoded['files'])) {
        foreach ($decoded['files'] as $file) {
            if (isset($file['path'], $file['role'])) { $roles[$file['path']] = $file['role']; }
        }
    }
    return ArchiveValidationContext::forBackup($archiveSha256, $manifestSha256, ['baseline.1' => ['202609200000']], $roles);
}

function zipEntries(array $payloads)
{
    $entries = [];
    foreach ($payloads as $path => $details) {
        $entries[] = [
            'name' => $path, 'content' => $details['content'],
            'mode' => isset($details['mode']) ? $details['mode'] : 0644,
            'type' => 0100000, 'method' => isset($details['method']) ? $details['method'] : 0,
        ];
    }
    return $entries;
}

function writeRawZip(array $entries, array $options = [])
{
    $locals = '';
    $records = [];
    foreach (array_values($entries) as $entry) {
        $name = $entry['name'];
        $localName = isset($entry['local_name']) ? $entry['local_name'] : $name;
        $content = $entry['content'];
        $method = isset($entry['method']) ? $entry['method'] : 0;
        $compressed = $method === 8 ? gzdeflate($content, 9) : $content;
        if (!is_string($compressed)) { archiveFail('Unable to deflate ZIP fixture.'); }
        $flags = isset($entry['flags']) ? $entry['flags'] : 0;
        $localFlags = isset($entry['local_flags']) ? $entry['local_flags'] : $flags;
        $localExtra = isset($entry['local_extra']) ? $entry['local_extra'] : '';
        $centralExtra = isset($entry['central_extra']) ? $entry['central_extra'] : '';
        $comment = isset($entry['comment']) ? $entry['comment'] : '';
        $crc = hexdec(hash('crc32b', $content));
        $declaredSize = isset($entry['declared_uncompressed_size']) ? $entry['declared_uncompressed_size'] : strlen($content);
        $offset = strlen($locals);
        $locals .= pack(
            'VvvvvvVVVvv', 0x04034b50, 20, $localFlags, $method, 0, 0, $crc,
            strlen($compressed), $declaredSize, strlen($localName), strlen($localExtra)
        ) . $localName . $localExtra . $compressed;
        $records[] = [
            'entry' => $entry, 'name' => $name, 'flags' => $flags, 'method' => $method,
            'crc' => $crc, 'compressed' => $compressed, 'content' => $content,
            'declared_size' => $declaredSize, 'extra' => $centralExtra, 'comment' => $comment, 'offset' => $offset,
        ];
    }
    $central = '';
    foreach ($records as $record) {
        $entry = $record['entry'];
        $type = isset($entry['type']) ? $entry['type'] : 0100000;
        $mode = isset($entry['mode']) ? $entry['mode'] : 0644;
        $offset = array_key_exists('central_local_offset', $entry) ? $entry['central_local_offset'] : $record['offset'];
        $compressedSize = !empty($entry['zip64_size']) ? 0xffffffff : strlen($record['compressed']);
        $uncompressedSize = !empty($entry['zip64_size']) ? 0xffffffff : $record['declared_size'];
        $central .= pack(
            'VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 20, 20, $record['flags'], $record['method'], 0, 0,
            $record['crc'], $compressedSize, $uncompressedSize,
            strlen($record['name']), strlen($record['extra']), strlen($record['comment']),
            isset($entry['disk_start']) ? $entry['disk_start'] : 0, 0,
            (($type | $mode) & 0xffff) << 16, $offset
        ) . $record['name'] . $record['extra'] . $record['comment'];
    }
    $gap = isset($options['gap']) ? $options['gap'] : '';
    $centralOffset = strlen($locals) + strlen($gap);
    $disk = isset($options['disk']) ? $options['disk'] : 0;
    $eocdComment = isset($options['comment']) ? $options['comment'] : '';
    $eocd = pack(
        'VvvvvVVv', 0x06054b50, $disk, $disk, count($records), count($records), strlen($central),
        $centralOffset, strlen($eocdComment)
    ) . $eocdComment;
    $path = tempnam(sys_get_temp_dir(), 'syndicatum-zip-fixture-');
    $preamble = isset($options['preamble']) ? $options['preamble'] : '';
    $bytes = $preamble . $locals . $gap . $central . $eocd . (!empty($options['trailing']) ? 'trailing-bytes' : '');
    if ($path === false || file_put_contents($path, $bytes) === false) { archiveFail('Unable to write ZIP fixture.'); }
    return $path;
}

function validateFixture(ArchiveSafetyReader $reader, array $entries, array $manifest, array $options = [], $contextKind = null, $trustedArchiveSha = null, $trustedManifestJson = null)
{
    $path = writeRawZip($entries, $options);
    $json = manifestJson($manifest);
    $trustedJson = $trustedManifestJson === null ? $json : $trustedManifestJson;
    $kind = $contextKind === null ? $manifest['package_kind'] : $contextKind;
    $archiveSha = $trustedArchiveSha === null ? hash_file('sha256', $path) : $trustedArchiveSha;
    try {
        return $reader->validate($path, $json, archiveContext($kind, $archiveSha, $trustedJson));
    } finally {
        @unlink($path);
    }
}

$releasePayloads = [
    'app/index.php' => ['content' => 'safe-app-bytes', 'role' => 'application'],
    'metadata/build.json' => ['content' => '{}', 'role' => 'package_metadata'],
];
$releaseManifest = archiveManifest('release', $releasePayloads);
$releaseEntries = zipEntries($releasePayloads);
$reader = new ArchiveSafetyReader();
$validated = validateFixture($reader, $releaseEntries, $releaseManifest);
if (!($validated instanceof ArchiveValidationReport) || count($validated->verifiedEntries()) !== 2) {
    archiveFail('Raw archive validation must return a typed, non-extracting report.');
}

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    $entries = $releaseEntries;
    $entries[] = ['name' => 'app/extra.php', 'content' => 'extra', 'type' => 0100000, 'mode' => 0644];
    validateFixture($reader, $entries, $releaseManifest);
}, 'Undeclared archive entries must fail closed.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    $entries = $releaseEntries; array_pop($entries);
    validateFixture($reader, $entries, $releaseManifest);
}, 'Missing archive entries must fail closed.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    $entries = $releaseEntries; $entries[] = $entries[0];
    validateFixture($reader, $entries, $releaseManifest);
}, 'Duplicate real ZIP names must fail closed.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    $entries = $releaseEntries; $entries[] = $entries[0]; $entries[2]['name'] = 'app/Index.php';
    validateFixture($reader, $entries, $releaseManifest);
}, 'Case-fold aliases must fail closed.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    $entries = $releaseEntries; $entries[0]['name'] = '../app/index.php'; $entries[0]['local_name'] = '../app/index.php';
    validateFixture($reader, $entries, $releaseManifest);
}, 'Traversal names must fail raw inspection.');

foreach (['/app/index.php', 'C:/app/index.php', 'app/CON/file.php', 'app/bad?.php'] as $unsafePath) {
    archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest, $unsafePath) {
        $entries = $releaseEntries; $entries[0]['name'] = $unsafePath; $entries[0]['local_name'] = $unsafePath;
        validateFixture($reader, $entries, $releaseManifest);
    }, 'Absolute and nonportable raw ZIP names must fail inspection.');
}

foreach ([0040000, 0120000, 0060000, 0020000, 0010000, 0140000] as $type) {
    archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest, $type) {
        $entries = $releaseEntries; $entries[0]['type'] = $type;
        validateFixture($reader, $entries, $releaseManifest);
    }, 'Unsupported UNIX archive types must fail raw inspection.');
}

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    $entries = $releaseEntries; $entries[1]['central_local_offset'] = 0;
    validateFixture($reader, $entries, $releaseManifest);
}, 'Aliased local records must fail raw inspection.');

archiveThrows(function () use ($releaseEntries, $releaseManifest) {
    validateFixture(new ArchiveSafetyReader(['maximum_entries' => 1]), $releaseEntries, $releaseManifest);
}, 'Entry-count limits must fail closed.');

archiveThrows(function () use ($releaseEntries, $releaseManifest) {
    validateFixture(new ArchiveSafetyReader(['maximum_file_bytes' => 5]), $releaseEntries, $releaseManifest);
}, 'Per-file limits must fail closed.');

archiveThrows(function () use ($releaseEntries, $releaseManifest) {
    validateFixture(new ArchiveSafetyReader(['maximum_total_bytes' => 15]), $releaseEntries, $releaseManifest);
}, 'Total-size limits must fail closed.');

archiveThrows(function () {
    $payloads = ['app/large.txt' => ['content' => str_repeat('A', 10000), 'role' => 'application', 'method' => 8]];
    $entries = zipEntries($payloads); $entries[0]['method'] = 8;
    validateFixture(new ArchiveSafetyReader(['maximum_compression_ratio' => 10, 'maximum_aggregate_compression_ratio' => 10]), $entries, archiveManifest('release', $payloads));
}, 'Real compressed expansion bombs must fail closed.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    $entries = $releaseEntries; $entries[0]['content'] = 'tampered';
    validateFixture($reader, $entries, $releaseManifest);
}, 'Payload digest mismatches must fail closed.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    $manifest = $releaseManifest; $manifest['content_tree_sha256'] = str_repeat('0', 64);
    validateFixture($reader, $releaseEntries, $manifest);
}, 'Stale or forged content-tree digests must fail closed.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    $entries = $releaseEntries; $entries[0]['declared_uncompressed_size'] = strlen($entries[0]['content']) + 1;
    validateFixture($reader, $entries, $releaseManifest);
}, 'Declared ZIP size and streamed-size disagreement must fail closed.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    validateFixture($reader, $releaseEntries, $releaseManifest, [], 'backup');
}, 'Trusted operation kind must not be selected by the manifest.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    validateFixture($reader, $releaseEntries, $releaseManifest, [], null, str_repeat('f', 64));
}, 'Archive bytes must match the trusted detached digest.');

archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest) {
    validateFixture($reader, $releaseEntries, $releaseManifest, [], null, null, manifestJson($releaseManifest) . "\n");
}, 'Sidecar manifest bytes must match trusted provenance.');

$recoveryMetadata = [
    'contract_name' => 'syndicatum-backup-metadata', 'format_version' => '1.0',
    'baseline_id' => 'baseline.1', 'schema_head' => '202609200000',
    'data_files' => ['data/records.ndjson'], 'asset_files' => [], 'secret_files' => [],
];
$backupPayloads = [
    'data/records.ndjson' => ['content' => "{\"id\":1}\n", 'role' => 'logical_data'],
    'metadata/recovery.json' => ['content' => json_encode($recoveryMetadata, JSON_UNESCAPED_SLASHES), 'role' => 'recovery_metadata'],
];
$backupManifest = archiveManifest('backup', $backupPayloads);
$backupResult = validateFixture($reader, zipEntries($backupPayloads), $backupManifest);
if (!($backupResult instanceof ArchiveValidationReport)) { archiveFail('Trusted backup fixture did not validate.'); }

archiveThrows(function () use ($reader, $backupPayloads) {
    $payloads = $backupPayloads;
    $metadata = json_decode($payloads['metadata/recovery.json']['content'], true);
    $metadata['ddl'] = 'CREATE TABLE smuggled(id INT)';
    $payloads['metadata/recovery.json']['content'] = json_encode($metadata);
    validateFixture($reader, zipEntries($payloads), archiveManifest('backup', $payloads));
}, 'Recovery metadata cannot claim schema authority.');

archiveThrows(function () use ($reader, $backupPayloads) {
    $payloads = $backupPayloads;
    $payloads['metadata/recovery.json']['content'] = '{"contract_name":"wrong","contract_name":"syndicatum-backup-metadata","format_version":"1.0","baseline_id":"baseline.1","schema_head":"202609200000","data_files":["data/records.ndjson"],"asset_files":[],"secret_files":[]}';
    validateFixture($reader, zipEntries($payloads), archiveManifest('backup', $payloads));
}, 'Duplicate recovery-metadata JSON keys must fail closed.');

archiveThrows(function () use ($reader, $backupPayloads) {
    $payloads = $backupPayloads;
    $payloads['metadata/recovery.json']['content'] = '{"contract_name":"syndicatum-backup-metadata","format_version":"1.0","baseline_id":"baseline.1","schema_head":"202609200000","data_files":{"0":"data/records.ndjson"},"asset_files":{},"secret_files":{}}';
    validateFixture($reader, zipEntries($payloads), archiveManifest('backup', $payloads));
}, 'Recovery-metadata objects must not masquerade as file-list arrays.');

archiveThrows(function () use ($reader, $backupPayloads) {
    $payloads = $backupPayloads; $payloads['data/records.ndjson']['content'] = "{\"id\":1,\"id\":2}\n";
    validateFixture($reader, zipEntries($payloads), archiveManifest('backup', $payloads));
}, 'Duplicate logical-data JSON keys must fail closed.');

archiveThrows(function () use ($reader, $backupPayloads) {
    $payloads = $backupPayloads;
    $payloads['data/.hidden.ndjson'] = $payloads['data/records.ndjson'];
    unset($payloads['data/records.ndjson']);
    validateFixture($reader, zipEntries($payloads), archiveManifest('backup', $payloads));
}, 'Hidden backup payload names must fail closed.');

archiveThrows(function () use ($reader, $backupPayloads, $recoveryMetadata) {
    $payloads = $backupPayloads;
    $metadata = $recoveryMetadata; $metadata['asset_files'] = ['assets/avatar.png'];
    $payloads['metadata/recovery.json']['content'] = json_encode($metadata, JSON_UNESCAPED_SLASHES);
    $payloads['assets/avatar.png'] = ['content' => '<?php echo 1; ?>', 'role' => 'persistent_asset'];
    validateFixture($reader, zipEntries($payloads), archiveManifest('backup', $payloads));
}, 'Persistent-asset extension/signature mismatches must fail closed.');

$densePayloads = $backupPayloads;
$densePayloads['data/records.ndjson']['content'] = str_repeat("{}\n", 100000);
$denseStart = microtime(true);
validateFixture($reader, zipEntries($densePayloads), archiveManifest('backup', $densePayloads));
if ((microtime(true) - $denseStart) > 10.0) {
    archiveFail('Dense short-record NDJSON validation exceeded the linear-time regression budget.');
}

archiveThrows(function () use ($backupPayloads) {
    $payloads = $backupPayloads;
    $payloads['data/records.ndjson']['content'] = "{}\n{}\n{}\n";
    validateFixture(new ArchiveSafetyReader(['maximum_logical_records' => 2]), zipEntries($payloads), archiveManifest('backup', $payloads));
}, 'Logical-data record-count limits must fail closed.');

foreach ([str_repeat('A', 80) . '<?php echo 1; ?>', "\xef\xbb\xbf  #! /bin/sh\necho unsafe\n", "{\"id\":1}\n<?php echo 1; ?>\n"] as $unsafeData) {
    archiveThrows(function () use ($reader, $backupPayloads, $unsafeData) {
        $payloads = $backupPayloads; $payloads['data/records.ndjson']['content'] = $unsafeData;
        validateFixture($reader, zipEntries($payloads), archiveManifest('backup', $payloads));
    }, 'Non-NDJSON executable or polyglot backup content must fail closed.');
}

$rawCases = [
    'local/central name disagreement' => function ($entries) { $entries[0]['local_name'] = 'app/other.php'; return [$entries, []]; },
    'unsupported encryption flag' => function ($entries) { $entries[0]['flags'] = 1; $entries[0]['local_flags'] = 1; return [$entries, []]; },
    'local/central flag disagreement' => function ($entries) { $entries[0]['local_flags'] = 0x0800; return [$entries, []]; },
    'data descriptor flag' => function ($entries) { $entries[0]['flags'] = 0x0008; $entries[0]['local_flags'] = 0x0008; return [$entries, []]; },
    'unsupported compression method' => function ($entries) { $entries[0]['method'] = 99; return [$entries, []]; },
    'local extra field' => function ($entries) { $entries[0]['local_extra'] = "\x01\x00"; return [$entries, []]; },
    'central extra field' => function ($entries) { $entries[0]['central_extra'] = "\x01\x00"; return [$entries, []]; },
    'entry comment' => function ($entries) { $entries[0]['comment'] = 'comment'; return [$entries, []]; },
    'multidisk marker' => function ($entries) { return [$entries, ['disk' => 1]]; },
    'ZIP64 marker' => function ($entries) { $entries[0]['zip64_size'] = true; return [$entries, []]; },
    'EOCD comment' => function ($entries) { return [$entries, ['comment' => 'comment']]; },
    'archive preamble' => function ($entries) { return [$entries, ['preamble' => 'preamble']]; },
    'unreferenced gap' => function ($entries) { return [$entries, ['gap' => 'gap']]; },
    'trailing polyglot bytes' => function ($entries) { return [$entries, ['trailing' => true]]; },
];
foreach ($rawCases as $label => $mutator) {
    archiveThrows(function () use ($reader, $releaseEntries, $releaseManifest, $mutator) {
        list($entries, $options) = $mutator($releaseEntries);
        validateFixture($reader, $entries, $releaseManifest, $options);
    }, $label . ' must fail raw ZIP inspection.');
}

echo 'Raw archive safety, sidecar trust, NDJSON, and zero-extraction assertions passed' . PHP_EOL;
