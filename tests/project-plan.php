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

    $test('plan deletion is version checked, dependency safe, audited, and broadcast', function () use ($plan, $tasks, $access, $same, $pdo) {
        $milestone = $plan->createMilestone($access, ['title' => 'Temporary checkpoint']);
        $deliverable = $plan->createDeliverable($access, ['title' => 'Temporary output', 'milestone_id' => $milestone['id']]);
        $task = $tasks->create($access, ['title' => 'Temporary work', 'deliverable_id' => $deliverable['id']]);
        try { $plan->deleteMilestone($access, $milestone['id'], ['version' => $milestone['version']]); }
        catch (RuntimeException $error) { $same('MILESTONE_DELETE_HAS_DELIVERABLES', $error->getMessage()); $milestoneBlocked = true; }
        if (empty($milestoneBlocked)) { throw new RuntimeException('Expected milestone child protection.'); }
        try { $plan->deleteDeliverable($access, $deliverable['id'], ['version' => $deliverable['version']]); }
        catch (RuntimeException $error) { $same('DELIVERABLE_DELETE_HAS_TASKS', $error->getMessage()); $deliverableBlocked = true; }
        if (empty($deliverableBlocked)) { throw new RuntimeException('Expected deliverable task protection.'); }
        $tasks->update($access, $task['id'], ['version' => $task['version'], 'deliverable_id' => null]);
        $afterDeliverable = $plan->deleteDeliverable($access, $deliverable['id'], ['version' => $deliverable['version']]);
        $same(false, in_array($deliverable['id'], array_column($afterDeliverable['deliverables'], 'id'), true));
        $afterMilestone = $plan->deleteMilestone($access, $milestone['id'], ['version' => $milestone['version']]);
        $same(false, in_array($milestone['id'], array_column($afterMilestone['milestones'], 'id'), true));
        $same(2, (int) $pdo->query("SELECT COUNT(*) FROM administrative_audit_events WHERE action = 'project.plan_item_deleted'")->fetchColumn());
        $same(2, (int) $pdo->query("SELECT COUNT(*) FROM message_events_outbox WHERE event_type = 'syndicatum.project_plan.changed' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.change')) = 'deleted'")->fetchColumn());
        $stale = $plan->createMilestone($access, ['title' => 'Stale deletion']);
        $updated = $plan->updateMilestone($access, $stale['id'], ['version' => $stale['version'], 'title' => $stale['title'],
            'description' => null, 'status' => 'planned', 'target_at' => null, 'position' => $stale['position']]);
        try { $plan->deleteMilestone($access, $stale['id'], ['version' => $stale['version']]); }
        catch (RuntimeException $error) { $same('MILESTONE_VERSION_CONFLICT', $error->getMessage()); return; }
        throw new RuntimeException('Expected stale milestone deletion rejection at version ' . $updated['version'] . '.');
    });

    $test('explicit agent plan stewardship links existing tasks and updates guarded progress with evidence', function () use ($plan, $tasks, $access, $same, $pdo, $owner, $project) {
        $unlinked = $tasks->create($access, ['title' => 'Existing research evidence']);
        $created = (new ProjectManagementService($pdo))->createAgent($project['id'], $owner['id'], [
            'display_name' => 'Executive Assistant', 'role_title' => 'Executive Assistant',
            'role_summary' => 'Maintains project progress.',
            'scopes' => ['messages:read', 'plan:progress'],
        ]);
        $participant = $pdo->prepare("SELECT id FROM project_participants WHERE project_id = ? AND agent_id = ? AND kind = 'agent'");
        $participant->execute([$project['id'], $created['agent_id']]);
        $agentAccess = ['project_id' => (int) $project['id'], 'participant_id' => (int) $participant->fetchColumn(),
            'project_status' => 'active', 'role' => 'agent',
            'identity' => ['kind' => 'agent', 'agent' => ['id' => (int) $created['agent_id']]]];
        $current = $plan->plan($agentAccess);
        $same(false, $current['can_manage']); $same(true, $current['can_update_progress']);
        $target = $current['deliverables'][0];
        $linked = $tasks->updateDeliverableLink($agentAccess, $unlinked['id'], [
            'version' => $unlinked['version'], 'deliverable_id' => $target['id'],
            'note' => 'The existing research task produced this deliverable.',
        ]);
        $same($target['id'], $linked['deliverable_id']);
        $same('deliverable_linked', $linked['events'][0]['event_type']);
        $same($target['id'], $linked['events'][0]['metadata']['to_deliverable_id']);
        $staleRejected = false;
        try { $tasks->updateDeliverableLink($agentAccess, $unlinked['id'], [
            'version' => $unlinked['version'], 'deliverable_id' => null, 'note' => 'Stale unlink.',
        ]); }
        catch (RuntimeException $error) { $same('TASK_VERSION_CONFLICT', $error->getMessage()); $staleRejected = true; }
        if (!$staleRejected) { throw new RuntimeException('Expected stale task-link rejection.'); }
        $ready = $plan->createDeliverable($access, ['title' => 'Already approved output', 'status' => 'completed']);
        $open = $tasks->create($access, ['title' => 'Unexpected follow-up work']);
        $readyRejected = false;
        try { $tasks->updateDeliverableLink($agentAccess, $open['id'], [
            'version' => $open['version'], 'deliverable_id' => $ready['id'], 'note' => 'Would invalidate readiness.',
        ]); }
        catch (RuntimeException $error) { $same('DELIVERABLE_TASK_LINK_CONFLICT', $error->getMessage()); $readyRejected = true; }
        if (!$readyRejected) { throw new RuntimeException('Expected ready-deliverable link rejection.'); }
        $milestone = null;
        foreach ($current['milestones'] as $candidate) {
            if ($candidate['deliverable_count'] > $candidate['ready_deliverable_count']) { $milestone = $candidate; break; }
        }
        if ($milestone === null) { throw new RuntimeException('Expected a milestone with unfinished deliverables.'); }
        $updated = $plan->updateMilestoneProgress($agentAccess, $milestone['id'], [
            'version' => $milestone['version'], 'status' => 'at_risk', 'note' => 'Task approval remains open.',
        ]);
        $same('at_risk', $updated['status']);
        $event = $pdo->query("SELECT event_type FROM message_events_outbox WHERE event_type = 'syndicatum.project_plan.changed' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $same('syndicatum.project_plan.changed', $event);
        try { $plan->updateMilestoneProgress($agentAccess, $milestone['id'], ['version' => $updated['version'], 'status' => 'completed', 'note' => 'Premature completion.']); }
        catch (RuntimeException $error) { $same('MILESTONE_DELIVERABLES_INCOMPLETE', $error->getMessage()); return; }
        throw new RuntimeException('Expected incomplete milestone completion rejection.');
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
