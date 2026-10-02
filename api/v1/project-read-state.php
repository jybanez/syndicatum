<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    projectApiRequireMethod(['POST']);
    list($pdo, $auth, $repository) = projectApiServices();
    $access = $auth->projectAccess(projectApiId('project_id'), 'messages:read');
    $auth->requireCsrfForHuman($access['identity']);
    $body = Api::body();
    if (!array_key_exists('last_read_sequence', $body)) {
        throw new InvalidArgumentException('last_read_sequence is required.');
    }
    Api::json(['data' => $repository->markProjectRead($access, $body['last_read_sequence'])]);
} catch (Exception $exception) {
    projectApiError($exception);
}
