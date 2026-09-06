<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/AuthService.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';
require_once dirname(dirname(__DIR__)) . '/src/RealtimeIntegration.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';

try {
    $pdo = Db::pdo();
    $service = new ConnectorDeviceService($pdo);
    $limiter = new RateLimiter($pdo);
    if (Api::method() === 'POST' && !isset($_GET['approve'])) {
        $limiter->hit('connector.device.begin', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', 10, 600, 600);
        $input = Api::body();
        $authorization = $service->begin($input);
        $realtime = new RealtimeIntegration(new SettingsService($pdo));
        $authorization['realtime'] = $realtime->buildConnectorAuthorizationAdmission($authorization['authorization_id'], $input['device_name'] ?? 'Codex connector', $authorization['expires_at']);
        Api::json(['data' => $authorization], 201);
    }
    if (Api::method() !== 'POST') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    $auth = new AuthService($pdo);
    $user = $auth->requireUser();
    $auth->validateCsrf($user, Api::csrfToken());
    $body = Api::body();
    $approved = $service->approve(isset($body['user_code']) ? $body['user_code'] : '', $user);
    $approved['notification_delivered'] = true;
    try {
        (new RealtimeIntegration(new SettingsService($pdo)))->publishConnectorAuthorizationApproved($approved['authorization_id']);
    } catch (Exception $exception) {
        $approved['notification_delivered'] = false;
        error_log('Connector authorization approved but Realtime notification failed: ' . $exception->getMessage());
    }
    unset($approved['authorization_id']);
    Api::json(['data' => $approved]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401 : ($code === 'RATE_LIMITED' ? 429 : 400);
    Api::json(['error' => true, 'code' => $code, 'message' => $code === 'AUTHENTICATION_REQUIRED' ? 'Authentication is required.' : 'Device authorization is unavailable.'], $status);
} catch (Exception $exception) {
    error_log('Connector authorization error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'Device authorization could not be completed.'], 500);
}
