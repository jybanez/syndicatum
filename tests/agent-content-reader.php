<?php

require_once dirname(__DIR__) . '/src/LocalFileStorage.php';
require_once dirname(__DIR__) . '/src/AgentContentReader.php';

final class AgentContentReaderTests
{
    private $passed = 0;
    private $failed = 0;
    public function test($name, callable $callback) { try { $callback(); $this->passed++; echo "PASS  $name\n"; } catch (Exception $e) { $this->failed++; echo "FAIL  $name: {$e->getMessage()}\n"; } }
    public function same($expected, $actual) { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }
    public function true($value, $message = 'Expected true.') { if (!$value) { throw new RuntimeException($message); } }
    public function throws(callable $callback, $message) { try { $callback(); } catch (Exception $e) { $this->same($message, $e->getMessage()); return; } throw new RuntimeException('Expected exception.'); }
    public function finish() { echo "\n{$this->passed} passed, {$this->failed} failed.\n"; return $this->failed ? 1 : 0; }
}

function agentContentRemove($path)
{
    if (!file_exists($path) && !is_link($path)) { return; }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) { if ($entry !== '.' && $entry !== '..') { agentContentRemove($path . DIRECTORY_SEPARATOR . $entry); } }
        rmdir($path); return;
    }
    unlink($path);
}

$suite = new AgentContentReaderTests();
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-agent-content-' . bin2hex(random_bytes(8));
$application = $root . DIRECTORY_SEPARATOR . 'public';
$storageRoot = $root . DIRECTORY_SEPARATOR . 'private';
mkdir($application, 0700, true);

try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE project_files (public_id TEXT, project_id INTEGER, display_name TEXT, mime_type TEXT, size_bytes INTEGER, sha256 TEXT, storage_driver TEXT, storage_key TEXT, state TEXT, deleted_at TEXT)');
    $storage = new LocalFileStorage($application, $storageRoot);
    $content = "First line\nSecond line\n";
    $stream = fopen('php://temp', 'w+b'); fwrite($stream, $content); rewind($stream);
    $staged = $storage->stageStream($stream, 1024); fclose($stream);
    $key = $storage->publish($staged['staging_key'], '3903bbfe-7ef3-4c70-af45-6bb6aa141757');
    $fileId = '89b13a72-0321-4a44-92ca-55aaf2d28710';
    $pdo->prepare('INSERT INTO project_files VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)')->execute([
        $fileId, 2, 'notes.txt', 'text/plain', strlen($content), hash('sha256', $content), 'local', $key, 'available',
    ]);
    $access = ['project_id' => 2, 'participant_id' => 9, 'project_status' => 'active'];

    $suite->test('project file reads are project-scoped, bounded, and resumable', function () use ($suite, $pdo, $storage, $access, $fileId, $content) {
        $reader = new AgentContentReader($pdo, $storage);
        $first = $reader->readProjectFile($access, $fileId, 0, 10);
        $suite->same('utf-8', $first['content_encoding']);
        $suite->same(substr($content, 0, 10), $first['content']);
        $suite->same(true, $first['truncated']);
        $suite->same(10, $first['next_offset']);
        $second = $reader->readProjectFile($access, $fileId, $first['next_offset'], 100);
        $suite->same(substr($content, 10), $second['content']);
        $suite->same(false, $second['truncated']);
        $suite->same(null, $second['next_offset']);
        $suite->same(true, $second['untrusted']);
        $suite->throws(function () use ($reader, $access, $fileId) {
            $other = $access; $other['project_id'] = 3; $reader->readProjectFile($other, $fileId);
        }, 'FILE_NOT_FOUND');
    });

    $suite->test('project image previews are bounded native-image payloads', function () use ($suite, $pdo, $storage, $access, $fileId) {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) { return; }
        $image = imagecreatetruecolor(800, 400);
        $background = imagecolorallocate($image, 26, 73, 110);
        imagefill($image, 0, 0, $background);
        ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        $stream = fopen('php://temp', 'w+b'); fwrite($stream, $png); rewind($stream);
        $staged = $storage->stageStream($stream, 1048576); fclose($stream);
        $key = $storage->publish($staged['staging_key'], '3903bbfe-7ef3-4c70-af45-6bb6aa141757');
        $imageId = '4fa89d65-eab1-45b8-817d-c55238e41946';
        $pdo->prepare('INSERT INTO project_files VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)')->execute([
            $imageId, 2, 'diagram.png', 'image/png', strlen($png), hash('sha256', $png), 'local', $key, 'available',
        ]);
        $preview = (new AgentContentReader($pdo, $storage))->previewProjectImage($access, $imageId, 320, 320, 80);
        $suite->same('image/jpeg', $preview['mime_type']);
        $suite->same(320, $preview['width']);
        $suite->same(160, $preview['height']);
        $suite->true($preview['size_bytes'] <= AgentContentReader::PREVIEW_MAX_OUTPUT_BYTES);
        $suite->true(substr(base64_decode($preview['content_base64']), 0, 2) === "\xff\xd8");
        $suite->same(true, $preview['untrusted']);
        $suite->throws(function () use ($pdo, $storage, $access, $fileId) {
            (new AgentContentReader($pdo, $storage))->previewProjectImage($access, $fileId);
        }, 'IMAGE_PREVIEW_UNSUPPORTED_TYPE');
    });

    $suite->test('project image previews reject unsafe dimensions before GD decode', function () use ($suite, $pdo, $storage, $access) {
        $ihdr = pack('NNCCCCC', 5000, 3000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n" . pack('N', strlen($ihdr)) . 'IHDR' . $ihdr
            . pack('N', crc32('IHDR' . $ihdr)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
        $stream = fopen('php://temp', 'w+b'); fwrite($stream, $png); rewind($stream);
        $staged = $storage->stageStream($stream, 1048576); fclose($stream);
        $key = $storage->publish($staged['staging_key'], '3903bbfe-7ef3-4c70-af45-6bb6aa141757');
        $imageId = '4c4094ca-bf04-40b5-ac77-2ab7f210ad67';
        $pdo->prepare('INSERT INTO project_files VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)')->execute([
            $imageId, 2, 'oversized.png', 'image/png', strlen($png), hash('sha256', $png), 'local', $key, 'available',
        ]);
        $suite->throws(function () use ($pdo, $storage, $access, $imageId) {
            (new AgentContentReader($pdo, $storage))->previewProjectImage($access, $imageId);
        }, 'IMAGE_PREVIEW_PIXEL_LIMIT');
    });

    $suite->test('public URL validation accepts HTTPS public addresses only', function () use ($suite, $pdo, $access) {
        $resolver = function ($host) { return $host === 'public.example' ? ['93.184.216.34'] : ['127.0.0.1']; };
        $reader = new AgentContentReader($pdo, null, $resolver, function () { throw new RuntimeException('Transport should not run.'); });
        $resolved = $reader->resolvePublicHttpsUrl('https://public.example/path?q=1');
        $suite->same('public.example', $resolved['host']);
        $suite->throws(function () use ($reader, $access) { $reader->readPublicUrl($access, 'http://public.example/'); }, 'URL must be an absolute HTTPS URL without credentials or a fragment.');
        $suite->throws(function () use ($reader, $access) { $reader->readPublicUrl($access, 'https://user:pass@public.example/'); }, 'URL must be an absolute HTTPS URL without credentials or a fragment.');
        $suite->throws(function () use ($reader, $access) { $reader->readPublicUrl($access, 'https://private.example/'); }, 'PUBLIC_URL_PRIVATE_ADDRESS');
    });

    $suite->test('redirect destinations are revalidated and HTTPS downgrade fails closed', function () use ($suite, $pdo, $access) {
        $resolver = function ($host) { return $host === 'one.example' ? ['93.184.216.34'] : ['10.0.0.4']; };
        $transport = function ($resolved) { return ['status' => 302, 'headers' => ['location' => 'https://private.example/secret'], 'body' => '', 'truncated' => false]; };
        $reader = new AgentContentReader($pdo, null, $resolver, $transport);
        $suite->throws(function () use ($reader, $access) { $reader->readPublicUrl($access, 'https://one.example/start'); }, 'PUBLIC_URL_PRIVATE_ADDRESS');
        $downgrade = new AgentContentReader($pdo, null, function () { return ['93.184.216.34']; }, function () {
            return ['status' => 301, 'headers' => ['location' => 'http://example.com/'], 'body' => '', 'truncated' => false];
        });
        $suite->throws(function () use ($downgrade, $access) { $downgrade->readPublicUrl($access, 'https://one.example/start'); }, 'PUBLIC_URL_HTTPS_DOWNGRADE');
    });

    $suite->test('public responses preserve status and expose text or bounded base64 bytes', function () use ($suite, $pdo, $access) {
        $resolver = function () { return ['93.184.216.34']; };
        $transport = function ($resolved, $maxBytes) {
            $body = substr("not found but readable", 0, $maxBytes);
            return ['status' => 404, 'headers' => ['content-type' => 'text/plain; charset=utf-8'], 'body' => $body, 'truncated' => $maxBytes < 22];
        };
        $reader = new AgentContentReader($pdo, null, $resolver, $transport);
        $result = $reader->readPublicUrl($access, 'https://public.example/missing', 9);
        $suite->same(404, $result['status']);
        $suite->same('not found', $result['content']);
        $suite->same(true, $result['truncated']);
        $suite->same(true, $result['untrusted']);
        $binary = new AgentContentReader($pdo, null, $resolver, function () {
            return ['status' => 200, 'headers' => ['content-type' => 'application/octet-stream'], 'body' => "\x00\x01\x02", 'truncated' => false];
        });
        $encoded = $binary->readPublicUrl($access, 'https://public.example/file.bin');
        $suite->same('base64', $encoded['content_encoding']);
        $suite->same(base64_encode("\x00\x01\x02"), $encoded['content_base64']);
    });

    $suite->test('query redirects preserve the current path and structured JSON suffixes stay textual', function () use ($suite, $pdo, $access) {
        $resolver = function () { return ['93.184.216.34']; };
        $requests = [];
        $transport = function ($resolved) use (&$requests) {
            $requests[] = $resolved['url'];
            if (count($requests) === 1) {
                return ['status' => 302, 'headers' => ['location' => '?page=2'], 'body' => '', 'truncated' => false];
            }
            return ['status' => 200, 'headers' => ['content-type' => 'application/ld+json'], 'body' => '{"ok":true}', 'truncated' => false];
        };
        $reader = new AgentContentReader($pdo, null, $resolver, $transport);
        $result = $reader->readPublicUrl($access, 'https://public.example/articles/current?page=1');
        $suite->same('https://public.example/articles/current?page=2', $requests[1]);
        $suite->same('utf-8', $result['content_encoding']);
        $suite->same('{"ok":true}', $result['content']);
    });

    $suite->test('authorized remote files download into bounded temporary streams', function () use ($suite, $pdo, $access) {
        $resolver = function () { return ['93.184.216.34']; };
        $payload = "project file\n";
        $reader = new AgentContentReader($pdo, null, $resolver, function () use ($payload) {
            return ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => $payload, 'truncated' => false];
        });
        $download = $reader->downloadPublicFile($access, 'https://files.example/authorized', 1024);
        try {
            $suite->true(is_resource($download['stream']));
            $suite->same($payload, stream_get_contents($download['stream']));
            $suite->same(strlen($payload), $download['size_bytes']);
            $suite->same(hash('sha256', $payload), $download['sha256']);
            $suite->same('text/plain', $download['mime_type']);
        } finally {
            if (is_resource($download['stream'])) { fclose($download['stream']); }
        }

        $tooLarge = new AgentContentReader($pdo, null, $resolver, function () {
            return ['status' => 200, 'headers' => ['content-type' => 'application/octet-stream'], 'body' => '1234', 'truncated' => true];
        });
        $suite->throws(function () use ($tooLarge, $access) {
            $tooLarge->downloadPublicFile($access, 'https://files.example/large', 4);
        }, 'FILE_TOO_LARGE');
        $failed = new AgentContentReader($pdo, null, $resolver, function () {
            return ['status' => 403, 'headers' => [], 'body' => '', 'truncated' => false];
        });
        $suite->throws(function () use ($failed, $access) {
            $failed->downloadPublicFile($access, 'https://files.example/expired', 1024);
        }, 'FILE_INPUT_FETCH_FAILED');
    });

    exit($suite->finish());
} finally {
    agentContentRemove($root);
}
