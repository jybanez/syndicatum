<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/PostBaselineMigrator.php';
require_once dirname(__DIR__) . '/src/ProjectTemplateService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';

$database = 'synd_tpl_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^synd_tpl_test_[a-f0-9]{12}$/', $database)) { throw new RuntimeException('Unsafe test database name.'); }
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
    $user = (new AuthService($pdo))->bootstrapAdministrator('templates@example.test', 'Template Owner', 'correct horse battery staple');
    $service = new ProjectTemplateService($pdo);
    $categories = $service->listCategories();
    $generalCategoryId = (int) $categories[0]['id'];
    $template = null; $lead = null;

    $test('approved built-in catalog ships with provider-neutral role presets', function () use ($service, $same) {
        $templates = $service->listTemplates();
        $same(16, count($templates));
        $byName = [];
        foreach ($templates as $entry) { $byName[$entry['name']] = $entry; }
        foreach (['Blank Project', 'Software Product Delivery', 'Bug Investigation and Resolution', 'Research and Recommendation'] as $name) {
            if (!isset($byName[$name])) { throw new RuntimeException('Missing built-in template: ' . $name); }
            $same('system', $byName[$name]['origin']);
        }
        $same(null, $byName['Blank Project']['description']);
        $same(null, $byName['Blank Project']['instructions']);
        $same(3, count($byName['Software Product Delivery']['agents']));
        $same('unassigned', $byName['Software Product Delivery']['agents'][0]['provider']);
        foreach (['Cross-Functional Initiative', 'Security Review and Hardening', 'Incident Response and Recovery', 'Deployment and Release', 'Architecture Decision Record', 'Commercial Readiness Review', 'Technical Documentation'] as $name) {
            if (!isset($byName[$name])) { throw new RuntimeException('Missing expanded built-in template: ' . $name); }
            $same('system', $byName[$name]['origin']);
            $same(3, count($byName[$name]['agents']));
            $same('unassigned', $byName[$name]['agents'][0]['provider']);
        }
        foreach (['Administrative Process Improvement', 'Event Planning and Delivery', 'Marketing Campaign', 'Product or Service Launch', 'Employee Hiring and Onboarding'] as $name) {
            if (!isset($byName[$name])) { throw new RuntimeException('Missing small-business built-in template: ' . $name); }
            $same('system', $byName[$name]['origin']);
            $same(3, count($byName[$name]['agents']));
            $same('unassigned', $byName[$name]['agents'][0]['provider']);
        }
        $categories = array_unique(array_map(fn ($entry) => $entry['category']['name'], $templates));
        $same(6, count($categories));
    });
    $test('built-in templates reject administrator mutation', function () use ($service, $user) {
        $builtIn = $service->listTemplates()[0];
        try { $service->archiveTemplate($builtIn['id'], $user['id'], $builtIn['version']); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'TEMPLATE_SYSTEM_MANAGED') { return; } throw $error; }
        throw new RuntimeException('Expected TEMPLATE_SYSTEM_MANAGED.');
    });

    $test('administrator creates categorized persistent project context', function () use (&$template, $service, $user, $generalCategoryId, $same) {
        $template = $service->createTemplate($user['id'], ['category_id' => $generalCategoryId, 'name' => 'Product delivery', 'description' => 'Coordinate delivery.', 'instructions' => 'Verify before completion.']);
        $same('Product delivery', $template['name']); $same('General', $template['category']['name']); $same('custom', $template['origin']); $same(1, $template['version']); $same([], $template['agents']);
    });
    $test('template stores ordered agent presets and supervision', function () use (&$template, &$lead, $service, $user, $same) {
        $template = $service->createAgent($template['id'], $user['id'], ['version' => $template['version'], 'display_name' => 'Lead Developer', 'provider' => 'codex', 'role_title' => 'Lead Developer']);
        $lead = $template['agents'][0];
        $template = $service->createAgent($template['id'], $user['id'], ['version' => $template['version'], 'display_name' => 'Reviewer', 'provider' => 'chatgpt', 'role_title' => 'Commercial Reviewer', 'supervising_agent_id' => $lead['id']]);
        $same(2, count($template['agents'])); $same($lead['id'], $template['agents'][1]['supervising_agent_id']); $same('Lead Developer', $template['agents'][1]['supervising_agent_name']);
    });
    $test('project creation applies template context and selected presets atomically', function () use ($template, $pdo, $user, $same) {
        $agents = $template['agents'];
        $project = (new ProjectManagementService($pdo))->createProject($user['id'], [
            'name' => 'Templated delivery', 'description' => $template['description'], 'instructions' => $template['instructions'],
            'template_id' => $template['id'], 'template_version' => $template['version'],
            'template_agents' => [
                ['template_agent_id' => $agents[0]['id'], 'provider' => 'codex', 'display_name' => 'Delivery Lead', 'role_title' => 'Delivery Lead'],
                ['template_agent_id' => $agents[1]['id'], 'provider' => 'gemini'],
            ],
        ]);
        $same($template['public_id'], $project['source_template_public_id']); $same($template['name'], $project['source_template_name']);
        $same($template['version'], (int) $project['source_template_version']); $same(2, count($project['agent_claims']));
        $same(2, (int) $pdo->query('SELECT COUNT(*) FROM project_agents WHERE project_id = ' . (int) $project['id'])->fetchColumn());
        $same(1, (int) $pdo->query('SELECT COUNT(*) FROM project_agents WHERE project_id = ' . (int) $project['id'] . ' AND supervising_participant_id IS NOT NULL')->fetchColumn());
    });
    $test('invalid preset configuration leaves no partial project', function () use ($template, $pdo, $user, $same) {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
        try {
            (new ProjectManagementService($pdo))->createProject($user['id'], [
                'name' => 'Must roll back', 'template_id' => $template['id'], 'template_version' => $template['version'],
                'template_agents' => [['template_agent_id' => $template['agents'][0]['id'], 'provider' => 'unassigned']],
            ]);
        } catch (InvalidArgumentException $error) {
            $same($before, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
            return;
        }
        throw new RuntimeException('Expected invalid provider configuration to fail.');
    });
    $test('stale template mutations fail closed', function () use ($template, $service, $user) {
        try { $service->updateTemplate($template['id'], $user['id'], ['version' => 1, 'category_id' => $template['category_id'], 'name' => 'Stale']); }
        catch (RuntimeException $error) { if ($error->getMessage() === 'TEMPLATE_VERSION_CONFLICT') { return; } throw $error; }
        throw new RuntimeException('Expected TEMPLATE_VERSION_CONFLICT.');
    });
    $test('archived templates are omitted from the active collection', function () use (&$template, $service, $user, $same) {
        $template = $service->archiveTemplate($template['id'], $user['id'], $template['version']);
        $same('archived', $template['status']); $same(16, count($service->listTemplates(false))); $same(17, count($service->listTemplates(true)));
    });
    $test('template tables are durable backup entities', function () use ($same) {
        $policy = json_decode(file_get_contents(dirname(__DIR__) . '/schema/mysql84/backup-policy-v1.json'), true);
        $same('durable', $policy['tables']['project_templates']['backup_policy']);
        $same('durable', $policy['tables']['project_template_agents']['backup_policy']);
        $same('durable', $policy['tables']['project_template_categories']['backup_policy']);
    });
} finally {
    $pdo = null;
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
