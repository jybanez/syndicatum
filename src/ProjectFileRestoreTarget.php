<?php

require_once __DIR__ . '/ProjectFileStorage.php';

/** Private staging and exact-key local restoration for authenticated project-file members. */
final class ProjectFileRestoreTarget
{
    private $root;
    private $created = [];

    public function __construct($applicationRoot, $configuredBase)
    {
        $this->root = (new ProjectFileStorage($applicationRoot, $configuredBase))->base();
    }

    public function stage(ZipArchive $zip, array $entries, $stageRoot)
    {
        if (!is_dir($stageRoot) || is_link($stageRoot)) { throw new InvalidArgumentException('Project file restore stage is invalid.'); }
        $directory = rtrim($stageRoot, '/\\') . DIRECTORY_SEPARATOR . 'project-files';
        if (!mkdir($directory, 0700) || is_link($directory)) { throw new RuntimeException('Project file restore stage could not be created.'); }
        @chmod($directory, 0700);
        $staged = [];
        foreach ($entries as $entry) {
            $stream = $zip->getStream($entry['path']);
            if (!is_resource($stream)) { throw new RuntimeException('A verified project file could not be opened for restore.'); }
            $path = $directory . DIRECTORY_SEPARATOR . $entry['public_id'];
            $output = @fopen($path, 'xb');
            if (!is_resource($output)) { fclose($stream); throw new RuntimeException('A project file restore stage could not be created.'); }
            @chmod($path, 0600); $hash = hash_init('sha256'); $bytes = 0;
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 1048576);
                    if ($chunk === false || ($chunk === '' && !feof($stream))) { throw new RuntimeException('A project file restore stream was interrupted.'); }
                    if ($chunk === '') { continue; }
                    $bytes += strlen($chunk);
                    if ($bytes > $entry['bytes'] || fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('A project file restore stream exceeded its verified inventory.');
                    }
                    hash_update($hash, $chunk);
                }
                if (!fflush($output) || $bytes !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_final($hash))) {
                    throw new RuntimeException('A staged project file differs from its verified inventory.');
                }
            } finally { fclose($stream); fclose($output); }
            $staged[] = ['entry' => $entry, 'path' => $path];
        }
        return $staged;
    }

    public function install(array $staged)
    {
        $this->created = [];
        foreach ($staged as $item) {
            $entry = $item['entry']; $source = $item['path']; $target = $this->target($entry['key'], true);
            if (file_exists($target)) {
                if (is_link($target) || !is_file($target) || filesize($target) !== $entry['bytes']
                    || !hash_equals($entry['sha256'], (string) @hash_file('sha256', $target))) {
                    throw new RuntimeException('A restored project file conflicts with existing local content.');
                }
                continue;
            }
            $temporary = $target . '.restore-' . bin2hex(random_bytes(8));
            $input = @fopen($source, 'rb'); $output = @fopen($temporary, 'xb');
            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) { fclose($input); } if (is_resource($output)) { fclose($output); }
                @unlink($temporary); throw new RuntimeException('A project file could not be prepared in the target provider.');
            }
            @chmod($temporary, 0600); $hash = hash_init('sha256'); $bytes = 0;
            try {
                while (!feof($input)) {
                    $chunk = fread($input, 1048576);
                    if ($chunk === false || ($chunk === '' && !feof($input))) { throw new RuntimeException('A staged project file could not be read.'); }
                    if ($chunk === '') { continue; }
                    $bytes += strlen($chunk);
                    if ($bytes > $entry['bytes'] || fwrite($output, $chunk) !== strlen($chunk)) { throw new RuntimeException('A project file target write failed.'); }
                    hash_update($hash, $chunk);
                }
                if (!fflush($output) || $bytes !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_final($hash))) {
                    throw new RuntimeException('A restored project file failed target verification.');
                }
            } catch (Throwable $error) {
                fclose($input); fclose($output); @unlink($temporary); throw $error;
            }
            fclose($input); fclose($output);
            if (!@link($temporary, $target)) { @unlink($temporary); throw new RuntimeException('A restored project file could not be published without replacement.'); }
            @unlink($temporary);
            @chmod($target, 0600); $this->created[] = $target;
        }
        $this->verify($staged);
    }

    public function verify(array $staged)
    {
        foreach ($staged as $item) {
            $entry = $item['entry']; $target = $this->target($entry['key'], false);
            if (!is_file($target) || is_link($target) || filesize($target) !== $entry['bytes']
                || !hash_equals($entry['sha256'], (string) @hash_file('sha256', $target))) {
                throw new RuntimeException('A restored project file is unavailable or failed checksum verification.');
            }
        }
    }

    public function rollbackCreated()
    {
        foreach (array_reverse($this->created) as $path) { @unlink($path); @rmdir(dirname($path)); }
        $this->created = [];
    }

    public function commit() { $this->created = []; }

    private function target($key, $prepare)
    {
        if (!preg_match('#\Aobjects/(?:[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}/)?([a-f0-9]{2})/([a-f0-9]{64})\z#', $key, $matches)
            || substr($matches[2], 0, 2) !== $matches[1]) { throw new InvalidArgumentException('Restored project file key is invalid.'); }
        $parts = explode('/', $key); $path = $this->root;
        array_pop($parts);
        foreach ($parts as $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            if (is_link($path)) { throw new RuntimeException('Project file restore target contains a symbolic link.'); }
            if ($prepare && !is_dir($path) && !mkdir($path, 0700)) { throw new RuntimeException('Project file restore target directory could not be created.'); }
            if ($prepare) { @chmod($path, 0700); }
        }
        $target = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
        $parent = realpath(dirname($target));
        if (($prepare && !is_string($parent)) || (is_string($parent) && !self::inside($parent, $this->root))) {
            throw new RuntimeException('Project file restore target escapes the configured storage root.');
        }
        return $target;
    }

    private static function inside($path, $root)
    {
        if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
    }
}
