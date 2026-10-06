<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';

try {
    $method = Api::method();
    if (!in_array($method, ['GET', 'POST'], true)) { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, POST']); }
    $service = new ConnectorDeviceService(Db::pdo());
    $device = $service->authenticate(Api::bearerToken());
    $input = $method === 'POST' ? Api::body() : $_GET;
    $projectId = (int) (isset($input['project_id']) ? $input['project_id'] : 0);
    $agentId = (int) (isset($input['agent_id']) ? $input['agent_id'] : 0);
    $messageId = (int) (isset($input['message_id']) ? $input['message_id'] : 0);
    if ($projectId < 1 || $agentId < 1 || $messageId < 1) { throw new InvalidArgumentException('project_id, agent_id, and message_id are required.'); }
    $provider = isset($input['provider']) ? $input['provider'] : 'chatgpt';
    Api::json(['data' => $method === 'GET'
        ? $service->notificationDeliveryStatus($device, $provider, $projectId, $agentId, $messageId)
        : $service->markNotificationDelivered($device, $provider, $projectId, $agentId, $messageId)]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    Api::json(['error' => true, 'code' => $code, 'message' => $code === 'AUTHENTICATION_REQUIRED' ? 'Connector authentication is required.' : 'Notification was not found.'], $code === 'AUTHENTICATION_REQUIRED' ? 401 : 404);
} catch (Exception $exception) {
    error_log('Connector notification delivery error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'Notification delivery could not be recorded.'], 500);
}
