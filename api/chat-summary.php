<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

try {
    $repository = new ChatRepository(Db::pdo());
    if (!$repository->hasSchema()) {
        Api::json(['error' => true, 'message' => 'Chat database schema is not installed.'], 503);
    }

    $payload = $repository->payload();
    Api::json([
        'data' => [
            'meta' => $payload['meta'],
            'project_count' => count($payload['projects']),
            'topic_count' => count($payload['active_topics']),
        ],
    ]);
} catch (Exception $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 500);
}
