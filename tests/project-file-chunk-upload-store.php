<?php

require_once dirname(__DIR__) . '/src/ProjectFileChunkUploadStore.php';

function chunkAssert($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

$applicationRoot = realpath(dirname(__DIR__));
$storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-chunks-' . bin2hex(random_bytes(6));
$remove = function ($path) use (&$remove) {
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') { $remove($path . DIRECTORY_SEPARATOR . $entry); }
        }
        rmdir($path);
        return;
    }
    unlink($path);
};

try {
    $store = new ProjectFileChunkUploadStore($applicationRoot, $storageRoot);
    $owner = ['project_id' => 7, 'participant_id' => 11];
    $payload = str_repeat('A', ProjectFileChunkUploadStore::CHUNK_BYTES * 4) . str_repeat('B', 102400);
    $uploadId = '123e4567-e89b-42d3-a456-426614174000';
    $base = [
        'upload_id' => $uploadId, 'chunk_count' => 5, 'total_size' => strlen($payload),
        'target_operation' => 'upload', 'original_name' => 'large.pdf', 'folder_id' => 'root',
        'idempotency_key' => 'chunk-test-idempotency-0001',
    ];
    $finalizations = 0;
    $finalize = function ($stream) use (&$finalizations, $payload) {
        $finalizations++;
        $contents = stream_get_contents($stream);
        chunkAssert($contents === $payload, 'Finalized stream did not match the uploaded chunks.');
        return ['file' => ['id' => 'file-1'], 'replayed' => false];
    };
    $first = tempnam(sys_get_temp_dir(), 'chunk-');
    file_put_contents($first, substr($payload, 0, ProjectFileChunkUploadStore::CHUNK_BYTES));
    $result = $store->receive($owner, $base + ['chunk_index' => 0], $first, filesize($first), strlen($payload), $finalize);
    chunkAssert($result['complete'] === false && $result['received_chunks'] === 1, 'First chunk was not retained.');
    $retry = $store->receive($owner, $base + ['chunk_index' => 0], $first, filesize($first), strlen($payload), $finalize);
    chunkAssert($retry['complete'] === false && $retry['replayed'] === true, 'Identical chunk retry was not accepted.');
    $last = null;
    for ($index = 1; $index < 5; $index++) {
        $chunk = tempnam(sys_get_temp_dir(), 'chunk-');
        file_put_contents($chunk, substr($payload, $index * ProjectFileChunkUploadStore::CHUNK_BYTES,
            ProjectFileChunkUploadStore::CHUNK_BYTES));
        $result = $store->receive($owner, $base + ['chunk_index' => $index], $chunk, filesize($chunk), strlen($payload), $finalize);
        if ($last !== null) { unlink($last); }
        $last = $chunk;
    }
    chunkAssert($result['complete'] === true && $result['result']['file']['id'] === 'file-1', 'Completed upload was not finalized.');
    $replay = $store->receive($owner, $base + ['chunk_index' => 4], $last, filesize($last), strlen($payload), $finalize);
    chunkAssert($replay['complete'] === true && $replay['replayed'] === true && $finalizations === 1,
        'Completed upload retry must return the recorded result without finalizing twice.');
    unlink($first);
    unlink($last);
    echo "Project file chunk upload store tests passed.\n";
} finally {
    $remove($storageRoot);
}
