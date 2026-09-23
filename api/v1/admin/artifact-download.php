<?php

require_once __DIR__ . '/_recovery.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/CurrentBackupJobStore.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/SettingsService.php';

if (isset($_GET['ticket']) && trim((string) $_GET['ticket']) !== '') {
    try {
        recoveryRequireMethod(['GET']);
        list($pdo, $auth, $user) = recoveryAuth();
        $ticket = trim((string) $_GET['ticket']);
        $streamed = recoveryService($pdo)->streamDownloadTicket($ticket, (int) $user['id'], (int) $user['session_id'],
            function ($authorized, $stream) use ($auth, $user) {
                $auth->audit((int) $user['id'], 'artifact.download_started', 'artifact', $authorized['sha256'],
                    ['filename' => $authorized['download_name']]);
                header('Content-Type: ' . $authorized['media_type']);
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $authorized['download_name']) . '"');
                header('Content-Length: ' . filesize($authorized['artifact_path']));
                header('X-Content-Type-Options: nosniff');
                header('X-Artifact-SHA256: ' . $authorized['sha256']);
                fpassthru($stream);
            });
        if (!$streamed) {
            Api::json(['error' => true, 'code' => 'DOWNLOAD_TICKET_INVALID',
                'message' => 'The download ticket is invalid, expired, or already used.'], 404);
        }
        exit;
    } catch (Throwable $exception) {
        recoveryError($exception);
    }
}

$streamingStarted = false;
try {
    if (Api::method() !== 'GET') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']);
    }
    $auth = new AuthService(Db::pdo());
    $user = $auth->requireAdministrator();
    $token = isset($_GET['token']) ? (string) $_GET['token'] : '';
    $settings = new SettingsService(Db::pdo());
    $storage = new CurrentBackupStorage(dirname(dirname(dirname(__DIR__))), $settings->get('recovery.backup_base_path'));
    $jobs = new CurrentBackupJobStore($storage);
    $job = $jobs->consumeDownload($token, (int) $user['id']);
    if ($job === null || $job['status'] !== 'Ready') {
        Api::json(['error' => true, 'code' => 'not_found', 'message' => 'Backup download is unavailable.'], 404);
    }
    $path = $storage->artifact($job['operation_id']);
    if (!is_file($path) || is_link($path) || filesize($path) !== $job['artifact_size_bytes']
        || !hash_equals($job['artifact_sha256'], hash_file('sha256', $path))) {
        Api::json(['error' => true, 'code' => 'artifact_unavailable', 'message' => 'Backup artifact failed verification.'], 409);
    }
    $stream = @fopen($path, 'rb');
    if (!is_resource($stream)) { throw new RuntimeException('Backup artifact could not be opened.'); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . $job['artifact_size_bytes']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    $streamingStarted = true;
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false) { throw new RuntimeException('Backup artifact read failed.'); }
            echo $chunk;
            if (connection_aborted()) { break; }
        }
    } finally { fclose($stream); }
} catch (RuntimeException $error) {
    if ($streamingStarted) { error_log('Syndicatum backup stream interrupted: ' . $error->getMessage()); exit; }
    $code = $error->getMessage();
    $status = $code === 'AUTHENTICATION_REQUIRED' ? 401 : ($code === 'ADMINISTRATOR_REQUIRED' ? 403 : 500);
    Api::json(['error' => true, 'code' => 'download_unavailable',
        'message' => $status === 401 ? 'Authentication is required.'
            : ($status === 403 ? 'Administrator access is required.' : 'Backup download failed.')], $status);
} catch (Throwable $error) {
    error_log('Syndicatum backup artifact streaming failed: ' . $error->getMessage());
    if ($streamingStarted) { exit; }
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Backup download failed.'], 500);
}
