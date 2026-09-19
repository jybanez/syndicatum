<?php
require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatGptOAuthService.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
if (Api::method() !== 'POST') { Api::json(['error' => 'invalid_request'], 405, ['Allow' => 'POST']); }
$input = $_POST;
if (!$input) { parse_str((string) file_get_contents('php://input'), $input); }
try {
    $pdo = Db::pdo();
    (new RateLimiter($pdo))->hit('oauth.token', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 300, 300);
    $oauth = new ChatGptOAuthService($pdo);
    $grant = (string) ($input['grant_type'] ?? '');
    if ($grant === 'authorization_code') { Api::json($oauth->exchangeAuthorizationCode($input), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']); }
    if ($grant === 'refresh_token') { Api::json($oauth->refresh($input), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']); }
    throw new InvalidArgumentException('unsupported_grant_type');
} catch (InvalidArgumentException $e) {
    $allowed = ['unsupported_grant_type', 'invalid_request'];
    Api::json(['error' => in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'invalid_request'], 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'RATE_LIMITED') {
        Api::json(['error' => 'temporarily_unavailable'], 429, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'Retry-After' => '300']);
    }
    Api::json(['error' => $e->getMessage() === 'invalid_grant' ? 'invalid_grant' : 'server_error'], $e->getMessage() === 'invalid_grant' ? 400 : 500, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
} catch (Exception $e) { Api::json(['error' => 'server_error'], 500, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']); }
