<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/RequestAuth.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectRepository.php';

function projectApiServices()
{
    $pdo = Db::pdo();
    if (!Db::tableExists($pdo, 'messages') || !Db::tableExists($pdo, 'project_participants')) {
        Api::json(['error' => true, 'code' => 'SCHEMA_NOT_INSTALLED', 'message' => 'Expanded Syndicatum schema is not installed.'], 503);
    }
    return [$pdo, new RequestAuth($pdo), new ProjectRepository($pdo)];
}

function projectApiId($name)
{
    $value = isset($_GET[$name]) ? (int) $_GET[$name] : 0;
    if ($value < 1) {
        throw new InvalidArgumentException('A valid ' . $name . ' is required.');
    }
    return $value;
}

function projectApiError(Exception $exception)
{
    if ($exception instanceof InvalidArgumentException) {
        Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
    }

    $code = $exception->getMessage();
    $errors = [
        'AUTHENTICATION_REQUIRED' => [401, 'Authentication is required.'],
        'CSRF_VALIDATION_FAILED' => [403, 'CSRF validation failed.'],
        'PROJECT_NOT_FOUND' => [404, 'Project not found.'],
        'MESSAGE_NOT_FOUND' => [404, 'Message not found.'],
        'PROJECT_WRITE_FORBIDDEN' => [403, 'This project role cannot post messages.'],
        'MESSAGE_WRITE_FORBIDDEN' => [403, 'Only the sender or a project administrator may change this message.'],
        'MESSAGE_NOT_ADDRESSED_TO_PARTICIPANT' => [409, 'This participant is not an addressee of the message.'],
        'PROJECT_ARCHIVED' => [409, 'Archived projects are read-only.'],
        'RATE_LIMITED' => [429, 'Too many requests. Try again later.'],
    ];
    if (isset($errors[$code])) {
        Api::json(['error' => true, 'code' => $code, 'message' => $errors[$code][1]], $errors[$code][0]);
    }

    error_log('Syndicatum project API error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'The request could not be completed.'], 500);
}

function projectApiRequireMethod(array $methods)
{
    if (!in_array(Api::method(), $methods, true)) {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, [
            'Allow' => implode(', ', $methods),
        ]);
    }
}
