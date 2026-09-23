<?php

require_once __DIR__ . '/CurrentBackupJobStore.php';
require_once __DIR__ . '/CurrentBackupProducer.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/RealtimeIntegration.php';

/** One exclusive server-side worker; the browser never owns the producer. */
final class CurrentBackupWorker
{
    private $storage;
    private $jobs;
    private $applicationRoot;
    private $avatarRoot;
    private $realtime;

    public function __construct(CurrentBackupStorage $storage, CurrentBackupJobStore $jobs, $applicationRoot, $avatarRoot, $realtime)
    {
        $this->storage = $storage;
        $this->jobs = $jobs;
        $this->applicationRoot = $applicationRoot;
        $this->avatarRoot = $avatarRoot;
        if (!is_object($realtime) || !method_exists($realtime, 'publishBackupUpdated')) {
            throw new InvalidArgumentException('Backup worker requires PBB Realtime publishing.');
        }
        $this->realtime = $realtime;
    }

    public function runPending()
    {
        $lock = @fopen($this->storage->workerLock(), 'c');
        if (!is_resource($lock)) { throw new RuntimeException('Backup worker lock could not be opened.'); }
        @chmod($this->storage->workerLock(), 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return 0; }
        try {
            foreach ($this->jobs->running() as $interrupted) {
                $artifact = $this->storage->artifact($interrupted['operation_id']);
                if (is_file($artifact)) { @unlink($artifact); }
            }
            foreach ($this->jobs->failInterrupted() as $interrupted) {
                try { $this->publish($interrupted); }
                catch (Throwable $error) {
                    error_log('Syndicatum interrupted backup publication failed: ' . $error->getMessage());
                }
            }
            $processed = 0;
            while (($job = $this->jobs->claimQueued()) !== null) {
                try { $this->publish($job); }
                catch (Throwable $error) {
                    $failed = $this->jobs->fail($job['operation_id'], $error->getMessage());
                    try { $this->publish($failed); }
                    catch (Throwable $publishError) { error_log('Syndicatum backup failure publication failed: ' . $publishError->getMessage()); }
                    error_log('Syndicatum backup ' . $job['operation_id'] . ' failed before production: ' . $error->getMessage());
                    $processed++;
                    continue;
                }
                $this->process($job);
                $processed++;
            }
            return $processed;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function process(array $job)
    {
        $id = $job['operation_id'];
        $artifact = $this->storage->artifact($id);
        try {
            $encodedKey = CurrentBackupStorage::configuration('SYNDICATUM_BACKUP_KEY');
            $key = CurrentBackupEnvelope::keyFromBase64($encodedKey);
            if (!is_dir($this->avatarRoot)) {
                throw new RuntimeException('Private avatar directory is unavailable.');
            }
            $producer = new CurrentBackupProducer(Db::pdo(), $this->storage->stage(),
                $this->avatarRoot, $this->applicationRoot);
            $lastPublishedStage = null;
            $lastPublishedPercent = -1;
            $made = $producer->produce($artifact, $key, $id, function ($stage, $percent) use ($id, &$lastPublishedStage, &$lastPublishedPercent) {
                $job = $this->jobs->progress($id, $stage, $percent);
                $stageChanged = $stage !== $lastPublishedStage;
                $percentAdvanced = $percent > $lastPublishedPercent;
                $publish = $stageChanged || ($percentAdvanced
                    && ($percent === 100 || $percent - $lastPublishedPercent >= 10));
                if (!$publish) { return; }
                $this->publish($job);
                $lastPublishedStage = $stage;
                $lastPublishedPercent = $percent;
            }, (bool) $job['include_data']);
            if (!$made['plaintext_cleanup_verified']) { throw new RuntimeException('Backup plaintext cleanup was not verified.'); }
            $this->publish($this->jobs->ready($id, $artifact, $made['envelope_sha256']));
        } catch (Throwable $error) {
            if (is_file($artifact)) { @unlink($artifact); }
            $inspection = $this->storage->inspection($id);
            if (is_file($inspection)) { @unlink($inspection); }
            $failed = $this->jobs->fail($id, strpos($error->getMessage(), 'Backup Realtime publish failed:') === 0
                ? $error->getMessage() : 'Backup failed; retry after checking the backup service logs.');
            try { $this->publish($failed); }
            catch (Throwable $publishError) { error_log('Syndicatum backup failure publication failed: ' . $publishError->getMessage()); }
            error_log('Syndicatum backup ' . $id . ' failed: ' . $error->getMessage());
        }
    }

    private function publish(array $job)
    {
        try { $this->realtime->publishBackupUpdated($job); }
        catch (Throwable $error) {
            $message = $error->getMessage();
            if (strpos($message, 'Backup Realtime publish failed:') !== 0) {
                $message = 'Backup Realtime publish failed: ' . $message;
            }
            throw new RuntimeException($message, 0, $error);
        }
    }
}
