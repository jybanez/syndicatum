<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/ProjectTemplateService.php';

try {
    $pdo = Db::pdo();
    $auth = new AuthService($pdo);
    $user = $auth->requireAdministrator();
    $service = new ProjectTemplateService($pdo);
    $method = Api::method();

    if ($method === 'GET') {
        $includeArchived = isset($_GET['include_archived']) && $_GET['include_archived'] === '1';
        Api::json(['data' => ['categories' => $service->listCategories(), 'templates' => $service->listTemplates($includeArchived)]]);
    }

    $auth->validateCsrf($user, Api::csrfToken());
    $body = Api::body();
    $templateId = isset($body['template_id']) ? (int) $body['template_id'] : 0;
    $agentId = isset($body['agent_id']) ? (int) $body['agent_id'] : 0;

    if ($method === 'POST') {
        $template = $templateId > 0
            ? $service->createAgent($templateId, (int) $user['id'], $body)
            : $service->createTemplate((int) $user['id'], $body);
        $auth->audit((int) $user['id'], $templateId > 0 ? 'project_template.agent_created' : 'project_template.created', 'project_template', (string) $template['id']);
        Api::json(['data' => ['template' => $template]], 201);
    }
    if ($method === 'PATCH') {
        if ($templateId < 1) { throw new InvalidArgumentException('A valid template is required.'); }
        $template = $agentId > 0
            ? $service->updateAgent($templateId, $agentId, (int) $user['id'], $body)
            : $service->updateTemplate($templateId, (int) $user['id'], $body);
        $auth->audit((int) $user['id'], $agentId > 0 ? 'project_template.agent_updated' : 'project_template.updated', 'project_template', (string) $templateId);
        Api::json(['data' => ['template' => $template]]);
    }
    if ($method === 'DELETE') {
        if ($templateId < 1) { throw new InvalidArgumentException('A valid template is required.'); }
        $version = isset($body['version']) ? (int) $body['version'] : 0;
        $template = $agentId > 0
            ? $service->deleteAgent($templateId, $agentId, (int) $user['id'], $version)
            : $service->archiveTemplate($templateId, (int) $user['id'], $version);
        $auth->audit((int) $user['id'], $agentId > 0 ? 'project_template.agent_deleted' : 'project_template.archived', 'project_template', (string) $templateId);
        Api::json(['data' => ['template' => $template]]);
    }
    Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, POST, PATCH, DELETE']);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $errors = [
        'AUTHENTICATION_REQUIRED' => [401, 'Authentication is required.'],
        'ADMINISTRATOR_REQUIRED' => [403, 'Administrator access is required.'],
        'CSRF_VALIDATION_FAILED' => [403, 'CSRF validation failed.'],
        'TEMPLATE_NOT_FOUND' => [404, 'Project template not found.'],
        'TEMPLATE_AGENT_NOT_FOUND' => [404, 'Agent preset not found.'],
        'TEMPLATE_VERSION_CONFLICT' => [409, 'This template changed. Reload it before trying again.'],
        'TEMPLATE_SYSTEM_MANAGED' => [403, 'Built-in templates are managed by Syndicatum. Create a custom template to make changes.'],
    ];
    if (isset($errors[$exception->getMessage()])) {
        $entry = $errors[$exception->getMessage()];
        Api::json(['error' => true, 'code' => strtolower($exception->getMessage()), 'message' => $entry[1]], $entry[0]);
    }
    error_log('Project template API error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to process project templates.'], 500);
} catch (Exception $exception) {
    error_log('Project template API error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to process project templates.'], 500);
}
