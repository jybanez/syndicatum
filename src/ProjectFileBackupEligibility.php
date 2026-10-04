<?php

require_once __DIR__ . '/ProjectFileBackupSource.php';

/** Read-only integrity and capacity preflight for Project Files in full-clone backups. */
final class ProjectFileBackupEligibility
{
    public static function snapshot(PDO $pdo, $applicationRoot, $configuredProjectRoot, $backupRoot, $freeSpaceProbe = null)
    {
        if ($freeSpaceProbe !== null && !is_callable($freeSpaceProbe)) {
            throw new InvalidArgumentException('Backup free-space probe must be callable.');
        }
        $summary = self::availableSummary($pdo);
        $result = [
            'state' => 'ready',
            'full_clone_backup_eligible' => true,
            'available_file_count' => $summary['count'],
            'available_bytes' => $summary['bytes'],
            'verified_file_count' => 0,
            'backup_free_bytes' => null,
            'unavailable_reason' => null,
            'message' => $summary['count'] === 0
                ? 'No available Project Files require protection in a full-clone backup.'
                : 'Project File integrity and backup capacity were verified.',
        ];

        if ($summary['count'] === 0) { return $result; }

        try {
            self::assertExistingPrivateRoot($applicationRoot, $configuredProjectRoot);
            $source = new ProjectFileBackupSource($applicationRoot, $configuredProjectRoot);
            $inventory = $source->inventory($pdo, true);
            if (count($inventory) !== $summary['count']) {
                throw new RuntimeException('Project File inventory changed during backup preflight.');
            }
            $result['verified_file_count'] = count($inventory);
        } catch (Throwable $error) {
            return self::unavailable($result, 'project_file_integrity_failed',
                'Project File storage is missing, unreadable, or failed integrity verification.');
        }

        $backupRoot = is_string($backupRoot) ? trim($backupRoot) : '';
        $application = realpath($applicationRoot);
        $resolvedBackup = $backupRoot === '' ? false : realpath($backupRoot);
        if (!is_string($application) || !is_string($resolvedBackup) || !is_dir($resolvedBackup)
            || is_link($backupRoot) || self::inside($resolvedBackup, $application)
            || self::inside($application, $resolvedBackup) || !is_writable($resolvedBackup)
            || (DIRECTORY_SEPARATOR === '/' && ((fileperms($resolvedBackup) & 0200) === 0
                || (fileperms($resolvedBackup) & 0077) !== 0))) {
            return self::unavailable($result, 'backup_storage_unwritable',
                'Backup storage is not writable; no full-clone backup can be started.');
        }
        $free = $freeSpaceProbe === null ? @disk_free_space($resolvedBackup)
            : call_user_func($freeSpaceProbe, $resolvedBackup);
        if ($free === false || !is_finite((float) $free)) {
            return self::unavailable($result, 'backup_capacity_unknown',
                'Backup storage capacity could not be verified; no full-clone backup can be started.');
        }
        $result['backup_free_bytes'] = (int) $free;
        if ((int) $free < $summary['bytes']) {
            return self::unavailable($result, 'insufficient_backup_space',
                'Backup storage does not have enough free space for the referenced Project Files.');
        }
        return $result;
    }

    public static function assertFullCloneEligible(PDO $pdo, $applicationRoot, $configuredProjectRoot, $backupRoot, $freeSpaceProbe = null)
    {
        $result = self::snapshot($pdo, $applicationRoot, $configuredProjectRoot, $backupRoot, $freeSpaceProbe);
        if (!$result['full_clone_backup_eligible']) {
            throw new RuntimeException('PROJECT_FILE_BACKUP_INELIGIBLE:' . $result['unavailable_reason']);
        }
        return $result;
    }

    private static function availableSummary(PDO $pdo)
    {
        if (!self::tableExists($pdo, 'project_files')) { return ['count' => 0, 'bytes' => 0]; }
        $row = $pdo->query("SELECT COUNT(*) AS file_count, COALESCE(SUM(size_bytes), 0) AS total_bytes
            FROM project_files WHERE deleted_at IS NULL AND state = 'available'")->fetch(PDO::FETCH_ASSOC);
        return ['count' => max(0, (int) $row['file_count']), 'bytes' => max(0, (int) $row['total_bytes'])];
    }

    private static function assertExistingPrivateRoot($applicationRoot, $configuredRoot)
    {
        $application = realpath($applicationRoot);
        $candidate = is_string($configuredRoot) ? trim($configuredRoot) : '';
        $absolute = preg_match('/\A[A-Za-z]:[\\\\\/]/', $candidate) === 1
            || preg_match('/\A\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+/', $candidate) === 1
            || strpos($candidate, '/') === 0;
        if (!is_string($application) || !is_dir($application) || $candidate === '' || !$absolute
            || !is_dir($candidate) || is_link($candidate)) {
            throw new RuntimeException('Project File storage root is unavailable.');
        }
        $root = realpath($candidate);
        if (!is_string($root) || self::inside($root, $application) || self::inside($application, $root)
            || !is_readable($root) || !is_writable($root)) {
            throw new RuntimeException('Project File storage root is unsafe or inaccessible.');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($root) & 0077) !== 0) {
            throw new RuntimeException('Project File storage root is not private.');
        }
    }

    private static function tableExists(PDO $pdo, $table)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $statement = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $statement->execute([$table]);
            return (int) $statement->fetchColumn() === 1;
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private static function unavailable(array $result, $reason, $message)
    {
        $result['state'] = 'unhealthy';
        $result['full_clone_backup_eligible'] = false;
        $result['unavailable_reason'] = $reason;
        $result['message'] = $message;
        return $result;
    }

    private static function inside($path, $root)
    {
        if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || strpos($path, $root . DIRECTORY_SEPARATOR) === 0;
    }
}
