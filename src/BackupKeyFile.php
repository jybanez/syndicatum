<?php

require_once __DIR__ . '/BackupEnvelope.php';

final class BackupKeyFile
{
    public static function loadFromEnvironment()
    {
        $path = getenv('SYNDICATUM_BACKUP_KEY_FILE');
        if ($path === false || trim((string) $path) === '') {
            throw new RuntimeException('SYNDICATUM_BACKUP_KEY_FILE must identify a private file containing one base64-encoded 32-byte key.');
        }
        if (!is_file($path) || is_link($path)) { throw new RuntimeException('Backup key file must be a regular non-link file.'); }
        if (DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($path);
            if (!is_int($mode) || (($mode & 0077) !== 0)) { throw new RuntimeException('Backup key file must not be accessible to group or other users.'); }
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) > 256) { throw new RuntimeException('Backup key file is missing or unexpectedly large.'); }
        return BackupEnvelope::keyFromBase64(trim($bytes));
    }
}
