<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectTaskService.php';

try {
    projectApiRequireMethod(['PATCH']);
    list($pdo, $auth) = projectApiServices();
    $access = $auth->projectAccess(projectApiId('project_id'), 'plan:progress');
    $auth->requireCsrfForHuman($access['identity']);
    Api::json(['data' => (new ProjectTaskService($pdo))->updateDeliverableLink(
        $access,
        projectApiId('id'),
        Api::body()
    )]);
} catch (Exception $exception) { projectApiError($exception); }
