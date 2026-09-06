<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';

try {
    $method = Api::method();
    if (!in_array($method, ['GET', 'PUT'], true)) { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, PUT']); }
    $service = new ConnectorDeviceService(Db::pdo());
    $device = $service->authenticate(Api::bearerToken());
    if ($method === 'PUT') {
        Api::json(['data' => $service->configureBinding($device, Api::body())]);
    }
    Api::json(['data' => ['device' => ['id' => $device['id'], 'display_name' => $device['display_name']], 'bindings' => $service->bindings($device)]]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_ERROR', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    Api::json(['error' => true, 'code' => $code, 'message' => $code === 'AUTHENTICATION_REQUIRED' ? 'Connector authentication is required.' : 'The activation binding is unavailable to this device.'], $code === 'AUTHENTICATION_REQUIRED' ? 401 : 404);
} catch (Exception $exception) {
    error_log('Connector bindings error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'Connector bindings are unavailable.'], 500);
}
