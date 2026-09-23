<?php

require_once __DIR__ . '/CurrentBackupManifest.php';

/** Exact, non-executable ZIP members inside the current-baseline encrypted envelope. */
final class CurrentBackupArchive
{
    public static function create($payloadRoot, array $manifest, $archivePath)
    {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('ZIP support is required.'); }
        if (file_exists($archivePath)) { throw new InvalidArgumentException('Backup archive destination already exists.'); }
        $expected = self::expected($manifest);
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Backup ZIP could not be created.');
        }
        try {
            foreach ($expected as $path => $entry) {
                $source = self::source($payloadRoot, $path);
                if (!is_file($source) || is_link($source) || filesize($source) !== $entry['bytes']
                    || !hash_equals($entry['sha256'], hash_file('sha256', $source))
                    || !$zip->addFile($source, $path)
                    || !$zip->setCompressionName($path, ZipArchive::CM_STORE)
                    || !$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, (0100000 | 0600) << 16)) {
                    throw new RuntimeException('Backup ZIP member failed verification: ' . $path . '.');
                }
            }
        } finally {
            if (!$zip->close()) { @unlink($archivePath); throw new RuntimeException('Backup ZIP could not be closed.'); }
        }
        @chmod($archivePath, 0600);
        self::verify($archivePath, $manifest);
        return hash_file('sha256', $archivePath);
    }

    public static function verify($archivePath, array $manifest)
    {
        if (!is_file($archivePath) || is_link($archivePath)) { throw new InvalidArgumentException('Backup ZIP must be a regular file.'); }
        $expected = self::expected($manifest);
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) { throw new InvalidArgumentException('Backup ZIP is invalid.'); }
        try {
            if ($zip->numFiles !== count($expected)) { throw new InvalidArgumentException('Backup ZIP member count differs from manifest.'); }
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name']) || !isset($expected[$stat['name']])
                    || isset($seen[$stat['name']])) { throw new InvalidArgumentException('Backup ZIP contains an undeclared or duplicate member.'); }
                $path = $stat['name'];
                $entry = $expected[$path];
                $seen[$path] = true;
                if (!isset($stat['size'], $stat['comp_method']) || $stat['size'] !== $entry['bytes']
                    || $stat['comp_method'] !== ZipArchive::CM_STORE) {
                    throw new InvalidArgumentException('Backup ZIP member size or compression differs from manifest.');
                }
                $opsys = null; $attributes = null;
                if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)
                    || $opsys !== ZipArchive::OPSYS_UNIX || (($attributes >> 16) & 0170000) !== 0100000
                    || (($attributes >> 16) & 0111) !== 0) {
                    throw new InvalidArgumentException('Backup ZIP member is not a non-executable regular file.');
                }
                $stream = $zip->getStream($path);
                if (!is_resource($stream)) { throw new InvalidArgumentException('Backup ZIP member could not be read.'); }
                $hash = hash_init('sha256'); $bytes = 0;
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false || ($chunk === '' && !feof($stream))) {
                            throw new InvalidArgumentException('Backup ZIP member read failed.');
                        }
                        $bytes += strlen($chunk);
                        if ($bytes > $entry['bytes']) { throw new InvalidArgumentException('Backup ZIP member exceeded declared size.'); }
                        hash_update($hash, $chunk);
                    }
                } finally { fclose($stream); }
                if ($bytes !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_final($hash))) {
                    throw new InvalidArgumentException('Backup ZIP member digest differs from manifest.');
                }
            }
            if (count($seen) !== count($expected)) { throw new InvalidArgumentException('Backup ZIP is missing a declared member.'); }
        } finally { $zip->close(); }
    }

    private static function expected(array $manifest)
    {
        $paths = CurrentBackupManifest::archivePaths($manifest);
        $entries = [$manifest['sql']['path'] => $manifest['sql']];
        foreach ($manifest['assets'] as $asset) { $entries[$asset['path']] = $asset; }
        $actual = array_keys($entries); sort($actual, SORT_STRING);
        if ($actual !== $paths) { throw new InvalidArgumentException('Backup member inventory is invalid.'); }
        return $entries;
    }

    private static function source($root, $path)
    {
        if (!preg_match('#\A(?:database/snapshot\.sql|assets/avatars/[a-f0-9]{40}\.(?:jpg|png|webp))\z#', $path)) {
            throw new InvalidArgumentException('Backup member path is invalid.');
        }
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
