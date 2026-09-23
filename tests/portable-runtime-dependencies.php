<?php

require_once dirname(__DIR__) . '/src/PortableBackupRuntime.php';

function runtimeRemoveTree($path)
{
    if (!is_dir($path)) { return; }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) { rmdir($item->getPathname()); }
        else { unlink($item->getPathname()); }
    }
    rmdir($path);
}
$root = dirname(__DIR__);
$stage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-runtime-proof-' . bin2hex(random_bytes(8));
if (!mkdir($stage, 0700, true)) { throw new RuntimeException('Unable to create runtime proof stage.'); }

try {
    $entries = (new PortableBackupRuntime($root))->collect($stage);
    $paths = array_column($entries, 'path');
    foreach (['runtime/scripts/process-current-backup-jobs.php', 'runtime/scripts/process-current-restore-jobs.php'] as $required) {
        if (!in_array($required, $paths, true)) {
            throw new RuntimeException('Required worker launch target is missing from the packaged runtime: ' . $required);
        }
    }
    $unexpectedRecovery = array_values(array_filter($paths, function ($path) {
        return strpos($path, 'runtime/resources/recovery/') === 0
            && $path !== 'runtime/resources/recovery/kickstart.php';
    }));
    if ($unexpectedRecovery) {
        throw new RuntimeException('Generated recovery snapshot leaked into the packaged runtime: ' . implode(', ', $unexpectedRecovery));
    }
    $identity = array_map(function ($entry) {
        return $entry['path'] . "\0" . $entry['sha256'] . "\0" . $entry['bytes'];
    }, $entries);
    echo 'Runtime files: ' . count($entries) . PHP_EOL;
    echo 'Runtime inventory SHA-256: ' . hash('sha256', implode("\n", $identity)) . PHP_EOL;
    echo "Worker launch targets: present\n";
    echo "Nested recovery snapshot: absent\n";
} finally {
    runtimeRemoveTree($stage);
}
