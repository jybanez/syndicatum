<?php

require_once __DIR__ . '/_recovery.php';

try {
    recoveryRequireMethod(['GET', 'POST']);
    list($pdo, $auth, $user) = recoveryAuth();
    if (Api::method() === 'GET') {
        $service = recoveryService($pdo);
        $operationId = isset($_GET['operation_id']) ? trim((string) $_GET['operation_id']) : '';
        $operation = $service->operation($operationId, (int) $user['id'], (int) $user['session_id']);
        if (!is_array($operation) || $operation['kind'] !== 'staged_restore') { Api::json(['error' => true, 'code' => 'NOT_FOUND', 'message' => 'Staged restore operation not found.'], 404); }
        Api::json(['data' => $operation]);
    }
    $auth->validateCsrf($user, Api::csrfToken());
    $service = recoveryService($pdo);
    $body = Api::body();
    $allowed = ['inspection_id', 'envelope_sha256', 'confirmation'];
    foreach ($body as $field => $_value) { if (!in_array($field, $allowed, true)) { throw new InvalidArgumentException('Staged restore does not accept client paths, database credentials, or cutover options.'); } }
    $operation = $service->stageRestore((int) $user['id'], (int) $user['session_id'], Api::idempotencyKey(),
        isset($body['inspection_id']) ? (string) $body['inspection_id'] : '',
        isset($body['envelope_sha256']) ? (string) $body['envelope_sha256'] : '',
        isset($body['confirmation']) ? (string) $body['confirmation'] : '');
    $auth->audit((int) $user['id'], 'restore.' . $operation['status'], 'recovery_operation', $operation['operation_id'], [
        'cutover_performed' => false, 'live_overwrite' => false,
    ]);
    Api::json(['data' => $operation], $operation['status'] === 'succeeded' ? 201 : ($operation['status'] === 'started' ? 202 : 500));
} catch (Throwable $exception) { recoveryError($exception); }
