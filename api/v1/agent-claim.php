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
    $projectReference = isset($body['project']) ? trim((string) $body['project']) : '';
    $identityReference = isset($body['identity']) ? trim((string) $body['identity']) : '';
    $claimCode = isset($body['claim_code']) ? (string) $body['claim_code'] : '';
    $usesIds = $projectId > 0 || $agentId > 0;
    if ($claimCode === '' || ($usesIds && ($projectId < 1 || $agentId < 1)) || (!$usesIds && ($projectReference === '' || $identityReference === ''))) {
        throw new InvalidArgumentException('Provide claim_code with either project_id and agent_id, or project and identity.');
    }
    $pdo = Db::pdo();
    $rateIdentity = $usesIds ? $projectId . ':' . $agentId : hash('sha256', strtolower($projectReference) . "\n" . strtolower($identityReference));
    (new RateLimiter($pdo))->hit('agent.claim', $rateIdentity . ':' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 8, 300, 900);
    $service = new ProjectManagementService($pdo);
    $claim = $usesIds
        ? $service->claimAgent($projectId, $agentId, $claimCode)
        : $service->claimAgentByReference($projectReference, $identityReference, $claimCode);
    Api::json(['data' => $claim, 'message' => 'Claim accepted. Store this token now; it will not be shown again.'], 201);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (Exception $exception) {
    if ($exception->getMessage() === 'RATE_LIMITED') {
        Api::json(['error' => true, 'code' => 'RATE_LIMITED', 'message' => 'Too many claim attempts. Try again later.'], 429, ['Retry-After' => '900']);
    }
    Api::json(['error' => true, 'code' => 'INVALID_CLAIM', 'message' => 'Claim code is invalid or already used.'], 403);
}
