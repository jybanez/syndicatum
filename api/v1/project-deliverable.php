<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectPlanService.php';

try {
    projectApiRequireMethod(['PATCH', 'DELETE']);
    list($pdo, $auth) = projectApiServices();
    $access = $auth->projectAccess(projectApiId('project_id'), 'messages:write');
    $auth->requireCsrfForHuman($access['identity']);
    $service = new ProjectPlanService($pdo);
    $result = Api::method() === 'DELETE'
        ? $service->deleteDeliverable($access, projectApiId('id'), Api::body())
        : $service->updateDeliverable($access, projectApiId('id'), Api::body());
    Api::json(['data' => $result]);
} catch (Exception $exception) { projectApiError($exception); }
