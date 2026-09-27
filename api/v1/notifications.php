<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/NotificationInboxService.php';

try {
    $method = Api::method();
    if (!in_array($method, ['GET', 'POST'], true)) { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    list($pdo, $auth, $user) = humanApiServices($method !== 'GET');
    $service = new NotificationInboxService($pdo);
    if ($method === 'GET') {
        Api::json(['data' => $service->listForUser($user['id'])], 200, ['Cache-Control' => 'no-store, private']);
    }
    $body = Api::body();
    if (($body['operation'] ?? '') !== 'mark_read' || !isset($body['invitation_ids']) || !is_array($body['invitation_ids'])) {
        throw new InvalidArgumentException('A valid notification operation is required.');
    }
    Api::json(['data' => $service->markRead($user['id'], $body['invitation_ids'])]);
} catch (Exception $exception) { humanApiError($exception); }
