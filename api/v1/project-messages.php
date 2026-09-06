<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

try {
    projectApiRequireMethod(['GET', 'POST']);
    list($pdo, $auth, $repository) = projectApiServices();
    $projectId = projectApiId('project_id');

    if (Api::method() === 'GET') {
        $access = $auth->projectAccess($projectId, 'messages:read');
        (new RateLimiter($pdo))->hit('messages.read', $projectId . ':' . $access['participant_id'], 600, 60, 60);
        Api::json($repository->messagePage($access, [
            'limit' => isset($_GET['limit']) ? (int) $_GET['limit'] : 100,
            'before' => isset($_GET['before']) ? trim((string) $_GET['before']) : '',
            'after' => isset($_GET['after']) ? trim((string) $_GET['after']) : '',
            'sender' => isset($_GET['sender']) ? trim((string) $_GET['sender']) : '',
            'idempotency_key' => isset($_GET['idempotency_key']) ? trim((string) $_GET['idempotency_key']) : '',
            'q' => isset($_GET['q']) ? trim((string) $_GET['q']) : '',
            'from' => isset($_GET['from']) ? trim((string) $_GET['from']) : '',
            'to' => isset($_GET['to']) ? trim((string) $_GET['to']) : '',
            'addressed_to_me' => isset($_GET['addressed_to']) && $_GET['addressed_to'] === 'me',
            'acknowledged' => isset($_GET['acknowledged']) ? trim((string) $_GET['acknowledged']) : '',
        ]));
    }

    $access = $auth->projectAccess($projectId, 'messages:write');
    (new RateLimiter($pdo))->hit('messages.write', $projectId . ':' . $access['participant_id'], 120, 60, 60);
    $auth->requireCsrfForHuman($access['identity']);
    $body = Api::body();
    $idempotencyHeader = Api::idempotencyKey();
    if ($idempotencyHeader !== '') {
        if (isset($body['idempotency_key']) && trim((string) $body['idempotency_key']) !== ''
            && !hash_equals(trim((string) $body['idempotency_key']), $idempotencyHeader)) {
            throw new InvalidArgumentException('Idempotency-Key header and body value must match.');
        }
        $body['idempotency_key'] = $idempotencyHeader;
    }
    $result = $repository->createMessage($access, $body);
    Api::json(['data' => $result['message'], 'idempotent_replay' => !$result['created']], $result['created'] ? 201 : 200);
} catch (Exception $exception) {
    projectApiError($exception);
}
