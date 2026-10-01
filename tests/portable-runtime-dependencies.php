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
    $requiredRuntime = [
        'runtime/Dockerfile',
        'runtime/compose.yaml',
        'runtime/.dockerignore',
        'runtime/.env.example',
        'runtime/docker/entrypoint.sh',
        'runtime/docker/mysql-entrypoint.sh',
        'runtime/docker/mysql.Dockerfile',
        'runtime/docker/worker-loop.sh',
        'runtime/scripts/chat-db.php',
        'runtime/scripts/process-current-backup-jobs.php',
        'runtime/scripts/process-current-restore-jobs.php',
        'runtime/scripts/process-agent-webhooks.php',
        'runtime/scripts/process-message-outbox.php',
        'runtime/scripts/record-delivery-worker-heartbeat.php',
        'runtime/schema/mysql84/baseline.json',
        'runtime/schema/mysql84/schema.sql',
    ];
    foreach ($requiredRuntime as $required) {
        if (!in_array($required, $paths, true)) {
            throw new RuntimeException('Required Linux recovery runtime file is missing from the packaged runtime: ' . $required);
        }
    }
    $migrationPaths = array_values(array_filter($paths, function ($path) {
        return strpos($path, 'runtime/migrations/') === 0 && substr($path, -4) === '.php';
    }));
    if ($migrationPaths === []) {
        throw new RuntimeException('Linux recovery runtime contains no schema migrations.');
    }
    $powerShellPaths = array_values(array_filter($paths, function ($path) {
        return substr(strtolower($path), -4) === '.ps1';
    }));
    if ($powerShellPaths !== []) {
        throw new RuntimeException('Portable Linux recovery runtime must not depend on PowerShell: ' . implode(', ', $powerShellPaths));
    }
    $dockerIgnore = file_get_contents($stage . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . '.dockerignore');
    if (!is_string($dockerIgnore) || !preg_match('/^\.env$/m', $dockerIgnore)
        || !preg_match('/^!\.env\.example$/m', $dockerIgnore)) {
        throw new RuntimeException('Linux recovery runtime does not prevent target-local .env secrets from entering the Docker build context.');
    }
    $workerLoop = file_get_contents($stage . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'docker' . DIRECTORY_SEPARATOR . 'worker-loop.sh');
    foreach (['process-agent-webhooks.php', 'process-message-outbox.php', 'record-delivery-worker-heartbeat.php'] as $workerTarget) {
        if (!is_string($workerLoop) || strpos($workerLoop, $workerTarget) === false) {
            throw new RuntimeException('Linux worker supervisor does not launch required target: ' . $workerTarget);
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
    echo "Linux Docker and worker launch targets: present\n";
    echo 'Schema migrations: ' . count($migrationPaths) . PHP_EOL;
    echo "PowerShell dependency: absent\n";
    echo "Nested recovery snapshot: absent\n";
} finally {
    runtimeRemoveTree($stage);
}
