<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';

try {
    if (Api::method() !== 'POST') {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'POST']);
    }
    $service = new ConnectorDeviceService(Db::pdo());
    $device = $service->authenticate(Api::bearerToken());
    $body = Api::body();
    Api::json(['data' => $service->submitAgentReply(
        $device,
        isset($body['provider']) ? $body['provider'] : '',
        isset($body['project_id']) ? $body['project_id'] : 0,
        isset($body['agent_id']) ? $body['agent_id'] : 0,
        isset($body['message_id']) ? $body['message_id'] : 0,
        isset($body['response']) ? $body['response'] : ''
    )]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401 : 404;
    Api::json(['error' => true, 'code' => $code, 'message' => $status === 401
        ? 'Connector authentication is required.' : 'The bound notification was not found.'], $status);
} catch (Exception $exception) {
    error_log('Connector agent reply error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'The captured agent response could not be posted.'], 500);
}
