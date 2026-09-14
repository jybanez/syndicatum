<?php
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ChatGptOAuthService.php';
$pdo = Db::pdo(); $auth = new AuthService($pdo); $oauth = new ChatGptOAuthService($pdo);
try { $request = $oauth->authorizationRequest(Api::method() === 'POST' ? $_POST : $_GET); }
catch (Exception $e) { http_response_code(400); echo 'Invalid OAuth authorization request.'; exit; }
$user = $auth->currentUser();
if (!$user) {
    $return = (string) ($_SERVER['REQUEST_URI'] ?? '/oauth/authorize');
    header('Location: /?return=' . rawurlencode($return), true, 302); exit;
}
$error = '';
if (Api::method() === 'POST') {
    try {
        $auth->validateCsrf($user, (string) ($_POST['csrf_token'] ?? ''));
        if (isset($_POST['deny'])) {
            $query = http_build_query(['error' => 'access_denied', 'state' => $request['state']]);
            header('Location: ' . $request['redirect_uri'] . (strpos($request['redirect_uri'], '?') === false ? '?' : '&') . $query, true, 302); exit;
        }
        $code = $oauth->issueAuthorizationCode($request, $user['id']);
        $query = http_build_query(['code' => $code, 'state' => $request['state']]);
        header('Location: ' . $request['redirect_uri'] . (strpos($request['redirect_uri'], '?') === false ? '?' : '&') . $query, true, 302); exit;
    } catch (Exception $e) { $error = 'Authorization could not be completed.'; }
}
$csrf = isset($_COOKIE[AuthService::CSRF_COOKIE]) ? (string) $_COOKIE[AuthService::CSRF_COOKIE] : '';
?><!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Authorize AI app · Syndicatum</title><link rel="stylesheet" href="/vendor/pbb-helper/dist/helpers.ui.bundle.min.css"><link rel="stylesheet" href="/assets/app.css"></head>
<body><main class="login-shell"><form class="login-card" method="post"><h1>Connect AI app to Syndicatum</h1>
<p>Authorize this AI app to connect to your Syndicatum account. Projects and agent identities are selected separately when you bind an individual discussion.</p>
<?php if ($error !== ''): ?><p class="ui-form-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<?php foreach ($request as $key => $value): if ($key === 'client') continue; ?><input type="hidden" name="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?>
<input type="hidden" name="response_type" value="code"><input type="hidden" name="code_challenge_method" value="S256"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
<div class="surface-actions"><button class="ui-button ui-button-ghost" type="submit" name="deny" value="1">Cancel</button><button class="ui-button ui-button-primary" type="submit">Authorize</button></div>
</form></main></body></html>
