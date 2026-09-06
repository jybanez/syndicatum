<?php

require_once dirname(dirname(__DIR__)) . '/src/AvatarService.php';

if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) === 'GET') {
    $filename = isset($_GET['file']) ? (string) $_GET['file'] : '';
    $path = (new AvatarService())->pathForFilename($filename);
    $extensions = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($path !== null && isset($extensions[$extension])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);
        $image = @getimagesize($path);
        if ($mime === $extensions[$extension] && $image && isset($image['mime']) && $image['mime'] === $mime) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=31536000, immutable');
            readfile($path);
            exit;
        }
    }
}
http_response_code(isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET' ? 405 : 404);
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo 'Avatar not found.';
