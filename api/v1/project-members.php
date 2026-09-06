<?php

require_once __DIR__ . '/_human.php';

try {
    if (!in_array(Api::method(), ['PATCH', 'DELETE'], true)) { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    list($pdo, $auth, $user, $service) = humanApiServices();
    $body = Api::body();
    $projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
    $memberUserId = isset($body['user_id']) ? (int) $body['user_id'] : 0;
    if ($projectId < 1 || $memberUserId < 1) { throw new InvalidArgumentException('project_id and user_id are required.'); }
    $service->updateMember($projectId, $user['id'], $memberUserId, isset($body['role']) ? $body['role'] : 'member', Api::method() === 'DELETE');
    Api::json(['data' => ['project_id' => $projectId, 'user_id' => $memberUserId, 'removed' => Api::method() === 'DELETE']]);
} catch (Exception $exception) { humanApiError($exception); }

