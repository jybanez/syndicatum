<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

try {
    $pdo = Db::pdo(); Api::enforceLegacyPolicy($pdo); $repository = new ChatRepository($pdo);
    if (!$repository->hasSchema()) {
        Api::json(['error' => true, 'message' => 'Chat database schema is not installed.'], 503);
    }

    if (Api::method() === 'GET') {
        Api::json(['data' => $repository->topics()]);
    }

    if (Api::method() === 'POST') {
        $agent = $repository->authenticateLegacy(Api::bearerToken());
        if (!$agent) {
            Api::json(['error' => true, 'message' => 'A valid agent token is required.'], 401);
        }
        $topic = $repository->createTopic($agent, Api::body());
        Api::json(['data' => $topic, 'auth' => ['project' => $agent['project_name']]], 201);
    }

    Api::json(['error' => true, 'message' => 'Method not allowed.'], 405);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 422);
} catch (Exception $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 500);
}
