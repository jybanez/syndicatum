<?php

require_once __DIR__ . '/CurrentBackupStorage.php';

/** Starts a detached CLI worker; no request or browser connection owns the job. */
final class CurrentBackupWorkerLauncher
{
    public static function launch(CurrentBackupStorage $storage, $applicationRoot)
    {
        $configured = CurrentBackupStorage::configuration('SYNDICATUM_BACKUP_PHP_BINARY');
        $binary = is_string($configured) && trim($configured) !== '' ? $configured
            : PHP_BINDIR . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
        $script = rtrim($applicationRoot, '/\\') . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'process-current-backup-jobs.php';
        if (!is_file($binary) || !is_file($script)) { throw new RuntimeException('Backup worker runtime is not installed.'); }
        $log = $storage->base() . DIRECTORY_SEPARATOR . 'backup-worker.log';
        if (DIRECTORY_SEPARATOR === '\\') {
            $out = $storage->base() . DIRECTORY_SEPARATOR . 'backup-worker.stdout.log';
            $err = $storage->base() . DIRECTORY_SEPARATOR . 'backup-worker.stderr.log';
            $quote = function ($value) { return "'" . str_replace("'", "''", $value) . "'"; };
            $ps = 'Start-Process -FilePath ' . $quote($binary) . ' -ArgumentList @(' . $quote('"' . $script . '"')
                . ') -WindowStyle Hidden -RedirectStandardOutput ' . $quote($out)
                . ' -RedirectStandardError ' . $quote($err) . ' -PassThru | Out-Null';
            $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $ps));
            $command = 'powershell.exe -NoProfile -NonInteractive -EncodedCommand ' . $encoded;
        } else {
            $command = 'nohup ' . escapeshellarg($binary) . ' ' . escapeshellarg($script)
                . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
        }
        $process = @popen($command, 'r');
        if (!is_resource($process)) { throw new RuntimeException('Backup worker could not be launched.'); }
        $exit = pclose($process);
        if ($exit !== 0) { throw new RuntimeException('Backup worker launcher exited with an error.'); }
    }
}
