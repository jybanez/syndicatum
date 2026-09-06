<?php

require_once __DIR__ . '/_human.php';

try {
    if (!in_array(Api::method(), ['POST', 'PATCH'], true)) { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    list($pdo, $auth, $user, $service) = humanApiServices();
    $body = Api::body();
    if (Api::method() === 'POST') {
        Api::json(['data' => $service->createProject($user['id'], $body)], 201);
    }
    $projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
    if ($projectId < 1) { throw new InvalidArgumentException('project_id is required.'); }
    if (isset($body['new_owner_user_id'])) {
        Api::json(['data' => $service->transferProject($projectId, $user['id'], (int) $body['new_owner_user_id'])]);
    }
    Api::json(['data' => $service->updateProject($projectId, $user['id'], $body)]);
} catch (Exception $exception) { humanApiError($exception); }

