<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';

$streamingStarted = false;
try {
    if (Api::method() !== 'GET') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']);
    }
    (new AuthService(Db::pdo()))->requireAdministrator();
    $path = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'resources'
        . DIRECTORY_SEPARATOR . 'recovery' . DIRECTORY_SEPARATOR . 'kickstart.php';
    if (!is_file($path) || is_link($path)) { throw new RuntimeException('Kickstart installer is unavailable.'); }
    $bytes = filesize($path);
    $stream = fopen($path, 'rb');
    if (!is_resource($stream) || !is_int($bytes) || $bytes < 1) { throw new RuntimeException('Kickstart installer could not be opened.'); }
    header('Content-Type: application/x-httpd-php');
    header('Content-Disposition: attachment; filename="kickstart.php"');
    header('Content-Length: ' . $bytes);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    $streamingStarted = true;
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) { throw new RuntimeException('Kickstart installer read failed.'); }
            echo $chunk;
            if (connection_aborted()) { break; }
        }
    } finally { fclose($stream); }
} catch (RuntimeException $error) {
    if ($streamingStarted) { error_log('Syndicatum Kickstart stream interrupted: ' . $error->getMessage()); exit; }
    $status = $error->getMessage() === 'AUTHENTICATION_REQUIRED' ? 401
        : ($error->getMessage() === 'ADMINISTRATOR_REQUIRED' ? 403 : 500);
    Api::json(['error' => true, 'code' => 'kickstart_unavailable',
        'message' => $status === 401 ? 'Authentication is required.'
            : ($status === 403 ? 'Administrator access is required.' : 'Kickstart installer is unavailable.')], $status);
} catch (Throwable $error) {
    error_log('Syndicatum Kickstart download failed: ' . $error->getMessage());
    if ($streamingStarted) { exit; }
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Kickstart installer is unavailable.'], 500);
}
