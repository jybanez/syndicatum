<?php

require_once __DIR__ . '/PrivateStorage.php';

/** Server-selected storage outside the application web root. */
final class CurrentBackupStorage
{
    private $base;

    public static function configuration($name)
    {
        $environment = getenv($name);
        if (is_string($environment) && trim($environment) !== '') { return $environment; }
        $path = PrivateStorage::file('syndicatum-backup-config.php');
        if (!is_file($path)) { return null; }
        $settings = require $path;
        if (!is_array($settings)) { throw new RuntimeException('Private backup configuration is invalid.'); }
        $value = isset($settings[$name]) ? $settings[$name] : null;
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function __construct($applicationRoot, $configuredBase)
    {
        $public = realpath($applicationRoot);
        if (!is_string($public) || !is_dir($public)) { throw new InvalidArgumentException('Application root is invalid.'); }
        $candidate = is_string($configuredBase) ? trim($configuredBase) : '';
        $absolute = preg_match('/\A[A-Za-z]:[\\\\\/]/', $candidate) === 1
            || preg_match('/\A\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+/', $candidate) === 1
            || strpos($candidate, '/') === 0;
        if ($candidate === '' || !$absolute) { throw new InvalidArgumentException('Backup storage location must be an absolute server filesystem path.'); }
        $ancestor = $candidate;
        while (!file_exists($ancestor)) {
            $next = dirname($ancestor);
            if ($next === $ancestor) { throw new RuntimeException('Backup directory has no valid parent.'); }
            $ancestor = $next;
        }
        $realAncestor = realpath($ancestor);
        if (!is_string($realAncestor) || self::inside($realAncestor, $public)) {
            throw new RuntimeException('Backup directory must be outside the public application root.');
        }
        if (!is_dir($candidate) && !@mkdir($candidate, 0700, true)) {
            throw new RuntimeException('Private backup directory could not be created.');
        }
        $base = realpath($candidate);
        if (!is_string($base) || !is_dir($base) || is_link($candidate) || self::inside($base, $public)
            || self::inside($public, $base)) {
            throw new RuntimeException('Backup directory must be separate from the public application root.');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($base) & 0077) !== 0) {
            throw new RuntimeException('Backup directory must be private (0700).');
        }
        $this->base = $base;
        foreach (['staging', 'artifacts', 'inspection', 'jobs', 'download-authorizations',
            'restore-staging', 'restore-jobs'] as $name) {
            $path = $base . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($path) && !@mkdir($path, 0700)) {
                throw new RuntimeException('Backup ' . $name . ' directory could not be created.');
            }
            if (is_link($path) || realpath($path) !== $path) {
                throw new RuntimeException('Backup ' . $name . ' directory is not canonical.');
            }
            if (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0077) !== 0) {
                throw new RuntimeException('Backup ' . $name . ' directory must be private.');
            }
        }
    }

    public function base() { return $this->base; }
    public function stage() { return $this->base . DIRECTORY_SEPARATOR . 'staging'; }
    public function artifacts() { return $this->base . DIRECTORY_SEPARATOR . 'artifacts'; }
    public function inspectionRoot() { return $this->base . DIRECTORY_SEPARATOR . 'inspection'; }
    public function jobs() { return $this->base . DIRECTORY_SEPARATOR . 'jobs'; }
    public function downloads() { return $this->base . DIRECTORY_SEPARATOR . 'download-authorizations'; }
    public function jobStoreLock() { return $this->base . DIRECTORY_SEPARATOR . 'backup-jobs.lock'; }
    public function workerLock() { return $this->base . DIRECTORY_SEPARATOR . 'backup-worker.lock'; }
    public function restoreStageRoot() { return $this->base . DIRECTORY_SEPARATOR . 'restore-staging'; }
    public function restoreJobs() { return $this->base . DIRECTORY_SEPARATOR . 'restore-jobs'; }
    public function restoreJobStoreLock() { return $this->base . DIRECTORY_SEPARATOR . 'restore-jobs.lock'; }
    public function restoreWorkerLock() { return $this->base . DIRECTORY_SEPARATOR . 'restore-worker.lock'; }

    public function restoreJob($operationId)
    {
        $this->artifact($operationId);
        return $this->restoreJobs() . DIRECTORY_SEPARATOR . strtolower($operationId) . '.json';
    }

    public function restoreStage($operationId)
    {
        $this->artifact($operationId);
        return $this->restoreStageRoot() . DIRECTORY_SEPARATOR . strtolower($operationId);
    }

    public function job($operationId)
    {
        $this->artifact($operationId);
        return $this->jobs() . DIRECTORY_SEPARATOR . strtolower($operationId) . '.json';
    }

    public function downloadAuthorization($tokenHash)
    {
        if (!is_string($tokenHash) || !preg_match('/\A[a-f0-9]{64}\z/', $tokenHash)) {
            throw new InvalidArgumentException('Backup download token hash is invalid.');
        }
        return $this->downloads() . DIRECTORY_SEPARATOR . $tokenHash . '.json';
    }

    public function artifact($operationId)
    {
        if (!is_string($operationId) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $operationId)) {
            throw new InvalidArgumentException('Backup operation ID is invalid.');
        }
        return $this->artifacts() . DIRECTORY_SEPARATOR . strtolower($operationId) . '.syndicatum-backup';
    }

    public function inspection($operationId)
    {
        $this->artifact($operationId);
        return $this->inspectionRoot() . DIRECTORY_SEPARATOR . strtolower($operationId) . '.zip';
    }

    private static function inside($path, $root)
    {
        $a = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $b = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        return $a === $b || strpos($a, $b . DIRECTORY_SEPARATOR) === 0;
    }
}
