<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/FileStorage.php';
require_once __DIR__ . '/SettingsService.php';

/** Canonical project-file metadata and mutation service. Never exposes provider keys or local paths. */
final class ProjectFileService
{
    private $pdo;
    private $storage;
    private $settings;

    public function __construct(PDO $pdo, FileStorage $storage, SettingsService $settings)
    {
        $this->pdo = $pdo;
        $this->storage = $storage;
        $this->settings = $settings;
        foreach (['project_file_folders', 'project_files', 'project_file_operations', 'project_file_events'] as $table) {
            if (!Db::tableExists($pdo, $table)) {
                throw new RuntimeException('FILE_SCHEMA_NOT_INSTALLED');
            }
        }
    }

    public function browse(array $access, $folderPublicId = 'root', array $query = [])
    {
        $this->assertActiveProject($access);
        $projectId = (int) $access['project_id'];
        $listing = $this->listingQuery($query);
        $folder = $this->folderByPublicId($projectId, $folderPublicId, false);
        if ($folder === null) {
            if ($folderPublicId !== '' && $folderPublicId !== 'root') {
                throw new RuntimeException('FILE_FOLDER_NOT_FOUND');
            }
            return $this->emptyBrowse($access, $listing);
        }

        $folders = $this->pdo->prepare(
            'SELECT public_id, name, version, created_at, updated_at FROM project_file_folders
             WHERE project_id = ? AND parent_folder_id = ? AND deleted_at IS NULL ORDER BY normalized_name, id'
        );
        $folders->execute([$projectId, (int) $folder['id']]);
        $uploaderName = "COALESCE(u.display_name, pa.display_name, ic.display_name, 'Unknown participant')";
        $where = "pf.project_id = ? AND pf.folder_id = ? AND pf.deleted_at IS NULL AND pf.state <> 'deleted'";
        $parameters = [$projectId, (int) $folder['id']];
        if ($listing['search'] !== '') {
            $where .= " AND (pf.display_name LIKE ? ESCAPE '=' OR pf.original_name LIKE ? ESCAPE '=' OR pf.mime_type LIKE ? ESCAPE '=' OR " . $uploaderName . " LIKE ? ESCAPE '=')";
            $needle = '%' . strtr($listing['search'], ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
            array_push($parameters, $needle, $needle, $needle, $needle);
        }
        $joins = " FROM project_files pf
            LEFT JOIN project_participants pp ON pp.id = pf.uploaded_by_participant_id AND pp.project_id = pf.project_id
            LEFT JOIN users u ON u.id = pp.user_id
            LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
            LEFT JOIN integration_connections ic ON ic.project_id = pp.project_id AND ic.id = pp.integration_id";
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $joins . ' WHERE ' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $listing['per_page']));
        $page = min($listing['page'], $totalPages);
        $offset = ($page - 1) * $listing['per_page'];
        $order = [
            'name' => 'pf.normalized_name', 'type' => 'pf.mime_type', 'size' => 'pf.size_bytes',
            'uploader' => 'uploader_name', 'created' => 'pf.created_at', 'updated' => 'pf.updated_at', 'state' => 'pf.state',
        ][$listing['sort']];
        $files = $this->pdo->prepare(
            'SELECT pf.public_id, pf.display_name, pf.original_name, pf.mime_type, pf.size_bytes, pf.sha256, pf.state, pf.version,
                    pf.created_at, pf.updated_at, pp.id AS uploader_participant_id, pp.kind AS uploader_kind, ' . $uploaderName . ' AS uploader_name'
            . $joins . ' WHERE ' . $where . ' ORDER BY ' . $order . ' ' . strtoupper($listing['direction']) . ', pf.id ' . strtoupper($listing['direction'])
            . ' LIMIT ' . (int) $listing['per_page'] . ' OFFSET ' . (int) $offset
        );
        $files->execute($parameters);

        return [
            'project_id' => $projectId,
            'root' => $this->folderTree($projectId, (int) $folder['id']),
            'current_folder' => $this->folderView($folder),
            'breadcrumbs' => $this->folderBreadcrumbs($projectId, $folder),
            'folders' => array_map([$this, 'folderView'], $folders->fetchAll()),
            'files' => array_map([$this, 'fileView'], $files->fetchAll()),
            'pagination' => [
                'page' => $page, 'per_page' => $listing['per_page'], 'total' => $total, 'total_pages' => $totalPages,
                'search' => $listing['search'], 'sort' => $listing['sort'], 'direction' => $listing['direction'],
                'has_more' => $page < $totalPages,
            ],
            'capabilities' => $this->capabilities(),
            'storage' => $this->storageSummary($projectId),
            'notice' => '',
        ];
    }

    public function createFolder(array $access, array $input, $idempotencyKey)
    {
        $this->assertActiveProject($access);
        $name = $this->validName(isset($input['name']) ? $input['name'] : '', 'Folder name');
        $parentPublicId = trim((string) (isset($input['parent_folder_id']) ? $input['parent_folder_id'] : 'root'));
        $fingerprint = $this->fingerprint('create_folder', [$parentPublicId, $name]);
        $this->beginWrite($access);
        try {
            $replay = $this->reserveOperation($access, $idempotencyKey, $fingerprint, 'create_folder');
            if ($replay !== null) {
                $this->pdo->commit();
                return $replay;
            }
            $parent = $this->resolveFolderForWrite($access, $parentPublicId);
            $duplicate = $this->pdo->prepare(
                'SELECT id FROM project_file_folders WHERE project_id = ? AND parent_folder_id = ? AND normalized_name = ? AND deleted_at IS NULL LIMIT 1'
            );
            $duplicate->execute([(int) $access['project_id'], (int) $parent['id'], $this->normalizeName($name)]);
            if ($duplicate->fetch()) {
                throw new RuntimeException('FILE_NAME_CONFLICT');
            }
            $publicId = Db::uuidV4();
            $now = Db::now();
            $insert = $this->pdo->prepare(
                'INSERT INTO project_file_folders
                 (public_id, project_id, parent_folder_id, name, normalized_name, root_marker, created_by_participant_id, version, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, 1, ?, ?)'
            );
            $insert->execute([$publicId, (int) $access['project_id'], (int) $parent['id'], $name,
                $this->normalizeName($name), (int) $access['participant_id'], $now, $now]);
            $folderId = (int) $this->pdo->lastInsertId();
            $folder = ['public_id' => $publicId, 'name' => $name, 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
            $result = ['folder' => $this->folderView($folder), 'replayed' => false];
            $this->event($access, 'folder.created', null, $folderId, ['name' => $name, 'parent_folder_id' => $this->publicFolderId($parent)]);
            $this->finishOperation($access, $idempotencyKey, $result, null, $folderId);
            $this->pdo->commit();
            return $result;
        } catch (Exception $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function uploadStream(array $access, $stream, $originalName, $folderPublicId, $idempotencyKey)
    {
        $this->assertActiveProject($access);
        $originalName = $this->validName($originalName, 'File name');
        $folderPublicId = trim((string) $folderPublicId);
        if ($folderPublicId === '') { $folderPublicId = 'root'; }
        $staged = $this->storage->stageStream($stream, (int) $this->settings->get('storage.max_upload_bytes'));
        try {
            $mime = $this->storage->detectMimeType($staged['staging_key']);
            $this->assertAllowedMime($mime);
            $fingerprint = $this->fingerprint('upload', [$folderPublicId, $originalName, (int) $staged['size_bytes'], $staged['sha256']]);
            $this->beginWrite($access);
            $publishedKey = null;
            try {
                $replay = $this->reserveOperation($access, $idempotencyKey, $fingerprint, 'upload');
                if ($replay !== null) {
                    $this->pdo->commit();
                    $this->storage->discard($staged['staging_key']);
                    return $replay;
                }
                $folder = $this->resolveFolderForWrite($access, $folderPublicId);
                $duplicate = $this->pdo->prepare(
                    "SELECT id FROM project_files WHERE project_id = ? AND folder_id = ? AND normalized_name = ? AND deleted_at IS NULL AND state = 'available' LIMIT 1"
                );
                $duplicate->execute([(int) $access['project_id'], (int) $folder['id'], $this->normalizeName($originalName)]);
                if ($duplicate->fetch()) { throw new RuntimeException('FILE_NAME_CONFLICT'); }
                $this->assertQuota((int) $access['project_id'], (int) $staged['size_bytes'], 0);
                $publishedKey = $this->storage->publish($staged['staging_key'], $this->projectStorageNamespace((int) $access['project_id']));
                $publicId = Db::uuidV4();
                $now = Db::now();
                $insert = $this->pdo->prepare(
                    "INSERT INTO project_files
                     (public_id, project_id, folder_id, storage_driver, storage_key, original_name, display_name, normalized_name,
                      mime_type, size_bytes, sha256, uploaded_by_participant_id, state, version, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available', 1, ?, ?)"
                );
                $insert->execute([$publicId, (int) $access['project_id'], (int) $folder['id'], $this->storage->driver(), $publishedKey,
                    $originalName, $originalName, $this->normalizeName($originalName), $mime, (int) $staged['size_bytes'],
                    $staged['sha256'], (int) $access['participant_id'], $now, $now]);
                $fileId = (int) $this->pdo->lastInsertId();
                $row = ['public_id' => $publicId, 'display_name' => $originalName, 'original_name' => $originalName,
                    'mime_type' => $mime, 'size_bytes' => (int) $staged['size_bytes'], 'sha256' => $staged['sha256'],
                    'state' => 'available', 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
                $result = ['file' => $this->fileView($row), 'replayed' => false];
                $this->event($access, 'file.uploaded', $fileId, (int) $folder['id'], ['name' => $originalName, 'mime_type' => $mime,
                    'size_bytes' => (int) $staged['size_bytes'], 'sha256' => $staged['sha256']]);
                $this->finishOperation($access, $idempotencyKey, $result, $fileId, (int) $folder['id']);
                $this->pdo->commit();
                return $result;
            } catch (Exception $exception) {
                $this->rollBack();
                if (is_string($publishedKey) && !$this->storage->delete($publishedKey)) {
                    error_log('Syndicatum could not compensate an unpublished project file object.');
                }
                throw $exception;
            }
        } catch (Exception $exception) {
            $this->storage->discard($staged['staging_key']);
            throw $exception;
        }
    }

    public function renameFile(array $access, array $input, $idempotencyKey)
    {
        $name = $this->validName(isset($input['name']) ? $input['name'] : '', 'File name');
        return $this->mutateFile($access, $input, $idempotencyKey, 'rename_file', function ($file) use ($name, $access) {
            $duplicate = $this->pdo->prepare(
                "SELECT id FROM project_files WHERE project_id = ? AND folder_id = ? AND normalized_name = ? AND id <> ? AND deleted_at IS NULL AND state = 'available' LIMIT 1"
            );
            $duplicate->execute([(int) $access['project_id'], (int) $file['folder_id'], $this->normalizeName($name), (int) $file['id']]);
            if ($duplicate->fetch()) { throw new RuntimeException('FILE_NAME_CONFLICT'); }
            $update = $this->pdo->prepare(
                'UPDATE project_files SET display_name = ?, normalized_name = ?, version = version + 1, updated_at = ?
                 WHERE id = ? AND version = ? AND state = ? AND deleted_at IS NULL'
            );
            $update->execute([$name, $this->normalizeName($name), Db::now(), (int) $file['id'], (int) $file['version'], 'available']);
            if ($update->rowCount() !== 1) { throw new RuntimeException('FILE_VERSION_CONFLICT'); }
            $this->event($access, 'file.renamed', (int) $file['id'], (int) $file['folder_id'], ['from' => $file['display_name'], 'to' => $name]);
        });
    }

    public function moveFile(array $access, array $input, $idempotencyKey)
    {
        $destinationPublicId = trim((string) (isset($input['destination_folder_id']) ? $input['destination_folder_id'] : ''));
        if ($destinationPublicId === '') { throw new InvalidArgumentException('Destination folder — required.'); }
        return $this->mutateFile($access, $input, $idempotencyKey, 'move_file', function ($file) use ($access, $destinationPublicId) {
            $destination = $this->resolveFolderForWrite($access, $destinationPublicId);
            if ((int) $destination['id'] === (int) $file['folder_id']) { throw new RuntimeException('FILE_MOVE_SAME_FOLDER'); }
            $duplicate = $this->pdo->prepare(
                "SELECT id FROM project_files WHERE project_id = ? AND folder_id = ? AND normalized_name = ? AND deleted_at IS NULL AND state = 'available' LIMIT 1"
            );
            $duplicate->execute([(int) $access['project_id'], (int) $destination['id'], $file['normalized_name']]);
            if ($duplicate->fetch()) { throw new RuntimeException('FILE_NAME_CONFLICT'); }
            $update = $this->pdo->prepare(
                "UPDATE project_files SET folder_id = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ? AND state = 'available' AND deleted_at IS NULL"
            );
            $update->execute([(int) $destination['id'], Db::now(), (int) $file['id'], (int) $file['version']]);
            if ($update->rowCount() !== 1) { throw new RuntimeException('FILE_VERSION_CONFLICT'); }
            $this->event($access, 'file.moved', (int) $file['id'], (int) $destination['id'], [
                'from_folder_id' => (int) $file['folder_id'], 'to_folder_id' => (int) $destination['id'], 'name' => $file['display_name'],
            ]);
        });
    }

    public function deleteFile(array $access, array $input, $idempotencyKey)
    {
        $storageKey = null;
        $result = $this->mutateFile($access, $input, $idempotencyKey, 'delete_file', function ($file) use ($access, &$storageKey) {
            $storageKey = $file['storage_key'];
            $now = Db::now();
            $update = $this->pdo->prepare(
                "UPDATE project_files SET state = 'deleted', deleted_at = ?, version = version + 1, updated_at = ?
                 WHERE id = ? AND version = ? AND state = 'available' AND deleted_at IS NULL"
            );
            $update->execute([$now, $now, (int) $file['id'], (int) $file['version']]);
            if ($update->rowCount() !== 1) { throw new RuntimeException('FILE_VERSION_CONFLICT'); }
            $this->event($access, 'file.deleted', (int) $file['id'], (int) $file['folder_id'], ['name' => $file['display_name'], 'sha256' => $file['sha256']]);
        });
        if (is_string($storageKey)) { $this->deleteObjectBestEffort($storageKey, 'deleted'); }
        return $result;
    }

    public function replaceFileStream(array $access, $stream, $originalName, array $input, $idempotencyKey)
    {
        $this->assertActiveProject($access);
        $originalName = $this->validName($originalName, 'File name');
        $publicId = trim((string) (isset($input['file_id']) ? $input['file_id'] : ''));
        $version = isset($input['version']) && ctype_digit((string) $input['version']) ? (int) $input['version'] : 0;
        if (!preg_match('/\A[0-9a-f-]{36}\z/i', $publicId) || $version < 1) {
            throw new InvalidArgumentException('A valid file_id and version are required.');
        }
        $staged = $this->storage->stageStream($stream, (int) $this->settings->get('storage.max_upload_bytes'));
        $publishedKey = null;
        try {
            $mime = $this->storage->detectMimeType($staged['staging_key']);
            $this->assertAllowedMime($mime);
            $fingerprint = $this->fingerprint('replace_file', [$publicId, $version, $originalName, (int) $staged['size_bytes'], $staged['sha256']]);
            $this->beginWrite($access);
            try {
                $replay = $this->reserveOperation($access, $idempotencyKey, $fingerprint, 'replace_file');
                if ($replay !== null) {
                    $this->pdo->commit();
                    $this->storage->discard($staged['staging_key']);
                    return $replay;
                }
                $select = $this->pdo->prepare('SELECT * FROM project_files WHERE project_id = ? AND public_id = ? LIMIT 1 FOR UPDATE');
                $select->execute([(int) $access['project_id'], $publicId]);
                $file = $select->fetch();
                if (!$file || $file['deleted_at'] !== null || $file['state'] !== 'available') { throw new RuntimeException('FILE_NOT_FOUND'); }
                if ((int) $file['version'] !== $version) { throw new RuntimeException('FILE_VERSION_CONFLICT'); }
                $this->assertQuota((int) $access['project_id'], (int) $staged['size_bytes'], (int) $file['size_bytes']);
                $publishedKey = $this->storage->publish($staged['staging_key'], $this->projectStorageNamespace((int) $access['project_id']));
                $now = Db::now();
                $update = $this->pdo->prepare(
                    'UPDATE project_files SET storage_driver = ?, storage_key = ?, original_name = ?, mime_type = ?, size_bytes = ?, sha256 = ?,
                     uploaded_by_participant_id = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?'
                );
                $update->execute([$this->storage->driver(), $publishedKey, $originalName, $mime, (int) $staged['size_bytes'], $staged['sha256'],
                    (int) $access['participant_id'], $now, (int) $file['id'], $version]);
                if ($update->rowCount() !== 1) { throw new RuntimeException('FILE_VERSION_CONFLICT'); }
                $this->event($access, 'file.replaced', (int) $file['id'], (int) $file['folder_id'], [
                    'previous_sha256' => $file['sha256'], 'sha256' => $staged['sha256'], 'mime_type' => $mime, 'size_bytes' => (int) $staged['size_bytes']]);
                $reload = $this->pdo->prepare(
                    'SELECT public_id, display_name, original_name, mime_type, size_bytes, sha256, state, version, created_at, updated_at FROM project_files WHERE id = ?'
                );
                $reload->execute([(int) $file['id']]);
                $result = ['file' => $this->fileView($reload->fetch()), 'replayed' => false];
                $this->finishOperation($access, $idempotencyKey, $result, (int) $file['id'], (int) $file['folder_id']);
                $this->pdo->commit();
                $publishedKey = null;
                $this->deleteObjectBestEffort($file['storage_key'], 'replaced');
                return $result;
            } catch (Exception $exception) {
                $this->rollBack();
                if (is_string($publishedKey)) { $this->storage->delete($publishedKey); }
                throw $exception;
            }
        } catch (Exception $exception) {
            $this->storage->discard($staged['staging_key']);
            throw $exception;
        }
    }

    private function mutateFile(array $access, array $input, $idempotencyKey, $action, callable $mutation)
    {
        $this->assertActiveProject($access);
        $publicId = trim((string) (isset($input['file_id']) ? $input['file_id'] : ''));
        $version = isset($input['version']) && ctype_digit((string) $input['version']) ? (int) $input['version'] : 0;
        if (!preg_match('/\A[0-9a-f-]{36}\z/i', $publicId) || $version < 1) {
            throw new InvalidArgumentException('A valid file_id and version are required.');
        }
        $fingerprintInput = $input;
        ksort($fingerprintInput);
        $fingerprint = $this->fingerprint($action, $fingerprintInput);
        $this->beginWrite($access);
        try {
            $replay = $this->reserveOperation($access, $idempotencyKey, $fingerprint, $action);
            if ($replay !== null) { $this->pdo->commit(); return $replay; }
            $statement = $this->pdo->prepare('SELECT * FROM project_files WHERE project_id = ? AND public_id = ? LIMIT 1 FOR UPDATE');
            $statement->execute([(int) $access['project_id'], $publicId]);
            $file = $statement->fetch();
            if (!$file || $file['deleted_at'] !== null || $file['state'] === 'deleted') { throw new RuntimeException('FILE_NOT_FOUND'); }
            if ((int) $file['version'] !== $version) { throw new RuntimeException('FILE_VERSION_CONFLICT'); }
            $mutation($file);
            $reload = $this->pdo->prepare(
                'SELECT public_id, folder_id, display_name, original_name, mime_type, size_bytes, sha256, state, version, created_at, updated_at
                 FROM project_files WHERE id = ?'
            );
            $reload->execute([(int) $file['id']]);
            $reloaded = $reload->fetch();
            $result = ['file' => $this->fileView($reloaded), 'replayed' => false];
            $this->finishOperation($access, $idempotencyKey, $result, (int) $file['id'], (int) $reloaded['folder_id']);
            $this->pdo->commit();
            return $result;
        } catch (Exception $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    private function beginWrite(array $access)
    {
        $this->pdo->beginTransaction();
        $lock = $this->pdo->prepare('SELECT id FROM projects WHERE id = ? FOR UPDATE');
        $lock->execute([(int) $access['project_id']]);
        if (!$lock->fetch()) { throw new RuntimeException('PROJECT_NOT_FOUND'); }
    }

    private function reserveOperation(array $access, $key, $fingerprint, $action)
    {
        $this->assertIdempotencyKey($key);
        $select = $this->pdo->prepare(
            'SELECT request_fingerprint, action, response_json FROM project_file_operations
             WHERE project_id = ? AND actor_participant_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE'
        );
        $select->execute([(int) $access['project_id'], (int) $access['participant_id'], $key]);
        $existing = $select->fetch();
        if ($existing) {
            if (!hash_equals($existing['request_fingerprint'], $fingerprint) || $existing['action'] !== $action) {
                throw new RuntimeException('FILE_IDEMPOTENCY_CONFLICT');
            }
            $response = json_decode($existing['response_json'], true);
            if (!is_array($response) || empty($response)) { throw new RuntimeException('FILE_OPERATION_IN_PROGRESS'); }
            $response['replayed'] = true;
            return $response;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO project_file_operations
             (project_id, actor_participant_id, idempotency_key, request_fingerprint, action, response_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([(int) $access['project_id'], (int) $access['participant_id'], $key, $fingerprint, $action, '{}', Db::now()]);
        return null;
    }

    private function finishOperation(array $access, $key, array $result, $fileId, $folderId)
    {
        $update = $this->pdo->prepare(
            'UPDATE project_file_operations SET file_id = ?, folder_id = ?, response_json = ?
             WHERE project_id = ? AND actor_participant_id = ? AND idempotency_key = ?'
        );
        $update->execute([$fileId, $folderId, json_encode($result, JSON_UNESCAPED_SLASHES),
            (int) $access['project_id'], (int) $access['participant_id'], $key]);
    }

    private function resolveFolderForWrite(array $access, $publicId)
    {
        $projectId = (int) $access['project_id'];
        if ($publicId === '' || $publicId === 'root') {
            $root = $this->folderByPublicId($projectId, 'root', true);
            if ($root) { return $root; }
            $now = Db::now();
            $insert = $this->pdo->prepare(
                'INSERT INTO project_file_folders
                 (public_id, project_id, parent_folder_id, name, normalized_name, root_marker, created_by_participant_id, version, created_at, updated_at)
                 VALUES (?, ?, NULL, ?, ?, 1, ?, 1, ?, ?)'
            );
            $insert->execute([Db::uuidV4(), $projectId, (string) $access['project_name'], $this->normalizeName($access['project_name']),
                (int) $access['participant_id'], $now, $now]);
            return $this->folderByPublicId($projectId, 'root', true);
        }
        $folder = $this->folderByPublicId($projectId, $publicId, true);
        if (!$folder) { throw new RuntimeException('FILE_FOLDER_NOT_FOUND'); }
        return $folder;
    }

    private function folderByPublicId($projectId, $publicId, $forUpdate)
    {
        $sql = $publicId === '' || $publicId === 'root'
            ? 'SELECT * FROM project_file_folders WHERE project_id = ? AND root_marker = 1 AND deleted_at IS NULL LIMIT 1'
            : 'SELECT * FROM project_file_folders WHERE project_id = ? AND public_id = ? AND deleted_at IS NULL LIMIT 1';
        if ($forUpdate) { $sql .= ' FOR UPDATE'; }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($publicId === '' || $publicId === 'root' ? [$projectId] : [$projectId, $publicId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function folderTree($projectId, $currentId)
    {
        $root = $this->folderByPublicId($projectId, 'root', false);
        return $this->folderTreeNode($projectId, $root, $currentId);
    }

    private function folderTreeNode($projectId, array $folder, $currentId)
    {
        $children = $this->pdo->prepare(
            'SELECT * FROM project_file_folders WHERE project_id = ? AND parent_folder_id = ? AND deleted_at IS NULL ORDER BY normalized_name, id'
        );
        $children->execute([$projectId, (int) $folder['id']]);
        $nodes = [];
        foreach ($children->fetchAll() as $child) { $nodes[] = $this->folderTreeNode($projectId, $child, $currentId); }
        return ['id' => $this->publicFolderId($folder), 'label' => $folder['name'], 'selected' => (int) $folder['id'] === (int) $currentId,
            'hasChildren' => !empty($nodes), 'children' => $nodes];
    }

    private function folderBreadcrumbs($projectId, array $folder)
    {
        $breadcrumbs = [];
        $cursor = $folder;
        while ($cursor) {
            array_unshift($breadcrumbs, $this->folderView($cursor));
            if (empty($cursor['parent_folder_id'])) { break; }
            $statement = $this->pdo->prepare(
                'SELECT * FROM project_file_folders WHERE project_id = ? AND id = ? AND deleted_at IS NULL LIMIT 1'
            );
            $statement->execute([(int) $projectId, (int) $cursor['parent_folder_id']]);
            $cursor = $statement->fetch() ?: null;
        }
        return $breadcrumbs;
    }

    private function emptyBrowse(array $access, array $listing = null)
    {
        $projectId = (int) $access['project_id'];
        $listing = $listing ?: $this->listingQuery([]);
        return ['project_id' => $projectId,
            'root' => ['id' => 'root', 'label' => (string) $access['project_name'], 'selected' => true, 'hasChildren' => false, 'children' => []],
            'current_folder' => ['id' => 'root', 'name' => (string) $access['project_name'], 'version' => 0],
            'breadcrumbs' => [['id' => 'root', 'name' => (string) $access['project_name'], 'version' => 0]],
            'folders' => [], 'files' => [],
            'pagination' => ['page' => 1, 'per_page' => $listing['per_page'], 'total' => 0, 'total_pages' => 1,
                'search' => $listing['search'], 'sort' => $listing['sort'], 'direction' => $listing['direction'], 'has_more' => false],
            'capabilities' => $this->capabilities(), 'storage' => $this->storageSummary($projectId), 'notice' => ''];
    }

    private function folderView($row)
    {
        return ['id' => isset($row['root_marker']) && (int) $row['root_marker'] === 1 ? 'root' : $row['public_id'],
            'name' => $row['name'], 'version' => (int) $row['version'], 'created_at' => isset($row['created_at']) ? $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? $row['updated_at'] : null];
    }

    private function fileView($row)
    {
        $uploader = isset($row['uploader_participant_id']) && $row['uploader_participant_id'] !== null
            ? ['participant_id' => (int) $row['uploader_participant_id'], 'kind' => $row['uploader_kind'], 'display_name' => $row['uploader_name']]
            : null;
        return ['id' => $row['public_id'], 'name' => $row['display_name'], 'original_name' => $row['original_name'],
            'url' => 'files/' . $row['public_id'],
            'mime_type' => $row['mime_type'], 'size_bytes' => (int) $row['size_bytes'], 'sha256' => $row['sha256'],
            'state' => $row['state'], 'available' => $row['state'] === 'available', 'uploader' => $uploader,
            'uploader_name' => $uploader ? $uploader['display_name'] : 'Unknown participant',
            'version' => (int) $row['version'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }

    private function listingQuery(array $query)
    {
        $pageValue = isset($query['page']) ? (string) $query['page'] : '1';
        $perPageValue = isset($query['per_page']) ? (string) $query['per_page'] : '20';
        if (!ctype_digit($pageValue)) { throw new InvalidArgumentException('Page must be between 1 and 1000000.'); }
        if (!ctype_digit($perPageValue)) { throw new InvalidArgumentException('Per page must be between 1 and 100.'); }
        $page = (int) $pageValue;
        $perPage = (int) $perPageValue;
        $search = trim((string) (isset($query['search']) ? $query['search'] : ''));
        $sort = trim((string) (isset($query['sort']) ? $query['sort'] : 'name'));
        $direction = strtolower(trim((string) (isset($query['direction']) ? $query['direction'] : 'asc')));
        if ($page < 1 || $page > 1000000) { throw new InvalidArgumentException('Page must be between 1 and 1000000.'); }
        if ($perPage < 1 || $perPage > 100) { throw new InvalidArgumentException('Per page must be between 1 and 100.'); }
        if (strlen($search) > 100 || preg_match('/[\x00-\x1f\x7f]/', $search)) {
            throw new InvalidArgumentException('Search must be at most 100 characters without control characters.');
        }
        if (!in_array($sort, ['name', 'type', 'size', 'uploader', 'created', 'updated', 'state'], true)) {
            throw new InvalidArgumentException('Sort must be name, type, size, uploader, created, updated, or state.');
        }
        if (!in_array($direction, ['asc', 'desc'], true)) { throw new InvalidArgumentException('Direction must be asc or desc.'); }
        return ['page' => $page, 'per_page' => $perPage, 'search' => $search, 'sort' => $sort, 'direction' => $direction];
    }

    private function event(array $access, $action, $fileId, $folderId, array $metadata)
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO project_file_events
             (public_id, project_id, actor_participant_id, file_id, folder_id, action, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([Db::uuidV4(), (int) $access['project_id'], (int) $access['participant_id'], $fileId, $folderId,
            $action, json_encode($metadata, JSON_UNESCAPED_SLASHES), Db::now()]);
    }

    private function storageSummary($projectId)
    {
        $statement = $this->pdo->prepare("SELECT COALESCE(SUM(size_bytes), 0) FROM project_files WHERE project_id = ? AND state = 'available' AND deleted_at IS NULL");
        $statement->execute([$projectId]);
        return ['configured' => true, 'ready' => true, 'metadata_ready' => true, 'driver' => $this->storage->driver(),
            'used_bytes' => (int) $statement->fetchColumn(), 'max_upload_bytes' => (int) $this->settings->get('storage.max_upload_bytes'),
            'max_files_per_action' => (int) $this->settings->get('storage.max_files_per_action'),
            'project_quota_bytes' => (int) $this->settings->get('storage.default_project_quota_bytes')];
    }

    private function capabilities() { return ['create_folder' => true, 'upload' => true, 'rename' => true, 'move' => true, 'copy_link' => true, 'download' => true, 'delete' => true]; }

    private function assertQuota($projectId, $incomingBytes, $replacedBytes)
    {
        $statement = $this->pdo->prepare("SELECT COALESCE(SUM(size_bytes), 0) FROM project_files WHERE project_id = ? AND state = 'available' AND deleted_at IS NULL");
        $statement->execute([$projectId]);
        if ((int) $statement->fetchColumn() - $replacedBytes + $incomingBytes > (int) $this->settings->get('storage.default_project_quota_bytes')) {
            throw new RuntimeException('FILE_PROJECT_QUOTA_EXCEEDED');
        }
    }

    private function assertAllowedMime($mime)
    {
        if (!in_array($mime, (array) $this->settings->get('storage.allowed_content_types'), true)) {
            throw new RuntimeException('FILE_TYPE_NOT_ALLOWED');
        }
    }

    private function validName($value, $label)
    {
        $name = trim((string) $value);
        if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 255 || preg_match('~[\x00-\x1f\x7f/\\\\]~', $name)) {
            throw new InvalidArgumentException($label . ' must be 1–255 characters and cannot contain slashes or control characters.');
        }
        return $name;
    }

    private function normalizeName($name) { return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name); }
    private function publicFolderId(array $folder) { return isset($folder['root_marker']) && (int) $folder['root_marker'] === 1 ? 'root' : $folder['public_id']; }
    private function fingerprint($action, $value) { return hash('sha256', $action . "\0" . json_encode($value, JSON_UNESCAPED_SLASHES)); }

    private function assertIdempotencyKey($key)
    {
        $length = strlen((string) $key);
        if ($length < 16 || $length > 160 || preg_match('/[\x00-\x1f\x7f]/', (string) $key)) {
            throw new InvalidArgumentException('Idempotency-Key must contain 16–160 printable characters.');
        }
    }

    private function assertActiveProject(array $access)
    {
        if (!isset($access['participant_status']) || $access['participant_status'] !== 'active') { throw new RuntimeException('PROJECT_NOT_FOUND'); }
        if (isset($access['project_status']) && $access['project_status'] === 'archived') { throw new RuntimeException('PROJECT_ARCHIVED'); }
    }

    private function projectStorageNamespace($projectId)
    {
        $statement = $this->pdo->prepare('SELECT public_id FROM projects WHERE id = ? LIMIT 1');
        $statement->execute([(int) $projectId]);
        $publicId = $statement->fetchColumn();
        if (!is_string($publicId) || $publicId === '') { throw new RuntimeException('PROJECT_NOT_FOUND'); }
        return $publicId;
    }

    private function deleteObjectBestEffort($storageKey, $context)
    {
        try {
            if (!$this->storage->delete($storageKey)) {
                error_log('Syndicatum retained an orphaned ' . $context . ' project file object for later reconciliation.');
            }
        } catch (Exception $exception) {
            error_log('Syndicatum could not remove an orphaned ' . $context . ' project file object: ' . $exception->getMessage());
        }
    }

    private function rollBack() { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }
}
