<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/AuthService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

try {
    if (Api::method() !== 'POST') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'POST']);
    }
    $pdo = Db::pdo();
    if (!Db::tableExists($pdo, 'user_registration_activations')) {
        Api::json(['error' => true, 'code' => 'setup_required', 'message' => 'Registration activation is not available.'], 503);
    }
    $body = Api::body();
    $token = isset($body['activation_token']) ? trim((string) $body['activation_token']) : '';
    (new RateLimiter($pdo))->hit(
        'registration_activation',
        hash('sha256', $token) . '|' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
        8,
        900,
        1800
    );
    $result = (new AuthService($pdo))->activateRegistration($token);
    AuthService::setSessionCookies($result['session']);
    Api::json(['data' => [
        'authenticated' => true,
        'user' => $result['user'],
        'csrf_token' => $result['session']['csrf_token'],
        'welcome_notification' => $result['welcome_notification'],
    ]], 201);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'activation_invalid', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'RATE_LIMITED') {
        Api::json(['error' => true, 'code' => 'rate_limited', 'message' => 'Too many activation attempts. Try again later.'], 429, ['Retry-After' => '900']);
    }
    Api::json(['error' => true, 'code' => 'activation_failed', 'message' => 'Unable to activate this registration.'], 500);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to activate this registration.'], 500);
}
