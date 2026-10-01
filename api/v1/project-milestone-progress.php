<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectPlanService.php';

try {
    projectApiRequireMethod(['PATCH']);
    list($pdo, $auth) = projectApiServices();
    $access = $auth->projectAccess(projectApiId('project_id'), 'plan:progress');
    $auth->requireCsrfForHuman($access['identity']);
    Api::json(['data' => (new ProjectPlanService($pdo))->updateMilestoneProgress($access, projectApiId('id'), Api::body())]);
} catch (Exception $exception) { projectApiError($exception); }
