<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectManagementService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

try {
    if (Api::method() !== 'POST') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    $body = Api::body();
    $projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
    $agentId = isset($body['agent_id']) ? (int) $body['agent_id'] : 0;
    $claimCode = isset($body['claim_code']) ? (string) $body['claim_code'] : '';
    if ($projectId < 1 || $agentId < 1 || $claimCode === '') { throw new InvalidArgumentException('project_id, agent_id, and claim_code are required.'); }
    $pdo = Db::pdo();
    (new RateLimiter($pdo))->hit('agent.claim', $projectId . ':' . $agentId . ':' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 8, 300, 900);
    Api::json(['data' => (new ProjectManagementService($pdo))->claimAgent($projectId, $agentId, $claimCode)], 201);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (Exception $exception) {
    if ($exception->getMessage() === 'RATE_LIMITED') {
        Api::json(['error' => true, 'code' => 'RATE_LIMITED', 'message' => 'Too many claim attempts. Try again later.'], 429, ['Retry-After' => '900']);
    }
    Api::json(['error' => true, 'code' => 'INVALID_CLAIM', 'message' => 'Claim code is invalid or already used.'], 403);
}
