<?php

require_once __DIR__ . '/_human.php';

try {
    if (Api::method() !== 'PATCH') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    list($pdo, $auth, $user, $service) = humanApiServices();
    Api::json(['data' => $service->updateWorkspace($user['id'], Api::body())]);
} catch (Exception $exception) { humanApiError($exception); }
