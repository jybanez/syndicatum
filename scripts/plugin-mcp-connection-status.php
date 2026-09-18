<?php

require_once dirname(__DIR__) . '/src/McpConnectionHealth.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Credentials enter on STDIN, never in process arguments or the JSON report.
$url = null;
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--url=') === 0 && $url === null) {
        $url = substr($argument, 6);
    } else {
        fwrite(STDERR, "Usage: php scripts/plugin-mcp-connection-status.php --url=HTTPS_MCP_URL < secret-json\n");
        exit(3);
    }
}
$urlParts = is_string($url) ? parse_url($url) : false;
$scheme = is_array($urlParts) ? strtolower($urlParts['scheme'] ?? '') : '';
$host = is_array($urlParts) ? strtolower($urlParts['host'] ?? '') : '';
$loopbackHttp = $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
if (!is_array($urlParts) || ($scheme !== 'https' && !$loopbackHttp)
    || empty($urlParts['host']) || ($urlParts['path'] ?? '') !== '/mcp'
    || isset($urlParts['user']) || isset($urlParts['pass'])
    || isset($urlParts['fragment']) || isset($urlParts['query'])) {
    fwrite(STDERR, "An HTTPS MCP URL (or loopback HTTP for local tests) without embedded credentials is required.\n");
    exit(3);
}

$input = stream_get_contents(STDIN, 16385);
$payload = is_string($input) && strlen($input) <= 16384 ? json_decode($input, true) : null;
if (!is_array($payload) || !array_key_exists('access_token', $payload)
    || !is_string($payload['access_token']) || strlen($payload['access_token']) > 4096
    || (isset($payload['binding_context_id']) && (!is_string($payload['binding_context_id'])
        || strlen($payload['binding_context_id']) > 4096))) {
    fwrite(STDERR, "STDIN must be JSON with an access_token string and optional binding_context_id string.\n");
    exit(3);
}
$arguments = [];
if (!empty($payload['binding_context_id'])) {
    $arguments['binding_context_id'] = $payload['binding_context_id'];
}
$request = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
    'params' => ['name' => 'diagnose_connection', 'arguments' => $arguments]]);
$headers = ["Content-Type: application/json", "Accept: application/json"];
if ($payload['access_token'] !== '') {
    $headers[] = 'Authorization: Bearer ' . $payload['access_token'];
}
unset($payload);
$context = stream_context_create(['http' => [
    'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $request,
    'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
]]);
$body = @file_get_contents($url, false, $context);
$status = 0;
foreach ($http_response_header ?? [] as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
        $status = (int) $matches[1];
    }
}
$response = is_string($body) ? json_decode($body, true) : null;
$health = mcpConnectionHealth($status, $response);
$health['checked_at'] = gmdate('c');
$health['http_status'] = $status ?: null;
echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($health['state'] === 'ok' ? 0 : ($health['state'] === 'unknown' ? 3 : 2));
