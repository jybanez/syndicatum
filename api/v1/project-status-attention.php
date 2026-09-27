<?php
require_once __DIR__ . '/_project-status.php';
try {
    projectApiRequireMethod(['GET']);
    list($service, $access) = projectStatusServices();
    Api::json(['data' => $service->attention($access,
        isset($_GET['limit']) ? (int) $_GET['limit'] : 10,
        isset($_GET['before']) ? $_GET['before'] : null)]);
} catch (Exception $exception) { projectApiError($exception); }
