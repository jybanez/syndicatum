<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/AuthService.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';
require_once dirname(dirname(__DIR__)) . '/src/RealtimeIntegration.php';

// Realtime admission must use the same unified human/agent project guard as
// the project API. Never fall back to token presence or a client-supplied id.
$requestAuthPath = dirname(dirname(__DIR__)) . '/src/RequestAuth.php';
$projectRepositoryPath = dirname(dirname(__DIR__)) . '/src/ProjectRepository.php';
if (!is_file($requestAuthPath) || !is_file($projectRepositoryPath)) {
    Api::json([
        'error' => true,
        'code' => 'service_unavailable',
        'message' => 'Project authorization is not available.',
    ], 503);
}
require_once $requestAuthPath;
require_once $projectRepositoryPath;

try {
    if (Api::method() !== 'GET') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']);
    }

    $pdo = Db::pdo();
    $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
    $settings = new SettingsService($pdo);
    $realtime = new RealtimeIntegration($settings);
    if (!$realtime->isEnabled()) { Api::json(['data' => ['enabled' => false]]); }

    if ($projectId < 1) {
        $user = (new AuthService($pdo))->requireAdministrator();
        try { $admission = $realtime->buildBackupAdmission($user); }
        catch (InvalidArgumentException $exception) { throw new RuntimeException('REALTIME_CONFIGURATION_INVALID'); }
        Api::json(['data' => $admission]);
    }

    $requestAuth = new RequestAuth($pdo);
    $access = $requestAuth->projectAccess($projectId, 'messages:read');

    $projects = new ProjectRepository($pdo);
    $participant = null;
    foreach ($projects->participants($access, ['status' => 'active']) as $candidate) {
        if ((int) $candidate['id'] === (int) $access['participant_id']) {
            $participant = $candidate;
            break;
        }
    }
    if (!$participant) {
        throw new RuntimeException('PROJECT_NOT_FOUND');
    }

    try {
        $roles = isset($access['identity']['user']['system_roles']) && is_array($access['identity']['user']['system_roles'])
            ? $access['identity']['user']['system_roles'] : [];
        $additionalRooms = in_array('administrator', $roles, true) ? [RealtimeIntegration::BACKUP_ROOM] : [];
        $admission = $realtime->buildAdmission($participant, $projectId, $additionalRooms);
    } catch (InvalidArgumentException $exception) {
        // Configuration errors are operational details and must not be
        // returned to participants with setting names or secret context.
        throw new RuntimeException('REALTIME_CONFIGURATION_INVALID');
    }
    Api::json(['data' => $admission]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'validation_failed', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    if ($code === 'AUTHENTICATION_REQUIRED') {
        Api::json(['error' => true, 'code' => 'authentication_required', 'message' => 'Authentication is required.'], 401);
    }
    if ($code === 'ADMINISTRATOR_REQUIRED') {
        Api::json(['error' => true, 'code' => 'administrator_required', 'message' => 'Administrator access is required.'], 403);
    }
    if ($code === 'PROJECT_NOT_FOUND') {
        Api::json(['error' => true, 'code' => 'project_not_found', 'message' => 'Project not found.'], 404);
    }
    Api::json(['error' => true, 'code' => 'realtime_unavailable', 'message' => 'Realtime admission is unavailable.'], 503);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to issue Realtime admission.'], 500);
}
