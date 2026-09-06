<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/AuthService.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectManagementService.php';

function humanApiServices($csrf = true)
{
    $pdo = Db::pdo();
    $auth = new AuthService($pdo);
    $user = $auth->requireUser();
    if ($csrf) { $auth->validateCsrf($user, Api::csrfToken()); }
    return [$pdo, $auth, $user, new ProjectManagementService($pdo)];
}

function humanApiError(Exception $exception)
{
    if ($exception instanceof InvalidArgumentException) {
        Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
    }
    $code = $exception->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401
        : ($code === 'RATE_LIMITED' ? 429
        : ($code === 'PASSWORD_MANAGED_BY_ACCOUNT' ? 409
        : (strpos($code, 'NOT_FOUND') !== false ? 404 : 403)));
    $messages = [
        'AUTHENTICATION_REQUIRED' => 'Authentication is required.', 'CSRF_VALIDATION_FAILED' => 'Request verification failed.',
        'PROJECT_NOT_FOUND' => 'Project not found.', 'WORKSPACE_NOT_FOUND' => 'Personal workspace not found.',
        'NEW_OWNER_NOT_FOUND' => 'The new owner is not available.', 'INVITATION_NOT_FOUND' => 'Invitation not found or no longer valid.',
        'MEMBER_NOT_FOUND' => 'Project member not found.', 'OWNER_MEMBERSHIP_LOCKED' => 'Transfer ownership before changing the owner membership.',
        'AGENT_NOT_FOUND' => 'Project agent not found.', 'INVALID_CLAIM' => 'Claim code is invalid or already used.',
        'RATE_LIMITED' => 'Too many requests. Try again later.',
        'INVALID_CURRENT_PASSWORD' => 'Current password is incorrect.',
        'PASSWORD_MANAGED_BY_ACCOUNT' => 'This account does not have a native Syndicatum password. Manage its password through PBB Account.',
    ];
    Api::json(['error' => true, 'code' => $code, 'message' => isset($messages[$code]) ? $messages[$code] : 'This operation is not authorized.'], $status);
}
