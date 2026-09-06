<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/ConnectorDeviceService.php';
require_once dirname(dirname(__DIR__)) . '/src/RealtimeIntegration.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';

try {
    if (Api::method() !== 'GET') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
    if ($projectId < 1) { throw new InvalidArgumentException('A valid project_id is required.'); }
    $pdo = Db::pdo(); $service = new ConnectorDeviceService($pdo);
    $device = $service->authenticate(Api::bearerToken());
    $realtime = new RealtimeIntegration(new SettingsService($pdo));
    if (!$realtime->isEnabled()) { Api::json(['data' => ['enabled' => false]]); }
    Api::json(['data' => $realtime->buildAdmission($service->humanParticipant($device, $projectId), $projectId)]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    $code = $exception->getMessage();
    Api::json(['error' => true, 'code' => $code, 'message' => $code === 'AUTHENTICATION_REQUIRED' ? 'Connector authentication is required.' : 'Project is unavailable.'], $code === 'AUTHENTICATION_REQUIRED' ? 401 : 404);
} catch (Exception $exception) {
    error_log('Connector admission error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'Realtime admission is unavailable.'], 500);
}
