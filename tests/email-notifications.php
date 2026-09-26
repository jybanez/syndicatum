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
        $assert($message['template_version'] === 3, 'Project invitation template version was not advanced.');
        $assert(strpos($message['text'], '<Pilot & Review>') !== false, 'Plain-text project name is missing.');
        $assert(strpos($message['html'], '&lt;Pilot &amp; Review&gt;') !== false, 'HTML template data was not escaped.');
        $assert(strpos($message['html'], 'PROJECT INVITATION') !== false, 'Project-brief heading is missing.');
        $assert(strpos($message['html'], 'Invited by') !== false && strpos($message['html'], 'Installation') !== false && strpos($message['html'], 'Your role') !== false, 'Project-brief details are incomplete.');
        $assert(strpos($message['html'], '>View invitation</a>') !== false, 'Primary invitation action is missing.');
        $assert(substr_count($message['html'], 'href="https://syndicatum.example/#invitation=test-token"') === 2, 'Invitation URL was changed or is not used by both actions.');
        $assert(strpos($message['html'], 'src="https://syndicatum.example/assets/brand/png/color/syndicatum-128.png"') !== false, 'Official raster brand asset is missing.');
        $assert(strpos($message['html'], 'width="48" height="48" alt=""') !== false, 'Brand asset dimensions or text fallback are missing.');
        $assert(strpos($message['text'], "View invitation:\nhttps://syndicatum.example/#invitation=test-token") !== false, 'Plain-text invitation action is incomplete.');
        $assert(strpos($message['text'], 'Keep the invitation link and token private.') !== false, 'Plain-text privacy guidance is missing.');
        $assert(strpos($message['html'], 'Expires October 3, 2026 at 12:00 PM UTC.') !== false, 'Human-friendly HTML expiry is missing.');
        $assert(strpos($message['text'], 'This invitation expires October 3, 2026 at 12:00 PM UTC.') !== false, 'Human-friendly plain-text expiry is missing.');
        $assert(strpos($message['html'], '2026-10-03 12:00:00 UTC') === false, 'Raw expiry leaked into HTML output.');
        $assert(strpos($message['html'], '{{') === false && strpos($message['text'], '{{') === false, 'Template placeholders remain unresolved.');
    });

    $test('project invitation template preserves an application subpath in its brand URL', function () use ($assert, $data) {
        $subpath = $data;
        $subpath['invitation_url'] = 'http://localhost/pbb/chatviewer/#invitation=test-token';
        $message = (new EmailTemplateRenderer())->render('project_invitation', $subpath);
        $assert($message['brand_logo_url'] === 'http://localhost/pbb/chatviewer/assets/brand/png/color/syndicatum-128.png', 'Application subpath was discarded from the brand URL.');
        $assert(strpos($message['html'], 'src="http://localhost/pbb/chatviewer/assets/brand/png/color/syndicatum-128.png"') !== false, 'Subpath-aware brand URL is missing from HTML.');
    });

    $test('project invitation template rejects an invalid expiry date', function () use ($assert, $data) {
        $invalid = $data;
        $invalid['expires_at'] = 'not-a-date';
        try {
            (new EmailTemplateRenderer())->render('project_invitation', $invalid);
        } catch (InvalidArgumentException $error) {
            $assert(strpos($error->getMessage(), 'expiry date is invalid') !== false, 'Unexpected expiry validation error.');
            return;
        }
        throw new RuntimeException('Invalid expiry date was accepted.');
    });

    $test('project invitation template rejects an invalid invitation URL', function () use ($assert, $data) {
        $invalid = $data;
        $invalid['invitation_url'] = 'not-an-absolute-url';
        try {
            (new EmailTemplateRenderer())->render('project_invitation', $invalid);
        } catch (InvalidArgumentException $error) {
            $assert(strpos($error->getMessage(), 'invitation URL is invalid') !== false, 'Unexpected invitation URL validation error.');
            return;
        }
        throw new RuntimeException('Invalid invitation URL was accepted.');
    });

    $test('project invitation template rejects a non-web invitation URL', function () use ($assert, $data) {
        $invalid = $data;
        $invalid['invitation_url'] = 'ftp://syndicatum.example/#invitation=test-token';
        try {
            (new EmailTemplateRenderer())->render('project_invitation', $invalid);
        } catch (InvalidArgumentException $error) {
            $assert(strpos($error->getMessage(), 'invitation URL is invalid') !== false, 'Unexpected invitation URL scheme error.');
            return;
        }
        throw new RuntimeException('Non-web invitation URL was accepted.');
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
        $assert(strpos($contents, 'X-Syndicatum-Template: project_invitation; version=3') !== false, 'Template version metadata is missing.');
        $assert(strpos($contents, 'Content-Type: multipart/alternative') !== false, 'Email capture is not multipart.');
        $assert(strpos($contents, 'Content-Type: multipart/related') !== false, 'Email capture does not group HTML and inline assets.');
        $assert(strpos($contents, 'Content-Type: image/png; name="syndicatum-128.png"') !== false, 'Inline brand image MIME part is missing.');
        $assert(strpos($contents, 'Content-ID: <syndicatum-brand-') !== false, 'Inline brand image Content-ID is missing.');
        $assert(strpos($contents, 'src="cid:syndicatum-brand-') !== false, 'Captured HTML does not reference the inline brand image.');
        $assert(strpos($contents, 'test-token') !== false, 'Invitation token is missing from the private capture.');
        $assert(strpos($preview, '<!doctype html>') === 0, 'HTML preview contains non-HTML capture metadata.');
        $assert(strpos($preview, 'X-Syndicatum-Transport') === false, 'HTML preview contains email headers.');
        $assert(strpos($preview, '&lt;Pilot &amp; Review&gt;') !== false, 'HTML preview does not match the rendered template.');
        $assert(strpos($preview, 'src="data:image/png;base64,') !== false, 'HTML preview does not embed the brand image for offline inspection.');
        $assert(strpos($preview, 'src="cid:') === false, 'HTML preview contains an email-only CID reference.');
        $assert(strpos($preview, 'https://syndicatum.example/assets/brand/png/color/syndicatum-128.png') === false, 'HTML preview still depends on the remote brand image.');
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
