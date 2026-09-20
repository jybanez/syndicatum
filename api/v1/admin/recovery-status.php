<?php

require_once __DIR__ . '/_recovery.php';

try {
    recoveryRequireMethod(['GET']);
    list($pdo, $_auth, $_user) = recoveryAuth();
    $service = recoveryService($pdo);
    Api::json(['data' => $service->status()]);
} catch (Throwable $exception) { recoveryError($exception); }
