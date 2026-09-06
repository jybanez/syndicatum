<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/AuthService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';

try {
    $pdo = Db::pdo();
    if (!Db::tableExists($pdo, 'syndicatum_sessions')) {
        Api::json(['error' => true, 'code' => 'setup_required', 'message' => 'Syndicatum expansion has not been migrated yet.'], 503);
    }
    $auth = new AuthService($pdo);
    $method = Api::method();

    if ($method === 'GET') {
        $user = $auth->currentUser();
        $settings = new SettingsService($pdo);
        $administrator = $user && in_array('administrator', $user['system_roles'], true);
        Api::json([
            'data' => $user ? ['authenticated' => true, 'user' => $user, 'csrf_token' => isset($_COOKIE[AuthService::CSRF_COOKIE]) ? $_COOKIE[AuthService::CSRF_COOKIE] : null] : ['authenticated' => false],
            'capabilities' => [
                'native_login' => (bool) $settings->get('account.native_login_enabled'),
                'account_sso' => (bool) $settings->get('account.enabled'),
                'account_profile_url' => (string) $settings->get('account.profile_url'),
                'realtime' => (bool) $settings->get('realtime.enabled'),
                'workspace.view' => (bool) $user,
                'project.create' => (bool) $user,
                'admin.users' => (bool) $administrator,
                'admin.agents' => (bool) $administrator,
                'admin.audit' => (bool) $administrator,
                'admin.settings' => (bool) $administrator,
            ],
        ]);
    }

    if ($method === 'POST') {
        $body = Api::body();
        $identity = isset($body['identity']) ? trim((string) $body['identity']) : '';
        $limiter = new RateLimiter($pdo);
        $limiter->hit('login', strtolower($identity) . '|' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 8, 300, 900);
        $result = $auth->login($identity, isset($body['password']) ? $body['password'] : '');
        AuthService::setSessionCookies($result['session']);
        Api::json(['data' => ['authenticated' => true, 'user' => $result['user'], 'csrf_token' => $result['session']['csrf_token']]], 201);
    }

    if ($method === 'DELETE') {
        $user = $auth->requireUser();
        $auth->validateCsrf($user, Api::csrfToken());
        $auth->logout();
        AuthService::clearSessionCookies();
        Api::json(['data' => ['authenticated' => false]]);
    }

    Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, POST, DELETE']);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    if ($code === 'RATE_LIMITED') {
        Api::json(['error' => true, 'code' => 'rate_limited', 'message' => 'Too many sign-in attempts. Try again later.'], 429, ['Retry-After' => '900']);
    }
    if ($code === 'AUTHENTICATION_REQUIRED') {
        Api::json(['error' => true, 'code' => 'authentication_required', 'message' => 'Authentication is required.'], 401);
    }
    if ($code === 'CSRF_VALIDATION_FAILED') {
        Api::json(['error' => true, 'code' => 'csrf_failed', 'message' => 'The request verification token is invalid.'], 403);
    }
    Api::json(['error' => true, 'code' => 'invalid_credentials', 'message' => 'Invalid sign-in credentials.'], 401);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to process the session request.'], 500);
}
