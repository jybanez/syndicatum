<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectPlanService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

try {
    projectApiRequireMethod(['PATCH']);
    list($pdo, $auth) = projectApiServices();
    $projectId = projectApiId('project_id');
    $access = $auth->projectAccess($projectId, 'messages:write');
    $auth->requireCsrfForHuman($access['identity']);
    (new RateLimiter($pdo))->hit('project-plan.write', $projectId . ':' . $access['participant_id'], 120, 60, 60);
    Api::json(['data' => (new ProjectPlanService($pdo))->reorder($access, Api::body())]);
} catch (Exception $exception) { projectApiError($exception); }
