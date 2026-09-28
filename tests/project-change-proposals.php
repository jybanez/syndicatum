<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/ProjectChangeProposalService.php';
require_once dirname(__DIR__) . '/src/ProjectPlanService.php';
require_once dirname(__DIR__) . '/src/PostBaselineMigrator.php';
require_once dirname(__DIR__) . '/src/BaselineMetadata.php';

$database = 'syndicatum_proposal_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_proposal_test_[a-f0-9]{12}$/', $database)) { throw new RuntimeException('Unsafe test database name.'); }
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
    $auth = new AuthService($pdo); $management = new ProjectManagementService($pdo);
    $owner = $auth->bootstrapAdministrator('proposal-owner@example.test', 'Proposal Owner', 'correct horse battery staple');
    $project = $management->createProject($owner['id'], ['name' => 'Proposal Contract']);
    $sourceAgent = $management->createAgent($project['id'], $owner['id'], ['display_name' => 'Planning Agent', 'provider' => 'Codex']);
    $participant = $pdo->prepare("SELECT id FROM project_participants WHERE project_id = ? AND agent_id = ? AND kind = 'agent'");
    $participant->execute([$project['id'], $sourceAgent['agent_id']]);
    $agentAccess = ['project_id' => (int) $project['id'], 'participant_id' => (int) $participant->fetchColumn(),
        'project_status' => 'active', 'role' => 'agent', 'identity' => ['kind' => 'agent', 'agent' => ['authenticated_agent_id' => $sourceAgent['agent_id']]]];
    $service = new ProjectChangeProposalService($pdo);
    $ownerParticipant = $pdo->prepare("SELECT id FROM project_participants WHERE project_id = ? AND user_id = ? AND kind = 'human'");
    $ownerParticipant->execute([$project['id'], $owner['id']]);
    $ownerAccess = ['project_id' => (int) $project['id'], 'participant_id' => (int) $ownerParticipant->fetchColumn(),
        'project_status' => 'active', 'role' => 'owner', 'identity' => ['kind' => 'human']];

    $details = $service->proposeProjectDetails($agentAccess, ['description' => 'A reviewed project description.', 'rationale' => 'The original brief is incomplete.']);
    $test('agents submit durable pending project proposals', function () use ($same, $details, $service, $project, $owner) {
        $same('pending', $details['status']); $same('project_details', $details['proposal_type']);
        $list = $service->listForReview($project['id'], $owner['id'], 'pending');
        $same(1, count($list)); $same('Planning Agent', $list[0]['proposer']['display_name']);
    });

    $test('proposal creation emits a content-free Realtime invalidation', function () use ($same, $details, $pdo) {
        $query = $pdo->prepare('SELECT event_type, payload_json FROM message_events_outbox WHERE event_type = ? ORDER BY id DESC LIMIT 1');
        $query->execute([MessageOutbox::EVENT_PROJECT_PROPOSALS_CHANGED]);
        $event = $query->fetch(PDO::FETCH_ASSOC);
        $same(MessageOutbox::EVENT_PROJECT_PROPOSALS_CHANGED, $event['event_type']);
        $payload = json_decode($event['payload_json'], true);
        $same((int) $details['id'], (int) $payload['proposal_id']);
        $same('pending', $payload['status']);
        $same(false, array_key_exists('payload', $payload));
        $same(false, array_key_exists('rationale', $payload));
    });

    $test('approval atomically applies project details and records the reviewer', function () use ($same, $details, $service, $project, $owner, $pdo) {
        $reviewed = $service->review($project['id'], $owner['id'], $details['id'], $details['version'], 'approve', 'Approved for clarity.');
        $same('approved', $reviewed['status']); $same('Proposal Owner', $reviewed['reviewer_name']);
        $query = $pdo->prepare('SELECT description FROM projects WHERE id = ?'); $query->execute([$project['id']]);
        $same('A reviewed project description.', $query->fetchColumn());
        $eventQuery = $pdo->prepare('SELECT payload_json FROM message_events_outbox WHERE event_type = ? ORDER BY id DESC LIMIT 1');
        $eventQuery->execute([MessageOutbox::EVENT_PROJECT_PROPOSALS_CHANGED]);
        $eventPayload = json_decode($eventQuery->fetchColumn(), true);
        $same('approved', $eventPayload['status']); $same(2, (int) $eventPayload['version']);
        try { $service->review($project['id'], $owner['id'], $details['id'], $details['version'], 'approve'); }
        catch (RuntimeException $error) { $same('PROPOSAL_VERSION_CONFLICT', $error->getMessage()); return; }
        throw new RuntimeException('Expected stale proposal review rejection.');
    });

    $setup = $service->proposeAgentSetup($agentAccess, ['display_name' => 'Accessibility Reviewer', 'provider' => 'Codex',
        'role_title' => 'Accessibility reviewer', 'role_summary' => 'Reviews deliverables.', 'rationale' => 'Public outputs need review.']);
    $test('new-agent approval creates only the proposed profile and never returns credentials', function () use ($same, $setup, $service, $project, $owner, $pdo) {
        $reviewed = $service->review($project['id'], $owner['id'], $setup['id'], $setup['version'], 'approve');
        $same('approved', $reviewed['status']);
        if (array_key_exists('claim_code', $reviewed) || array_key_exists('scopes', $reviewed['payload'])) { throw new RuntimeException('Credential material escaped the proposal boundary.'); }
        $query = $pdo->prepare('SELECT display_name, provider FROM project_agents WHERE project_id = ? AND agent_id = ?');
        $query->execute([$project['id'], $reviewed['applied_agent_id']]);
        $row = $query->fetch(); $same('Accessibility Reviewer', $row['display_name']); $same('Codex', $row['provider']);
    });

    $profile = $service->proposeAgentProfileUpdate($agentAccess, ['target_agent_id' => $sourceAgent['agent_id'], 'role_title' => 'Release coordinator']);
    $test('profile proposals can be rejected without changing the target agent', function () use ($same, $profile, $service, $project, $owner, $pdo, $sourceAgent) {
        $reviewed = $service->review($project['id'], $owner['id'], $profile['id'], $profile['version'], 'reject', 'Not needed yet.');
        $same('rejected', $reviewed['status']);
        $query = $pdo->prepare('SELECT role_title FROM project_agents WHERE project_id = ? AND agent_id = ?');
        $query->execute([$project['id'], $sourceAgent['agent_id']]); $same(null, $query->fetchColumn());
    });

    $plan = $service->proposeProjectPlan($agentAccess, [
        'milestones' => [[
            'title' => 'Technical SEO baseline', 'description' => 'Establish the measurable starting point.',
            'target_date' => '2030-10-15',
            'deliverables' => [[
                'title' => 'Crawl and indexation audit', 'due_date' => '2030-10-10',
                'owner_participant_id' => $agentAccess['participant_id'],
            ], ['title' => 'Prioritized remediation plan']],
        ]],
        'standalone_deliverables' => [['title' => 'SEO measurement brief']],
        'rationale' => 'The project needs outcome checkpoints before tasks are assigned.',
    ]);
    $test('project-plan approval atomically creates nested milestones and deliverables', function () use ($same, $plan, $service, $project, $owner, $pdo) {
        $same('project_plan', $plan['proposal_type']); $same('pending', $plan['status']);
        $same(1, count($plan['payload']['milestones']));
        $same(2, count($plan['payload']['milestones'][0]['deliverables']));
        $reviewed = $service->review($project['id'], $owner['id'], $plan['id'], $plan['version'], 'approve');
        $same('approved', $reviewed['status']);
        $milestones = $pdo->prepare('SELECT title, status, target_at FROM project_milestones WHERE project_id = ? ORDER BY position, id');
        $milestones->execute([$project['id']]); $rows = $milestones->fetchAll(PDO::FETCH_ASSOC);
        $same(1, count($rows)); $same('Technical SEO baseline', $rows[0]['title']); $same('planned', $rows[0]['status']);
        $same('2030-10-15 00:00:00', $rows[0]['target_at']);
        $deliverables = $pdo->prepare('SELECT title, milestone_id, status FROM project_deliverables WHERE project_id = ? ORDER BY milestone_id IS NULL, position, id');
        $deliverables->execute([$project['id']]); $outputs = $deliverables->fetchAll(PDO::FETCH_ASSOC);
        $same(3, count($outputs)); $same('Crawl and indexation audit', $outputs[0]['title']);
        $same('planned', $outputs[0]['status']); $same(null, $outputs[2]['milestone_id']);
    });

    $stalePlan = $service->proposeProjectPlan($agentAccess, ['milestones' => [['title' => 'Content authority']]]);
    (new ProjectPlanService($pdo))->createMilestone($ownerAccess, ['title' => 'Owner-added checkpoint']);
    $test('project-plan approval rejects a stale plan without partial writes', function () use ($same, $stalePlan, $service, $project, $owner, $pdo) {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM project_milestones')->fetchColumn();
        try { $service->review($project['id'], $owner['id'], $stalePlan['id'], $stalePlan['version'], 'approve'); }
        catch (RuntimeException $error) {
            $same('PROPOSAL_PLAN_CHANGED', $error->getMessage());
            $same($before, (int) $pdo->query('SELECT COUNT(*) FROM project_milestones')->fetchColumn());
            return;
        }
        throw new RuntimeException('Expected stale project plan rejection.');
    });

    $test('project-plan proposals enforce bounded create-only input', function () use ($service, $agentAccess) {
        try { $service->proposeProjectPlan($agentAccess, ['milestones' => array_fill(0, 11, ['title' => 'Too many'])]); }
        catch (InvalidArgumentException $error) {
            if (strpos($error->getMessage(), 'at most 10 milestones') === false) { throw $error; }
            return;
        }
        throw new RuntimeException('Expected oversized project plan rejection.');
    });

    $test('human and credential-shaped submissions are rejected', function () use ($same, $service, $agentAccess) {
        $human = $agentAccess; $human['identity']['kind'] = 'human';
        try { $service->proposeProjectDetails($human, ['name' => 'Forbidden']); }
        catch (RuntimeException $error) { $same('PROPOSAL_AGENT_REQUIRED', $error->getMessage()); }
        try { $service->proposeAgentSetup($agentAccess, ['display_name' => 'Unsafe profile', 'api_key' => 'must-not-persist']); }
        catch (InvalidArgumentException $error) { return; }
        throw new RuntimeException('Expected credential-shaped field rejection.');
    });

    $test('proposal records are part of the durable backup contract', function () use ($same) {
        $metadata = BaselineMetadata::fromArray(json_decode(file_get_contents(dirname(__DIR__) . '/schema/mysql84/baseline.json'), true));
        $same('durable', $metadata->tablePolicy('project_change_proposals')['backup_policy']);
    });
} finally {
    $admin->exec('DROP DATABASE `' . $database . '`');
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed ? 1 : 0);
