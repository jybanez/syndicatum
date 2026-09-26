<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/ProjectTaskService.php';
require_once dirname(__DIR__) . '/src/ProjectRepository.php';
require_once dirname(__DIR__) . '/src/IntegrationConnectionService.php';
require_once dirname(__DIR__) . '/src/IntegrationEventService.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/PostBaselineMigrator.php';

$database = 'syndicatum_int_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_int_test_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe test database name.');
}
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1'); putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root'); putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32))); putenv('SYNDICATUM_MASTER_KEY=' . bin2hex(random_bytes(32)));

$passed = 0; $failed = 0;
$test = function ($name, callable $callback) use (&$passed, &$failed) {
    try { $callback(); $passed++; echo 'PASS  ' . $name . "\n"; }
    catch (Throwable $error) { $failed++; echo 'FAIL  ' . $name . ': ' . $error->getMessage() . "\n"; }
};
$same = function ($expected, $actual) {
    if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
};

try {
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    (new PostBaselineMigrator($pdo))->migrate(false);
    $auth = new AuthService($pdo);
    $owner = $auth->bootstrapAdministrator('integration-owner@example.test', 'Integration Owner', 'correct horse battery staple');
    $project = (new ProjectManagementService($pdo))->createProject($owner['id'], ['name' => 'Integration Contract']);
    $ownerParticipant = $pdo->prepare('SELECT id FROM project_participants WHERE project_id = ? AND user_id = ?');
    $ownerParticipant->execute([$project['id'], $owner['id']]);
    $ownerParticipantId = (int) $ownerParticipant->fetchColumn();
    $access = ['project_id' => (int) $project['id'], 'participant_id' => $ownerParticipantId,
        'project_status' => 'active', 'role' => 'owner',
        'identity' => ['kind' => 'human', 'user' => ['id' => (int) $owner['id']]]];
    $service = new IntegrationConnectionService($pdo);
    $eventService = new IntegrationEventService($pdo);
    $integration = null;
    $activeSecret = null;

    $test('owner creates a durable integration identity and FYI routing atomically', function () use (&$integration, $service, $project, $owner, $ownerParticipantId, $same) {
        $integration = $service->create($project['id'], $owner['id'], [
            'display_name' => 'Build Monitor',
            'provider' => 'github-actions',
            'description' => 'Reports CI results.',
            'external_reference' => 'acme/widgets',
            'capabilities' => ['messages.read', 'tasks.write'],
            'notification_participant_ids' => [$ownerParticipantId],
        ]);
        $same('integration', $integration['kind']);
        $same('active', $integration['status']);
        $same('active', $integration['participant_status']);
        $same(['events.write'], $integration['capabilities']);
        $same([$ownerParticipantId], $integration['notification_participant_ids']);
        if ($integration['participant_id'] < 1 || !preg_match('/^[a-f0-9-]{36}$/', $integration['public_id'])) {
            throw new RuntimeException('Integration identity was not fully materialized.');
        }
    });

    $test('participant directory normalizes external integration metadata', function () use ($pdo, $access, &$integration, $same) {
        $participants = (new ProjectRepository($pdo))->participants($access, ['kind' => 'integration']);
        $same(1, count($participants));
        $same($integration['participant_id'], $participants[0]['id']);
        $same('integration', $participants[0]['kind']);
        $same('integration', $participants[0]['role']);
        $same('github-actions', $participants[0]['provider']);
        $same(['events.write'], $participants[0]['capabilities']);
        $same('Reports CI results.', $participants[0]['description']);
        $same('acme/widgets', $participants[0]['external_reference']);
    });

    $test('FYI routing requires active human or agent participants in the same project', function () use ($service, $project, $owner, &$integration, $same) {
        foreach ([[], [$integration['participant_id']], [999999999]] as $recipients) {
            try {
                $service->update($project['id'], $owner['id'], $integration['id'], [
                    'notification_participant_ids' => $recipients,
                ]);
            } catch (InvalidArgumentException $error) {
                continue;
            }
            throw new RuntimeException('Expected invalid FYI recipient rejection.');
        }
        $same(1, count($integration['notification_participant_ids']));
    });

    $test('ordinary project members cannot manage integration identities', function () use ($pdo, $auth, $service, $project) {
        $member = $auth->register([
            'email' => 'integration-member@example.test', 'display_name' => 'Integration Member',
            'password' => 'correct horse battery staple', 'password_confirmation' => 'correct horse battery staple',
        ])['user'];
        $now = Db::now();
        $pdo->prepare("INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, 'member', 'active', ?, ?)")
            ->execute([$project['id'], $member['id'], $now, $now]);
        try { $service->listConnections($project['id'], $member['id']); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'PROJECT_NOT_FOUND') { return; } throw $error; }
        throw new RuntimeException('Expected project-scoped management concealment.');
    });

    $test('integration identities cannot receive messages or responsibility', function () use ($pdo, $access, &$integration) {
        try {
            (new ProjectRepository($pdo))->createMessage($access, [
                'body' => 'This must not become an integration responsibility.',
                'direct_participant_ids' => [$integration['participant_id']],
                'action_requested' => true,
            ]);
        } catch (InvalidArgumentException $error) {
            if ($error->getMessage() === 'One or more addressees do not belong to this project.') { return; }
            throw $error;
        }
        throw new RuntimeException('Expected non-addressable integration rejection.');
    });

    $test('broadcast addressing excludes integration identities', function () use ($pdo, $access, &$integration, $same) {
        $created = (new ProjectRepository($pdo))->createMessage($access, ['body' => 'Human and agent audience only.']);
        $ids = array_map(function ($addressee) { return (int) $addressee['participant_id']; }, $created['message']['addressees']);
        $same(false, in_array($integration['participant_id'], $ids, true));
    });

    $test('integration identities cannot be assigned tasks', function () use ($pdo, $access, &$integration) {
        try {
            (new ProjectTaskService($pdo))->create($access, [
                'title' => 'Invalid integration assignment',
                'assignee_participant_id' => $integration['participant_id'],
            ]);
        } catch (InvalidArgumentException $error) {
            if ($error->getMessage() === 'Select an active participant in this project.') { return; }
            throw $error;
        }
        throw new RuntimeException('Expected integration task-assignment rejection.');
    });

    $test('credential issue returns a one-time capability URL and stores only its hash', function () use ($pdo, $owner, $project, $eventService, &$integration, &$activeSecret, $same) {
        (new SettingsService($pdo))->update(['general.public_origin' => 'https://syndicatum.example.test'], $owner['id']);
        $issued = $eventService->issueCredential($project['id'], $owner['id'], $integration['id']);
        $same(true, $issued['shown_once']);
        if (!preg_match('#/api/v1/integration-events/[a-f0-9-]{36}/([A-Za-z0-9_-]{43})$#', $issued['callback_url'], $matches)) {
            throw new RuntimeException('Issued callback URL is not a valid universal capability URL.');
        }
        $activeSecret = $matches[1];
        $stored = $pdo->query('SELECT secret_hash, secret_prefix FROM integration_credentials ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $same(hash('sha256', $activeSecret), $stored['secret_hash']);
        if ($stored['secret_hash'] === $activeSecret || strpos(json_encode($stored), $activeSecret) !== false) {
            throw new RuntimeException('Plaintext integration credential was persisted.');
        }
        try { $eventService->issueCredential($project['id'], $owner['id'], $integration['id']); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'INTEGRATION_CREDENTIAL_EXISTS') { return; } throw $error; }
        throw new RuntimeException('Expected explicit rotation requirement.');
    });

    $test('participant directory exposes safe credential state without credential material', function () use ($pdo, $access, $integration, $same) {
        $participants = (new ProjectRepository($pdo))->participants($access, ['kind' => 'integration']);
        $same(1, count($participants));
        $same(true, $participants[0]['credential_configured']);
        $same(null, $participants[0]['credential_last_used_at']);
        if (array_key_exists('secret_hash', $participants[0]) || array_key_exists('callback_url', $participants[0])) {
            throw new RuntimeException('Participant metadata exposed integration credential material.');
        }
    });

    $test('authenticated event creates one immutable addressed FYI system message idempotently', function () use ($pdo, $eventService, $integration, &$activeSecret, $ownerParticipantId, $same) {
        $payload = json_encode([
            'event_type' => 'alert', 'severity' => 'warning', 'title' => 'Build requires attention',
            'message' => 'The external build reported a failed quality gate.',
            'source' => ['event_id' => 'build-1042', 'resource_type' => 'build', 'resource_id' => '1042'],
        ], JSON_UNESCAPED_SLASHES);
        $first = $eventService->ingest($integration['public_id'], $activeSecret, $payload, 'delivery-1042');
        $same(false, $first['duplicate']);
        $duplicate = $eventService->ingest($integration['public_id'], $activeSecret, $payload, 'delivery-1042');
        $same(true, $duplicate['duplicate']); $same($first['message_id'], $duplicate['message_id']);
        $same(1, (int) $pdo->query('SELECT COUNT(*) FROM integration_event_receipts')->fetchColumn());
        $message = $pdo->query('SELECT message_kind, severity, event_type, sender_participant_id, action_requested, body FROM messages WHERE id = '
            . (int) $first['message_id'])->fetch(PDO::FETCH_ASSOC);
        $same('system', $message['message_kind']); $same('warning', $message['severity']);
        $same('integration.alert', $message['event_type']); $same($integration['participant_id'], (int) $message['sender_participant_id']);
        $same(0, (int) $message['action_requested']);
        $same(true, strpos($message['body'], 'Build requires attention') === 0);
        $addressees = $pdo->query('SELECT participant_id, reason FROM message_addressees WHERE message_id = '
            . (int) $first['message_id'])->fetchAll(PDO::FETCH_ASSOC);
        $same([['participant_id' => $ownerParticipantId, 'reason' => 'direct']], array_map(function ($row) {
            return ['participant_id' => (int) $row['participant_id'], 'reason' => $row['reason']];
        }, $addressees));
        $participants = (new ProjectRepository($pdo))->participants([
            'project_id' => $integration['project_id'], 'participant_id' => $integration['participant_id'],
            'identity' => ['kind' => 'integration'], 'role' => 'integration',
        ], ['kind' => 'integration']);
        $same(true, !empty($participants[0]['credential_last_used_at']));
        try { $eventService->ingest($integration['public_id'], $activeSecret, '{"title":"different"}', 'delivery-1042'); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'INTEGRATION_IDEMPOTENCY_CONFLICT') { return; } throw $error; }
        throw new RuntimeException('Expected idempotency conflict for a changed payload.');
    });

    $test('rotation invalidates the previous URL without replacing the identity', function () use ($eventService, $owner, $project, &$integration, &$activeSecret, $same) {
        $oldSecret = $activeSecret;
        $rotated = $eventService->issueCredential($project['id'], $owner['id'], $integration['id'], true);
        preg_match('#/([A-Za-z0-9_-]{43})$#', $rotated['callback_url'], $matches);
        $activeSecret = $matches[1];
        $same($integration['id'], $rotated['integration_id']);
        try { $eventService->ingest($integration['public_id'], $oldSecret, '{"title":"old"}', 'old-secret'); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'INTEGRATION_AUTHENTICATION_FAILED') { return; } throw $error; }
        throw new RuntimeException('Expected rotated credential rejection.');
    });

    $test('explicit revocation fails closed and a new URL can be issued for the same identity', function () use ($eventService, $owner, $project, &$integration, &$activeSecret, $same) {
        $revokedSecret = $activeSecret;
        $revoked = $eventService->revokeCredential($project['id'], $owner['id'], $integration['id']);
        $same(true, $revoked['revoked']);
        try { $eventService->ingest($integration['public_id'], $revokedSecret, '{"title":"revoked"}', 'revoked-event'); }
        catch (RuntimeException $error) {
            if ($error->getMessage() !== 'INTEGRATION_AUTHENTICATION_FAILED') { throw $error; }
            $issued = $eventService->issueCredential($project['id'], $owner['id'], $integration['id']);
            preg_match('#/([A-Za-z0-9_-]{43})$#', $issued['callback_url'], $matches);
            $activeSecret = $matches[1];
            $same($integration['id'], $issued['integration_id']);
            return;
        }
        throw new RuntimeException('Expected revoked credential rejection.');
    });

    $test('event allowlists and global payload and rate caps fail closed', function () use ($pdo, $eventService, $integration, &$activeSecret) {
        foreach ([
            [json_encode(['event_type' => 'unapproved', 'title' => 'No']), 'INTEGRATION_EVENT_TYPE_REJECTED'],
            [json_encode(['severity' => 'catastrophic', 'title' => 'No']), 'INTEGRATION_SEVERITY_REJECTED'],
            [json_encode(['message' => str_repeat('x', IntegrationEventService::MAX_PAYLOAD_BYTES)]), 'INTEGRATION_PAYLOAD_REJECTED'],
        ] as $case) {
            try { $eventService->ingest($integration['public_id'], $activeSecret, $case[0], 'reject-' . md5($case[0])); }
            catch (RuntimeException $error) { if ($error->getMessage() === $case[1]) { continue; } throw $error; }
            throw new RuntimeException('Expected fail-closed event rejection: ' . $case[1]);
        }
        $credentialId = (int) $pdo->query("SELECT id FROM integration_credentials WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $insert = $pdo->prepare("INSERT INTO integration_event_receipts
            (public_id, project_id, integration_id, credential_id, idempotency_key_hash, payload_sha256,
             payload_bytes, event_type, severity, source_metadata_json, message_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 2, 'event', 'info', NULL, NULL, UTC_TIMESTAMP())");
        for ($index = 0; $index < IntegrationEventService::RATE_LIMIT_PER_MINUTE; $index++) {
            $seed = 'rate-' . $index;
            $insert->execute([sprintf('00000000-0000-4000-8000-%012d', $index), $integration['project_id'],
                $integration['id'], $credentialId, hash('sha256', $seed), hash('sha256', '{}' . $seed)]);
        }
        try { $eventService->ingest($integration['public_id'], $activeSecret, '{"title":"limited"}', 'rate-limited'); }
        catch (RuntimeException $error) {
            if ($error->getMessage() !== 'INTEGRATION_RATE_LIMITED') { throw $error; }
            $pdo->exec('DELETE FROM integration_event_receipts WHERE message_id IS NULL');
            return;
        }
        throw new RuntimeException('Expected integration rate limit.');
    });

    $test('disable and enable synchronize participant lifecycle generation and fail closed', function () use ($service, $eventService, $project, $owner, &$integration, &$activeSecret, $same) {
        $disabled = $service->update($project['id'], $owner['id'], $integration['id'], ['status' => 'disabled']);
        $same('disabled', $disabled['status']); $same('suspended', $disabled['participant_status']);
        $same(1, (int) $disabled['status_generation']);
        $rejected = false;
        try { $eventService->ingest($integration['public_id'], $activeSecret, '{"title":"disabled"}', 'disabled-event'); }
        catch (RuntimeException $error) {
            if ($error->getMessage() !== 'INTEGRATION_AUTHENTICATION_FAILED') { throw $error; }
            $rejected = true;
        }
        $same(true, $rejected);
        $enabled = $service->update($project['id'], $owner['id'], $integration['id'], ['status' => 'active']);
        $same('active', $enabled['status']); $same('active', $enabled['participant_status']);
        $same(2, (int) $enabled['status_generation']);
        $integration = $enabled;
    });

    $test('removal preserves identity history and cannot be reversed', function () use ($service, $project, $owner, &$integration, $same) {
        $removed = $service->remove($project['id'], $owner['id'], $integration['id']);
        $same('removed', $removed['status']); $same('removed', $removed['participant_status']);
        $same(3, (int) $removed['status_generation']);
        $same(0, count($service->listConnections($project['id'], $owner['id'])));
        $same(1, count($service->listConnections($project['id'], $owner['id'], true)));
        try { $service->update($project['id'], $owner['id'], $integration['id'], ['status' => 'active']); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'INTEGRATION_REMOVED') { return; } throw $error; }
        throw new RuntimeException('Expected removed integration to remain retired.');
    });

    $test('integration lifecycle writes durable audit evidence', function () use ($pdo, &$integration, $same) {
        $statement = $pdo->prepare("SELECT action FROM administrative_audit_events WHERE subject_type = 'integration_connection' AND subject_id = ? ORDER BY id");
        $statement->execute([(string) $integration['id']]);
        $actions = $statement->fetchAll(PDO::FETCH_COLUMN);
        foreach (['project.integration_created', 'project.integration_credential_issued',
            'project.integration_credential_rotated', 'integration.event_ingested',
            'integration.event_rejected', 'project.integration_removed'] as $action) {
            if (!in_array($action, $actions, true)) { throw new RuntimeException('Missing audit action: ' . $action); }
        }
    });
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
