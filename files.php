<?php

require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/SettingsService.php';
require_once __DIR__ . '/src/LocalFileStorage.php';

function projectFileNotFound()
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "File not found.\n";
    exit;
}

try {
    if (!in_array(strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')), ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }
    $publicId = trim((string) (isset($_GET['id']) ? $_GET['id'] : ''));
    if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $publicId)) {
        projectFileNotFound();
    }
    $pdo = Db::pdo();
    if (!Db::tableExists($pdo, 'project_files')) { projectFileNotFound(); }
    $statement = $pdo->prepare(
        "SELECT display_name, mime_type, size_bytes, sha256, storage_driver, storage_key
         FROM project_files WHERE public_id = ? AND state = 'available' AND deleted_at IS NULL LIMIT 1"
    );
    $statement->execute([$publicId]);
    $file = $statement->fetch();
    if (!$file || $file['storage_driver'] !== 'local') { projectFileNotFound(); }

    $settings = new SettingsService($pdo);
    $storage = new LocalFileStorage(__DIR__, trim((string) $settings->get('storage.local_base_path')));
    if (!$storage->exists($file['storage_key'])) { projectFileNotFound(); }
    $mime = strtolower(trim((string) $file['mime_type']));
    $inline = in_array($mime, (array) $settings->get('storage.inline_preview_types'), true);
    $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file['display_name']);
    if ($asciiName === '') { $asciiName = 'download'; }
    $disposition = $inline ? 'inline' : 'attachment';
    $etag = '"' . strtolower((string) $file['sha256']) . '"';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (int) $file['size_bytes']);
    header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($asciiName, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode((string) $file['display_name']));
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=' . (int) $settings->get('storage.public_cache_max_age_seconds'));
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    if (trim((string) (isset($_SERVER['HTTP_IF_NONE_MATCH']) ? $_SERVER['HTTP_IF_NONE_MATCH'] : '')) === $etag) {
        http_response_code(304);
        exit;
    }
    if (strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) === 'HEAD') { exit; }
    $stream = $storage->openReadStream($file['storage_key']);
    while (!feof($stream)) {
        $chunk = fread($stream, 1024 * 1024);
        if ($chunk === false) { break; }
        echo $chunk;
    }
    fclose($stream);
} catch (Exception $exception) {
    error_log('Syndicatum public project file delivery failed: ' . $exception->getMessage());
    projectFileNotFound();
}
