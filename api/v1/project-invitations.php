<?php

require_once __DIR__ . '/_human.php';

try {
    $method = Api::method();
    if (!in_array($method, ['GET', 'POST'], true)) { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    list($pdo, $auth, $user, $service) = humanApiServices($method !== 'GET');
    if ($method === 'GET') {
        $token = Api::header('X-Syndicatum-Invitation-Token');
        Api::json(['data' => $service->previewInvitation($user['id'], $token)], 200, ['Cache-Control' => 'no-store, private']);
    }
    $body = Api::body();
    if (isset($body['invitation_token'])) {
        Api::json(['data' => $service->acceptInvitation($user['id'], $body['invitation_token'])]);
    }
    $projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
    if ($projectId < 1) { throw new InvalidArgumentException('project_id is required.'); }
    Api::json(['data' => $service->invite($projectId, $user['id'], $body)], 201);
} catch (Exception $exception) { humanApiError($exception); }
