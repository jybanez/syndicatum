<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/ProjectPlanService.php';
require_once dirname(__DIR__) . '/src/ProjectTaskService.php';
require_once dirname(__DIR__) . '/src/PostBaselineMigrator.php';
require_once dirname(__DIR__) . '/src/BaselineMetadata.php';

$database = 'syndicatum_plan_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_plan_test_[a-f0-9]{12}$/', $database)) { throw new RuntimeException('Unsafe test database name.'); }
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1'); putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root'); putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32))); putenv('SYNDICATUM_MASTER_KEY=' . bin2hex(random_bytes(32)));

$passed = 0; $failed = 0;
$test = function ($name, callable $callback) use (&$passed, &$failed) { try { $callback(); $passed++; echo 'PASS  ' . $name . "\n"; } catch (Throwable $error) { $failed++; echo 'FAIL  ' . $name . ': ' . $error->getMessage() . "\n"; } };
$same = function ($expected, $actual) { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } };

try {
    $pdo = Db::pdo(); (new ChatRepository($pdo))->installSchema(); (new PostBaselineMigrator($pdo))->migrate(false);
    $owner = (new AuthService($pdo))->bootstrapAdministrator('plan-owner@example.test', 'Plan Owner', 'correct horse battery staple');
    $project = (new ProjectManagementService($pdo))->createProject($owner['id'], ['name' => 'Plan Contract']);
    $participant = $pdo->prepare('SELECT id FROM project_participants WHERE project_id = ? AND user_id = ?');
    $participant->execute([$project['id'], $owner['id']]); $participantId = (int) $participant->fetchColumn();
    $access = ['project_id' => (int) $project['id'], 'participant_id' => $participantId, 'project_status' => 'active', 'role' => 'owner', 'identity' => ['kind' => 'human', 'user' => ['id' => (int) $owner['id']]]];
    $plan = new ProjectPlanService($pdo); $tasks = new ProjectTaskService($pdo);

    $milestone = $plan->createMilestone($access, ['title' => 'Event launch readiness', 'target_at' => '2030-10-15 09:00:00']);
    $deliverable = $plan->createDeliverable($access, ['title' => 'Event poster', 'milestone_id' => $milestone['id'], 'owner_participant_id' => $participantId]);
    $first = $tasks->create($access, ['title' => 'Draft event copy', 'deliverable_id' => $deliverable['id']]);
    $second = $tasks->create($access, ['title' => 'Approve poster', 'deliverable_id' => $deliverable['id']]);
    $first = $tasks->update($access, $first['id'], ['version' => $first['version'], 'status' => 'in_progress']);
    $first = $tasks->update($access, $first['id'], ['version' => $first['version'], 'status' => 'in_review']);
    $tasks->update($access, $first['id'], ['version' => $first['version'], 'status' => 'completed']);

    $test('milestone target dates are stored without a time component', function () use ($same, $milestone) {
        $same('2030-10-15 00:00:00', $milestone['target_at']);
    });

    $test('deliverables without linked tasks expose numeric zero progress', function () use ($same, $deliverable) {
        $same(0, $deliverable['task_count']);
        $same(0, $deliverable['completed_task_count']);
        $same(0, $deliverable['cancelled_task_count']);
        $same(0, $deliverable['blocked_task_count']);
        $same(0, $deliverable['eligible_task_count']);
        $same(0, $deliverable['completion_percent']);
    });

    $test('plan groups deliverables under milestones and computes task progress', function () use ($plan, $access, $same, $milestone, $deliverable) {
        $result = $plan->plan($access);
        $same(true, $result['can_manage']); $same(1, count($result['milestones'])); $same(1, count($result['deliverables']));
        $same($milestone['id'], $result['deliverables'][0]['milestone_id']);
        $same($deliverable['id'], $result['deliverables'][0]['id']);
        $same(2, $result['deliverables'][0]['task_count']); $same(1, $result['deliverables'][0]['completed_task_count']);
        $same(50.0, $result['deliverables'][0]['completion_percent']);
    });

    $test('task records expose their deliverable without changing task lifecycle', function () use ($tasks, $access, $second, $deliverable, $same) {
        $task = $tasks->task($access, $second['id']);
        $same($deliverable['id'], $task['deliverable_id']); $same('Event poster', $task['deliverable_title']);
    });

    $test('planning updates are version checked', function () use ($plan, $access, $milestone, $deliverable, $same) {
        $updatedMilestone = $plan->updateMilestone($access, $milestone['id'], ['version' => $milestone['version'], 'title' => $milestone['title'], 'description' => null, 'status' => 'in_progress', 'target_at' => $milestone['target_at'], 'position' => 0]);
        $same('in_progress', $updatedMilestone['status']);
        $updated = $plan->updateDeliverable($access, $deliverable['id'], ['version' => $deliverable['version'], 'title' => $deliverable['title'], 'description' => null, 'status' => 'in_review', 'milestone_id' => $milestone['id'], 'owner_participant_id' => $deliverable['owner_participant_id'], 'due_at' => null, 'artifact_url' => 'https://example.test/poster.pdf', 'position' => 0]);
        $same('in_review', $updated['status']); $same('https://example.test/poster.pdf', $updated['artifact_url']);
        try { $plan->updateDeliverable($access, $deliverable['id'], ['version' => $deliverable['version'], 'title' => 'Stale', 'status' => 'planned']); }
        catch (RuntimeException $error) { $same('DELIVERABLE_VERSION_CONFLICT', $error->getMessage()); return; }
        throw new RuntimeException('Expected stale deliverable rejection.');
    });

    $test('planning reorder is atomic, complete, and version checked', function () use ($plan, $access, $same) {
        $secondMilestone = $plan->createMilestone($access, ['title' => 'Production']);
        $thirdMilestone = $plan->createMilestone($access, ['title' => 'Release']);
        $plan->createDeliverable($access, ['title' => 'Production proof', 'milestone_id' => $secondMilestone['id']]);
        $plan->createDeliverable($access, ['title' => 'Standalone brief']);
        $current = $plan->plan($access);
        $milestoneIds = array_map(function ($row) { return $row['id']; }, $current['milestones']);
        $milestoneVersions = [];
        foreach ($current['milestones'] as $row) { $milestoneVersions[$row['id']] = $row['version']; }
        $reversed = array_reverse($milestoneIds);
        $ordered = $plan->reorder($access, ['kind' => 'milestones', 'ordered_ids' => $reversed, 'versions' => $milestoneVersions]);
        $same($reversed, array_map(function ($row) { return $row['id']; }, $ordered['milestones']));
        $orderedVersions = [];
        foreach ($ordered['milestones'] as $row) { $orderedVersions[$row['id']] = $row['version']; }
        $incompleteMilestonesRejected = false;
        try { $plan->reorder($access, ['kind' => 'milestones', 'ordered_ids' => array_slice($reversed, 0, -1), 'versions' => $orderedVersions]); }
        catch (RuntimeException $error) {
            $incompleteMilestonesRejected = true;
            $same('PROJECT_PLAN_REORDER_CONFLICT', $error->getMessage());
            $same($reversed, array_map(function ($row) { return $row['id']; }, $plan->plan($access)['milestones']));
        }
        if (!$incompleteMilestonesRejected) { throw new RuntimeException('Expected incomplete milestone order rejection.'); }

        $sourceId = $milestoneIds[0]; $destinationId = $secondMilestone['id'];
        $moved = null; $sourceOrder = []; $destinationOrder = [];
        foreach ($ordered['deliverables'] as $row) {
            if ($row['milestone_id'] === $sourceId && $moved === null) { $moved = $row; continue; }
            if ($row['milestone_id'] === $sourceId) { $sourceOrder[] = $row['id']; }
            if ($row['milestone_id'] === $destinationId) { $destinationOrder[] = $row['id']; }
        }
        $destinationOrder[] = $moved['id'];
        $versions = [];
        foreach ($ordered['deliverables'] as $row) {
            if ($row['milestone_id'] === $sourceId || $row['milestone_id'] === $destinationId) { $versions[$row['id']] = $row['version']; }
        }
        $request = ['kind' => 'deliverables', 'moved_id' => $moved['id'], 'from_milestone_id' => $sourceId, 'to_milestone_id' => $destinationId,
            'orders' => [['milestone_id' => $sourceId, 'ordered_ids' => $sourceOrder], ['milestone_id' => $destinationId, 'ordered_ids' => $destinationOrder]], 'versions' => $versions];
        $movedPlan = $plan->reorder($access, $request);
        $movedRow = null;
        foreach ($movedPlan['deliverables'] as $row) { if ($row['id'] === $moved['id']) { $movedRow = $row; break; } }
        $same($destinationId, $movedRow['milestone_id']);
        $same(count($destinationOrder) - 1, $movedRow['position']);
        $freshDestination = [];
        $freshVersions = [];
        foreach ($movedPlan['deliverables'] as $row) {
            if ($row['milestone_id'] === $destinationId) { $freshDestination[] = $row['id']; $freshVersions[$row['id']] = $row['version']; }
        }
        $incompleteDeliverablesRejected = false;
        try { $plan->reorder($access, ['kind' => 'deliverables', 'moved_id' => $moved['id'], 'from_milestone_id' => $destinationId, 'to_milestone_id' => $destinationId,
            'orders' => [['milestone_id' => $destinationId, 'ordered_ids' => array_slice($freshDestination, 0, -1)]], 'versions' => $freshVersions]); }
        catch (RuntimeException $error) {
            $incompleteDeliverablesRejected = true;
            $same('PROJECT_PLAN_REORDER_CONFLICT', $error->getMessage());
            $same($freshDestination, array_values(array_map(function ($row) { return $row['id']; }, array_filter($plan->plan($access)['deliverables'], function ($row) use ($destinationId) { return $row['milestone_id'] === $destinationId; }))));
        }
        if (!$incompleteDeliverablesRejected) { throw new RuntimeException('Expected incomplete deliverable order rejection.'); }
        try { $plan->reorder($access, $request); }
        catch (RuntimeException $error) { $same('PROJECT_PLAN_REORDER_CONFLICT', $error->getMessage()); return; }
        throw new RuntimeException('Expected stale reorder rejection.');
    });

    $test('non-manager participants can read but cannot change the plan', function () use ($plan, $access, $same) {
        $viewer = $access; $viewer['role'] = 'member';
        $same(false, $plan->plan($viewer)['can_manage']);
        try { $plan->createMilestone($viewer, ['title' => 'Forbidden']); }
        catch (RuntimeException $error) { $same('PROJECT_PLAN_FORBIDDEN', $error->getMessage()); return; }
        throw new RuntimeException('Expected planning authorization rejection.');
    });

    $test('milestones and deliverables are durable backup entities', function () use ($same) {
        $metadata = BaselineMetadata::fromArray(json_decode(file_get_contents(dirname(__DIR__) . '/schema/mysql84/baseline.json'), true));
        $same('durable', $metadata->tablePolicy('project_milestones')['backup_policy']);
        $same('durable', $metadata->tablePolicy('project_deliverables')['backup_policy']);
        $same(true, in_array('deliverable_id', $metadata->tablePolicy('project_tasks')['columns'], true));
    });
} finally {
    $admin->exec('DROP DATABASE `' . $database . '`');
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed ? 1 : 0);
