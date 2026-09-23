<?php

/** Captures only files required by the production Syndicatum runtime. */
final class PortableBackupRuntime
{
    private $applicationRoot;

    private static $rootFiles = [
        '.htaccess','claim.php','connector-authorize.php','index.php','legal-page.php','license.php',
        'manifest.webmanifest','mcp.php','privacy.php','setup.php','support.php','terms.php',
        'LICENSE','THIRD_PARTY_NOTICES.md','VENDORED.md',
        'resources/recovery/kickstart.php',
    ];

    private static $runtimeScripts = [
        'scripts/plugin-mcp-connection-status.php','scripts/plugin-message-delivery-status.php',
        'scripts/plugin-operational-status.php','scripts/process-agent-webhooks.php',
        'scripts/process-current-backup-jobs.php','scripts/process-current-restore-jobs.php',
        'scripts/process-message-outbox.php',
        'scripts/record-delivery-worker-heartbeat.php',
    ];

    public function __construct($applicationRoot)
    {
        $real = realpath($applicationRoot);
        if (!is_string($real) || !is_dir($real) || is_link($applicationRoot)) {
            throw new InvalidArgumentException('Production runtime root is invalid.');
        }
        $this->applicationRoot = $real;
    }

    public function collect($payloadRoot, $onProgress = null)
    {
        $paths = self::$rootFiles;
        foreach (self::$runtimeScripts as $path) { $paths[] = $path; }
        foreach (['api','auth','claim','oauth','src'] as $directory) {
            foreach ($this->files($directory, ['php']) as $path) { $paths[] = $path; }
        }
        foreach ($this->files('assets', ['css','mjs','svg','png','ico','webmanifest']) as $path) {
            if (strpos($path, 'assets/brand/source/') === 0 || strpos($path, 'assets/brand/desktop/') === 0
                || strpos($path, 'assets/brand/png/') === 0 || $path === 'assets/brand/README.md') { continue; }
            $paths[] = $path;
        }
        foreach (['vendor/pbb-helper/dist/helpers.ui.bundle.min.js','vendor/pbb-helper/dist/helpers.ui.bundle.min.css'] as $path) {
            $paths[] = $path;
        }
        foreach ($this->files('vendor/pbb-realtime/js/sdk', ['js']) as $path) { $paths[] = $path; }
        $paths = array_values(array_unique($paths)); sort($paths, SORT_STRING);
        $entries = []; $total = count($paths);
        foreach ($paths as $index => $relative) {
            if (strpos('/' . $relative . '/', '/migrations/') !== false) { continue; }
            $source = $this->applicationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($source) || is_link($source)) { throw new RuntimeException('Required production runtime file is missing: ' . $relative . '.'); }
            $destinationPath = 'runtime/' . $relative;
            $destination = rtrim($payloadRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $destinationPath);
            self::copyStable($source, $destination);
            $entries[] = ['path' => $destinationPath, 'sha256' => hash_file('sha256', $destination), 'bytes' => filesize($destination)];
            if ($onProgress !== null) { call_user_func($onProgress, (int) floor((($index + 1) * 100) / max(1, $total))); }
        }
        usort($entries, function ($a, $b) { return strcmp($a['path'], $b['path']); });
        return $entries;
    }

    private function files($directory, array $extensions)
    {
        $root = $this->applicationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (!is_dir($root) || is_link($root)) { throw new RuntimeException('Required production runtime directory is missing: ' . $directory . '.'); }
        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) { continue; }
            $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (!in_array($extension, $extensions, true)) { continue; }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->applicationRoot) + 1));
            $paths[] = $relative;
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    private static function copyStable($source, $destination)
    {
        clearstatcache(true, $source); $before = @stat($source); $beforeHash = @hash_file('sha256', $source);
        if (!is_array($before) || !is_string($beforeHash)) { throw new RuntimeException('Production runtime file could not be read.'); }
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0700, true)) { throw new RuntimeException('Portable runtime directory could not be created.'); }
        $bytes = @file_get_contents($source);
        if (!is_string($bytes)) { throw new RuntimeException('Production runtime file could not be read.'); }
        self::writePrivate($destination, $bytes);
        clearstatcache(true, $source); $after = @stat($source); $afterHash = @hash_file('sha256', $source);
        if (!is_array($after) || !is_string($afterHash) || $before['size'] !== $after['size']
            || strlen($bytes) !== $before['size'] || !hash_equals($beforeHash, $afterHash)
            || !hash_equals($beforeHash, hash_file('sha256', $destination))) {
            throw new RuntimeException('Production runtime changed during capture; retry the backup.');
        }
    }

    public static function writePrivate($path, $bytes)
    {
        $stream = @fopen($path, 'xb');
        if (!is_resource($stream)) { throw new RuntimeException('Portable backup private file could not be created.'); }
        @chmod($path, 0600);
        try {
            for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
                $written = fwrite($stream, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) { throw new RuntimeException('Portable backup private file write failed.'); }
                $offset += $written;
            }
        } finally { fclose($stream); }
    }
}
