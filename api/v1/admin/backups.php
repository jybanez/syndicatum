<?php

require_once __DIR__ . '/_recovery.php';

try {
    recoveryRequireMethod(['GET', 'POST']);
    list($pdo, $auth, $user) = recoveryAuth();
    if (Api::method() === 'GET') {
        $service = recoveryService($pdo);
        $operationId = isset($_GET['operation_id']) ? trim((string) $_GET['operation_id']) : '';
        $operation = $service->operation($operationId, (int) $user['id'], (int) $user['session_id']);
        if (!is_array($operation) || $operation['kind'] !== 'backup') { Api::json(['error' => true, 'code' => 'NOT_FOUND', 'message' => 'Backup operation not found.'], 404); }
        Api::json(['data' => $operation]);
    }
    $auth->validateCsrf($user, Api::csrfToken());
    $service = recoveryService($pdo);
    if (Api::body()) { throw new InvalidArgumentException('Backup creation does not accept client-controlled paths or options.'); }
    $key = Api::idempotencyKey();
    $auth->audit((int) $user['id'], 'backup.requested', 'recovery_operation', hash('sha256', $key));
    $operation = $service->buildBackup((int) $user['id'], (int) $user['session_id'], $key);
    $auth->audit((int) $user['id'], 'backup.' . $operation['status'], 'recovery_operation', $operation['operation_id'], [
        'encrypted' => true, 'executable' => false,
        'envelope_sha256' => isset($operation['result']['envelope_sha256']) ? $operation['result']['envelope_sha256'] : null,
    ]);
    Api::json(['data' => $operation], $operation['status'] === 'succeeded' ? 201 : ($operation['status'] === 'started' ? 202 : 500));
} catch (Throwable $exception) { recoveryError($exception); }
