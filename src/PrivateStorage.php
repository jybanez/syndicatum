<?php

/** Resolves the installation's private sibling directory with legacy-host compatibility. */
final class PrivateStorage
{
    public static function base()
    {
        $configured = getenv('SYNDICATUM_PRIVATE_DIR');
        if (is_string($configured) && trim($configured) !== '') { return rtrim(trim($configured), '/\\'); }
        $applicationRoot = dirname(__DIR__);
        $cursor = $applicationRoot;
        $preferred = null;
        while (dirname($cursor) !== $cursor) {
            if (strtolower(basename($cursor)) === 'www') {
                $preferred = dirname($cursor) . DIRECTORY_SEPARATOR . '.syndicatum';
                break;
            }
            $cursor = dirname($cursor);
        }
        if ($preferred === null) { $preferred = dirname($applicationRoot) . DIRECTORY_SEPARATOR . '.syndicatum'; }
        if (is_dir($preferred)) { return $preferred; }
        $legacy = dirname($applicationRoot, 3) . DIRECTORY_SEPARATOR . 'private';
        return is_dir($legacy) ? $legacy : $preferred;
    }

    public static function file($name)
    {
        if (!is_string($name) || !preg_match('/\A[a-z0-9][a-z0-9.-]*\z/', $name)) {
            throw new InvalidArgumentException('Private storage filename is invalid.');
        }
        return self::base() . DIRECTORY_SEPARATOR . $name;
    }
}
