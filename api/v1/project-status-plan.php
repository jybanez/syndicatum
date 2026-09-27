<?php

require_once __DIR__ . '/_project-status.php';

try {
    list($service, $access) = projectStatusContext();
    Api::json(['data' => $service->plan($access)]);
} catch (Exception $exception) { projectApiError($exception); }
