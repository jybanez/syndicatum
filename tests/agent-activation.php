<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/AgentActivationService.php';
require_once dirname(__DIR__) . '/src/ConnectorDeviceService.php';
require_once dirname(__DIR__) . '/src/DiscussionBindingIntentService.php';
require_once dirname(__DIR__) . '/src/ProjectRepository.php';

class AgentActivationTests
{
    private $passed = 0; private $failed = 0;
    public function test($name, callable $callback) { try { $callback(); $this->passed++; echo "PASS  $name\n"; } catch (Exception $e) { $this->failed++; echo "FAIL  $name: {$e->getMessage()}\n"; } }
    public function same($expected, $actual) { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }
    public function true($value, $message = 'Expected true.') { if (!$value) { throw new RuntimeException($message); } }
    public function throws(callable $callback, $expectedMessage = null) { try { $callback(); } catch (Exception $e) {
        if ($expectedMessage !== null) { $this->same($expectedMessage, $e->getMessage()); }
        return;
    } throw new RuntimeException('Expected exception.'); }
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
        $suite->true(!isset($pending[0]['message']['body']), 'ChatGPT recovery notifications must not expose authoritative message bodies.');
        $suite->throws(function () use ($connector, $owner, $project, $agent, $created) {
            $connector->submitAgentReply(['user_id' => $owner['id']], 'chatgpt', $project['id'], $agent['agent_id'], $created['message']['id'], 'Browser capture must not post as ChatGPT');
        });
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
        $duplicate = $connector->submitAgentReply(['user_id' => $owner['id']], 'gemini', $project['id'], $agent['agent_id'], $created['message']['id'], 'Gemini browser response');
        $suite->same(false, $duplicate['created']);
        $suite->same($reply['message']['id'], $duplicate['message']['id']);
        $suite->throws(function () use ($connector, $owner, $project, $agent, $created) {
            $connector->submitAgentReply(['user_id' => $owner['id']], 'gemini', $project['id'], $agent['agent_id'], $created['message']['id'], 'A changed response must be rejected');
        });
        $suite->same(0, count($connector->pendingNotifications(['user_id' => $owner['id']], 'gemini')));
        $acknowledged = $pdo->query('SELECT acknowledged_at FROM message_addressees WHERE message_id = ' . (int) $created['message']['id'] . ' AND participant_id = ' . (int) $geminiBindings[0]['participant_id'])->fetchColumn();
        $suite->true($acknowledged !== false && $acknowledged !== null, 'The originating message must be acknowledged only after the reply is posted.');
        $suite->throws(function () use ($connector, $owner, $project, $agent, $created) {
            $connector->submitAgentReply(['user_id' => $owner['id']], 'chatgpt', $project['id'], $agent['agent_id'], $created['message']['id'], 'Not supported');
        });
    });

    $suite->test('activation audit metadata does not contain the conversation id or path', function () use ($suite, $pdo) {
        $metadata = $pdo->query("SELECT metadata_json FROM administrative_audit_events WHERE action = 'project.agent_activation_configured' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $suite->true(strpos($metadata, '01a06d4b') === false);
        $suite->true(strpos($metadata, 'chatviewer') === false);
    });

    $suite->test('MCP intent creates an agent only after Companion confirmation and resolves a successful context', function () use ($suite, $project, $owner, $pdo) {
        $now = Db::now();
        $pdo->prepare("INSERT INTO oauth_clients (client_id, client_name, redirect_uris_json, token_endpoint_auth_method, created_at, updated_at)
            VALUES ('intent-test-client', 'Intent test', '[]', 'none', ?, ?)")->execute([$now, $now]);
        $placeholder = (new ProjectManagementService($pdo))->createAgent($project['id'], $owner['id'], ['display_name' => 'OAuth Placeholder', 'provider' => 'chatgpt']);
        $pdo->prepare("INSERT INTO oauth_access_tokens
            (token_hash, client_id, user_id, project_id, agent_id, resource_uri, scope_text, created_at, expires_at)
            VALUES (?, 'intent-test-client', ?, ?, ?, 'https://syndicatum.wizaya.com/mcp', ?, ?, ?)")
            ->execute([hash('sha256', 'intent-access'), $owner['id'], $project['id'], $placeholder['agent_id'],
                'projects:read participants:read messages:read messages:write messages:acknowledge', $now, gmdate('Y-m-d H:i:s', time() + 3600)]);
        $accessTokenId = (int) $pdo->lastInsertId();
        $access = ['principal_user_id' => $owner['id'], 'access_token_id' => $accessTokenId,
            'scope' => ['projects:read', 'participants:read', 'messages:read', 'messages:write', 'messages:acknowledge']];
        $service = new DiscussionBindingIntentService($pdo);
        $suite->same('missing', $service->contextHealth($access, '')['state']);
        $suite->same('invalid', $service->contextHealth($access, 'not-a-binding-context')['state']);
        $normalizedInteractive = $service->prepareInteractiveContext($access,
            '  activation   PROJECT  ', ' oAuTh   Placeholder ');
        $suite->same('Activation Project', $normalizedInteractive['project']['name']);
        $suite->same('OAuth Placeholder', $normalizedInteractive['agent']['name']);
        $activationCount = (int) $pdo->query('SELECT COUNT(*) FROM agent_activation_bindings')->fetchColumn();
        $interactive = $service->prepareInteractiveContext($access, 'Activation Project', 'OAuth Placeholder');
        $suite->same('interactive', $interactive['context_type']);
        $suite->same('OAuth Placeholder', $interactive['agent']['name']);
        $suite->same($activationCount, (int) $pdo->query('SELECT COUNT(*) FROM agent_activation_bindings')->fetchColumn());
        $interactiveContext = $service->context($access, $interactive['binding_context_id']);
        $suite->same('interactive', $interactiveContext['binding']['type']);
        $suite->same('healthy', $service->contextHealth($access, $interactive['binding_context_id'])['state']);
        $suite->same(null, $interactiveContext['binding']['discussion_reference']);
        $pdo->prepare("UPDATE connector_discussion_binding_intents SET expires_at = DATE_SUB(?, INTERVAL 1 SECOND) WHERE id = ?")
            ->execute([Db::now(), $interactiveContext['binding']['intent_id']]);
        $suite->same(null, $service->context($access, $interactive['binding_context_id']));
        $suite->same('stale', $service->contextHealth($access, $interactive['binding_context_id'])['state']);
        $suite->throws(function () use ($service, $access) {
            $service->prepareInteractiveContext($access, 'Activation Project', 'Missing Agent');
        }, 'INTERACTIVE_CONTEXT_NOT_FOUND');

        $prepared = $service->prepare($access, 'Activation Project', 'Intent Created Agent');
        $suite->same('create_on_confirmation', $prepared['agent']['action']);
        $suite->same('pending', $service->contextHealth($access, $prepared['binding_context_id'])['state']);
        $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM project_agents WHERE display_name = 'Intent Created Agent'")->fetchColumn());

        $deviceId = Db::uuidV4();
        $pdo->prepare("INSERT INTO connector_devices
            (id, user_id, display_name, platform, token_prefix, token_hash, created_at, last_seen_at, expires_at)
            VALUES (?, ?, 'Intent Companion', 'test', 'intent', ?, ?, ?, ?)")
            ->execute([$deviceId, $owner['id'], hash('sha256', 'intent-device'), $now, $now, gmdate('Y-m-d H:i:s', time() + 3600)]);
        $device = ['id' => $deviceId, 'user_id' => $owner['id']];
        $suite->same(1, count($service->pending($device)));
        $pdo->prepare('INSERT INTO system_settings (setting_key, value_json, updated_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)')
            ->execute(['realtime.enabled', 'true', Db::now()]);
        $confirmed = $service->confirm($device, $prepared['intent_id'], 'https://chatgpt.com/c/intent_created_agent?model=test');
        $suite->same('Successful', $confirmed['discussion_binding']);
        $suite->same(true, $confirmed['enabled']);
        $participantEvent = $pdo->query("SELECT event_type, payload_json FROM message_events_outbox
            WHERE event_type = 'syndicatum.participants.changed' ORDER BY id DESC LIMIT 1")->fetch();
        $suite->same(MessageOutbox::EVENT_PARTICIPANTS_CHANGED, $participantEvent['event_type']);
        $suite->same('agent_created', json_decode($participantEvent['payload_json'], true)['change']);
        $context = $service->context($access, $prepared['binding_context_id']);
        $suite->same('Successful', $context['binding']['status']);
        $suite->same('healthy', $service->contextHealth($access, $prepared['binding_context_id'])['state']);
        $suite->same('Intent Created Agent', $context['identity']['agent']['display_name']);
        $suite->same(0, count($service->pending($device)));
        $confirmedAgentId = (int) $context['identity']['agent']['id'];
        $pdo->prepare('UPDATE agent_activation_bindings SET enabled = 0 WHERE agent_id = ?')->execute([$confirmedAgentId]);
        $suite->same(null, $service->context($access, $prepared['binding_context_id']));
        $suite->same('unusable', $service->contextHealth($access, $prepared['binding_context_id'])['state']);
        $pdo->prepare('UPDATE agent_activation_bindings SET enabled = 1 WHERE agent_id = ?')->execute([$confirmedAgentId]);
        $suite->true($service->context($access, $prepared['binding_context_id']) !== null);
        $pdo->prepare("UPDATE project_members SET status = 'removed' WHERE project_id = ? AND user_id = ?")
            ->execute([$project['id'], $owner['id']]);
        $suite->same(null, $service->context($access, $prepared['binding_context_id']));
        $pdo->prepare("UPDATE project_members SET status = 'active' WHERE project_id = ? AND user_id = ?")
            ->execute([$project['id'], $owner['id']]);
        $pdo->prepare("UPDATE project_participants SET status = 'suspended' WHERE project_id = ? AND agent_id = ?")
            ->execute([$project['id'], $confirmedAgentId]);
        $suite->same(null, $service->context($access, $prepared['binding_context_id']));
        $pdo->prepare("UPDATE project_participants SET status = 'active' WHERE project_id = ? AND agent_id = ?")
            ->execute([$project['id'], $confirmedAgentId]);
        $suite->true($service->context($access, $prepared['binding_context_id']) !== null);

        $cancel = $service->prepare($access, 'Activation Project', 'Cancelled Agent');
        $service->cancel($device, $cancel['intent_id']);
        $suite->throws(function () use ($service, $device, $cancel) {
            $service->confirm($device, $cancel['intent_id'], 'https://chatgpt.com/c/cancelled_intent');
        }, 'INVALID_DISCUSSION_BINDING_INTENT');
        $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM project_agents WHERE display_name = 'Cancelled Agent'")->fetchColumn());
        $suite->same(null, $service->context($access, $cancel['binding_context_id']));
        $suite->same('revoked', $service->contextHealth($access, $cancel['binding_context_id'])['state']);

        $revokedAccess = $service->prepare($access, 'Activation Project', 'No Access Agent');
        $pdo->prepare("UPDATE project_members SET status = 'removed' WHERE project_id = ? AND user_id = ?")
            ->execute([$project['id'], $owner['id']]);
        $suite->throws(function () use ($service, $device, $revokedAccess) {
            $service->confirm($device, $revokedAccess['intent_id'], 'https://chatgpt.com/c/revoked_access');
        }, 'INVALID_DISCUSSION_BINDING_INTENT');
        $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM project_agents WHERE display_name = 'No Access Agent'")->fetchColumn());
        $pdo->prepare("UPDATE project_members SET status = 'active' WHERE project_id = ? AND user_id = ?")
            ->execute([$project['id'], $owner['id']]);
        $service->cancel($device, $revokedAccess['intent_id']);

        $inactiveProject = $service->prepare($access, 'Activation Project', 'Inactive Project Agent');
        $pdo->prepare("UPDATE projects SET status = 'archived' WHERE id = ?")->execute([$project['id']]);
        $suite->throws(function () use ($service, $device, $inactiveProject) {
            $service->confirm($device, $inactiveProject['intent_id'], 'https://chatgpt.com/c/inactive_project');
        }, 'INVALID_DISCUSSION_BINDING_INTENT');
        $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM project_agents WHERE display_name = 'Inactive Project Agent'")->fetchColumn());
        $pdo->prepare("UPDATE projects SET status = 'active' WHERE id = ?")->execute([$project['id']]);
        $service->cancel($device, $inactiveProject['intent_id']);

        $staleAgent = $service->prepare($access, 'Activation Project', 'OAuth Placeholder');
        $bindingsBefore = (int) $pdo->query('SELECT COUNT(*) FROM agent_activation_bindings')->fetchColumn();
        $pdo->prepare("UPDATE project_participants SET status = 'suspended' WHERE project_id = ? AND agent_id = ?")
            ->execute([$project['id'], $placeholder['agent_id']]);
        $suite->throws(function () use ($service, $device, $staleAgent) {
            $service->confirm($device, $staleAgent['intent_id'], 'https://chatgpt.com/c/stale_agent');
        }, 'INVALID_DISCUSSION_BINDING_INTENT');
        $suite->same($bindingsBefore, (int) $pdo->query('SELECT COUNT(*) FROM agent_activation_bindings')->fetchColumn());
        $pdo->prepare("UPDATE project_participants SET status = 'active' WHERE project_id = ? AND agent_id = ?")
            ->execute([$project['id'], $placeholder['agent_id']]);
        $service->cancel($device, $staleAgent['intent_id']);

        $inactiveAgent = $service->prepare($access, 'Activation Project', 'OAuth Placeholder');
        $pdo->prepare("UPDATE project_agents SET status = 'suspended' WHERE project_id = ? AND agent_id = ?")
            ->execute([$project['id'], $placeholder['agent_id']]);
        $suite->throws(function () use ($service, $device, $inactiveAgent) {
            $service->confirm($device, $inactiveAgent['intent_id'], 'https://chatgpt.com/c/inactive_agent');
        }, 'INVALID_DISCUSSION_BINDING_INTENT');
        $suite->same($bindingsBefore, (int) $pdo->query('SELECT COUNT(*) FROM agent_activation_bindings')->fetchColumn());
        $pdo->prepare("UPDATE project_agents SET status = 'active' WHERE project_id = ? AND agent_id = ?")
            ->execute([$project['id'], $placeholder['agent_id']]);
        $service->cancel($device, $inactiveAgent['intent_id']);

        $disabledAgent = $service->prepare($access, 'Activation Project', 'OAuth Placeholder');
        $pdo->prepare('UPDATE chat_agents SET is_active = 0 WHERE id = ?')->execute([$placeholder['agent_id']]);
        $suite->throws(function () use ($service, $device, $disabledAgent) {
            $service->confirm($device, $disabledAgent['intent_id'], 'https://chatgpt.com/c/disabled_agent');
        }, 'INVALID_DISCUSSION_BINDING_INTENT');
        $suite->same($bindingsBefore, (int) $pdo->query('SELECT COUNT(*) FROM agent_activation_bindings')->fetchColumn());
        $pdo->prepare('UPDATE chat_agents SET is_active = 1 WHERE id = ?')->execute([$placeholder['agent_id']]);
        $service->cancel($device, $disabledAgent['intent_id']);

        $expired = $service->prepare($access, 'Activation Project', 'Expired Agent');
        $pdo->prepare("UPDATE connector_discussion_binding_intents SET expires_at = DATE_SUB(?, INTERVAL 1 SECOND) WHERE id = ?")
            ->execute([Db::now(), $expired['intent_id']]);
        $suite->throws(function () use ($service, $device, $expired) {
            $service->confirm($device, $expired['intent_id'], 'https://chatgpt.com/c/expired_intent');
        }, 'INVALID_DISCUSSION_BINDING_INTENT');
        $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM project_agents WHERE display_name = 'Expired Agent'")->fetchColumn());

        $stableIds = $service->prepare($access, 'Activation Project', 'OAuth Placeholder');
        $suite->same((int) $project['id'], $stableIds['project']['id']);
        $suite->same((int) $placeholder['agent_id'], $stableIds['agent']['id']);
        $pdo->prepare("UPDATE project_agents SET display_name = 'Renamed After Preparation' WHERE project_id = ? AND agent_id = ?")
            ->execute([$project['id'], $placeholder['agent_id']]);
        $bound = $service->confirm($device, $stableIds['intent_id'], 'https://chatgpt.com/c/stable_ids');
        $suite->same((int) $placeholder['agent_id'], $bound['agent_id']);
        $suite->same((int) $placeholder['agent_id'], $service->context($access, $stableIds['binding_context_id'])['identity']['agent']['id']);
        $pdo->prepare("UPDATE project_agents SET display_name = 'OAuth Placeholder' WHERE project_id = ? AND agent_id = ?")
            ->execute([$project['id'], $placeholder['agent_id']]);

        $normalized = $service->prepare($access, 'ACTIVATION   project', 'oAuTh   Placeholder');
        $suite->same('Activation Project', $normalized['project']['name']);
        $suite->same('OAuth Placeholder', $normalized['agent']['name']);
        $suite->same('use_existing', $normalized['agent']['action']);
        $service->cancel($device, $normalized['intent_id']);
        $suite->throws(function () use ($service, $access) {
            $service->prepare($access, 'Unknown   Project', 'OAuth Placeholder');
        }, 'PROJECT_NOT_FOUND');
        (new ProjectManagementService($pdo))->createAgent($project['id'], $owner['id'],
            ['display_name' => 'OAuth  Placeholder', 'provider' => 'chatgpt']);
        $suite->throws(function () use ($service, $access) {
            $service->prepare($access, 'Activation Project', 'OAuth   Placeholder');
        }, 'AGENT_NAME_AMBIGUOUS');
        $suite->throws(function () use ($service, $access) {
            $service->prepareInteractiveContext($access, 'Activation Project', 'OAuth   Placeholder');
        }, 'INTERACTIVE_CONTEXT_AMBIGUOUS');
        (new ProjectManagementService($pdo))->createProject($owner['id'],
            ['name' => 'Activation  Project', 'slug' => 'activation-double-space']);
        $suite->throws(function () use ($service, $access) {
            $service->prepare($access, 'Activation   Project', 'OAuth Placeholder');
        }, 'PROJECT_NAME_AMBIGUOUS');
        $suite->throws(function () use ($service, $access) {
            $service->prepareInteractiveContext($access, 'Activation   Project', 'OAuth Placeholder');
        }, 'INTERACTIVE_CONTEXT_AMBIGUOUS');
    });

    exit($suite->finish());
} finally {
    if (isset($pdo)) { $pdo = null; }
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
