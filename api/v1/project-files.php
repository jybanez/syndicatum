<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(dirname(__DIR__)) . '/src/SettingsService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';
require_once dirname(dirname(__DIR__)) . '/src/LocalFileStorage.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectFileService.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectFileChunkUploadStore.php';

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
            'breadcrumbs' => [['id' => 'root', 'name' => (string) $access['project_name'], 'version' => 0]],
            'folders' => [], 'files' => [],
            'pagination' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'total_pages' => 1,
                'search' => '', 'sort' => 'name', 'direction' => 'asc', 'has_more' => false],
            'capabilities' => ['create_folder' => false, 'upload' => false, 'rename' => false, 'move' => false, 'copy_link' => false, 'download' => false, 'delete' => false],
            'storage' => ['configured' => false, 'ready' => false, 'metadata_ready' => Db::tableExists($pdo, 'project_files')],
            'notice' => 'Ask an administrator to configure an available private storage location.',
        ]]);
    }

    $service = new ProjectFileService($pdo, new LocalFileStorage(dirname(dirname(__DIR__)), $configuredPath), $settings);
    if (Api::method() === 'GET') {
        (new RateLimiter($pdo))->hit('project-files.read', $projectId . ':' . $access['participant_id'], 240, 60, 60);
        Api::json(['data' => $service->browse($access, isset($_GET['folder_id']) ? trim((string) $_GET['folder_id']) : 'root', [
            'page' => isset($_GET['page']) ? $_GET['page'] : 1,
            'per_page' => isset($_GET['per_page']) ? $_GET['per_page'] : 20,
            'search' => isset($_GET['search']) ? $_GET['search'] : '',
            'sort' => isset($_GET['sort']) ? $_GET['sort'] : 'name',
            'direction' => isset($_GET['direction']) ? $_GET['direction'] : 'asc',
        ])]);
    }

    $auth->requireCsrfForHuman($access['identity']);
    if (strpos(strtolower((string) Api::header('Content-Type')), 'multipart/form-data') === 0) {
        $operation = isset($_POST['operation']) ? trim((string) $_POST['operation']) : 'upload';
        if ($operation === 'upload_chunk') {
            (new RateLimiter($pdo))->hit('project-files.chunk', $projectId . ':' . $access['participant_id'], 2400, 600, 300);
            if (!isset($_FILES['file']) || !is_array($_FILES['file']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $error = isset($_FILES['file']['error']) ? (int) $_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
                if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { throw new RuntimeException('FILE_CHUNK_TOO_LARGE'); }
                throw new InvalidArgumentException('Choose one upload chunk.');
            }
            $temporary = (string) $_FILES['file']['tmp_name'];
            if (!is_uploaded_file($temporary)) { throw new InvalidArgumentException('The upload chunk could not be verified.'); }
            $idempotencyKey = Api::idempotencyKey();
            $_POST['idempotency_key'] = $idempotencyKey;
            $store = new ProjectFileChunkUploadStore(dirname(dirname(__DIR__)), $configuredPath);
            $received = $store->receive($access, $_POST, $temporary, (int) $_FILES['file']['size'],
                (int) $settings->get('storage.max_upload_bytes'), function ($stream, $session) use ($service, $access) {
                    if ($session['target_operation'] === 'upload') {
                        return $service->uploadStream($access, $stream, $session['original_name'], $session['folder_id'], $session['idempotency_key']);
                    }
                    return $service->replaceFileStream($access, $stream, $session['original_name'], [
                        'file_id' => $session['file_id'], 'version' => $session['version'],
                    ], $session['idempotency_key']);
                });
            if (!$received['complete']) {
                Api::json(['data' => ['upload' => $received]], 202);
            }
            $result = $received['result'];
            Api::json(['data' => $result], ($received['replayed'] || !empty($result['replayed'])) ? 200
                : ($_POST['target_operation'] === 'upload' ? 201 : 200));
        }
        (new RateLimiter($pdo))->hit('project-files.write', $projectId . ':' . $access['participant_id'], 120, 60, 60);
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

    (new RateLimiter($pdo))->hit('project-files.write', $projectId . ':' . $access['participant_id'], 120, 60, 60);
    $body = Api::body();
    $operation = isset($body['operation']) ? trim((string) $body['operation']) : '';
    if ($operation === 'create_folder') { $result = $service->createFolder($access, $body, Api::idempotencyKey()); }
    elseif ($operation === 'rename_file') { $result = $service->renameFile($access, $body, Api::idempotencyKey()); }
    elseif ($operation === 'move_file') { $result = $service->moveFile($access, $body, Api::idempotencyKey()); }
    elseif ($operation === 'delete_file') { $result = $service->deleteFile($access, $body, Api::idempotencyKey()); }
    else { throw new InvalidArgumentException('Unsupported file operation.'); }
    Api::json(['data' => $result], $result['replayed'] ? 200 : ($operation === 'create_folder' ? 201 : 200));
} catch (Exception $exception) {
    projectApiError($exception);
}

