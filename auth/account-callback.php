<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/AccountIntegration.php';

$redirect = '/?account_sso_error=1';
try {
    if (Api::method() !== 'GET') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']);
    }
    $pdo = Db::pdo();
    $auth = new AuthService($pdo);
    $settings = new SettingsService($pdo);
    $account = new AccountIntegration($pdo, $settings, $auth);
    $attemptToken = isset($_COOKIE[AccountIntegration::ATTEMPT_COOKIE])
        ? (string) $_COOKIE[AccountIntegration::ATTEMPT_COOKIE]
        : '';
    $result = $account->completeCallback($_GET, $attemptToken);
    AuthService::setSessionCookies($result['session']);
    $redirect = AccountIntegration::safeReturnPath($result['return_path']);
} catch (Exception $exception) {
    // The marker prevents a client from immediately restarting SSO and hiding
    // the callback failure. Detailed transport/configuration errors stay local.
    $redirect = '/?account_sso_error=1';
}

AccountIntegration::clearAttemptCookie();
header('Cache-Control: no-store');
header('Location: ' . $redirect, true, 302);
exit;
