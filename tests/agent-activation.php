<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/AgentActivationService.php';

class AgentActivationTests
{
    private $passed = 0; private $failed = 0;
    public function test($name, callable $callback) { try { $callback(); $this->passed++; echo "PASS  $name\n"; } catch (Exception $e) { $this->failed++; echo "FAIL  $name: {$e->getMessage()}\n"; } }
    public function same($expected, $actual) { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }
    public function true($value, $message = 'Expected true.') { if (!$value) { throw new RuntimeException($message); } }
    public function throws(callable $callback) { try { $callback(); } catch (Exception $e) { return; } throw new RuntimeException('Expected exception.'); }
    public function finish() { echo "\n{$this->passed} passed, {$this->failed} failed.\n"; return $this->failed ? 1 : 0; }
}

$suite = new AgentActivationTests();
$database = 'syndicatum_activation_test_' . bin2hex(random_bytes(5));
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1'); putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root'); putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32)));

try {
    $pdo = Db::pdo(); (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo);
    $owner = $auth->bootstrapAdministrator('activation-owner@example.test', 'Activation Owner', 'correct horse battery staple');
    $management = new ProjectManagementService($pdo);
    $project = $management->createProject($owner['id'], ['name' => 'Activation Project']);
    $agent = $management->createAgent($project['id'], $owner['id'], ['display_name' => 'Linked Agent']);
    $service = new AgentActivationService($pdo);

    $suite->test('new agents have a disabled empty activation binding', function () use ($suite, $service, $project, $agent, $owner) {
        $binding = $service->configuration($project['id'], $agent['agent_id'], $owner['id']);
        $suite->same(false, $binding['enabled']);
        $suite->same(false, $binding['configured']);
        $suite->same('', $binding['conversation_id']);
        $disabled = $service->configure($project['id'], $agent['agent_id'], $owner['id'], [
            'enabled' => false, 'provider' => 'codex', 'discussion_reference' => '', 'working_directory' => '',
        ]);
        $suite->same(false, $disabled['enabled']);
    });

    $suite->test('provider discussion references are normalized and working directory is optional', function () use ($suite, $service, $project, $agent, $owner) {
        $suite->throws(function () use ($service, $project, $agent, $owner) {
            $service->configure($project['id'], $agent['agent_id'], $owner['id'], ['enabled' => true, 'provider' => 'codex', 'discussion_reference' => 'https://example.test/thread']);
        });
        $suite->throws(function () use ($service, $project, $agent, $owner) {
            $service->configure($project['id'], $agent['agent_id'], $owner['id'], ['enabled' => true, 'conversation_id' => 'bad id', 'working_directory' => 'relative']);
        });
        $binding = $service->configure($project['id'], $agent['agent_id'], $owner['id'], [
            'enabled' => true,
            'provider' => 'codex',
            'discussion_reference' => 'codex://threads/01a06d4b-077b-79c0-afc9-8373a6887483',
            'working_directory' => '',
        ]);
        $suite->same(true, $binding['enabled']);
        $suite->same(true, $binding['configured']);
        $suite->same('codex', $binding['runtime_type']);
        $suite->same('codex', $binding['provider']);
        $suite->same('codex://threads/01a06d4b-077b-79c0-afc9-8373a6887483', $binding['discussion_reference']);
        $suite->same('', $binding['working_directory']);
    });

    $suite->test('the authenticated agent view returns only its own configured binding', function () use ($suite, $service, $project, $agent) {
        $binding = $service->ownConfiguration($project['id'], $agent['agent_id']);
        $suite->same($agent['agent_id'], $binding['agent_id']);
        $suite->same('01a06d4b-077b-79c0-afc9-8373a6887483', $binding['conversation_id']);
        $suite->same('', $binding['working_directory']);
    });

    $suite->test('activation audit metadata does not contain the conversation id or path', function () use ($suite, $pdo) {
        $metadata = $pdo->query("SELECT metadata_json FROM administrative_audit_events WHERE action = 'project.agent_activation_configured' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $suite->true(strpos($metadata, '01a06d4b') === false);
        $suite->true(strpos($metadata, 'chatviewer') === false);
    });

    exit($suite->finish());
} finally {
    if (isset($pdo)) { $pdo = null; }
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
