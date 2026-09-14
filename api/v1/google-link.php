<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';
require_once dirname(dirname(__DIR__)) . '/src/GoogleIntegration.php';

try {
    if (Api::method() !== 'POST') {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'POST']);
    }
    list($pdo, $auth, $user) = humanApiServices();
    $google = new GoogleIntegration($pdo, new SettingsService($pdo), $auth);
    if (!$google->isEnabled()) {
        Api::json(['error' => true, 'code' => 'GOOGLE_SIGN_IN_DISABLED', 'message' => 'Google sign in is not enabled.'], 409);
    }
    $body = Api::body();
    $returnPath = isset($body['return_path']) ? $body['return_path'] : '/';
    $attempt = $google->beginLinkAuthorization((int) $user['id'], $returnPath);
    GoogleIntegration::setAttemptCookie($attempt['attempt_token'], $attempt['attempt_expires_at']);
    Api::json(['data' => ['authorization_url' => $attempt['authorization_url']]], 201);
} catch (Exception $exception) {
    humanApiError($exception);
}
