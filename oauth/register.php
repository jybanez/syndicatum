<?php
require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatGptOAuthService.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
if (Api::method() !== 'POST') { Api::json(['error' => 'invalid_request'], 405, ['Allow' => 'POST']); }
try {
    $pdo = Db::pdo();
    (new RateLimiter($pdo))->hit('oauth.dynamic_registration', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', 120, 3600, 3600);
    Api::json((new ChatGptOAuthService($pdo))->registerClient(Api::body()), 201, ['Cache-Control' => 'no-store']);
}
catch (InvalidArgumentException $e) {
    $allowed = ['invalid_redirect_uris', 'invalid_redirect_uri', 'unsupported_token_endpoint_auth_method'];
    Api::json(['error' => in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'invalid_client_metadata'], 400, ['Cache-Control' => 'no-store']);
}
catch (RuntimeException $e) {
    Api::json(['error' => $e->getMessage() === 'RATE_LIMITED' ? 'temporarily_unavailable' : 'server_error'], $e->getMessage() === 'RATE_LIMITED' ? 429 : 500, ['Cache-Control' => 'no-store', 'Retry-After' => '3600']);
}
catch (Exception $e) { Api::json(['error' => 'server_error'], 500, ['Cache-Control' => 'no-store']); }
