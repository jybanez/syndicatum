<?php
require_once __DIR__ . '/_project-status.php';
try {
    projectApiRequireMethod(['GET']);
    list($service, $access) = projectStatusServices();
    Api::json(['data' => $service->activity($access,
        isset($_GET['days']) ? (int) $_GET['days'] : 14)]);
} catch (Exception $exception) { projectApiError($exception); }
