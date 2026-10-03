<?php

/** Validates and prepares the private root used by project file storage. */
final class ProjectFileStorage
{
    private $base;

    public function __construct($applicationRoot, $configuredBase)
    {
        $public = realpath($applicationRoot);
        if (!is_string($public) || !is_dir($public)) {
            throw new InvalidArgumentException('Application root is invalid.');
        }

        $candidate = is_string($configuredBase) ? trim($configuredBase) : '';
        $absolute = preg_match('/\A[A-Za-z]:[\\\\\/]/', $candidate) === 1
            || preg_match('/\A\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+/', $candidate) === 1
            || strpos($candidate, '/') === 0;
        if ($candidate === '' || !$absolute) {
            throw new InvalidArgumentException('Storage location must be an absolute server filesystem path.');
        }

        $ancestor = $candidate;
        while (!file_exists($ancestor)) {
            $next = dirname($ancestor);
            if ($next === $ancestor) {
                throw new RuntimeException('Storage location has no valid parent.');
            }
            $ancestor = $next;
        }
        $realAncestor = realpath($ancestor);
        if (!is_string($realAncestor) || self::inside($realAncestor, $public)) {
            throw new RuntimeException('Storage location must be outside the public application root.');
        }
        if (!is_dir($candidate) && !@mkdir($candidate, 0700, true)) {
            throw new RuntimeException('Storage location could not be created.');
        }

        $base = realpath($candidate);
        if (!is_string($base) || !is_dir($base) || is_link($candidate)
            || self::inside($base, $public) || self::inside($public, $base)) {
            throw new RuntimeException('Storage location must be separate from the public application root.');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($base) & 0077) !== 0) {
            throw new RuntimeException('Storage location must be private (0700).');
        }
        if (!is_writable($base)) {
            throw new RuntimeException('Storage location must be writable by Syndicatum.');
        }

        $this->base = $base;
    }

    public function base()
    {
        return $this->base;
    }

    private static function inside($path, $root)
    {
        $a = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $b = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        return $a === $b || strpos($a, $b . DIRECTORY_SEPARATOR) === 0;
    }
}
