<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectTemplateService.php';

try {
    if (Api::method() !== 'GET') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    list($pdo) = humanApiServices(false);
    $service = new ProjectTemplateService($pdo);
    Api::json(['data' => ['categories' => $service->listCategories(), 'templates' => $service->listTemplates(false)]]);
} catch (Exception $exception) {
    humanApiError($exception);
}
