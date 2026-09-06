<?php

class AvatarService
{
    const MAX_BYTES = 2097152;
    const MAX_DIMENSION = 4096;

    private $directory;

    public function __construct($directory = null)
    {
        $configured = getenv('SYNDICATUM_AVATAR_DIR');
        $this->directory = $directory ?: ($configured !== false && trim((string) $configured) !== ''
            ? (string) $configured
            : dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'syndicatum-avatars');
    }

    public function storeData(array $input)
    {
        $encoded = isset($input['data']) ? trim((string) $input['data']) : '';
        if (preg_match('#^data:([^;,]+);base64,(.*)$#s', $encoded, $matches)) {
            $encoded = $matches[2];
        }
        if ($encoded === '') {
            throw new InvalidArgumentException('Avatar image data is required.');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new InvalidArgumentException('Avatar image data must be valid base64.');
        }
        return $this->storeBytes($bytes);
    }

    public function storeUpload(array $upload)
    {
        if (!isset($upload['error']) || (int) $upload['error'] !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            throw new InvalidArgumentException('A valid avatar upload is required.');
        }
        if (!isset($upload['size']) || (int) $upload['size'] < 1 || (int) $upload['size'] > self::MAX_BYTES) {
            throw new InvalidArgumentException('Avatar image must be no larger than 2 MB.');
        }
        $bytes = file_get_contents($upload['tmp_name']);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read the avatar upload.');
        }
        return $this->storeBytes($bytes);
    }

    public function pathForFilename($filename)
    {
        if (!preg_match('/^[a-f0-9]{40}\.(jpg|png|webp)$/', (string) $filename)) {
            return null;
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $filename;
        return is_file($path) ? $path : null;
    }

    public function deleteIfLocal($url)
    {
        $prefix = 'api/v1/avatar.php?file=';
        $url = ltrim((string) $url, '/');
        if (strpos($url, $prefix) !== 0) {
            return;
        }
        $filename = rawurldecode(substr((string) $url, strlen($prefix)));
        $path = $this->pathForFilename($filename);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function storeBytes($bytes)
    {
        $length = strlen($bytes);
        if ($length < 1 || $length > self::MAX_BYTES) {
            throw new InvalidArgumentException('Avatar image must be no larger than 2 MB.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'syndicatum-avatar-');
        if ($temporary === false || file_put_contents($temporary, $bytes, LOCK_EX) !== $length) {
            throw new RuntimeException('Unable to validate the avatar image.');
        }
        try {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($temporary);
            $image = @getimagesize($temporary);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime]) || !$image || !isset($image['mime']) || $image['mime'] !== $mime) {
                throw new InvalidArgumentException('Avatar must be a valid JPEG, PNG, or WebP image.');
            }
            if ((int) $image[0] < 1 || (int) $image[1] < 1
                || (int) $image[0] > self::MAX_DIMENSION || (int) $image[1] > self::MAX_DIMENSION) {
                throw new InvalidArgumentException('Avatar dimensions must be between 1 and 4096 pixels.');
            }
            $decoded = @imagecreatefromstring($bytes);
            if (!$decoded) { throw new InvalidArgumentException('Avatar image could not be decoded safely.'); }
            $this->ensureDirectory();
            $filename = bin2hex($this->randomBytes(20)) . '.' . $allowed[$mime];
            $destination = $this->directory . DIRECTORY_SEPARATOR . $filename;
            imagesavealpha($decoded, true);
            $written = $mime === 'image/jpeg' ? imagejpeg($decoded, $destination, 90)
                : ($mime === 'image/png' ? imagepng($decoded, $destination, 6)
                : (function_exists('imagewebp') && imagewebp($decoded, $destination, 90)));
            imagedestroy($decoded);
            if (!$written || !is_file($destination) || filesize($destination) < 1 || filesize($destination) > self::MAX_BYTES) {
                if (is_file($destination)) { @unlink($destination); }
                throw new RuntimeException('Unable to store the avatar image.');
            }
            return 'api/v1/avatar.php?file=' . rawurlencode($filename);
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function ensureDirectory()
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the avatar storage directory.');
        }
    }

    private function randomBytes($length)
    {
        if (function_exists('random_bytes')) {
            return random_bytes($length);
        }
        $strong = false;
        $bytes = openssl_random_pseudo_bytes($length, $strong);
        if ($bytes === false || !$strong) {
            throw new RuntimeException('A cryptographically secure random source is required.');
        }
        return $bytes;
    }
}
