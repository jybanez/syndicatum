<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/SettingsService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/IntegrationHealth.php';

try {
    if (Api::method() !== 'POST') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    $pdo = Db::pdo(); $auth = new AuthService($pdo); $user = $auth->requireAdministrator(); $auth->validateCsrf($user, Api::csrfToken());
    $body = Api::body(); $name = isset($body['integration']) ? trim((string) $body['integration']) : '';
    $baseUrl = array_key_exists('base_url', $body) ? trim((string) $body['base_url']) : null;
    $result = (new IntegrationHealth(new SettingsService($pdo)))->test($name, $baseUrl);
    $auth->audit($user['id'], 'integration.connection_tested', 'integration', $name, ['reachable' => $result['reachable']]);
    Api::json(['data' => $result]);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    Api::json(['error' => true, 'code' => $exception->getMessage(), 'message' => 'Administrator access is required.'], $exception->getMessage() === 'AUTHENTICATION_REQUIRED' ? 401 : 403);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'INTEGRATION_TEST_FAILED', 'message' => 'The integration test could not be completed.'], 502);
}
