<?php
require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatGptOAuthService.php';
if (Api::method() !== 'POST') { Api::json(['error' => 'invalid_request'], 405, ['Allow' => 'POST']); }
$input = $_POST;
if (!$input) { parse_str((string) file_get_contents('php://input'), $input); }
try {
    (new ChatGptOAuthService(Db::pdo()))->revoke($input);
    http_response_code(200);
} catch (Exception $e) {
    Api::json(['error' => 'server_error'], 500, ['Cache-Control' => 'no-store']);
}
