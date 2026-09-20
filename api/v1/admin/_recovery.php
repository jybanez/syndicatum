<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AdminRecoveryService.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    Api::json(['error' => true, 'code' => 'NOT_FOUND', 'message' => 'Not found.'], 404);
}

function recoveryAuth()
{
    $pdo = Db::pdo();
    $auth = new AuthService($pdo);
    $user = $auth->requireAdministrator();
    return [$pdo, $auth, $user];
}

function recoveryService(PDO $pdo) { return new AdminRecoveryService($pdo); }

function recoveryRequireMethod(array $methods)
{
    if (!in_array(Api::method(), $methods, true)) {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => implode(', ', $methods)]);
    }
}

function recoveryError(Throwable $exception)
{
    if ($exception instanceof InvalidArgumentException) {
        Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
    }
    $code = $exception->getMessage();
    $known = [
        'AUTHENTICATION_REQUIRED' => [401, 'Authentication is required.'],
        'ADMINISTRATOR_REQUIRED' => [403, 'Administrator access is required.'],
        'CSRF_VALIDATION_FAILED' => [403, 'CSRF validation failed.'],
        'IDEMPOTENCY_KEY_CONFLICT' => [409, 'This idempotency key was already used for a different recovery request.'],
        'RECOVERY_OPERATION_IN_PROGRESS' => [409, 'Another recovery operation is in progress. Try again after it finishes.'],
        'RECOVERY_LOCK_UNAVAILABLE' => [503, 'Recovery operation locking is unavailable.'],
        'RELEASE_PACKAGE_UNAVAILABLE' => [503, 'A verified CI-built release package is not available on this instance.'],
        'RESTORE_TARGET_NOT_CONFIGURED' => [503, 'A separate staged-restore database is not configured.'],
        'RESTORE_TARGET_IS_SERVING_DATABASE' => [409, 'The serving database can never be used as a staged-restore target.'],
        'RESTORE_INSPECTION_EXPIRED' => [410, 'The verified restore inspection expired. Upload and inspect the backup again.'],
        'RESTORE_INSPECTION_CHANGED' => [409, 'The retained encrypted backup no longer matches its verified inspection.'],
        'RESTORE_INSPECTION_QUOTA_EXCEEDED' => [507, 'Private restore-inspection storage reached its capacity limit. Let existing inspections expire or complete one before retrying.'],
        'BACKUP_ARTIFACT_NOT_AVAILABLE' => [410, 'The encrypted backup artifact is no longer available. Build a new backup.'],
        'BACKUP_STORAGE_QUOTA_EXCEEDED' => [507, 'Private backup storage reached its retention or capacity limit.'],
        'RESTORE_INSPECTION_ALREADY_CLAIMED' => [409, 'This inspection is already bound to another staged-restore operation. Upload and inspect the backup again before retrying.'],
    ];
    if (isset($known[$code])) { Api::json(['error' => true, 'code' => $code, 'message' => $known[$code][1]], $known[$code][0]); }
    error_log('Syndicatum recovery API error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'RECOVERY_OPERATION_FAILED', 'message' => 'The recovery operation could not be completed safely.'], 500);
}
