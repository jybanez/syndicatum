<?php
require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatGptOAuthService.php';
if (Api::method() !== 'POST') { Api::json(['error' => 'invalid_request'], 405, ['Allow' => 'POST']); }
$input = $_POST;
if (!$input) { parse_str((string) file_get_contents('php://input'), $input); }
try {
    $oauth = new ChatGptOAuthService(Db::pdo());
    $grant = (string) ($input['grant_type'] ?? '');
    if ($grant === 'authorization_code') { Api::json($oauth->exchangeAuthorizationCode($input), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']); }
    if ($grant === 'refresh_token') { Api::json($oauth->refresh($input), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']); }
    throw new InvalidArgumentException('unsupported_grant_type');
} catch (InvalidArgumentException $e) {
    $allowed = ['unsupported_grant_type', 'invalid_request'];
    Api::json(['error' => in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'invalid_request'], 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
} catch (RuntimeException $e) {
    Api::json(['error' => $e->getMessage() === 'invalid_grant' ? 'invalid_grant' : 'server_error'], $e->getMessage() === 'invalid_grant' ? 400 : 500, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
} catch (Exception $e) { Api::json(['error' => 'server_error'], 500, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']); }
