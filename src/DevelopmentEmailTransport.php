<?php

require_once __DIR__ . '/PrivateStorage.php';

final class DevelopmentEmailTransport
{
    private $directory;

    public function __construct($directory = null)
    {
        $configured = is_string($directory) && trim($directory) !== ''
            ? trim($directory)
            : getenv('SYNDICATUM_MAIL_CAPTURE_DIR');
        $this->directory = is_string($configured) && trim($configured) !== ''
            ? rtrim(trim($configured), '/\\')
            : PrivateStorage::base() . DIRECTORY_SEPARATOR . 'mail-captures';
    }

    public function send(array $envelope, array $message)
    {
        $this->ensureDirectory();
        $captureId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8));
        $alternativeBoundary = 'syndicatum-alternative-' . bin2hex(random_bytes(12));
        $relatedBoundary = 'syndicatum-related-' . bin2hex(random_bytes(12));
        $brandContentId = 'syndicatum-brand-' . bin2hex(random_bytes(6)) . '@local';
        $brandBase64 = $this->brandImageBase64();
        $brandLogoUrl = isset($message['brand_logo_url']) ? (string) $message['brand_logo_url'] : '';
        if ($brandLogoUrl === '' || strpos($message['html'], $brandLogoUrl) === false) {
            throw new RuntimeException('Development email brand image reference is missing.');
        }
        $emailHtml = str_replace($brandLogoUrl, 'cid:' . $brandContentId, $message['html']);
        $previewHtml = str_replace($brandLogoUrl, 'data:image/png;base64,' . $brandBase64, $message['html']);
        $headers = [
            'Date: ' . gmdate(DATE_RFC2822),
            'Message-ID: <' . $captureId . '@syndicatum.local>',
            'To: ' . $this->mailbox($envelope['to_email'], $envelope['to_name']),
            'From: ' . $this->mailbox($envelope['from_email'], $envelope['from_name']),
        ];
        if (!empty($envelope['reply_to'])) { $headers[] = 'Reply-To: ' . $this->cleanHeader($envelope['reply_to']); }
        $headers[] = 'Subject: ' . $this->cleanHeader($message['subject']);
        $headers[] = 'X-Syndicatum-Transport: development-capture';
        $headers[] = 'X-Syndicatum-Template: ' . $this->cleanHeader($message['template']) . '; version=' . (int) $message['template_version'];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . '"';
        $body = '--' . $alternativeBoundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . str_replace("\n", "\r\n", $message['text']) . "\r\n--" . $alternativeBoundary
            . "\r\nContent-Type: multipart/related; boundary=\"" . $relatedBoundary . "\"\r\n\r\n"
            . '--' . $relatedBoundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . str_replace("\n", "\r\n", $emailHtml) . "\r\n--" . $relatedBoundary
            . "\r\nContent-Type: image/png; name=\"syndicatum-128.png\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <" . $brandContentId . ">\r\nContent-Disposition: inline; filename=\"syndicatum-128.png\"\r\n\r\n"
            . chunk_split($brandBase64, 76, "\r\n")
            . '--' . $relatedBoundary . "--\r\n--" . $alternativeBoundary . "--\r\n";
        $contents = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $destination = $this->directory . DIRECTORY_SEPARATOR . $captureId . '.eml';
        $preview = $this->directory . DIRECTORY_SEPARATOR . $captureId . '.html';
        $this->writeAtomic($destination, $contents, 'Development email capture');
        try {
            $this->writeAtomic($preview, $previewHtml, 'Development email HTML preview');
        } catch (Exception $exception) {
            @unlink($destination);
            throw $exception;
        }
        return ['status' => 'captured', 'transport' => 'development', 'capture_id' => $captureId, 'formats' => ['eml', 'html']];
    }

    public function directory() { return $this->directory; }

    private function ensureDirectory()
    {
        $created = false;
        if (!is_dir($this->directory)) {
            if (!@mkdir($this->directory, 0700, true)) {
                throw new RuntimeException('Development email capture directory could not be created.');
            }
            $created = true;
        }
        if (!is_dir($this->directory) || is_link($this->directory)) {
            throw new RuntimeException('Development email capture directory is invalid.');
        }
        $resolved = realpath($this->directory);
        $applicationRoot = realpath(dirname(__DIR__));
        if ($resolved === false || $applicationRoot === false || $this->within($resolved, $applicationRoot)) {
            if ($created) { @rmdir($this->directory); }
            throw new RuntimeException('Development email capture directory must be outside the public application root.');
        }
        @chmod($this->directory, 0700);
    }

    private function within($path, $root)
    {
        $path = rtrim(str_replace('\\', '/', (string) $path), '/');
        $root = rtrim(str_replace('\\', '/', (string) $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || strpos($path . '/', $root . '/') === 0;
    }

    private function mailbox($email, $name)
    {
        $email = $this->cleanHeader($email);
        $name = $this->cleanHeader($name);
        return $name === '' ? $email : $name . ' <' . $email . '>';
    }

    private function cleanHeader($value)
    {
        return trim(str_replace(["\r", "\n"], '', (string) $value));
    }

    private function brandImageBase64()
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'brand'
            . DIRECTORY_SEPARATOR . 'png' . DIRECTORY_SEPARATOR . 'color' . DIRECTORY_SEPARATOR . 'syndicatum-128.png';
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Development email brand image could not be read.');
        }
        return base64_encode($contents);
    }

    private function writeAtomic($destination, $contents, $label)
    {
        $temporary = $destination . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException($label . ' could not be written.');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException($label . ' could not be committed.');
        }
        @chmod($destination, 0600);
    }
}
