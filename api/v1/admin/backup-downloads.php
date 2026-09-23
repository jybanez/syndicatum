<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentBackupJobStore.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/SettingsService.php';

try {
    if (Api::method() !== 'POST') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'POST']);
    }
    $auth = new AuthService(Db::pdo());
    $user = $auth->requireAdministrator();
    $auth->validateCsrf($user, Api::csrfToken());
    $body = Api::body();
    if (!is_array($body) || array_keys($body) !== ['operation_id'] || !is_string($body['operation_id'])) {
        throw new InvalidArgumentException('Choose a completed backup to download.');
    }
    $settings = new SettingsService(Db::pdo());
    $storage = new CurrentBackupStorage(dirname(dirname(dirname(__DIR__))), $settings->get('recovery.backup_base_path'));
    $jobs = new CurrentBackupJobStore($storage);
    $token = $jobs->issueDownload($body['operation_id'], (int) $user['id']);
    Api::json(['data' => ['download_url' => 'api/v1/admin/artifact-download.php?token=' . rawurlencode($token),
        'expires_in_seconds' => 600]]);
} catch (InvalidArgumentException $error) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    $code = $error->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401
        : ($code === 'ADMINISTRATOR_REQUIRED' || $code === 'CSRF_VALIDATION_FAILED' ? 403 : 409);
    Api::json(['error' => true, 'code' => 'download_unavailable',
        'message' => $status === 401 ? 'Authentication is required.'
            : ($status === 403 ? 'Administrator access or a valid request token is required.' : 'Backup download is unavailable.')], $status);
} catch (Throwable $error) {
    error_log('Syndicatum backup download authorization failed: ' . $error->getMessage());
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Backup download is unavailable.'], 500);
}
