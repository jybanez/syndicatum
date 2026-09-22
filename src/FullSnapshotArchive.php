<?php

require_once __DIR__ . '/FullSnapshotManifest.php';

/** Exact-member ZIP transport inside BackupEnvelope; not an application package. */
final class FullSnapshotArchive
{
    public static function create($payloadRoot, array $manifest, $archivePath)
    {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('ZIP support is required.'); }
        if (file_exists($archivePath)) { throw new InvalidArgumentException('Full-snapshot archive destination already exists.'); }
        $paths = FullSnapshotManifest::archivePaths($manifest);
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Full-snapshot ZIP could not be created.');
        }
        try {
            foreach ($paths as $path) {
                $source = self::file($payloadRoot, $path);
                if (!is_file($source) || is_link($source) || !$zip->addFile($source, $path)
                    || !$zip->setCompressionName($path, ZipArchive::CM_STORE)
                    || !$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, (0100000 | 0600) << 16)) {
                    throw new RuntimeException('Full-snapshot ZIP member could not be added safely: ' . $path . '.');
                }
            }
        } finally {
            if (!$zip->close()) { @unlink($archivePath); throw new RuntimeException('Full-snapshot ZIP could not be closed.'); }
        }
        @chmod($archivePath, 0600);
        return hash_file('sha256', $archivePath);
    }

    public static function extractVerified($archivePath, array $manifest, $targetRoot)
    {
        if (!is_file($archivePath) || is_link($archivePath)) { throw new InvalidArgumentException('Full-snapshot ZIP must be a regular file.'); }
        if (!class_exists('ZipArchive')) { throw new RuntimeException('ZIP support is required.'); }
        $expected = [];
        $expected[$manifest['sql']['path']] = $manifest['sql'];
        $expected[$manifest['secrets']['path']] = $manifest['secrets'];
        foreach ($manifest['assets'] as $asset) { $expected[$asset['path']] = $asset; }
        $expectedPaths = FullSnapshotManifest::archivePaths($manifest);
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) { throw new InvalidArgumentException('Full-snapshot ZIP is invalid.'); }
        try {
            if ($zip->numFiles !== count($expectedPaths)) { throw new InvalidArgumentException('Full-snapshot ZIP member count differs from manifest.'); }
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name']) || !is_string($stat['name'])) {
                    throw new InvalidArgumentException('Full-snapshot ZIP member is invalid.');
                }
                $name = $stat['name'];
                PackageManifest::validateSafeRelativePath($name, 'full-snapshot ZIP member');
                if (!isset($expected[$name]) || isset($seen[$name])) {
                    throw new InvalidArgumentException('Full-snapshot ZIP has an undeclared or duplicate member.');
                }
                $seen[$name] = true;
                $entry = $expected[$name];
                if (!isset($stat['size'], $stat['comp_method']) || $stat['size'] !== $entry['bytes'] || $stat['comp_method'] !== ZipArchive::CM_STORE) {
                    throw new InvalidArgumentException('Full-snapshot ZIP member size or compression differs from manifest.');
                }
                $opsys = null; $attributes = null;
                if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)
                    || $opsys !== ZipArchive::OPSYS_UNIX || (($attributes >> 16) & 0170000) !== 0100000
                    || (($attributes >> 16) & 0111) !== 0) {
                    throw new InvalidArgumentException('Full-snapshot ZIP member is not a non-executable regular file.');
                }
                $input = $zip->getStream($name);
                if (!is_resource($input)) { throw new InvalidArgumentException('Full-snapshot ZIP member could not be read.'); }
                $destination = self::file($targetRoot, $name);
                $parent = dirname($destination);
                if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
                    fclose($input); throw new RuntimeException('Full-snapshot extraction directory could not be created.');
                }
                $output = @fopen($destination, 'xb');
                if (!is_resource($output)) { fclose($input); throw new RuntimeException('Full-snapshot extraction file could not be created.'); }
                @chmod($destination, 0600);
                $hash = hash_init('sha256'); $bytes = 0;
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 65536);
                        if ($chunk === false || ($chunk === '' && !feof($input))) { throw new InvalidArgumentException('Full-snapshot ZIP read failed.'); }
                        $bytes += strlen($chunk);
                        if ($bytes > $entry['bytes']) { throw new InvalidArgumentException('Full-snapshot ZIP member exceeded declared size.'); }
                        hash_update($hash, $chunk);
                        self::write($output, $chunk);
                    }
                } finally { fclose($input); fclose($output); }
                if ($bytes !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_final($hash))) {
                    throw new InvalidArgumentException('Full-snapshot ZIP member digest differs from manifest: ' . $name . '.');
                }
            }
            $actual = array_keys($seen); sort($actual, SORT_STRING);
            if ($actual !== $expectedPaths) { throw new InvalidArgumentException('Full-snapshot ZIP is missing a declared member.'); }
            return ['sql_path' => self::file($targetRoot, FullSnapshotManifest::SQL_PATH),
                'secrets_path' => self::file($targetRoot, FullSnapshotManifest::SECRET_PATH),
                'asset_count' => count($manifest['assets'])];
        } finally { $zip->close(); }
    }

    private static function file($root, $path)
    {
        PackageManifest::validateSafeRelativePath($path, 'full-snapshot member');
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private static function write($stream, $bytes)
    {
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) { throw new RuntimeException('Full-snapshot ZIP extraction write failed.'); }
            $offset += $written;
        }
    }
}
