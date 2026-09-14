<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';

try {
    if (Api::method() !== 'POST') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'POST']); }
    $service = new ConnectorDeviceService(Db::pdo());
    $device = $service->authenticate(Api::bearerToken());
    $body = Api::body();
    $projectId = (int) (isset($body['project_id']) ? $body['project_id'] : 0);
    $agentId = (int) (isset($body['agent_id']) ? $body['agent_id'] : 0);
    $messageId = (int) (isset($body['message_id']) ? $body['message_id'] : 0);
    if ($projectId < 1 || $agentId < 1 || $messageId < 1) { throw new InvalidArgumentException('project_id, agent_id, and message_id are required.'); }
    Api::json(['data' => $service->markNotificationDelivered($device, isset($body['provider']) ? $body['provider'] : 'chatgpt', $projectId, $agentId, $messageId)]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    Api::json(['error' => true, 'code' => $code, 'message' => $code === 'AUTHENTICATION_REQUIRED' ? 'Connector authentication is required.' : 'Notification was not found.'], $code === 'AUTHENTICATION_REQUIRED' ? 401 : 404);
} catch (Exception $exception) {
    error_log('Connector notification delivery error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'Notification delivery could not be recorded.'], 500);
}
