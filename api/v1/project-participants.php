<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    projectApiRequireMethod(['GET']);
    list($pdo, $auth, $repository) = projectApiServices();
    $access = $auth->projectAccess(projectApiId('project_id'), 'profile:read');
    Api::json(['data' => $repository->participants($access, [
        'kind' => isset($_GET['kind']) ? trim((string) $_GET['kind']) : '',
        'status' => isset($_GET['status']) ? trim((string) $_GET['status']) : 'active',
    ])]);
} catch (Exception $exception) {
    projectApiError($exception);
}
