<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    projectApiRequireMethod(['GET', 'PATCH', 'DELETE']);
    list($pdo, $auth, $repository) = projectApiServices();
    $scope = Api::method() === 'GET' ? 'messages:read' : 'messages:write';
    $access = $auth->projectAccess(projectApiId('project_id'), $scope);
    $messageId = projectApiId('id');

    if (Api::method() === 'GET') {
        $message = $repository->message($access, $messageId);
        if (isset($_GET['include']) && $_GET['include'] === 'revisions') {
            $message['revisions'] = $repository->revisions($access, $messageId);
        }
        Api::json(['data' => $message]);
    }

    $auth->requireCsrfForHuman($access['identity']);
    if (Api::method() === 'PATCH') {
        Api::json(['data' => $repository->updateMessage($access, $messageId, Api::body())]);
    }
    Api::json(['data' => $repository->deleteMessage($access, $messageId)]);
} catch (Exception $exception) {
    projectApiError($exception);
}
