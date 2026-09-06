<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AdminService.php';

try {
    $pdo = Db::pdo();
    $auth = new AuthService($pdo);
    $actor = $auth->requireAdministrator();
    $service = new AdminService($pdo);
    if (Api::method() === 'GET') {
        Api::json(['data' => $service->agents()]);
    }
    if (Api::method() !== 'PATCH') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, PATCH']);
    }
    $auth->validateCsrf($actor, Api::csrfToken());
    $body = Api::body();
    $agentId = isset($body['agent_id']) ? (int) $body['agent_id'] : 0;
    if ($agentId < 1 || !isset($body['suspended'])) {
        throw new InvalidArgumentException('agent_id and suspended are required.');
    }
    $service->suspendAgent($agentId, (bool) $body['suspended'], !empty($body['revoke_token']), $actor['id']);
    Api::json(['data' => ['agent_id' => $agentId, 'suspended' => (bool) $body['suspended'], 'token_revoked' => !empty($body['revoke_token'])]]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $status = $exception->getMessage() === 'AUTHENTICATION_REQUIRED' ? 401 : ($exception->getMessage() === 'NOT_FOUND' ? 404 : 403);
    Api::json(['error' => true, 'code' => strtolower($exception->getMessage()), 'message' => $status === 404 ? 'Agent not found.' : 'This operation is not authorized.'], $status);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to process the agent request.'], 500);
}
