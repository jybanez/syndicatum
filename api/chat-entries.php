<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

try {
    $pdo = Db::pdo(); Api::enforceLegacyPolicy($pdo); $repository = new ChatRepository($pdo);
    if (!$repository->hasSchema()) {
        Api::json(['error' => true, 'message' => 'Chat database schema is not installed.'], 503);
    }

    $method = Api::method();
    if ($method === 'GET') {
        $filters = [
            'sender' => isset($_GET['sender']) ? trim((string) $_GET['sender']) : '',
            'target' => isset($_GET['target']) ? trim((string) $_GET['target']) : '',
            'q' => isset($_GET['q']) ? trim((string) $_GET['q']) : '',
            'direct' => isset($_GET['direct']) ? trim((string) $_GET['direct']) : '',
            'order' => isset($_GET['order']) ? trim((string) $_GET['order']) : 'desc',
            'participant' => isset($_GET['participant']) ? trim((string) $_GET['participant']) : '',
            'day' => isset($_GET['day']) ? trim((string) $_GET['day']) : '',
            'limit' => isset($_GET['limit']) ? (int) $_GET['limit'] : 0,
            'before' => isset($_GET['before']) ? trim((string) $_GET['before']) : '',
            'after' => isset($_GET['after']) ? trim((string) $_GET['after']) : '',
        ];
        if ($filters['limit'] > 0 || $filters['before'] !== '' || $filters['after'] !== '') {
            Api::json($repository->messagePage($filters));
        }
        Api::json(['data' => $repository->messages($filters)]);
    }

    if ($method === 'POST') {
        $agent = $repository->authenticateLegacy(Api::bearerToken());
        if (!$agent) {
            Api::json(['error' => true, 'message' => 'A valid agent token is required.'], 401);
        }

        $entry = $repository->createEntry($agent, Api::body());
        Api::json(['data' => $entry, 'auth' => ['project' => $agent['project_name']]], 201);
    }

    Api::json(['error' => true, 'message' => 'Method not allowed.'], 405);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 422);
} catch (Exception $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 500);
}
