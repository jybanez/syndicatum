<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/AccountIntegration.php';

$redirect = '/';
try {
    $pdo = Db::pdo();
    $auth = new AuthService($pdo);

    $accountSessionId = null;
    $localToken = isset($_COOKIE[AuthService::SESSION_COOKIE]) ? trim((string) $_COOKIE[AuthService::SESSION_COOKIE]) : '';
    if ($localToken !== '') {
        $sessionLookup = $pdo->prepare(
            'SELECT account_session_id FROM syndicatum_sessions
             WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1'
        );
        $sessionLookup->execute([hash('sha256', $localToken)]);
        $accountSessionId = $sessionLookup->fetchColumn();
    }

    // Syndicatum owns its local session. Clear it before any optional redirect
    // to Account so an Account outage can never leave the local session alive.
    $auth->logout();
    AuthService::clearSessionCookies();
    AccountIntegration::clearAttemptCookie();

    $settings = new SettingsService($pdo);
    $account = new AccountIntegration($pdo, $settings, $auth);
    if ($account->isEnabled() && is_string($accountSessionId) && trim($accountSessionId) !== '') {
        $redirect = $account->logoutUrl();
    }
} catch (Exception $exception) {
    // Local cookie clearing is repeated even when database/configuration access
    // fails. Account logout is an optional continuation, not a prerequisite.
    AuthService::clearSessionCookies();
    AccountIntegration::clearAttemptCookie();
    $redirect = '/';
}

header('Cache-Control: no-store');
header('Location: ' . $redirect, true, 302);
exit;
