<?php

require_once __DIR__ . '/PortableBackupRuntime.php';
require_once __DIR__ . '/PrivateStorage.php';

/** Captures protected application configuration while intentionally excluding database credentials. */
final class PortableBackupConfiguration
{
    public static function collect($payloadRoot)
    {
        $sources = [
            'syndicatum-secrets' => getenv('SYNDICATUM_SECRETS_FILE') ?: PrivateStorage::file('syndicatum-secrets.php'),
            'syndicatum-backup-config' => getenv('SYNDICATUM_BACKUP_CONFIG') ?: PrivateStorage::file('syndicatum-backup-config.php'),
        ];
        $excluded = ['PBB_AGENTCHAT_DB_HOST','PBB_AGENTCHAT_DB_NAME','PBB_AGENTCHAT_DB_USER','PBB_AGENTCHAT_DB_PASS'];
        $entries = [];
        foreach ($sources as $name => $source) {
            if (!is_file($source) || is_link($source)) { throw new RuntimeException('Protected Syndicatum configuration is unavailable: ' . $name . '.'); }
            $values = require $source;
            if (!is_array($values)) { throw new RuntimeException('Protected Syndicatum configuration is invalid: ' . $name . '.'); }
            foreach ($excluded as $key) { unset($values[$key]); }
            ksort($values, SORT_STRING);
            foreach ($values as $key => $value) {
                if (!is_string($key) || !preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) || (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null)) {
                    throw new RuntimeException('Protected Syndicatum configuration contains an unsupported value.');
                }
            }
            $json = json_encode(['values' => $values], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) { throw new RuntimeException('Protected Syndicatum configuration could not be encoded.'); }
            $path = 'configuration/' . $name . '.json';
            $destination = rtrim($payloadRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0700, true)) { throw new RuntimeException('Portable configuration directory could not be created.'); }
            PortableBackupRuntime::writePrivate($destination, $json);
            $entries[] = ['path' => $path, 'sha256' => hash_file('sha256', $destination), 'bytes' => filesize($destination)];
        }
        usort($entries, function ($a, $b) { return strcmp($a['path'], $b['path']); });
        return $entries;
    }
}
