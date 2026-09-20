<?php

require_once __DIR__ . '/_recovery.php';

try {
    recoveryRequireMethod(['POST']);
    list($pdo, $auth, $user) = recoveryAuth();
    $auth->validateCsrf($user, Api::csrfToken());
    $body = Api::body();
    foreach ($body as $field => $_value) {
        if ($field !== 'operation_id') { throw new InvalidArgumentException('Backup download authorization accepts only an operation id.'); }
    }
    $operationId = isset($body['operation_id']) ? trim((string) $body['operation_id']) : '';
    $download = recoveryService($pdo)->reissueBackupDownload($operationId, (int) $user['id'], (int) $user['session_id']);
    $auth->audit((int) $user['id'], 'backup.download_reauthorized', 'recovery_operation', $operationId);
    Api::json(['data' => ['download' => $download]]);
} catch (Throwable $exception) { recoveryError($exception); }
