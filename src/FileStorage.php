<?php

/** Provider-neutral project-file object storage. Keys are opaque application data, never paths. */
interface FileStorage
{
    public function driver();
    public function stageStream($stream, $maximumBytes);
    public function detectMimeType($stagingKey);
    public function publish($stagingKey);
    public function discard($stagingKey);
    public function openReadStream($storageKey);
    public function exists($storageKey);
    public function size($storageKey);
    public function checksum($storageKey);
    public function delete($storageKey);
    public function copyTo($storageKey, FileStorage $destination);
}

