<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';
require_once dirname(dirname(__DIR__)) . '/src/LocalFileStorage.php';
require_once dirname(dirname(__DIR__)) . '/src/AgentContentReader.php';

try {
    projectApiRequireMethod(['POST']);
    list($pdo, $auth) = projectApiServices();
    $projectId = projectApiId('project_id');
    $access = $auth->projectAccess($projectId, 'profile:read');
    $auth->requireCsrfForHuman($access['identity']);
    (new RateLimiter($pdo))->hit('agent-content.read', $projectId . ':' . $access['participant_id'], 60, 60, 60);
    $body = Api::body();
    $operation = trim((string) ($body['operation'] ?? ''));
    $settings = new SettingsService($pdo);
    $storagePath = trim((string) $settings->get('storage.local_base_path'));
    $storage = $storagePath === '' ? null : new LocalFileStorage(dirname(dirname(__DIR__)), $storagePath);
    $reader = new AgentContentReader($pdo, $storage, null, null, $settings->get('agent_content.ca_bundle'));
    if ($operation === 'read_project_file') {
        $result = $reader->readProjectFile($access, $body['file_id'] ?? '', $body['offset'] ?? 0, $body['max_bytes'] ?? null);
    } elseif ($operation === 'read_public_url') {
        $result = $reader->readPublicUrl($access, $body['url'] ?? '', $body['max_bytes'] ?? null);
    } else {
        throw new InvalidArgumentException('Operation must be read_project_file or read_public_url.');
    }
    Api::json(['data' => $result], 200, ['Cache-Control' => 'private, no-store']);
} catch (Exception $exception) {
    projectApiError($exception);
}
