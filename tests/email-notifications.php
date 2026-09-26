<?php

require_once dirname(__DIR__) . '/src/EmailTemplateRenderer.php';
require_once dirname(__DIR__) . '/src/EmailNotificationService.php';

$passed = 0;
$failed = 0;
$test = function ($name, callable $callback) use (&$passed, &$failed) {
    try { $callback(); $passed++; echo 'PASS  ' . $name . "\n"; }
    catch (Throwable $error) { $failed++; echo 'FAIL  ' . $name . ': ' . $error->getMessage() . "\n"; }
};
$assert = function ($condition, $message) { if (!$condition) { throw new RuntimeException($message); } };
$captureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-email-test-' . bin2hex(random_bytes(6));

try {
    $data = [
        'installation_name' => 'Syndicatum',
        'project_name' => '<Pilot & Review>',
        'inviter_name' => 'Test Administrator',
        'role_label' => 'Member',
        'expires_at' => '2026-10-03 12:00:00 UTC',
        'invitation_url' => 'https://syndicatum.example/#invitation=test-token',
        'invitation_token' => 'test-token',
    ];

    $test('project invitation template produces plain text and escaped HTML', function () use ($assert, $data) {
        $message = (new EmailTemplateRenderer())->render('project_invitation', $data);
        $assert(strpos($message['text'], '<Pilot & Review>') !== false, 'Plain-text project name is missing.');
        $assert(strpos($message['html'], '&lt;Pilot &amp; Review&gt;') !== false, 'HTML template data was not escaped.');
        $assert(strpos($message['html'], '{{') === false && strpos($message['text'], '{{') === false, 'Template placeholders remain unresolved.');
    });

    $test('development transport writes one private inspectable email capture', function () use ($assert, $data, $captureRoot) {
        $result = (new EmailNotificationService($captureRoot))->sendProjectInvitation([
            'to_name' => 'Invited Person', 'to_email' => 'invitee@example.test',
            'from_name' => 'Syndicatum', 'from_email' => 'notifications@example.test',
            'reply_to' => 'support@example.test',
        ], $data);
        $assert($result['status'] === 'captured' && $result['transport'] === 'development', 'Development delivery status is invalid.');
        $captures = glob($captureRoot . DIRECTORY_SEPARATOR . '*.eml') ?: [];
        $previews = glob($captureRoot . DIRECTORY_SEPARATOR . '*.html') ?: [];
        $assert(count($captures) === 1, 'Expected exactly one email capture.');
        $assert(count($previews) === 1, 'Expected exactly one HTML preview.');
        $assert($result['formats'] === ['eml', 'html'], 'Capture formats were not reported.');
        $contents = file_get_contents($captures[0]);
        $preview = file_get_contents($previews[0]);
        $assert(strpos($contents, 'X-Syndicatum-Transport: development-capture') !== false, 'Transport metadata is missing.');
        $assert(strpos($contents, 'Content-Type: multipart/alternative') !== false, 'Email capture is not multipart.');
        $assert(strpos($contents, 'test-token') !== false, 'Invitation token is missing from the private capture.');
        $assert(strpos($preview, '<!doctype html>') === 0, 'HTML preview contains non-HTML capture metadata.');
        $assert(strpos($preview, 'X-Syndicatum-Transport') === false, 'HTML preview contains email headers.');
        $assert(strpos($preview, '&lt;Pilot &amp; Review&gt;') !== false, 'HTML preview does not match the rendered template.');
        $assert(empty(glob($captureRoot . DIRECTORY_SEPARATOR . '*.tmp-*')), 'A temporary capture file was left behind.');
    });

    $test('email envelopes reject invalid addresses before writing', function () use ($assert, $data, $captureRoot) {
        try {
            (new EmailNotificationService($captureRoot))->sendProjectInvitation([
                'to_name' => 'Invalid', 'to_email' => "bad\n@example.test",
                'from_name' => 'Syndicatum', 'from_email' => 'notifications@example.test', 'reply_to' => '',
            ], $data);
        } catch (InvalidArgumentException $error) {
            $assert(strpos($error->getMessage(), 'invalid address') !== false, 'Unexpected validation error.');
            return;
        }
        throw new RuntimeException('Invalid envelope was accepted.');
    });

    $test('development captures reject public application storage', function () use ($assert, $data) {
        try {
            (new EmailNotificationService(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'output'))->sendProjectInvitation([
                'to_name' => 'Invited Person', 'to_email' => 'invitee@example.test',
                'from_name' => 'Syndicatum', 'from_email' => 'notifications@example.test', 'reply_to' => '',
            ], $data);
        } catch (RuntimeException $error) {
            $assert(strpos($error->getMessage(), 'outside the public application root') !== false, 'Unexpected private-storage error.');
            return;
        }
        throw new RuntimeException('Public capture storage was accepted.');
    });
} finally {
    if (is_dir($captureRoot)) {
        foreach (glob($captureRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $path) { if (is_file($path)) { @unlink($path); } }
        @rmdir($captureRoot);
    }
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
