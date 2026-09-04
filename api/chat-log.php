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
    $lastModifiedUnix = strtotime($feedVersion['last_updated']) ?: time();
    $lastModified = gmdate('D, d M Y H:i:s', $lastModifiedUnix) . ' GMT';

    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: no-cache, must-revalidate');

    $requestEtag = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : null;
    if ($requestEtag !== null && trim($requestEtag) === $etag) {
        http_response_code(304);
        exit;
    }

    $payload = $repository->payload($feedVersion);
    Api::json($payload);
} catch (Exception $exception) {
    Api::json(['error' => true, 'message' => 'Chat database is unavailable.'], 503);
}
