<?php

require_once dirname(__DIR__) . '/src/LocalFileStorage.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-file-storage-' . bin2hex(random_bytes(8));
$application = $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'app';
$storageRoot = $root . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'files';
if (!mkdir($application, 0700, true)) {
    throw new RuntimeException('Unable to create test application root.');
}

function projectFileStorageRemove($path)
{
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') { projectFileStorageRemove($path . DIRECTORY_SEPARATOR . $entry); }
        }
        rmdir($path);
        return;
    }
    unlink($path);
}

try {
    $storage = new LocalFileStorage($application, $storageRoot);
    if ($storage->driver() !== 'local') { throw new RuntimeException('Unexpected storage driver.'); }

    $content = "Syndicatum project file\n";
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $content);
    rewind($stream);
    $staged = $storage->stageStream($stream, strlen($content));
    fclose($stream);
    if ($staged['size_bytes'] !== strlen($content) || $staged['sha256'] !== hash('sha256', $content)) {
        throw new RuntimeException('Staged metadata did not match streamed content.');
    }
    if ($storage->detectMimeType($staged['staging_key']) !== 'text/plain') {
        throw new RuntimeException('Server-side MIME inspection did not identify text content.');
    }
    $key = $storage->publish($staged['staging_key']);
    if (!preg_match('#\Aobjects/[a-f0-9]{2}/[a-f0-9]{64}\z#', $key) || !$storage->exists($key)
        || $storage->size($key) !== strlen($content) || $storage->checksum($key) !== hash('sha256', $content)) {
        throw new RuntimeException('Published object contract failed.');
    }
    $read = $storage->openReadStream($key);
    $roundTrip = stream_get_contents($read);
    fclose($read);
    if ($roundTrip !== $content) { throw new RuntimeException('Published content did not round-trip.'); }

    $oversized = fopen('php://temp', 'w+b');
    fwrite($oversized, 'too large');
    rewind($oversized);
    try {
        $storage->stageStream($oversized, 3);
        throw new RuntimeException('Oversized stream was accepted.');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'FILE_TOO_LARGE') { throw $exception; }
    } finally { fclose($oversized); }

    try {
        $storage->openReadStream('../escape');
        throw new RuntimeException('Unsafe storage key was accepted.');
    } catch (InvalidArgumentException $expected) {
    }

    if (!$storage->delete($key) || $storage->exists($key)) { throw new RuntimeException('Published object was not deleted.'); }
    echo "PASS  local project file storage streams, inspects, publishes, reads, and deletes private objects\n";
} finally {
    projectFileStorageRemove($root);
}
