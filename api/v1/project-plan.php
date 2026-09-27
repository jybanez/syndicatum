<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectPlanService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

try {
    projectApiRequireMethod(['GET']);
    list($pdo, $auth) = projectApiServices();
    $projectId = projectApiId('project_id');
    $access = $auth->projectAccess($projectId, 'messages:read');
    (new RateLimiter($pdo))->hit('project-plan.read', $projectId . ':' . $access['participant_id'], 300, 60, 60);
    Api::json(['data' => (new ProjectPlanService($pdo))->plan($access)]);
} catch (Exception $exception) { projectApiError($exception); }
