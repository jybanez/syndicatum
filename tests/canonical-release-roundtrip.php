<?php

require_once __DIR__ . '/../src/PackageManifest.php';
require_once __DIR__ . '/../src/ArchiveSafetyReader.php';

if ($argc !== 9) {
    fwrite(STDERR, "Usage: php canonical-release-roundtrip.php ARCHIVE MANIFEST STAGING_ROOT PUBLIC_ROOT SOURCE_COMMIT SOURCE_TAG BASELINE_ID SCHEMA_HEAD\n");
    exit(2);
}

list($script, $archivePath, $manifestPath, $stagingRoot, $publicRoot, $sourceCommit, $sourceTag, $baselineId, $schemaHead) = $argv;
$manifestJson = file_get_contents($manifestPath);
if (!is_string($manifestJson)) {
    throw new RuntimeException('Manifest could not be read.');
}
$manifest = PackageManifest::parse($manifestJson);
$archiveSha = hash_file('sha256', $archivePath);
$manifestSha = hash('sha256', $manifestJson);
$context = ArchiveValidationContext::forRelease($archiveSha, $manifestSha, [
    'source_commit' => $sourceCommit,
    'source_tag' => $sourceTag,
    'schema_baseline' => $baselineId,
    'schema_head' => $schemaHead,
]);

$reader = new ArchiveSafetyReader();
$report = $reader->validate($archivePath, $manifestJson, $context);
$stage = $reader->extractToNewStage($archivePath, $manifestJson, $context, $stagingRoot, $publicRoot);
$expected = [];
foreach ($manifest['files'] as $entry) {
    $expected[$entry['path']] = $entry;
}
$actual = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage->path(), FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->isLink()) {
        throw new RuntimeException('Extraction produced a non-regular entry.');
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($stage->path()) + 1));
    $actual[$relative] = true;
    if (!isset($expected[$relative])) {
        throw new RuntimeException('Extraction produced an unexpected path: ' . $relative);
    }
    $entry = $expected[$relative];
    if ($file->getSize() !== $entry['size'] || !hash_equals($entry['sha256'], hash_file('sha256', $file->getPathname()))) {
        throw new RuntimeException('Extracted bytes differ from the manifest: ' . $relative);
    }
    if (DIRECTORY_SEPARATOR === '/' && (($file->getPerms() & 0777) !== $entry['mode'])) {
        throw new RuntimeException('Extracted mode differs from the manifest: ' . $relative);
    }
}
$expectedPaths = array_keys($expected);
$actualPaths = array_keys($actual);
sort($expectedPaths, SORT_STRING);
sort($actualPaths, SORT_STRING);
if ($expectedPaths !== $actualPaths) {
    throw new RuntimeException('Extracted tree does not exactly match the manifest.');
}
if (!hash_equals($manifest['content_tree_sha256'], PackageManifest::calculateContentTreeSha256($manifest['files']))) {
    throw new RuntimeException('Content-tree digest does not round-trip.');
}

echo json_encode([
    'archive_sha256' => $report->archiveSha256(),
    'manifest_sha256' => $manifestSha,
    'content_tree_sha256' => $manifest['content_tree_sha256'],
    'files' => count($manifest['files']),
    'stage' => $stage->path(),
], JSON_UNESCAPED_SLASHES) . "\n";
