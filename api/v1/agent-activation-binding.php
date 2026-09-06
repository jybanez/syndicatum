<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/RequestAuth.php';
require_once dirname(dirname(__DIR__)) . '/src/AgentActivationService.php';

try {
    if (Api::method() !== 'GET') {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']);
    }
    $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
    if ($projectId < 1) { throw new InvalidArgumentException('project_id is required.'); }
    $pdo = Db::pdo();
    $requestAuth = new RequestAuth($pdo);
    $access = $requestAuth->projectAccess($projectId, 'messages:read');
    if ($access['identity']['kind'] !== 'agent') { throw new RuntimeException('PROJECT_NOT_FOUND'); }
    $agentId = (int) $access['identity']['agent']['id'];
    Api::json(['data' => (new AgentActivationService($pdo))->ownConfiguration($projectId, $agentId)]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (Exception $exception) {
    $code = $exception->getMessage();
    Api::json(['error' => true, 'code' => $code, 'message' => $code === 'AUTHENTICATION_REQUIRED' ? 'Authentication is required.' : 'Activation binding is unavailable.'], $code === 'AUTHENTICATION_REQUIRED' ? 401 : 404);
}
