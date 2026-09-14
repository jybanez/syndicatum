<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';
require_once dirname(dirname(__DIR__)) . '/src/DiscussionBindingIntentService.php';

try {
    $pdo = Db::pdo();
    $device = (new ConnectorDeviceService($pdo))->authenticate(Api::bearerToken());
    $intents = new DiscussionBindingIntentService($pdo);
    if (Api::method() === 'GET') {
        Api::json(['data' => ['intents' => $intents->pending($device)]]);
    }
    if (Api::method() !== 'POST') {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, POST']);
    }
    $body = Api::body();
    if (!empty($body['binding_intent_id'])) {
        $action = strtolower(trim((string) ($body['action'] ?? '')));
        $result = $action === 'cancel'
            ? $intents->cancel($device, $body['binding_intent_id'])
            : ($action === 'continue'
                ? $intents->confirm($device, $body['binding_intent_id'], $body['discussion_reference'] ?? '')
                : null);
        if ($result === null) { throw new InvalidArgumentException('action must be continue or cancel.'); }
        Api::json(['data' => $result]);
    }
    throw new InvalidArgumentException('binding_intent_id and action are required. Start binding from the Syndicatum MCP tool.');
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401 : ($code === 'DISCUSSION_ALREADY_BOUND' ? 409 : 403);
    $message = $code === 'AUTHENTICATION_REQUIRED' ? 'Connector authentication is required.'
        : ($code === 'DISCUSSION_ALREADY_BOUND' ? 'This discussion is already bound to another agent.'
        : 'The binding request is invalid, expired, cancelled, or already handled.');
    Api::json(['error' => true, 'code' => $code, 'message' => $message], $status);
} catch (Exception $exception) {
    error_log('Connector discussion binding error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'The discussion could not be bound.'], 500);
}
