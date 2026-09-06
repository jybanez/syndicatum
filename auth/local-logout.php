<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/AccountIntegration.php';

try {
    $auth = new AuthService(Db::pdo());
    $auth->logout();
} catch (Exception $ignored) {
    // The browser cookies are still expired below. This endpoint deliberately
    // does not redirect to Account because it handles Account session events.
}

AuthService::clearSessionCookies();
AccountIntegration::clearAttemptCookie();
header('Cache-Control: no-store');
header('Location: /?account_logged_out=1', true, 302);
exit;
