<?php

require_once __DIR__ . '/PortableBackupManifest.php';

/** Compressed ZIP payload inside the authenticated encrypted envelope. */
final class PortableBackupArchive
{
    public static function create($payloadRoot, array $manifest, $archivePath)
    {
        if (!class_exists('ZipArchive')) { throw new RuntimeException('ZIP support is required.'); }
        if (file_exists($archivePath)) { throw new InvalidArgumentException('Backup archive destination already exists.'); }
        $entries = PortableBackupManifest::entries($manifest);
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('Backup ZIP could not be created.'); }
        try {
            foreach ($entries as $path => $entry) {
                $source = self::source($payloadRoot, $path);
                if (!is_file($source) || is_link($source) || filesize($source) !== $entry['bytes']
                    || !hash_equals($entry['sha256'], hash_file('sha256', $source))
                    || !$zip->addFile($source, $path)
                    || !$zip->setCompressionName($path, ZipArchive::CM_DEFLATE, 6)
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
        $expected = PortableBackupManifest::entries($manifest);
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) { throw new InvalidArgumentException('Backup ZIP is invalid.'); }
        try {
            if ($zip->numFiles !== count($expected)) { throw new InvalidArgumentException('Backup ZIP member count differs from manifest.'); }
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat) || !isset($stat['name'], $expected[$stat['name']]) || isset($seen[$stat['name']])) {
                    throw new InvalidArgumentException('Backup ZIP contains an undeclared or duplicate member.');
                }
                $entry = $expected[$stat['name']]; $seen[$stat['name']] = true;
                if (!isset($stat['size'], $stat['comp_method']) || $stat['size'] !== $entry['bytes']
                    || !in_array($stat['comp_method'], [ZipArchive::CM_DEFLATE, ZipArchive::CM_STORE], true)) {
                    throw new InvalidArgumentException('Backup ZIP member size or compression differs from manifest.');
                }
                $stream = $zip->getStream($stat['name']);
                if (!is_resource($stream)) { throw new InvalidArgumentException('Backup ZIP member could not be read.'); }
                $hash = hash_init('sha256'); $bytes = 0;
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false || ($chunk === '' && !feof($stream))) { throw new InvalidArgumentException('Backup ZIP member read failed.'); }
                        $bytes += strlen($chunk); hash_update($hash, $chunk);
                    }
                } finally { fclose($stream); }
                if ($bytes !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_final($hash))) {
                    throw new InvalidArgumentException('Backup ZIP member digest differs from manifest.');
                }
            }
            if (count($seen) !== count($expected)) { throw new InvalidArgumentException('Backup ZIP is missing a declared member.'); }
        } finally { $zip->close(); }
    }

    private static function source($root, $path)
    {
        if (!preg_match('#\A(?:database/(?:baseline|schema|triggers)\.sql|database/data/[a-z][a-z0-9_]{0,63}/[0-9]{6}\.sql|runtime/[A-Za-z0-9_.@/+\-]+|persistent/avatars/[a-f0-9]{40}\.(?:jpg|png|webp)|configuration/[a-z0-9_.-]+\.json)\z#', $path)
            || strpos('/' . $path . '/', '/../') !== false || strpos('/' . $path . '/', '/./') !== false) {
            throw new InvalidArgumentException('Backup member path is invalid.');
        }
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
