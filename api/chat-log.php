<?php

require_once dirname(__DIR__) . '/src/ChatLogParser.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $payload = null;
    try {
        $repository = new ChatRepository(Db::pdo());
        if ($repository->hasSchema()) {
            $payload = $repository->payload();
        }
    } catch (Exception $dbException) {
        $payload = null;
    }

    if ($payload === null) {
        $parser = new ChatLogParser(dirname(__DIR__) . '/../chat_log.md');
        $payload = $parser->parse();
    }

    $etag = $payload['meta']['etag'];
    $lastModified = gmdate('D, d M Y H:i:s', (int) $payload['meta']['last_modified_unix']) . ' GMT';

    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: no-cache, must-revalidate');

    $requestEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : null;
    if ($requestEtag !== null && trim($requestEtag) === $etag) {
        http_response_code(304);
        exit;
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Exception $throwable) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $throwable->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
