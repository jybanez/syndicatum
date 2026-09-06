<?php

require_once __DIR__ . '/_human.php';

try {
    if (Api::method() !== 'POST') {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'POST']);
    }
    list($pdo, $auth, $user) = humanApiServices();
    $body = Api::body();
    $result = $auth->changePassword(
        $user,
        isset($body['current_password']) ? $body['current_password'] : '',
        isset($body['new_password']) ? $body['new_password'] : '',
        isset($body['new_password_confirmation']) ? $body['new_password_confirmation'] : ''
    );
    AuthService::setSessionCookies($result['session']);
    Api::json(['data' => [
        'user' => $result['user'],
        'csrf_token' => $result['session']['csrf_token'],
    ]]);
} catch (Exception $exception) {
    humanApiError($exception);
}
