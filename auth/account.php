<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/AccountIntegration.php';

try {
    if (Api::method() !== 'GET') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']);
    }
    $pdo = Db::pdo();
    $settings = new SettingsService($pdo);
    $account = new AccountIntegration($pdo, $settings, new AuthService($pdo));
    if (!$account->isEnabled()) {
        Api::json(['error' => true, 'code' => 'not_found', 'message' => 'PBB Account sign in is not enabled.'], 404);
    }
    $attempt = $account->beginAuthorization(isset($_GET['return']) ? $_GET['return'] : '/');
    AccountIntegration::setAttemptCookie($attempt['attempt_token'], $attempt['attempt_expires_at']);
    header('Cache-Control: no-store');
    header('Location: ' . $attempt['authorization_url'], true, 302);
    exit;
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'account_sso_unavailable', 'message' => 'Unable to start PBB Account sign in.'], 503);
}
