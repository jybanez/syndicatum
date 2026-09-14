<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/GoogleIntegration.php';

$redirect = '/?google_sso_error=1';
try {
    if (Api::method() !== 'GET') { Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']); }
    $pdo = Db::pdo();
    $auth = new AuthService($pdo);
    $google = new GoogleIntegration($pdo, new SettingsService($pdo), $auth);
    $attemptToken = isset($_COOKIE[GoogleIntegration::ATTEMPT_COOKIE]) ? (string) $_COOKIE[GoogleIntegration::ATTEMPT_COOKIE] : '';
    $result = $google->completeCallback($_GET, $attemptToken);
    if (isset($result['session']) && is_array($result['session'])) {
        AuthService::setSessionCookies($result['session']);
    }
    $redirect = AccountIntegration::safeReturnPath($result['return_path']);
    if (!empty($result['linked'])) {
        $redirect .= strpos($redirect, '?') === false ? '?google_linked=1' : '&google_linked=1';
    }
} catch (Exception $exception) {
    $code = $exception->getMessage();
    $linkErrors = ['GOOGLE_LINK_SESSION_EXPIRED', 'GOOGLE_ACCOUNT_ALREADY_LINKED', 'GOOGLE_IDENTITY_ALREADY_LINKED'];
    $redirect = in_array($code, $linkErrors, true) ? '/?google_link_error=' . rawurlencode(strtolower($code)) : '/?google_sso_error=1';
}
GoogleIntegration::clearAttemptCookie();
header('Cache-Control: no-store');
header('Location: ' . $redirect, true, 302);
exit;
