<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AdminDeliveryHealth.php';

try {
    if (Api::method() !== 'GET') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']);
    }
    $pdo = Db::pdo();
    (new AuthService($pdo))->requireAdministrator();
    Api::json(['data' => AdminDeliveryHealth::snapshot($pdo)], 200,
        ['Cache-Control' => 'private, no-store']);
} catch (RuntimeException $exception) {
    $authenticationRequired = $exception->getMessage() === 'AUTHENTICATION_REQUIRED';
    $status = $authenticationRequired ? 401 : 403;
    Api::json(['error' => true, 'code' => $authenticationRequired ? 'authentication_required' : 'administrator_required',
        'message' => 'Administrator access is required.'], $status);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'server_error',
        'message' => 'Unable to load delivery health.'], 500);
}
