<?php

require_once __DIR__ . '/CurrentBackupStorage.php';

final class CurrentRestoreWorkerLauncher
{
    public static function launch(CurrentBackupStorage $storage, $applicationRoot)
    {
        $configured = CurrentBackupStorage::configuration('SYNDICATUM_BACKUP_PHP_BINARY');
        $binary = is_string($configured) && trim($configured) !== '' ? $configured
            : PHP_BINDIR . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');
        $script = rtrim($applicationRoot, '/\\') . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'process-current-restore-jobs.php';
        if (!is_file($binary) || !is_file($script)) { throw new RuntimeException('Restore worker runtime is not installed.'); }
        if (DIRECTORY_SEPARATOR === '\\') {
            $out=$storage->base().DIRECTORY_SEPARATOR.'restore-worker.stdout.log';
            $err=$storage->base().DIRECTORY_SEPARATOR.'restore-worker.stderr.log';
            $quote=function($v){return "'".str_replace("'","''",$v)."'";};
            $ps='Start-Process -FilePath '.$quote($binary).' -ArgumentList @('.$quote('"'.$script.'"').') -WindowStyle Hidden -RedirectStandardOutput '.$quote($out).' -RedirectStandardError '.$quote($err).' -PassThru | Out-Null';
            $command='powershell.exe -NoProfile -NonInteractive -EncodedCommand '.base64_encode(iconv('UTF-8','UTF-16LE',$ps));
        } else {
            $log=$storage->base().DIRECTORY_SEPARATOR.'restore-worker.log';
            $command='nohup '.escapeshellarg($binary).' '.escapeshellarg($script).' >> '.escapeshellarg($log).' 2>&1 < /dev/null &';
        }
        $process=@popen($command,'r');if(!is_resource($process))throw new RuntimeException('Restore worker could not be launched.');
        if(pclose($process)!==0)throw new RuntimeException('Restore worker launcher exited with an error.');
    }
}
