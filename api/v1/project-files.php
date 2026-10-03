<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

try {
    projectApiRequireMethod(['GET']);
    list($pdo, $auth, $repository) = projectApiServices();
    $projectId = projectApiId('project_id');
    $access = $auth->projectAccess($projectId, 'profile:read');
    (new RateLimiter($pdo))->hit('project-files.read', $projectId . ':' . $access['participant_id'], 240, 60, 60);

    $settings = new SettingsService($pdo);
    $configuredPath = trim((string) $settings->get('storage.local_base_path'));
    $resolvedPath = $configuredPath === '' ? false : realpath($configuredPath);
    $storageReady = is_string($resolvedPath) && is_dir($resolvedPath) && is_writable($resolvedPath);

    $context = $repository->projectContext($access);
    $projectName = (string) ($context['project']['name'] ?? 'Project files');
    $metadataReady = Db::tableExists($pdo, 'project_file_nodes');
    $operationsReady = false;

    Api::json(['data' => [
        'project_id' => $projectId,
        'root' => [
            'id' => 'root',
            'label' => $projectName,
            'hasChildren' => false,
            'children' => [],
        ],
        'current_folder' => ['id' => 'root', 'name' => $projectName],
        'files' => [],
        'capabilities' => [
            'create_folder' => $operationsReady,
            'upload' => $operationsReady,
        ],
        'storage' => [
            'configured' => $configuredPath !== '',
            'ready' => $storageReady,
            'metadata_ready' => $metadataReady,
            'max_upload_bytes' => (int) $settings->get('storage.max_upload_bytes'),
            'max_files_per_action' => (int) $settings->get('storage.max_files_per_action'),
            'default_project_quota_bytes' => (int) $settings->get('storage.default_project_quota_bytes'),
        ],
        'notice' => $operationsReady
            ? ''
            : ($storageReady
                ? 'Project file metadata and write actions are being prepared.'
                : 'Ask an administrator to configure an available private storage location.'),
    ]]);
} catch (Exception $exception) {
    projectApiError($exception);
}

