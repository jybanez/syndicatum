<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/ProjectRepository.php';
require_once dirname(__DIR__) . '/src/AgentActivationService.php';
require_once dirname(__DIR__) . '/src/ResponsesApiActivationService.php';
require_once dirname(__DIR__) . '/src/McpServiceTokenService.php';

class ResponsesApiActivationTests
{
    private $passed = 0; private $failed = 0;
    public function test($name, callable $callback) { try { $callback(); $this->passed++; echo "PASS  $name\n"; } catch (Exception $e) { $this->failed++; echo "FAIL  $name: {$e->getMessage()}\n"; } }
    public function same($expected, $actual) { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }
    public function true($value, $message = 'Expected true.') { if (!$value) { throw new RuntimeException($message); } }
    public function throws(callable $callback, $expected) { try { $callback(); } catch (Exception $e) { if ($e->getMessage() === $expected) { return; } throw $e; } throw new RuntimeException('Expected ' . $expected); }
    public function finish() { echo "\n{$this->passed} passed, {$this->failed} failed.\n"; return $this->failed ? 1 : 0; }
}

$suite = new ResponsesApiActivationTests();
$database = 'syndicatum_responses_test_' . bin2hex(random_bytes(5));
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1'); putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root'); putenv('PBB_AGENTCHAT_DB_PASS='); putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32)));

try {
    $pdo = Db::pdo(); (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo); $owner = $auth->bootstrapAdministrator('responses@example.test', 'Responses Owner', 'correct horse battery staple');
    $management = new ProjectManagementService($pdo); $project = $management->createProject($owner['id'], ['name' => 'Responses Project']);
    $agent = $management->createAgent($project['id'], $owner['id'], ['display_name' => 'PBB ChatGPT', 'provider' => 'chatgpt']);
    $activation = new AgentActivationService($pdo);
    $suite->test('Responses API activation is rejected at the configuration boundary', function () use ($suite, $activation, $project, $agent, $owner) {
        $suite->throws(function () use ($activation, $project, $agent, $owner) {
            $activation->configure($project['id'], $agent['agent_id'], $owner['id'], [
                'enabled' => true, 'provider' => 'chatgpt', 'activation_driver' => 'responses_api',
                'responses_api_key' => 'sk-test-platform-key', 'responses_model' => 'gpt-test',
            ]);
        }, 'Responses API and Workspace Agent activation are disabled. Use the Syndicatum browser companion.');
    });
    $binding = $activation->configure($project['id'], $agent['agent_id'], $owner['id'], [
        'enabled' => false, 'provider' => 'chatgpt', 'activation_driver' => 'responses_api',
        'discussion_reference' => 'https://chatgpt.com/c/46604e19-202c-4224-bb05-d2ddf5f58c9f',
    ]);
    $ownerParticipant = (int) $pdo->query('SELECT id FROM project_participants WHERE project_id = ' . (int) $project['id'] . ' AND user_id = ' . (int) $owner['id'])->fetchColumn();
    $agentParticipant = (int) $pdo->query('SELECT id FROM project_participants WHERE project_id = ' . (int) $project['id'] . ' AND agent_id = ' . (int) $agent['agent_id'])->fetchColumn();
    $access = ['project_id' => $project['id'], 'participant_id' => $ownerParticipant, 'project_status' => 'active', 'role' => 'owner', 'identity' => ['kind' => 'human']];
    $created = (new ProjectRepository($pdo))->createMessage($access, ['body' => 'Do the autonomous test', 'direct_participant_ids' => [$agentParticipant]]);

    $suite->test('disabled Responses API configuration stores no secret and queues no message', function () use ($suite, $binding, $pdo) {
        $suite->same('responses_api', $binding['activation_driver']);
        $suite->same(false, $binding['enabled']);
        $suite->same(false, $binding['responses_api_key_configured']);
        $suite->true(!isset($binding['responses_api_key']));
        $suite->same(0, (int) $pdo->query('SELECT COUNT(*) FROM responses_api_deliveries')->fetchColumn());
    });

    exit($suite->finish());
} finally {
    Db::reset(); $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
