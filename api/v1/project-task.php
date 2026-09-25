<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectTaskService.php';

try {
    projectApiRequireMethod(['GET', 'PATCH']);
    list($pdo, $auth) = projectApiServices();
    $scope = Api::method() === 'GET' ? 'messages:read' : 'messages:write';
    $access = $auth->projectAccess(projectApiId('project_id'), $scope);
    $service = new ProjectTaskService($pdo);
    $taskId = projectApiId('id');
    if (Api::method() === 'GET') { Api::json(['data' => $service->task($access, $taskId, true)]); }
    $auth->requireCsrfForHuman($access['identity']);
    Api::json(['data' => $service->update($access, $taskId, Api::body())]);
} catch (Exception $exception) { projectApiError($exception); }
