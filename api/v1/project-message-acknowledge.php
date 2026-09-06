<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    projectApiRequireMethod(['POST']);
    list($pdo, $auth, $repository) = projectApiServices();
    $access = $auth->projectAccess(projectApiId('project_id'), 'messages:acknowledge');
    $auth->requireCsrfForHuman($access['identity']);
    Api::json(['data' => $repository->acknowledge($access, projectApiId('id'))]);
} catch (Exception $exception) {
    projectApiError($exception);
}
