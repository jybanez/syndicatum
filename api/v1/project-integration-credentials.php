<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/IntegrationEventService.php';

try {
    $method = Api::method();
    if (!in_array($method, ['POST', 'DELETE'], true)) {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405);
    }
    list($pdo, $auth, $user) = humanApiServices(true);
    $input = Api::body();
    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    $integrationId = isset($input['integration_id']) ? (int) $input['integration_id'] : 0;
    if ($projectId < 1 || $integrationId < 1) {
        throw new InvalidArgumentException('project_id and integration_id are required.');
    }
    $service = new IntegrationEventService($pdo);
    if ($method === 'DELETE') {
        Api::json(['data' => $service->revokeCredential($projectId, $user['id'], $integrationId)]);
    }
    $action = isset($input['action']) ? strtolower(trim((string) $input['action'])) : 'issue';
    if (!in_array($action, ['issue', 'rotate'], true)) {
        throw new InvalidArgumentException('Credential action must be issue or rotate.');
    }
    Api::json(['data' => $service->issueCredential(
        $projectId, $user['id'], $integrationId, $action === 'rotate'
    )], 201);
} catch (Exception $exception) {
    humanApiError($exception);
}
