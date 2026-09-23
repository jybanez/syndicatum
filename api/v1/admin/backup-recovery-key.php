<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentBackupEnvelope.php';
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
        throw new InvalidArgumentException('Choose a completed backup before copying its recovery key.');
    }
    $root = dirname(dirname(dirname(__DIR__)));
    $settings = new SettingsService(Db::pdo());
    $storage = new CurrentBackupStorage($root, $settings->get('recovery.backup_base_path'));
    $job = (new CurrentBackupJobStore($storage))->get($body['operation_id']);
    if ($job === null || $job['status'] !== 'Ready' || !is_file($storage->artifact($job['operation_id']))) {
        throw new RuntimeException('Backup is not ready for recovery-key access.');
    }
    $encoded = CurrentBackupStorage::configuration('SYNDICATUM_BACKUP_KEY');
    $key = CurrentBackupEnvelope::keyFromBase64($encoded);
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    Api::json(['data' => ['recovery_key' => trim($encoded), 'key_id' => CurrentBackupEnvelope::keyId($key)]]);
} catch (InvalidArgumentException $error) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    $code = $error->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401
        : ($code === 'ADMINISTRATOR_REQUIRED' || $code === 'CSRF_VALIDATION_FAILED' ? 403 : 409);
    Api::json(['error' => true, 'code' => 'recovery_key_unavailable',
        'message' => $status === 401 ? 'Authentication is required.'
            : ($status === 403 ? 'Administrator access or a valid request token is required.'
                : 'The recovery key is unavailable for this backup.')], $status);
} catch (Throwable $error) {
    error_log('Syndicatum backup recovery-key access failed: ' . $error->getMessage());
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'The recovery key is unavailable.'], 500);
}
