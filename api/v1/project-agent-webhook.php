<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/AgentWebhookService.php';

try {
    $method = Api::method();
    if (!in_array($method, ['GET', 'PATCH'], true)) {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, PATCH']);
    }
    list($pdo, $auth, $user) = humanApiServices($method !== 'GET');
    $body = $method === 'PATCH' ? Api::body() : [];
    $projectId = isset($body['project_id']) ? (int) $body['project_id'] : (isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0);
    $agentId = isset($body['agent_id']) ? (int) $body['agent_id'] : (isset($_GET['agent_id']) ? (int) $_GET['agent_id'] : 0);
    if ($projectId < 1 || $agentId < 1) { throw new InvalidArgumentException('project_id and agent_id are required.'); }
    $service = new AgentWebhookService($pdo);
    $data = $method === 'GET'
        ? $service->configuration($projectId, $agentId, $user['id'])
        : $service->configure($projectId, $agentId, $user['id'], $body);
    Api::json(['data' => $data]);
} catch (Exception $exception) {
    humanApiError($exception);
}
