<?php

require_once __DIR__ . '/_recovery.php';

try {
    recoveryRequireMethod(['POST']);
    list($pdo, $auth, $user) = recoveryAuth();
    $auth->validateCsrf($user, Api::csrfToken());
    $service = recoveryService($pdo);
    $result = $service->releaseDownloadTicket((int) $user['id'], (int) $user['session_id']);
    $auth->audit((int) $user['id'], 'release_package.download_authorized', 'release_package', $result['release']['sha256'], [
        'source_commit' => $result['release']['source_commit'], 'runtime_build_allowed' => false,
    ]);
    Api::json(['data' => $result], 201);
} catch (Throwable $exception) { recoveryError($exception); }
