<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/ProjectChangeProposalService.php';
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

    $details = $service->proposeProjectDetails($agentAccess, ['description' => 'A reviewed project description.', 'rationale' => 'The original brief is incomplete.']);
    $test('agents submit durable pending project proposals', function () use ($same, $details, $service, $project, $owner) {
        $same('pending', $details['status']); $same('project_details', $details['proposal_type']);
        $list = $service->listForReview($project['id'], $owner['id'], 'pending');
        $same(1, count($list)); $same('Planning Agent', $list[0]['proposer']['display_name']);
    });

    $test('approval atomically applies project details and records the reviewer', function () use ($same, $details, $service, $project, $owner, $pdo) {
        $reviewed = $service->review($project['id'], $owner['id'], $details['id'], $details['version'], 'approve', 'Approved for clarity.');
        $same('approved', $reviewed['status']); $same('Proposal Owner', $reviewed['reviewer_name']);
        $query = $pdo->prepare('SELECT description FROM projects WHERE id = ?'); $query->execute([$project['id']]);
        $same('A reviewed project description.', $query->fetchColumn());
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
