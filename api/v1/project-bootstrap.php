<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    projectApiRequireMethod(['GET']);
    list($pdo, $auth, $repository) = projectApiServices();
    $access = $auth->projectAccess(projectApiId('project_id'), 'profile:read');
    Api::json(['data' => $repository->bootstrapContext($access)]);
} catch (Exception $exception) {
    projectApiError($exception);
}
