<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/ProjectRepository.php';
require_once dirname(__DIR__) . '/src/AvatarService.php';
require_once dirname(__DIR__) . '/src/AgentWebhookService.php';
require_once dirname(__DIR__) . '/src/AgentWebhookWorker.php';

class AvatarWebhookTests
{
    private $passed = 0; private $failed = 0;
    public function test($name, callable $callback) { try { $callback(); $this->passed++; echo "PASS  $name\n"; } catch (Exception $e) { $this->failed++; echo "FAIL  $name: {$e->getMessage()}\n"; } }
    public function same($expected, $actual) { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }
    public function true($value, $message = 'Expected true.') { if (!$value) { throw new RuntimeException($message); } }
    public function throws(callable $callback) { try { $callback(); } catch (Exception $e) { return; } throw new RuntimeException('Expected exception.'); }
    public function finish() { echo "\n{$this->passed} passed, {$this->failed} failed.\n"; return $this->failed ? 1 : 0; }
}

$suite = new AvatarWebhookTests();
$database = 'syndicatum_avatar_webhook_test_' . bin2hex(openssl_random_pseudo_bytes(5));
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$avatarDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-avatar-test-' . bin2hex(openssl_random_pseudo_bytes(5));
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1'); putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root'); putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(openssl_random_pseudo_bytes(32)));
putenv('SYNDICATUM_AVATAR_DIR=' . $avatarDirectory);
putenv('SYNDICATUM_WEBHOOK_PRIVATE_HOST_ALLOWLIST=127.0.0.1');

try {
    $pdo = Db::pdo(); (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo);
    $owner = $auth->bootstrapAdministrator('avatar-webhook@example.test', 'Avatar Owner', 'correct horse battery staple');
    $management = new ProjectManagementService($pdo);
    $project = $management->createProject($owner['id'], ['name' => 'Avatar Webhooks']);
    $agentOne = $management->createAgent($project['id'], $owner['id'], ['display_name' => 'Hook One']);
    $agentTwo = $management->createAgent($project['id'], $owner['id'], ['display_name' => 'Hook Two']);
    $avatars = new AvatarService();

    $suite->test('avatars strictly validate, re-encode, randomize, and delete managed files', function () use ($suite, $avatars) {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $first = $avatars->storeData(['data' => $png]); $second = $avatars->storeData(['data' => $png]);
        $suite->true($first !== $second); $suite->true(strpos($first, 'api/v1/avatar.php?file=') === 0);
        $file = rawurldecode(substr($first, strlen('api/v1/avatar.php?file=')));
        $suite->true($avatars->pathForFilename($file) !== null);
        $suite->throws(function () use ($avatars) { $avatars->storeData(['data' => base64_encode('<svg></svg>')]); });
        $avatars->deleteIfLocal($first); $suite->same(null, $avatars->pathForFilename($file));
        $avatars->deleteIfLocal($second);
    });

    $webhooks = new AgentWebhookService($pdo);
    $suite->test('webhook secrets are encrypted and disclosed once while unsafe URLs are rejected', function () use ($suite, $pdo, $webhooks, $project, $owner, $agentOne) {
        $suite->throws(function () use ($webhooks, $project, $owner, $agentOne) {
            $webhooks->configure($project['id'], $agentOne['agent_id'], $owner['id'], ['endpoint_url' => 'https://user@127.0.0.1/hook']);
        });
        $created = $webhooks->configure($project['id'], $agentOne['agent_id'], $owner['id'], ['endpoint_url' => 'https://127.0.0.1/hook']);
        $suite->true(!empty($created['signing_secret']));
        $stored = $pdo->query('SELECT signing_secret_encrypted FROM agent_notification_webhooks WHERE agent_id = ' . (int) $agentOne['agent_id'])->fetchColumn();
        $suite->true(strpos($stored, $created['signing_secret']) === false);
        $current = $webhooks->configuration($project['id'], $agentOne['agent_id'], $owner['id']);
        $suite->true(!isset($current['signing_secret']));
        $updated = $webhooks->configure($project['id'], $agentOne['agent_id'], $owner['id'], ['enabled' => true]);
        $suite->true(!isset($updated['signing_secret']));
    });

    $webhooks->configure($project['id'], $agentTwo['agent_id'], $owner['id'], ['endpoint_url' => 'https://127.0.0.1/hook']);
    $ownerParticipant = (int) $pdo->query('SELECT id FROM project_participants WHERE project_id = ' . (int) $project['id'] . ' AND user_id = ' . (int) $owner['id'])->fetchColumn();
    $access = ['project_id' => $project['id'], 'participant_id' => $ownerParticipant, 'project_status' => 'active', 'role' => 'owner', 'identity' => ['kind' => 'human']];
    $message = (new ProjectRepository($pdo))->createMessage($access, ['body' => 'Notify both agents', 'broadcast' => true]);

    $suite->test('message creation durably queues only addressed agent deliveries with recipient metadata', function () use ($suite, $pdo, $message) {
        $rows = $pdo->query('SELECT payload_json FROM agent_webhook_deliveries ORDER BY agent_id')->fetchAll(PDO::FETCH_COLUMN);
        $suite->same(2, count($rows));
        $payload = json_decode($rows[0], true);
        $suite->same('syndicatum.message.created', $payload['type']);
        $suite->true(!empty($payload['occurred_at']) && !empty($payload['recipient']['agent_id']) && !empty($payload['recipient']['participant_id']));
        $suite->same($message['message']['id'], $payload['message']['id']);
    });

    $captured = [];
    $worker = new AgentWebhookWorker($pdo, function ($url, $payload, $headers) use (&$captured, $agentTwo) {
        $decoded = json_decode($payload, true); $captured[] = ['payload' => $decoded, 'headers' => $headers];
        return ['status' => (int) $decoded['recipient']['agent_id'] === (int) $agentTwo['agent_id'] ? 500 : 204, 'body' => ''];
    });
    $result = $worker->process();
    $suite->test('worker isolates failures, signs idempotently, retries transient errors, and marks only success notified', function () use ($suite, $pdo, $result, $captured, $agentOne, $agentTwo, $message) {
        $suite->same(1, $result['succeeded']); $suite->same(1, $result['retried']);
        $suite->true((bool) array_filter($captured[0]['headers'], function ($h) { return strpos($h, 'Idempotency-Key: ') === 0; }));
        $statuses = $pdo->query('SELECT agent_id, status FROM agent_webhook_deliveries ORDER BY agent_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $suite->same('succeeded', $statuses[$agentOne['agent_id']]); $suite->same('retry', $statuses[$agentTwo['agent_id']]);
        $query = $pdo->prepare('SELECT pp.agent_id, ma.notified_at FROM message_addressees ma JOIN project_participants pp ON pp.id = ma.participant_id WHERE ma.message_id = ? ORDER BY pp.agent_id');
        $query->execute([$message['message']['id']]); $notified = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        $suite->true($notified[$agentOne['agent_id']] !== null); $suite->same(null, $notified[$agentTwo['agent_id']]);
    });
    exit($suite->finish());
} finally {
    Db::reset();
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    if (is_dir($avatarDirectory)) { foreach (glob($avatarDirectory . DIRECTORY_SEPARATOR . '*') as $file) { @unlink($file); } @rmdir($avatarDirectory); }
}
