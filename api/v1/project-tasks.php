<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectTaskService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

try {
    projectApiRequireMethod(['GET', 'POST']);
    list($pdo, $auth) = projectApiServices();
    $projectId = projectApiId('project_id');
    $scope = Api::method() === 'GET' ? 'messages:read' : 'messages:write';
    $access = $auth->projectAccess($projectId, $scope);
    $service = new ProjectTaskService($pdo);
    if (Api::method() === 'GET') {
        (new RateLimiter($pdo))->hit('tasks.read', $projectId . ':' . $access['participant_id'], 600, 60, 60);
        Api::json(['data' => $service->listTasks($access, [
            'status' => isset($_GET['status']) ? trim((string) $_GET['status']) : '',
            'assignee' => isset($_GET['assignee']) ? trim((string) $_GET['assignee']) : '',
            'q' => isset($_GET['q']) ? trim((string) $_GET['q']) : '',
        ])]);
    }
    $auth->requireCsrfForHuman($access['identity']);
    (new RateLimiter($pdo))->hit('tasks.write', $projectId . ':' . $access['participant_id'], 120, 60, 60);
    Api::json(['data' => $service->create($access, Api::body())], 201);
} catch (Exception $exception) { projectApiError($exception); }
