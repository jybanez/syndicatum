<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';
require_once dirname(dirname(__DIR__)) . '/src/LocalFileStorage.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectFileService.php';

try {
    projectApiRequireMethod(['GET', 'POST']);
    list($pdo, $auth) = projectApiServices();
    $projectId = projectApiId('project_id');
    $access = $auth->projectAccess($projectId, 'profile:read');
    $settings = new SettingsService($pdo);
    $configuredPath = trim((string) $settings->get('storage.local_base_path'));
    if ($configuredPath === '') {
        if (Api::method() !== 'GET') { throw new RuntimeException('FILE_STORAGE_NOT_CONFIGURED'); }
        Api::json(['data' => [
            'project_id' => $projectId,
            'root' => ['id' => 'root', 'label' => (string) $access['project_name'], 'selected' => true, 'hasChildren' => false, 'children' => []],
            'current_folder' => ['id' => 'root', 'name' => (string) $access['project_name'], 'version' => 0],
            'folders' => [], 'files' => [],
            'capabilities' => ['create_folder' => false, 'upload' => false, 'rename' => false, 'replace' => false, 'regenerate_link' => false, 'delete' => false],
            'storage' => ['configured' => false, 'ready' => false, 'metadata_ready' => Db::tableExists($pdo, 'project_files')],
            'notice' => 'Ask an administrator to configure an available private storage location.',
        ]]);
    }

    $service = new ProjectFileService($pdo, new LocalFileStorage(dirname(dirname(__DIR__)), $configuredPath), $settings);
    if (Api::method() === 'GET') {
        (new RateLimiter($pdo))->hit('project-files.read', $projectId . ':' . $access['participant_id'], 240, 60, 60);
        Api::json(['data' => $service->browse($access, isset($_GET['folder_id']) ? trim((string) $_GET['folder_id']) : 'root')]);
    }

    $auth->requireCsrfForHuman($access['identity']);
    (new RateLimiter($pdo))->hit('project-files.write', $projectId . ':' . $access['participant_id'], 120, 60, 60);
    if (strpos(strtolower((string) Api::header('Content-Type')), 'multipart/form-data') === 0) {
        $operation = isset($_POST['operation']) ? trim((string) $_POST['operation']) : 'upload';
        if (!isset($_FILES['file']) || !is_array($_FILES['file']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $error = isset($_FILES['file']['error']) ? (int) $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { throw new RuntimeException('FILE_TOO_LARGE'); }
            throw new InvalidArgumentException('Choose one file to upload.');
        }
        $temporary = (string) $_FILES['file']['tmp_name'];
        if (!is_uploaded_file($temporary)) { throw new InvalidArgumentException('The uploaded file could not be verified.'); }
        $stream = fopen($temporary, 'rb');
        if (!is_resource($stream)) { throw new RuntimeException('FILE_UPLOAD_READ_FAILED'); }
        try {
            if ($operation === 'upload') {
                $result = $service->uploadStream($access, $stream, (string) $_FILES['file']['name'], isset($_POST['folder_id']) ? $_POST['folder_id'] : 'root', Api::idempotencyKey());
            } elseif ($operation === 'replace_file') {
                $result = $service->replaceFileStream($access, $stream, (string) $_FILES['file']['name'], $_POST, Api::idempotencyKey());
            } else { throw new InvalidArgumentException('Unsupported file operation.'); }
        } finally { fclose($stream); }
        Api::json(['data' => $result], $result['replayed'] ? 200 : ($operation === 'upload' ? 201 : 200));
    }

    $body = Api::body();
    $operation = isset($body['operation']) ? trim((string) $body['operation']) : '';
    if ($operation === 'create_folder') { $result = $service->createFolder($access, $body, Api::idempotencyKey()); }
    elseif ($operation === 'rename_file') { $result = $service->renameFile($access, $body, Api::idempotencyKey()); }
    elseif ($operation === 'delete_file') { $result = $service->deleteFile($access, $body, Api::idempotencyKey()); }
    elseif ($operation === 'regenerate_link') { $result = $service->regeneratePublicId($access, $body, Api::idempotencyKey()); }
    else { throw new InvalidArgumentException('Unsupported file operation.'); }
    Api::json(['data' => $result], $result['replayed'] ? 200 : ($operation === 'create_folder' ? 201 : 200));
} catch (Exception $exception) {
    projectApiError($exception);
}

