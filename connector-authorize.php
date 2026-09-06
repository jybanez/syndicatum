<?php

require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/AuthService.php';
require_once __DIR__ . '/src/ConnectorDeviceService.php';
require_once __DIR__ . '/src/RealtimeIntegration.php';
require_once __DIR__ . '/src/SettingsService.php';

$pdo = Db::pdo();
$auth = new AuthService($pdo);
$user = $auth->currentUser();
$code = strtoupper(trim(isset($_POST['code']) ? (string) $_POST['code'] : (isset($_GET['code']) ? (string) $_GET['code'] : '')));
$error = null; $approved = false; $notificationFailed = false; $request = null;
if ($user && $code !== '') {
    try {
        $service = new ConnectorDeviceService($pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $auth->validateCsrf($user, isset($_POST['csrf']) ? $_POST['csrf'] : '');
            $approval = $service->approve($code, $user);
            $approved = true;
            try {
                (new RealtimeIntegration(new SettingsService($pdo)))->publishConnectorAuthorizationApproved($approval['authorization_id']);
            } catch (Exception $exception) {
                $notificationFailed = true;
                error_log('Connector authorization approved but Realtime notification failed: ' . $exception->getMessage());
            }
        } else { $request = $service->pendingForUser($code); }
    } catch (Exception $exception) { $error = 'This authorization request is invalid or has expired.'; }
}
function connectorEscape($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Authorize Syndicatum connector</title><link rel="stylesheet" href="vendor/pbb-helper/dist/helpers.ui.bundle.min.css"><link rel="stylesheet" href="assets/app.css"></head>
<body>
<?php if (!$user): ?>
<main class="login-shell"><noscript><section class="login-card ui-panel"><h1>Sign in required</h1><p>JavaScript is required to authorize this Codex device.</p></section></noscript></main>
<script type="module" src="assets/connector-authorize.mjs?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/assets/connector-authorize.mjs')); ?>"></script>
<?php else: ?>
<main class="login-shell"><section class="login-card ui-panel"><p class="ui-eyebrow">Syndicatum connector</p><h1 class="ui-title">Authorize this device</h1>
<?php if ($approved && !$notificationFailed): ?><p id="authorization-complete">This device is now authorized for <?php echo connectorEscape($user['display_name']); ?>. Returning you to Codex&hellip;</p><script>window.close();setTimeout(function(){document.getElementById('authorization-complete').textContent='Authorization complete. You may close this tab and return to Codex.';},500);</script>
<?php elseif ($approved): ?><p>This device is authorized, but the live completion signal could not be delivered. Return to Codex and use the connector login recovery action to complete the one-time credential exchange.</p>
<?php elseif ($error || !$request): ?><p class="form-error"><?php echo connectorEscape($error ?: 'The authorization code is missing.'); ?></p>
<?php else: ?><p><strong><?php echo connectorEscape($request['device_name']); ?></strong> is requesting permission to receive addressed Syndicatum notifications for your configured Codex agents.</p>
<p>Code: <strong><?php echo connectorEscape($code); ?></strong></p><form method="post"><input type="hidden" name="code" value="<?php echo connectorEscape($code); ?>"><input type="hidden" name="csrf" value="<?php echo connectorEscape(isset($_COOKIE[AuthService::CSRF_COOKIE]) ? $_COOKIE[AuthService::CSRF_COOKIE] : ''); ?>"><button class="ui-button ui-button-primary" type="submit">Authorize device</button></form>
<?php endif; ?></section></main>
<?php endif; ?>
</body></html>
