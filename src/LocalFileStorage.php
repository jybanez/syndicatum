<?php

require_once __DIR__ . '/FileStorage.php';
require_once __DIR__ . '/ProjectFileStorage.php';

/** Private local implementation with staged streaming and atomic publication. */
final class LocalFileStorage implements FileStorage
{
    private $root;
    private $temporaryRoot;
    private $objectRoot;

    public function __construct($applicationRoot, $configuredBase)
    {
        $this->root = (new ProjectFileStorage($applicationRoot, $configuredBase))->base();
        $this->temporaryRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . '.tmp');
        $this->objectRoot = $this->ensureDirectory($this->root . DIRECTORY_SEPARATOR . 'objects');
    }

    public function driver()
    {
        return 'local';
    }

    public function stageStream($stream, $maximumBytes)
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('A readable upload stream is required.');
        }
        $maximumBytes = (int) $maximumBytes;
        if ($maximumBytes < 1) {
            throw new InvalidArgumentException('The upload byte limit is invalid.');
        }
        $key = 'stage-' . bin2hex(random_bytes(24));
        $path = $this->temporaryRoot . DIRECTORY_SEPARATOR . $key;
        $output = @fopen($path, 'xb');
        if (!is_resource($output)) {
            throw new RuntimeException('FILE_STORAGE_WRITE_FAILED');
        }
        @chmod($path, 0600);
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('FILE_UPLOAD_READ_FAILED');
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($bytes > $maximumBytes) {
                    throw new RuntimeException('FILE_TOO_LARGE');
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('FILE_STORAGE_WRITE_FAILED');
                }
                hash_update($hash, $chunk);
            }
            if ($bytes < 1) {
                throw new RuntimeException('FILE_EMPTY');
            }
            if (!fflush($output)) {
                throw new RuntimeException('FILE_STORAGE_WRITE_FAILED');
            }
            fclose($output);
            $output = null;
            return ['staging_key' => $key, 'size_bytes' => $bytes, 'sha256' => hash_final($hash)];
        } catch (Exception $exception) {
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($path);
            throw $exception;
        }
    }

    public function detectMimeType($stagingKey)
    {
        $path = $this->stagingPath($stagingKey, true);
        if (!class_exists('finfo')) {
            throw new RuntimeException('FILE_TYPE_INSPECTION_UNAVAILABLE');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (!is_string($mime) || trim($mime) === '') {
            throw new RuntimeException('FILE_TYPE_INSPECTION_FAILED');
        }
        return strtolower(trim($mime));
    }

    public function publish($stagingKey, $projectNamespace)
    {
        $source = $this->stagingPath($stagingKey, true);
        $projectNamespace = $this->projectNamespace($projectNamespace);
        $random = bin2hex(random_bytes(32));
        $prefix = substr($random, 0, 2);
        $projectDirectory = $this->ensureDirectory($this->objectRoot . DIRECTORY_SEPARATOR . $projectNamespace);
        $directory = $this->ensureDirectory($projectDirectory . DIRECTORY_SEPARATOR . $prefix);
        $destination = $directory . DIRECTORY_SEPARATOR . $random;
        if (is_link($directory) || !@rename($source, $destination)) {
            throw new RuntimeException('FILE_STORAGE_PUBLISH_FAILED');
        }
        @chmod($destination, 0600);
        return 'objects/' . $projectNamespace . '/' . $prefix . '/' . $random;
    }

    public function discard($stagingKey)
    {
        $path = $this->stagingPath($stagingKey, false);
        return !file_exists($path) || @unlink($path);
    }

    public function openReadStream($storageKey)
    {
        $path = $this->objectPath($storageKey, true);
        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('FILE_STORAGE_READ_FAILED');
        }
        return $stream;
    }

    public function exists($storageKey)
    {
        try {
            return is_file($this->objectPath($storageKey, false));
        } catch (Exception $exception) {
            return false;
        }
    }

    public function size($storageKey)
    {
        $size = filesize($this->objectPath($storageKey, true));
        if ($size === false) {
            throw new RuntimeException('FILE_STORAGE_READ_FAILED');
        }
        return (int) $size;
    }

    public function checksum($storageKey)
    {
        $checksum = hash_file('sha256', $this->objectPath($storageKey, true));
        if (!is_string($checksum)) {
            throw new RuntimeException('FILE_STORAGE_READ_FAILED');
        }
        return $checksum;
    }

    public function delete($storageKey)
    {
        $path = $this->objectPath($storageKey, false);
        return !file_exists($path) || @unlink($path);
    }

    public function copyTo($storageKey, FileStorage $destination, $destinationProjectNamespace)
    {
        $stream = $this->openReadStream($storageKey);
        $staged = null;
        try {
            $staged = $destination->stageStream($stream, $this->size($storageKey));
            return $destination->publish($staged['staging_key'], $destinationProjectNamespace);
        } catch (Exception $exception) {
            if (is_array($staged) && isset($staged['staging_key'])) {
                $destination->discard($staged['staging_key']);
            }
            throw $exception;
        } finally {
            fclose($stream);
        }
    }

    /** Move a legacy or differently grouped object into the requested project namespace. */
    public function relocateObject($storageKey, $projectNamespace)
    {
        $parts = $this->storageKeyParts($storageKey);
        $targetNamespace = $projectNamespace === null ? null : $this->projectNamespace($projectNamespace);
        if ($parts['namespace'] === $targetNamespace) { return $storageKey; }
        if ($parts['namespace'] !== null && $targetNamespace !== null) {
            throw new RuntimeException('FILE_STORAGE_NAMESPACE_CONFLICT');
        }
        $targetKey = $targetNamespace === null
            ? 'objects/' . $parts['prefix'] . '/' . $parts['object']
            : 'objects/' . $targetNamespace . '/' . $parts['prefix'] . '/' . $parts['object'];
        $source = $this->objectPath($storageKey, false);
        $target = $this->objectPath($targetKey, false, true);
        if (!is_file($source)) {
            if (is_file($target) && !is_link($target)) { return $targetKey; }
            throw new RuntimeException('FILE_STORAGE_OBJECT_MISSING');
        }
        if (file_exists($target)) { throw new RuntimeException('FILE_STORAGE_RELOCATION_CONFLICT'); }
        if (!@rename($source, $target)) { throw new RuntimeException('FILE_STORAGE_RELOCATION_FAILED'); }
        @chmod($target, 0600);
        @rmdir(dirname($source));
        if ($parts['namespace'] !== null) { @rmdir(dirname(dirname($source))); }
        return $targetKey;
    }

    private function stagingPath($key, $mustExist)
    {
        if (!preg_match('/\Astage-[a-f0-9]{48}\z/', (string) $key)) {
            throw new InvalidArgumentException('Invalid staging key.');
        }
        $path = $this->temporaryRoot . DIRECTORY_SEPARATOR . $key;
        if ($mustExist && (!is_file($path) || is_link($path))) {
            throw new RuntimeException('FILE_STORAGE_OBJECT_MISSING');
        }
        return $path;
    }

    private function objectPath($key, $mustExist, $prepareDirectories = false)
    {
        $parts = $this->storageKeyParts($key);
        $directory = $this->objectRoot;
        if ($parts['namespace'] !== null) {
            $projectDirectory = $directory . DIRECTORY_SEPARATOR . $parts['namespace'];
            $directory = $prepareDirectories ? $this->ensureDirectory($projectDirectory) : $projectDirectory;
            if (is_link($directory)) { throw new RuntimeException('FILE_STORAGE_PATH_UNSAFE'); }
        }
        $shardDirectory = $directory . DIRECTORY_SEPARATOR . $parts['prefix'];
        $directory = $prepareDirectories ? $this->ensureDirectory($shardDirectory) : $shardDirectory;
        $path = $directory . DIRECTORY_SEPARATOR . $parts['object'];
        if (is_link($directory) || is_link($path)) {
            throw new RuntimeException('FILE_STORAGE_PATH_UNSAFE');
        }
        if ($mustExist && !is_file($path)) {
            throw new RuntimeException('FILE_STORAGE_OBJECT_MISSING');
        }
        return $path;
    }

    private function storageKeyParts($key)
    {
        $key = (string) $key;
        if (preg_match('#\Aobjects/([a-f0-9]{2})/([a-f0-9]{64})\z#', $key, $matches)) {
            $parts = ['namespace' => null, 'prefix' => $matches[1], 'object' => $matches[2]];
        } elseif (preg_match('#\Aobjects/([a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})/([a-f0-9]{2})/([a-f0-9]{64})\z#', $key, $matches)) {
            $parts = ['namespace' => $matches[1], 'prefix' => $matches[2], 'object' => $matches[3]];
        } else {
            throw new InvalidArgumentException('Invalid storage key.');
        }
        if (substr($parts['object'], 0, 2) !== $parts['prefix']) { throw new InvalidArgumentException('Invalid storage key.'); }
        return $parts;
    }

    private function projectNamespace($value)
    {
        $value = strtolower(trim((string) $value));
        if (!preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/', $value)) {
            throw new InvalidArgumentException('Invalid project storage namespace.');
        }
        return $value;
    }

    private function ensureDirectory($path)
    {
        if (is_link($path)) {
            throw new RuntimeException('FILE_STORAGE_PATH_UNSAFE');
        }
        if (!is_dir($path) && !@mkdir($path, 0700, true)) {
            throw new RuntimeException('FILE_STORAGE_DIRECTORY_FAILED');
        }
        @chmod($path, 0700);
        $resolved = realpath($path);
        if (!is_string($resolved) || !$this->inside($resolved, $this->root)) {
            throw new RuntimeException('FILE_STORAGE_PATH_UNSAFE');
        }
        return $resolved;
    }

    private function inside($path, $root)
    {
        $path = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $root = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
    }
}

