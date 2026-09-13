<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/AgentActivationService.php';
require_once dirname(__DIR__) . '/src/ConnectorDeviceService.php';
require_once dirname(__DIR__) . '/src/DiscussionBindingService.php';
require_once dirname(__DIR__) . '/src/ProjectRepository.php';

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

    $suite->test('ChatGPT browser companion activation accepts discussion URLs while legacy drivers remain disabled', function () use ($suite, $service, $project, $agent, $owner, $pdo) {
        $suite->throws(function () use ($service) {
            $service->validateConfigurationInput(['enabled' => false, 'provider' => 'chatgpt', 'discussion_reference' => '']);
        });
        $validated = $service->validateConfigurationInput([
            'enabled' => false, 'provider' => 'chatgpt',
            'discussion_reference' => 'https://chatgpt.com/c/46604e19-202c-4224-bb05-d2ddf5f58c9f?model=test',
        ]);
        $suite->same('https://chatgpt.com/c/46604e19-202c-4224-bb05-d2ddf5f58c9f', $validated['conversation_id']);
        $binding = $service->configure($project['id'], $agent['agent_id'], $owner['id'], [
            'enabled' => true, 'provider' => 'chatgpt', 'activation_driver' => 'browser_companion',
            'discussion_reference' => 'https://chatgpt.com/c/46604e19-202c-4224-bb05-d2ddf5f58c9f?model=test',
        ]);
        $suite->same(true, $binding['enabled']);
        $suite->same('browser_companion', $binding['activation_driver']);
        $suite->same('chatgpt', $binding['provider']);
        $suite->same('https://chatgpt.com/c/46604e19-202c-4224-bb05-d2ddf5f58c9f', $binding['discussion_reference']);
        $connector = new ConnectorDeviceService($pdo);
        $chatGptBindings = $connector->bindings(['user_id' => $owner['id']], 'chatgpt');
        $suite->same(1, count($chatGptBindings));
        $suite->same('browser_companion', $binding['activation_driver']);
        $suite->same('chatgpt', $chatGptBindings[0]['provider']);
        $suite->same(0, count($connector->bindings(['user_id' => $owner['id']], 'codex')));
        $suite->throws(function () use ($connector, $owner) { $connector->bindings(['user_id' => $owner['id']], 'unknown'); });
        $ownerParticipant = (int) $pdo->query('SELECT id FROM project_participants WHERE project_id = ' . (int) $project['id'] . ' AND user_id = ' . (int) $owner['id'])->fetchColumn();
        $created = (new ProjectRepository($pdo))->createMessage([
            'project_id' => $project['id'], 'participant_id' => $ownerParticipant, 'project_status' => 'active',
            'role' => 'owner', 'identity' => ['kind' => 'human'],
        ], ['body' => 'Sensitive body stays in Syndicatum', 'direct_participant_ids' => [$chatGptBindings[0]['participant_id']]]);
        $pending = $connector->pendingNotifications(['user_id' => $owner['id']], 'chatgpt');
        $suite->same(1, count($pending));
        $suite->same($created['message']['id'], $pending[0]['message']['id']);
        $suite->true(!isset($pending[0]['message']['body']), 'Recovery notifications must not expose message bodies.');
        $connector->markNotificationDelivered(['user_id' => $owner['id']], 'chatgpt', $project['id'], $agent['agent_id'], $created['message']['id']);
        $suite->same(0, count($connector->pendingNotifications(['user_id' => $owner['id']], 'chatgpt')));
        $connector->markNotificationDelivered(['user_id' => $owner['id']], 'chatgpt', $project['id'], $agent['agent_id'], $created['message']['id']);
        $suite->throws(function () use ($service, $project, $agent, $owner) {
            $service->configure($project['id'], $agent['agent_id'], $owner['id'], [
                'enabled' => true, 'provider' => 'chatgpt', 'activation_driver' => 'responses_api',
                'responses_api_key' => 'sk-should-not-be-stored',
            ]);
        });
        $suite->throws(function () use ($service, $project, $agent, $owner) {
            $service->configure($project['id'], $agent['agent_id'], $owner['id'], [
                'enabled' => true, 'provider' => 'chatgpt', 'activation_driver' => 'workspace_agent',
                'discussion_reference' => 'agtch_test_agent_123', 'workspace_agent_access_token' => 'should-not-be-stored',
            ]);
        });
        $binding = $service->configure($project['id'], $agent['agent_id'], $owner['id'], [
            'enabled' => false, 'provider' => 'chatgpt', 'activation_driver' => 'browser_companion',
            'discussion_reference' => 'https://chatgpt.com/g/g-test-assistant/c/46604e19-202c-4224-bb05-d2ddf5f58c9f?model=test',
        ]);
        $suite->same(false, $binding['enabled']);
        $suite->same('browser_companion', $binding['activation_driver']);
        $suite->same('https://chatgpt.com/g/g-test-assistant/c/46604e19-202c-4224-bb05-d2ddf5f58c9f', $binding['discussion_reference']);
        $suite->same(false, $binding['responses_api_key_configured']);
        $suite->throws(function () use ($service, $project, $agent, $owner) {
            $service->configure($project['id'], $agent['agent_id'], $owner['id'], [
                'enabled' => false, 'provider' => 'chatgpt', 'discussion_reference' => 'https://example.test/c/not-chatgpt',
            ]);
        });
    });

    $suite->test('Gemini browser companion securely relays an authoritative response through its bound identity', function () use ($suite, $service, $project, $agent, $owner, $pdo) {
        $suite->throws(function () use ($service) {
            $service->validateConfigurationInput(['enabled' => false, 'provider' => 'gemini', 'discussion_reference' => '']);
        });
        $suite->throws(function () use ($service) {
            $service->validateConfigurationInput(['enabled' => true, 'provider' => 'gemini', 'discussion_reference' => 'https://gemini.google.com/share/not-editable']);
        });
        $binding = $service->configure($project['id'], $agent['agent_id'], $owner['id'], [
            'enabled' => true, 'provider' => 'gemini', 'activation_driver' => 'browser_companion',
            'discussion_reference' => 'https://gemini.google.com/app/6f8b2d_example?hl=en',
        ]);
        $suite->same(true, $binding['enabled']);
        $suite->same('gemini', $binding['provider']);
        $suite->same('browser_companion', $binding['activation_driver']);
        $suite->same('https://gemini.google.com/app/6f8b2d_example', $binding['discussion_reference']);
        $connector = new ConnectorDeviceService($pdo);
        $geminiBindings = $connector->bindings(['user_id' => $owner['id']], 'gemini');
        $suite->same(1, count($geminiBindings));
        $suite->same('gemini', $geminiBindings[0]['provider']);
        $ownerParticipant = (int) $pdo->query('SELECT id FROM project_participants WHERE project_id = ' . (int) $project['id'] . ' AND user_id = ' . (int) $owner['id'])->fetchColumn();
        $created = (new ProjectRepository($pdo))->createMessage([
            'project_id' => $project['id'], 'participant_id' => $ownerParticipant, 'project_status' => 'active',
            'role' => 'owner', 'identity' => ['kind' => 'human'],
        ], ['body' => 'Gemini retrieves this from the timeline', 'direct_participant_ids' => [$geminiBindings[0]['participant_id']]]);
        $pending = $connector->pendingNotifications(['user_id' => $owner['id']], 'gemini');
        $suite->same(1, count($pending));
        $suite->same($created['message']['id'], $pending[0]['message']['id']);
        $suite->same('Gemini retrieves this from the timeline', $pending[0]['message']['body']);
        $reply = $connector->submitAgentReply(['user_id' => $owner['id']], 'gemini', $project['id'], $agent['agent_id'], $created['message']['id'], 'Gemini browser response');
        $suite->same(true, $reply['created']);
        $suite->same('Gemini browser response', $reply['message']['body']);
        $suite->same($created['message']['id'], $reply['message']['reply_to_message_id']);
        $suite->same($geminiBindings[0]['participant_id'], $reply['message']['sender']['participant_id']);
        $duplicate = $connector->submitAgentReply(['user_id' => $owner['id']], 'gemini', $project['id'], $agent['agent_id'], $created['message']['id'], 'A duplicate body must not replace the first reply');
        $suite->same(false, $duplicate['created']);
        $suite->same($reply['message']['id'], $duplicate['message']['id']);
        $suite->same(0, count($connector->pendingNotifications(['user_id' => $owner['id']], 'gemini')));
        $acknowledged = $pdo->query('SELECT acknowledged_at FROM message_addressees WHERE message_id = ' . (int) $created['message']['id'] . ' AND participant_id = ' . (int) $geminiBindings[0]['participant_id'])->fetchColumn();
        $suite->true($acknowledged !== false && $acknowledged !== null, 'The originating message must be acknowledged only after the reply is posted.');
        $suite->throws(function () use ($connector, $owner, $project, $agent, $created) {
            $connector->submitAgentReply(['user_id' => $owner['id']], 'chatgpt', $project['id'], $agent['agent_id'], $created['message']['id'], 'Not supported');
        });
    });

    $suite->test('one-time discussion codes bind the authenticated device active tab without storing plaintext', function () use ($suite, $service, $project, $agent, $owner, $pdo) {
        $bindingService = new DiscussionBindingService($pdo);
        $issued = $bindingService->issue($project['id'], $agent['agent_id'], $owner['id']);
        $suite->same('gemini', $issued['provider']);
        $suite->true(strpos($issued['binding_code'], 'syndicatum_binding_') === 0);
        $stored = $pdo->query('SELECT code_hash, consumed_at FROM connector_discussion_binding_codes ORDER BY id DESC LIMIT 1')->fetch();
        $suite->same(hash('sha256', $issued['binding_code']), $stored['code_hash']);
        $suite->same(null, $stored['consumed_at']);
        $deviceId = Db::uuidV4();
        $now = Db::now();
        $pdo->prepare(
            "INSERT INTO connector_devices
             (id, user_id, display_name, platform, token_prefix, token_hash, created_at, last_seen_at, expires_at)
             VALUES (?, ?, 'Test Companion', 'test', 'test', ?, ?, ?, ?)"
        )->execute([$deviceId, $owner['id'], hash('sha256', 'device-token'), $now, $now, gmdate('Y-m-d H:i:s', time() + 3600)]);
        $bound = $bindingService->redeem(['id' => $deviceId, 'user_id' => $owner['id']], [
            'binding_code' => $issued['binding_code'],
            'provider' => 'gemini',
            'discussion_reference' => 'https://gemini.google.com/app/bound_discussion?hl=en',
        ]);
        $suite->same(true, $bound['enabled']);
        $suite->same('https://gemini.google.com/app/bound_discussion', $bound['discussion_reference']);
        $suite->throws(function () use ($bindingService, $issued, $deviceId, $owner) {
            $bindingService->redeem(['id' => $deviceId, 'user_id' => $owner['id']], [
                'binding_code' => $issued['binding_code'],
                'provider' => 'gemini',
                'discussion_reference' => 'https://gemini.google.com/app/replay_attempt',
            ]);
        });
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
