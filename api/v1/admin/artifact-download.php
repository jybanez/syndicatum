<?php

require_once __DIR__ . '/_recovery.php';

try {
    recoveryRequireMethod(['GET']);
    list($pdo, $auth, $user) = recoveryAuth();
    $service = recoveryService($pdo);
    $token = isset($_GET['ticket']) ? trim((string) $_GET['ticket']) : '';
    $streamed = $service->streamDownloadTicket($token, (int) $user['id'], (int) $user['session_id'], function ($ticket, $stream) use ($auth, $user) {
        $auth->audit((int) $user['id'], 'artifact.download_started', 'artifact', $ticket['sha256'], ['filename' => $ticket['download_name']]);
        header('Content-Type: ' . $ticket['media_type']);
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $ticket['download_name']) . '"');
        header('Content-Length: ' . filesize($ticket['artifact_path']));
        header('X-Content-Type-Options: nosniff');
        header('X-Artifact-SHA256: ' . $ticket['sha256']);
        fpassthru($stream);
    });
    if (!$streamed) { Api::json(['error' => true, 'code' => 'DOWNLOAD_TICKET_INVALID', 'message' => 'The download ticket is invalid, expired, or already used.'], 404); }
    exit;
} catch (Throwable $exception) { recoveryError($exception); }
