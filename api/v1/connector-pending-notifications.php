<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';

try {
    if (Api::method() !== 'GET') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']); }
    $service = new ConnectorDeviceService(Db::pdo());
    $device = $service->authenticate(Api::bearerToken());
    $provider = isset($_GET['provider']) ? (string) $_GET['provider'] : 'chatgpt';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
    Api::json(['data' => $service->pendingNotifications($device, $provider, $limit)]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    Api::json(['error' => true, 'code' => $exception->getMessage(), 'message' => 'Connector authentication is required.'], 401);
} catch (Exception $exception) {
    error_log('Connector pending notifications error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'Pending notifications are unavailable.'], 500);
}
