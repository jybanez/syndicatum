<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/ProjectTaskService.php';
require_once dirname(__DIR__) . '/src/ProjectStatusService.php';
require_once dirname(__DIR__) . '/src/IntegrationConnectionService.php';
require_once dirname(__DIR__) . '/src/PostBaselineMigrator.php';

$database = 'syndicatum_status_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_status_test_[a-f0-9]{12}$/', $database)) {
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
    $owner = (new AuthService($pdo))->bootstrapAdministrator('status-owner@example.test', 'Status Owner', 'correct horse battery staple');
    $project = (new ProjectManagementService($pdo))->createProject($owner['id'], ['name' => 'Status Contract']);
    $participant = $pdo->prepare('SELECT id FROM project_participants WHERE project_id = ? AND user_id = ?');
    $participant->execute([$project['id'], $owner['id']]);
    $participantId = (int) $participant->fetchColumn();
    $access = ['project_id' => (int) $project['id'], 'participant_id' => $participantId,
        'project_status' => 'active', 'role' => 'owner',
        'identity' => ['kind' => 'human', 'user' => ['id' => (int) $owner['id']]]];
    $tasks = new ProjectTaskService($pdo);
    $status = new ProjectStatusService($pdo);

    $overdue = $tasks->create($access, ['title' => 'Overdue task', 'due_at' => '2020-01-01 00:00:00']);
    $blocked = $tasks->create($access, ['title' => 'Blocked task']);
    $blocked = $tasks->update($access, $blocked['id'], ['version' => $blocked['version'], 'status' => 'in_progress']);
    $blocked = $tasks->update($access, $blocked['id'], ['version' => $blocked['version'], 'status' => 'blocked', 'blocked_reason' => 'Waiting']);
    $review = $tasks->create($access, ['title' => 'Review task']);
    $review = $tasks->update($access, $review['id'], ['version' => $review['version'], 'status' => 'in_progress']);
    $review = $tasks->update($access, $review['id'], ['version' => $review['version'], 'status' => 'in_review']);
    $complete = $tasks->create($access, ['title' => 'Completed task']);
    $complete = $tasks->update($access, $complete['id'], ['version' => $complete['version'], 'status' => 'in_progress']);
    $complete = $tasks->update($access, $complete['id'], ['version' => $complete['version'], 'status' => 'in_review']);
    $complete = $tasks->update($access, $complete['id'], ['version' => $complete['version'], 'status' => 'completed']);

    $test('summary returns small owner-scoped aggregates', function () use ($status, $access, $same) {
        $summary = $status->summary($access);
        $same(4, $summary['tasks']['total']);
        $same(3, $summary['tasks']['active']);
        $same(1, $summary['tasks']['blocked']);
        $same(1, $summary['tasks']['in_review']);
        $same(1, $summary['tasks']['completed']);
        $same(1, $summary['tasks']['overdue']);
        $same(3, $summary['tasks']['needs_attention']);
        $same(1, $summary['team']['active_total']);
        if ($summary['message_sequence'] < 1) { throw new RuntimeException('Expected task system-message activity.'); }
    });

    $test('task progress returns status counts without task records', function () use ($status, $access, $same) {
        $progress = $status->taskProgress($access);
        $same(1, $progress['counts']['open']);
        $same(1, $progress['counts']['blocked']);
        $same(1, $progress['counts']['in_review']);
        $same(1, $progress['counts']['completed']);
        $same(25.0, $progress['completion_percent']);
    });

    $test('activity is date-bucketed and range bounded', function () use ($status, $access, $same) {
        $activity = $status->activity($access, 7);
        $same(7, count($activity['series']));
        $same(4, array_sum(array_column($activity['series'], 'tasks_opened')));
        $same(1, array_sum(array_column($activity['series'], 'tasks_completed')));
        if (array_sum(array_column($activity['series'], 'messages')) < 1) { throw new RuntimeException('Expected message activity.'); }
        try { $status->activity($access, 365); }
        catch (InvalidArgumentException $error) { return; }
        throw new RuntimeException('Expected bounded activity range validation.');
    });

    $test('attention uses a bounded cursor page', function () use ($status, $access, $same) {
        $first = $status->attention($access, 2);
        $same(2, count($first['items']));
        $same(true, $first['has_more']);
        if (!$first['next_before']) { throw new RuntimeException('Expected attention cursor.'); }
        $second = $status->attention($access, 2, $first['next_before']);
        $same(1, count($second['items']));
        $same(false, $second['has_more']);
    });

    $test('team and integration sections load independently', function () use ($pdo, $status, $access, $project, $owner, $same) {
        (new IntegrationConnectionService($pdo))->create($project['id'], $owner['id'], [
            'display_name' => 'Status Integration', 'provider' => 'generic',
        ]);
        $team = $status->team($access);
        $same(1, $team['counts']['human']['active']);
        $same(1, $team['counts']['integration']['active']);
        $integrations = $status->integrations($access);
        $same(1, $integrations['active']);
        $same(1, $integrations['without_active_credential']);
    });

    $test('non-owner project roles cannot read status sections', function () use ($status, $access) {
        $member = $access; $member['role'] = 'member';
        try { $status->summary($member); }
        catch (RuntimeException $error) {
            if ($error->getMessage() === 'PROJECT_STATUS_FORBIDDEN') { return; }
            throw $error;
        }
        throw new RuntimeException('Expected PROJECT_STATUS_FORBIDDEN.');
    });

    $test('post-baseline migration installs activity indexes', function () use ($pdo, $same) {
        $statement = $pdo->prepare("SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
        foreach ([
            ['messages', 'idx_messages_project_created'],
            ['project_tasks', 'idx_project_tasks_project_created'],
            ['project_tasks', 'idx_project_tasks_project_completed'],
        ] as $index) {
            $statement->execute($index);
            $same(1, (int) $statement->fetchColumn());
        }
    });
} finally {
    $pdo = null;
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
