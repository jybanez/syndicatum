<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentBackupJobStore.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentBackupWorkerLauncher.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/SettingsService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/RealtimeIntegration.php';

try {
    $method = Api::method();
    if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, POST, DELETE']);
    }
    $auth = new AuthService(Db::pdo());
    $user = $auth->requireAdministrator();
    if ($method !== 'GET') {
        $auth->validateCsrf($user, Api::csrfToken());
    }
    $root = dirname(dirname(dirname(__DIR__)));
    $settings = new SettingsService(Db::pdo());
    $storage = new CurrentBackupStorage($root, $settings->get('recovery.backup_base_path'));
    $jobs = new CurrentBackupJobStore($storage);
    $realtime = new RealtimeIntegration($settings);
    if ($method === 'GET') {
        $id = isset($_GET['operation_id']) ? trim((string) $_GET['operation_id']) : '';
        if ($id !== '') {
            $job = $jobs->get($id);
            if ($job === null) { Api::json(['error' => true, 'code' => 'not_found', 'message' => 'Backup operation not found.'], 404); }
            Api::json(['data' => ['backup' => $job]]);
        }
        Api::json(['data' => ['backups' => $jobs->recent(), 'storage_base' => $storage->base()]]);
    }
    // The mutation does not accept client-controlled paths; storage destinations are server-selected.
    $body = Api::body();
    if ($method === 'DELETE') {
        if (is_array($body) && array_keys($body) === ['operation_id'] && is_string($body['operation_id'])) {
            $deleted = $jobs->delete(trim($body['operation_id']));
            $auth->audit((int) $user['id'], 'backup.deleted', 'backup', (string) $deleted['operation_id'], [
                'status' => $deleted['status'], 'package_type' => $deleted['include_data'] ? 'full_clone' : 'clean_installation',
            ]);
            Api::json(['data' => ['operation_id' => $deleted['operation_id'], 'deleted' => true]]);
        }
        if (!is_array($body) || array_keys($body) !== ['clear', 'confirmation'] || !is_string($body['clear']) || !is_string($body['confirmation'])
            || !in_array($body['clear'], ['failed', 'all'], true)
            || ($body['clear'] === 'failed' && $body['confirmation'] !== 'CLEAR FAILED')
            || ($body['clear'] === 'all' && $body['confirmation'] !== 'CLEAR ALL BACKUPS')) {
            throw new InvalidArgumentException('Confirm which completed backups must be cleared.');
        }
        $deleted = $jobs->clear($body['clear']);
        $ids = array_map(function ($row) { return $row['operation_id']; }, $deleted);
        $auth->audit((int) $user['id'], 'backup.cleared', 'backup', $body['clear'], [
            'scope' => $body['clear'], 'deleted_count' => count($ids),
        ]);
        Api::json(['data' => ['scope' => $body['clear'], 'deleted_operation_ids' => $ids, 'deleted_count' => count($ids)]]);
    }
    if (!is_array($body) || array_keys($body) !== ['include_data'] || !is_bool($body['include_data'])) {
        throw new InvalidArgumentException('Choose either a full clone or a clean installation package.');
    }
    $key = Api::idempotencyKey();
    $job = $jobs->create((int) $user['id'], (string) $user['display_name'], $key, $body['include_data']);
    if ($job['status'] === 'Queued') {
        try {
            $realtime->publishBackupUpdated($job);
            CurrentBackupWorkerLauncher::launch($storage, $root);
        }
        catch (Throwable $launchError) {
            $failed = $jobs->fail($job['operation_id'], strpos($launchError->getMessage(), 'Realtime') !== false
                ? 'Backup Realtime is unavailable; no backup was started.'
                : 'Backup worker is unavailable; contact the administrator.');
            error_log('Syndicatum backup start failed: ' . $launchError->getMessage());
            try { $realtime->publishBackupUpdated($failed); } catch (Throwable $ignored) {}
            throw new RuntimeException(strpos($launchError->getMessage(), 'Realtime') !== false
                ? 'BACKUP_REALTIME_UNAVAILABLE' : 'BACKUP_WORKER_UNAVAILABLE');
        }
    }
    Api::json(['data' => ['backup' => $jobs->get($job['operation_id'])]], 202);
} catch (InvalidArgumentException $error) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    $code = $error->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401
        : ($code === 'ADMINISTRATOR_REQUIRED' || $code === 'CSRF_VALIDATION_FAILED' ? 403 : 503);
    $message = $status === 401 ? 'Authentication is required.'
        : ($status === 403 ? 'Administrator access or a valid request token is required.'
            : ($code === 'BACKUP_REALTIME_UNAVAILABLE' ? 'Backup Realtime is unavailable; no backup was started.' : 'Backup service is unavailable.'));
    Api::json(['error' => true, 'code' => strtolower($code), 'message' => $message], $status);
} catch (Throwable $error) {
    error_log('Syndicatum backup API failed: ' . $error->getMessage());
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Backup service is unavailable.'], 500);
}
