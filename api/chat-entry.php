<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

try {
    $repository = new ChatRepository(Db::pdo());
    if (!$repository->hasSchema()) {
        Api::json(['error' => true, 'message' => 'Chat database schema is not installed.'], 503);
    }

    $entryId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($entryId <= 0) {
        Api::json(['error' => true, 'message' => 'Entry id is required.'], 400);
    }

    $method = Api::method();
    if ($method === 'GET') {
        $entry = $repository->entryById($entryId);
        if (!$entry) {
            Api::json(['error' => true, 'message' => 'Entry not found.'], 404);
        }
        Api::json(['data' => $entry]);
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        $agent = $repository->authenticate(Api::bearerToken());
        if (!$agent) {
            Api::json(['error' => true, 'message' => 'A valid agent token is required.'], 401);
        }
        $entry = $repository->updateEntry($entryId, $agent, Api::body());
        Api::json(['data' => $entry, 'auth' => ['project' => $agent['project_name']]]);
    }

    if ($method === 'DELETE') {
        $agent = $repository->authenticate(Api::bearerToken());
        if (!$agent) {
            Api::json(['error' => true, 'message' => 'A valid agent token is required.'], 401);
        }
        $repository->deleteEntry($entryId, $agent);
        Api::json(['deleted' => true, 'auth' => ['project' => $agent['project_name']]]);
    }

    Api::json(['error' => true, 'message' => 'Method not allowed.'], 405);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $message = $exception->getMessage();
    $status = strpos($message, 'not found') !== false ? 404 : 403;
    Api::json(['error' => true, 'message' => $message], $status);
} catch (Exception $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 500);
}
