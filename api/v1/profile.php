<?php

require_once __DIR__ . '/_human.php';

try {
    if (Api::method() !== 'PATCH') {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'PATCH']);
    }
    list($pdo, $auth, $user) = humanApiServices();
    Api::json(['data' => ['user' => $auth->updateProfile($user, Api::body())]]);
} catch (Exception $exception) {
    humanApiError($exception);
}
