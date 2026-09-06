<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    projectApiRequireMethod(['GET']);
    list($pdo, $auth, $repository) = projectApiServices();
    $access = $auth->projectAccess(projectApiId('project_id'));
    Api::json(['data' => $repository->projectContext($access)]);
} catch (Exception $exception) {
    projectApiError($exception);
}
