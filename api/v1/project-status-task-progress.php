<?php
require_once __DIR__ . '/_project-status.php';
try {
    projectApiRequireMethod(['GET']);
    list($service, $access) = projectStatusServices();
    Api::json(['data' => $service->taskProgress($access)]);
} catch (Exception $exception) { projectApiError($exception); }
