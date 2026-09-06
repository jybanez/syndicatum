<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AdminService.php';

try {
    $pdo = Db::pdo();
    $auth = new AuthService($pdo);
    $actor = $auth->requireAdministrator();
    $service = new AdminService($pdo);
    $method = Api::method();
    if ($method === 'GET') {
        Api::json(['data' => $service->users()]);
    }
    $auth->validateCsrf($actor, Api::csrfToken());
    $body = Api::body();
    if ($method === 'POST') {
        Api::json(['data' => $service->createUser($body, $actor['id'])], 201);
    }
    if ($method === 'PATCH') {
        $userId = isset($body['user_id']) ? (int) $body['user_id'] : 0;
        if ($userId < 1) {
            throw new InvalidArgumentException('user_id is required.');
        }
        if (isset($body['password'])) {
            $service->resetPassword($userId, $body['password'], $actor['id']);
            Api::json(['data' => ['user_id' => $userId, 'password_reset' => true]]);
        }
        Api::json(['data' => $service->updateUser($userId, $body, $actor['id'])]);
    }
    Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, POST, PATCH']);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401 : ($code === 'NOT_FOUND' ? 404 : 403);
    $message = $code === 'FINAL_ADMINISTRATOR' ? 'The final active administrator cannot be disabled or demoted.' : ($status === 404 ? 'User not found.' : 'This operation is not authorized.');
    Api::json(['error' => true, 'code' => strtolower($code), 'message' => $message], $status);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to process the user request.'], 500);
}
