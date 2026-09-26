<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/IntegrationEventService.php';

function integrationEventJson($payload, $status)
{
    Api::json($payload, $status, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
}

try {
    if (Api::method() !== 'POST') {
        integrationEventJson(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405);
    }
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || strtolower(Api::header('X-Forwarded-Proto')) === 'https';
    $host = strtolower((string) (isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : ''));
    if (!$secure && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        integrationEventJson(['error' => true, 'code' => 'HTTPS_REQUIRED', 'message' => 'HTTPS is required.'], 400);
    }
    $uriPath = (string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
    if (!preg_match('#/api/v1/integration-events/([a-f0-9-]{36})/([A-Za-z0-9_-]{43})/?$#i', $uriPath, $matches)) {
        integrationEventJson(['error' => true, 'code' => 'NOT_FOUND', 'message' => 'Integration endpoint not found.'], 404);
    }
    $contentLength = (int) Api::header('Content-Length');
    if ($contentLength > IntegrationEventService::MAX_PAYLOAD_BYTES) {
        integrationEventJson(['error' => true, 'code' => 'PAYLOAD_TOO_LARGE', 'message' => 'Payload is too large.'], 413);
    }
    $contentType = strtolower(Api::header('Content-Type'));
    if (strpos($contentType, 'application/json') !== 0) {
        integrationEventJson(['error' => true, 'code' => 'JSON_REQUIRED', 'message' => 'Content-Type must be application/json.'], 415);
    }
    $rawBody = (string) file_get_contents('php://input', false, null, 0, IntegrationEventService::MAX_PAYLOAD_BYTES + 1);
    $result = (new IntegrationEventService(Db::pdo()))->ingest(
        strtolower($matches[1]), $matches[2], $rawBody, Api::idempotencyKey()
    );
    integrationEventJson(['data' => $result], $result['duplicate'] ? 200 : 202);
} catch (Exception $exception) {
    $code = $exception->getMessage();
    $status = $code === 'INTEGRATION_RATE_LIMITED' ? 429
        : ($code === 'INTEGRATION_IDEMPOTENCY_CONFLICT' ? 409
        : ($code === 'INTEGRATION_PAYLOAD_REJECTED' ? 422
        : (in_array($code, ['INTEGRATION_EVENT_TYPE_REJECTED', 'INTEGRATION_SEVERITY_REJECTED', 'INTEGRATION_IDEMPOTENCY_INVALID'], true) ? 422 : 404)));
    $publicCode = $status === 404 ? 'INTEGRATION_NOT_FOUND' : $code;
    $message = $status === 429 ? 'Rate limit exceeded.'
        : ($status === 409 ? 'Idempotency key conflicts with an earlier event.'
        : ($status === 422 ? 'Event payload was rejected.' : 'Integration endpoint not found.'));
    integrationEventJson(['error' => true, 'code' => $publicCode, 'message' => $message], $status);
}
