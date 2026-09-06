<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

try {
    if (Api::method() !== 'POST') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    $pdo = Db::pdo(); $body = Api::body();
    (new RateLimiter($pdo))->hit('connector.device.exchange', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', 120, 600, 600);
    Api::json(['data' => (new ConnectorDeviceService($pdo))->exchange(isset($body['device_code']) ? $body['device_code'] : '')]);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    Api::json(['error' => true, 'code' => $code, 'message' => 'Device authorization is invalid or expired.'], $code === 'RATE_LIMITED' ? 429 : 400);
} catch (Exception $exception) {
    error_log('Connector token error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'Device authorization could not be completed.'], 500);
}
