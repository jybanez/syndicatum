<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/AvatarService.php';

try {
    if (Api::method() !== 'POST') {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, ['Allow' => 'POST']);
    }
    list($pdo, $auth, $user, $projects) = humanApiServices();
    $kind = isset($_POST['kind']) ? strtolower(trim((string) $_POST['kind'])) : '';
    if (!in_array($kind, ['human', 'agent'], true)) {
        throw new InvalidArgumentException('kind must be human or agent.');
    }
    if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
        throw new InvalidArgumentException('avatar is required.');
    }

    $projectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $agentId = isset($_POST['agent_id']) ? (int) $_POST['agent_id'] : 0;
    if ($kind === 'agent') {
        if ($projectId < 1) { throw new InvalidArgumentException('project_id is required for an agent avatar.'); }
        $projects->authorizeAgentManagement($projectId, $user['id']);
    }

    $avatars = new AvatarService();
    $avatarUrl = $avatars->storeUpload($_FILES['avatar']);
    try {
        if ($kind === 'human') {
            $auth->replaceAvatar($user, $avatarUrl);
        } elseif ($agentId > 0) {
            $projects->updateAgentAvatar($projectId, $user['id'], $agentId, $avatarUrl);
        }
    } catch (Exception $exception) {
        $avatars->deleteIfLocal($avatarUrl);
        throw $exception;
    }
    Api::json(['data' => ['avatar_url' => $avatarUrl]], 201);
} catch (Exception $exception) {
    humanApiError($exception);
}
