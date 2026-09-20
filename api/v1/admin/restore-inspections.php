<?php

require_once __DIR__ . '/_recovery.php';

try {
    recoveryRequireMethod(['POST']);
    list($pdo, $auth, $user) = recoveryAuth();
    $auth->validateCsrf($user, Api::csrfToken());
    $service = recoveryService($pdo);
    if (!isset($_FILES['backup']) || !is_array($_FILES['backup'])) { throw new InvalidArgumentException('Select one encrypted backup file.'); }
    $upload = $_FILES['backup'];
    if (!isset($upload['error']) || (int) $upload['error'] !== UPLOAD_ERR_OK) { throw new InvalidArgumentException('The encrypted backup upload did not complete.'); }
    $temporary = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
    if (!is_uploaded_file($temporary)) { throw new InvalidArgumentException('The encrypted backup upload is not a valid HTTP upload.'); }
    $inspection = $service->inspectUploadedBackup((int) $user['id'], (int) $user['session_id'], $temporary,
        isset($upload['name']) ? (string) $upload['name'] : '', isset($upload['size']) ? (int) $upload['size'] : -1);
    $auth->audit((int) $user['id'], 'restore.inspected', 'restore_inspection', $inspection['inspection_id'], [
        'envelope_sha256' => $inspection['metadata']['envelope_sha256'],
        'target_ready' => $inspection['metadata']['target_ready'],
        'cutover_performed' => false,
    ]);
    Api::json(['data' => $inspection], 201);
} catch (Throwable $exception) { recoveryError($exception); }
