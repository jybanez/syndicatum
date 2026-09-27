<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectStatusService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

function projectStatusServices()
{
    list($pdo, $auth) = projectApiServices();
    $access = $auth->projectAccess(projectApiId('project_id'), 'profile:read');
    if ($access['identity']['kind'] !== 'human' || $access['role'] !== 'owner') {
        throw new RuntimeException('PROJECT_STATUS_FORBIDDEN');
    }
    (new RateLimiter($pdo))->hit('project.status.read',
        (int) $access['project_id'] . ':' . (int) $access['participant_id'], 180, 60, 60);
    return [new ProjectStatusService($pdo), $access];
}
