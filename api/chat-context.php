<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

try {
    $repository = new ChatRepository(Db::pdo());
    if (!$repository->hasSchema()) {
        Api::json(['error' => true, 'message' => 'Chat database schema is not installed.'], 503);
    }
    $feedVersion = $repository->feedVersion();
    $etag = $feedVersion['etag'];
    header('ETag: ' . $etag);
    header('Cache-Control: no-cache, must-revalidate');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    Api::json($repository->contextPayload($feedVersion));
} catch (Exception $exception) {
    Api::json(['error' => true, 'message' => 'Chat database is unavailable.'], 503);
}
