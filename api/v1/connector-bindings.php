<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';

try {
    if (Api::method() !== 'GET') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']); }
    $service = new ConnectorDeviceService(Db::pdo());
    $device = $service->authenticate(Api::bearerToken());
    Api::json(['data' => ['device' => ['id' => $device['id'], 'display_name' => $device['display_name']], 'bindings' => $service->bindings($device)]]);
} catch (RuntimeException $exception) {
    Api::json(['error' => true, 'code' => $exception->getMessage(), 'message' => 'Connector authentication is required.'], 401);
} catch (Exception $exception) {
    error_log('Connector bindings error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'Connector bindings are unavailable.'], 500);
}
