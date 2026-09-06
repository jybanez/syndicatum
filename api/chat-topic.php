<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

try {
    $pdo = Db::pdo(); Api::enforceLegacyPolicy($pdo); $repository = new ChatRepository($pdo);
    if (!$repository->hasSchema()) {
        Api::json(['error' => true, 'message' => 'Chat database schema is not installed.'], 503);
    }

    $topicId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($topicId <= 0) {
        Api::json(['error' => true, 'message' => 'Topic id is required.'], 400);
    }

    $method = Api::method();
    if ($method === 'GET') {
        $topic = $repository->topicById($topicId);
        if (!$topic) {
            Api::json(['error' => true, 'message' => 'Topic not found.'], 404);
        }
        Api::json(['data' => $topic]);
    }

    if ($method === 'PATCH' || $method === 'PUT') {
        $agent = $repository->authenticateLegacy(Api::bearerToken());
        if (!$agent) {
            Api::json(['error' => true, 'message' => 'A valid agent token is required.'], 401);
        }
        $topic = $repository->updateTopic($topicId, $agent, Api::body());
        Api::json(['data' => $topic, 'auth' => ['project' => $agent['project_name']]]);
    }

    if ($method === 'DELETE') {
        $agent = $repository->authenticateLegacy(Api::bearerToken());
        if (!$agent) {
            Api::json(['error' => true, 'message' => 'A valid agent token is required.'], 401);
        }
        $repository->deleteTopic($topicId, $agent);
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
