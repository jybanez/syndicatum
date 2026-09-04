<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

try {
    $repository = new ChatRepository(Db::pdo());
    if (!$repository->hasSchema()) {
        Api::json(['error' => true, 'message' => 'Chat database schema is not installed.'], 503);
    }

    Api::json(['data' => $repository->publicAgents(isset($_GET['active']) ? (bool) $_GET['active'] : false)]);
} catch (Exception $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 500);
}
