<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/IntegrationConnectionService.php';

try {
    $method = Api::method();
    if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405);
    }
    list($pdo, $auth, $user) = humanApiServices($method !== 'GET');
    $input = $method === 'GET' ? $_GET : Api::body();
    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    if ($projectId < 1) { throw new InvalidArgumentException('project_id is required.'); }
    $service = new IntegrationConnectionService($pdo);

    if ($method === 'GET') {
        $integrationId = isset($input['integration_id']) ? (int) $input['integration_id'] : 0;
        if ($integrationId > 0) {
            Api::json(['data' => $service->connection($projectId, $user['id'], $integrationId)]);
        }
        Api::json(['data' => $service->listConnections($projectId, $user['id'], !empty($input['include_removed']))]);
    }
    if ($method === 'POST') {
        Api::json(['data' => $service->create($projectId, $user['id'], $input)], 201);
    }

    $integrationId = isset($input['integration_id']) ? (int) $input['integration_id'] : 0;
    if ($integrationId < 1) { throw new InvalidArgumentException('integration_id is required.'); }
    if ($method === 'DELETE') {
        Api::json(['data' => $service->remove($projectId, $user['id'], $integrationId)]);
    }
    Api::json(['data' => $service->update($projectId, $user['id'], $integrationId, $input)]);
} catch (Exception $exception) {
    humanApiError($exception);
}
