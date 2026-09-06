<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/SettingsService.php';

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
    if ($method === 'PATCH') {
        $auth->validateCsrf($user, Api::csrfToken());
        $body = Api::body();
        $changes = isset($body['settings']) && is_array($body['settings']) ? $body['settings'] : [];
        if (empty($changes)) {
            throw new InvalidArgumentException('At least one setting change is required.');
        }
        $data = $settings->update($changes, $user['id']);
        Api::json(['data' => ['settings' => $data]]);
    }
    Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, PATCH']);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401 : 403;
    Api::json(['error' => true, 'code' => strtolower($code), 'message' => $status === 401 ? 'Authentication is required.' : 'Administrator access or a valid request token is required.'], $status);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to process system settings.'], 500);
}
