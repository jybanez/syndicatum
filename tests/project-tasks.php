<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/ProjectTaskService.php';
require_once dirname(__DIR__) . '/src/ProjectRepository.php';
require_once dirname(__DIR__) . '/src/PostBaselineMigrator.php';

$database = 'syndicatum_tasks_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_tasks_test_[a-f0-9]{12}$/', $database)) { throw new RuntimeException('Unsafe test database name.'); }
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
$same = function ($expected, $actual) { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } };

try {
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    (new PostBaselineMigrator($pdo))->migrate(false);
    $user = (new AuthService($pdo))->bootstrapAdministrator('tasks@example.test', 'Task Owner', 'correct horse battery staple');
    $project = (new ProjectManagementService($pdo))->createProject($user['id'], ['name' => 'Task Contract']);
    $participant = $pdo->prepare('SELECT id FROM project_participants WHERE project_id = ? AND user_id = ?');
    $participant->execute([$project['id'], $user['id']]);
    $participantId = (int) $participant->fetchColumn();
    $access = ['project_id' => (int) $project['id'], 'participant_id' => $participantId, 'project_status' => 'active',
        'role' => 'owner', 'identity' => ['kind' => 'human', 'user' => ['id' => (int) $user['id']]]];
    $service = new ProjectTaskService($pdo);
    $task = null;

    $test('authenticated participant is recorded as the immutable task giver', function () use (&$task, $service, $access, $participantId, $same) {
        $task = $service->create($access, ['title' => 'Verify task lifecycle', 'priority' => 'high', 'assignee_participant_id' => $participantId,
            'supervising_participant_id' => 999999, 'due_at' => '']);
        $same('open', $task['status']); $same('high', $task['priority']); $same($participantId, $task['assignee_participant_id']);
        $same($participantId, $task['created_by_participant_id']); $same($participantId, $task['supervising_participant_id']);
        $same(null, $task['due_at']);
        $same('created', $task['events'][0]['event_type']);
    });
    $test('authorized agent identity may create a task under its own identity', function () use ($service, $access, $participantId, $same) {
        $agentAccess = $access; $agentAccess['identity'] = ['kind' => 'agent', 'agent' => ['authenticated_agent_id' => 77]];
        $agentAccess['role'] = 'agent';
        $created = $service->create($agentAccess, ['title' => 'Agent-created task']);
        $same($participantId, $created['created_by_participant_id']);
        $same($participantId, $created['supervising_participant_id']);
    });
    $test('read-only participant cannot create a task', function () use ($service, $access) {
        $viewer = $access; $viewer['role'] = 'viewer';
        try { $service->create($viewer, ['title' => 'Must not be created']); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'TASK_WRITE_FORBIDDEN') { return; } throw $error; }
        throw new RuntimeException('Expected TASK_WRITE_FORBIDDEN.');
    });
    $test('only the immutable task giver may edit the task definition', function () use (&$task, $service, $access, $participantId, $same) {
        $task = $service->update($access, $task['id'], ['version' => $task['version'], 'title' => 'Verified task lifecycle']);
        $same('Verified task lifecycle', $task['title']);
        $otherManager = $access;
        $otherManager['participant_id'] = $participantId + 999999;
        try { $service->update($otherManager, $task['id'], ['version' => $task['version'], 'title' => 'Unauthorized rewrite']); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'TASK_WRITE_FORBIDDEN') { return; } throw $error; }
        throw new RuntimeException('Expected TASK_WRITE_FORBIDDEN.');
    });
    $test('assigned participant advances through review and completion', function () use (&$task, $service, $access, $same) {
        $task = $service->update($access, $task['id'], ['version' => $task['version'], 'status' => 'in_progress']);
        $same('in_progress', $task['status']);
        $task = $service->update($access, $task['id'], ['version' => $task['version'], 'status' => 'in_review']);
        $same('in_review', $task['status']);
        $task = $service->update($access, $task['id'], ['version' => $task['version'], 'status' => 'completed', 'completion_summary' => 'Verified']);
        $same('completed', $task['status']); $same('Verified', $task['completion_summary']);
    });
    $test('meaningful task events create immutable structured system messages', function () use ($pdo, &$task, $access, $same) {
        $messages = (new ProjectRepository($pdo))->messagePage($access, ['limit' => 20])['data'];
        $system = array_values(array_filter($messages, function ($message) {
            return $message['message_kind'] === 'system';
        }));
        $same(5, count($system));
        $same('task.status_changed', $system[0]['system_event']['type']);
        $same((int) $task['id'], (int) $system[0]['system_event']['data']['task_id']);
        $same('completed', $system[0]['system_event']['data']['to_status']);
        $same('success', $system[0]['severity']);
        $same($access['participant_id'], $system[0]['sender']['participant_id']);
        $repository = new ProjectRepository($pdo);
        foreach ([
            function () use ($repository, $access, $system) { $repository->updateMessage($access, $system[0]['id'], ['body' => 'Rewrite']); },
            function () use ($repository, $access, $system) { $repository->deleteMessage($access, $system[0]['id']); },
        ] as $mutation) {
            try { $mutation(); }
            catch (RuntimeException $error) {
                if ($error->getMessage() === 'SYSTEM_MESSAGE_IMMUTABLE') { continue; }
                throw $error;
            }
            throw new RuntimeException('Expected SYSTEM_MESSAGE_IMMUTABLE.');
        }
    });
    $test('task mutations enqueue authoritative Realtime snapshots', function () use ($pdo, &$task, $same) {
        $statement = $pdo->prepare("SELECT event_type, payload_json FROM message_events_outbox
            WHERE project_id = ? AND event_type = ? ORDER BY id DESC LIMIT 1");
        $statement->execute([$task['project_id'], MessageOutbox::EVENT_TASK_UPDATED]);
        $event = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$event) { throw new RuntimeException('Expected a task Realtime outbox event.'); }
        $payload = json_decode($event['payload_json'], true);
        $same(MessageOutbox::EVENT_TASK_UPDATED, $event['event_type']);
        $same((int) $task['id'], (int) $payload['task_id']);
        $same((int) $task['version'], (int) $payload['task']['version']);
        $same('completed', $payload['task']['status']);
        $same('status_changed', $payload['change']);
    });
    $test('task assignment changes create one system event with the updated subject', function () use ($pdo, $service, $access, &$task, $same) {
        $task = $service->update($access, $task['id'], [
            'version' => $task['version'], 'assignee_participant_id' => null,
        ]);
        $messageId = (int) $pdo->query("SELECT id FROM messages WHERE message_kind = 'system' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $message = (new ProjectRepository($pdo))->message($access, $messageId);
        $same('task.assigned', $message['system_event']['type']);
        $same('neutral', $message['severity']);
        $same(null, $message['system_event']['data']['assignee_participant_id']);
        $same(true, strpos($message['body'], ' unassigned: ') !== false);
    });
    $test('stale task versions are rejected', function () use ($task, $service, $access) {
        try { $service->update($access, $task['id'], ['version' => 1, 'status' => 'open']); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'TASK_VERSION_CONFLICT') { return; } throw $error; }
        throw new RuntimeException('Expected TASK_VERSION_CONFLICT.');
    });
    $test('all project participants read the same task collection', function () use ($service, $access, $same) {
        $viewer = $access; $viewer['role'] = 'viewer';
        $tasks = $service->listTasks($viewer); $same(2, count($tasks));
    });
    $test('action request conversion links one task and rejects duplicate conversion', function () use ($pdo, $service, $access, $project, $user, $same) {
        $management = new ProjectManagementService($pdo);
        $agent = $management->createAgent($project['id'], $user['id'], [
            'display_name' => 'Task Recipient', 'provider' => 'codex',
        ]);
        $participant = $pdo->prepare('SELECT id FROM project_participants WHERE project_id = ? AND agent_id = ?');
        $participant->execute([$project['id'], $agent['agent_id']]);
        $recipientId = (int) $participant->fetchColumn();
        $request = (new ProjectRepository($pdo))->createMessage($access, [
            'body' => 'Please turn this request into tracked work.',
            'direct_participant_ids' => [$recipientId],
            'action_requested' => true,
        ]);
        $created = $service->create($access, [
            'title' => 'Tracked request',
            'assignee_participant_id' => $recipientId,
            'source_message_id' => $request['message']['id'],
            'convert_action_request' => true,
        ]);
        $same($request['message']['id'], $created['source_message_id']);
        try {
            $service->create($access, [
                'title' => 'Duplicate tracked request',
                'source_message_id' => $request['message']['id'],
                'convert_action_request' => true,
            ]);
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'TASK_ALREADY_LINKED') { return; }
            throw $error;
        }
        throw new RuntimeException('Expected TASK_ALREADY_LINKED.');
    });
    $test('informational direct messages cannot use action-request conversion', function () use ($pdo, $service, $access, $project) {
        $recipientId = (int) $pdo->query("SELECT id FROM project_participants WHERE project_id = "
            . (int) $project['id'] . " AND kind = 'agent' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $message = (new ProjectRepository($pdo))->createMessage($access, [
            'body' => 'For your information only.',
            'direct_participant_ids' => [$recipientId],
        ]);
        try {
            $service->create($access, [
                'title' => 'Must not be converted',
                'source_message_id' => $message['message']['id'],
                'convert_action_request' => true,
            ]);
        } catch (InvalidArgumentException $error) {
            if ($error->getMessage() === 'Only an action request can be converted to a task.') { return; }
            throw $error;
        }
        throw new RuntimeException('Expected action-request validation failure.');
    });
    $test('task and task event tables are durable backup entities', function () use ($same) {
        $policy = json_decode(file_get_contents(dirname(__DIR__) . '/schema/mysql84/backup-policy-v1.json'), true);
        $same('durable', $policy['tables']['project_tasks']['backup_policy']);
        $same('durable', $policy['tables']['project_task_events']['backup_policy']);
    });
} finally {
    $pdo = null;
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
