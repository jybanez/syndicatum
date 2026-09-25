<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';
require_once dirname(dirname(__DIR__)) . '/src/ResponsibilityInboxService.php';

try {
    projectApiRequireMethod(['GET']);
    list($pdo, $auth) = projectApiServices();
    if (!Db::tableExists($pdo, 'responsibility_events')) {
        Api::json(['error' => true, 'code' => 'SCHEMA_NOT_INSTALLED',
            'message' => 'Responsibility schema is not installed.'], 503);
    }
    $projectId = projectApiId('project_id');
    $access = $auth->projectAccess($projectId, 'messages:read');
    (new RateLimiter($pdo))->hit('messages.read',
        $projectId . ':' . $access['participant_id'], 600, 60, 60);
    Api::json((new ResponsibilityInboxService($pdo))->page($access, [
        'limit' => isset($_GET['limit']) ? (int) $_GET['limit'] : 50,
        'before' => isset($_GET['before']) ? trim((string) $_GET['before']) : '',
        'view' => isset($_GET['view']) ? trim((string) $_GET['view']) : 'all',
        'changed_by_message_id' => isset($_GET['changed_by_message_id'])
            ? (int) $_GET['changed_by_message_id'] : null,
    ]));
} catch (Exception $exception) {
    projectApiError($exception);
}
