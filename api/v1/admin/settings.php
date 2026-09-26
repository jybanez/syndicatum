<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/SettingsService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentBackupStorage.php';

try {
    $pdo = Db::pdo();
    $auth = new AuthService($pdo);
    $user = $auth->requireAdministrator();
    $settings = new SettingsService($pdo);
    $method = Api::method();

    if ($method === 'GET') {
        $section = isset($_GET['section']) && trim((string) $_GET['section']) !== '' ? trim((string) $_GET['section']) : null;
        Api::json(['data' => ['settings' => $settings->publicSettings($section)]]);
    }
    if ($method === 'POST') {
        $auth->validateCsrf($user, Api::csrfToken());
        $body = Api::body();
        if (($body['operation'] ?? null) !== 'read_secrets' || count($body) !== 1) {
            throw new InvalidArgumentException('A valid settings operation is required.');
        }
        $secretKeys = [
            'realtime.signing_secret',
            'realtime.backend_ingress_secret',
            'account.client_secret',
            'google.client_secret',
        ];
        $secrets = [];
        foreach ($secretKeys as $key) {
            $value = $settings->get($key);
            $secrets[$key] = $value === null ? '' : (string) $value;
        }
        $audit = $pdo->prepare(
            'INSERT INTO administrative_audit_events
             (actor_user_id, action, subject_type, subject_id, metadata_json, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $audit->execute([
            (int) $user['id'],
            'settings.secrets_viewed',
            'system_settings',
            null,
            json_encode(['keys' => $secretKeys]),
            isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 80) : null,
            Db::now(),
        ]);
        Api::json(
            ['data' => ['secrets' => $secrets]],
            200,
            ['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']
        );
    }
    if ($method === 'PATCH') {
        $auth->validateCsrf($user, Api::csrfToken());
        $body = Api::body();
        $changes = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : [];
        if (empty($changes)) {
            throw new InvalidArgumentException('At least one setting change is required.');
        }
        if (array_key_exists('recovery.backup_base_path', $changes)) {
            try {
                $storage = new CurrentBackupStorage(dirname(dirname(dirname(__DIR__))), $changes['recovery.backup_base_path']);
                $changes['recovery.backup_base_path'] = $storage->base();
            } catch (Throwable $error) {
                throw new InvalidArgumentException('Base location for generated backups is invalid or unavailable: ' . $error->getMessage());
            }
        }
        $data = $settings->update($changes, $user['id']);
        Api::json(['data' => ['settings' => $data]]);
    }
    Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, POST, PATCH']);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401 : 403;
    Api::json(['error' => true, 'code' => strtolower($code), 'message' => $status === 401 ? 'Authentication is required.' : 'Administrator access or a valid request token is required.'], $status);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to process system settings.'], 500);
}
