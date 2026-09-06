<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/AdminService.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/ExpansionMigrator.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';

class ExpansionTestSuite
{
    private $passed = 0;
    private $failed = 0;
    public function test($name, callable $callback)
    {
        try { $callback(); $this->passed++; echo 'PASS  ' . $name . "\n"; }
        catch (Exception $exception) { $this->failed++; echo 'FAIL  ' . $name . ': ' . $exception->getMessage() . "\n"; }
    }
    public function same($expected, $actual, $message = '')
    {
        if ($expected !== $actual) { throw new RuntimeException($message ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    }
    public function truthy($actual, $message = 'Expected truthy value.') { if (!$actual) { throw new RuntimeException($message); } }
    public function throws($message, callable $callback)
    {
        try { $callback(); } catch (Exception $exception) { if (strpos($exception->getMessage(), $message) !== false) { return; } throw $exception; }
        throw new RuntimeException('Expected exception containing ' . $message);
    }
    public function finish() { echo "\n{$this->passed} passed, {$this->failed} failed.\n"; return $this->failed === 0 ? 0 : 1; }
}

$suite = new ExpansionTestSuite();
$database = 'syndicatum_expansion_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_expansion_test_[a-f0-9]{12}$/', $database)) { throw new RuntimeException('Unsafe test database name.'); }
$adminPdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$adminPdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root');
putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32)));
putenv('SYNDICATUM_MASTER_KEY=' . bin2hex(random_bytes(32)));

try {
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo);
    $administrator = $auth->bootstrapAdministrator('admin@example.test', 'Test Administrator', 'correct horse battery staple');

    $suite->test('bootstrap creates final administrator and personal workspace', function () use ($suite, $administrator) {
        $suite->truthy($administrator['workspace']);
        $suite->same(['administrator', 'user'], $administrator['system_roles']);
    });

    $suite->test('native login creates a verifiable database session and csrf token', function () use ($suite, $auth) {
        $login = $auth->login('admin@example.test', 'correct horse battery staple');
        $suite->truthy(strlen($login['session']['token']) > 32);
        $current = $auth->currentUser($login['session']['token']);
        $suite->same('Test Administrator', $current['display_name']);
        $auth->validateCsrf($current, $login['session']['csrf_token']);
        $suite->throws('CSRF_VALIDATION_FAILED', function () use ($auth, $current) { $auth->validateCsrf($current, 'wrong'); });
    });

    $suite->test('settings validate values and never return plaintext secrets', function () use ($suite, $pdo, $administrator) {
        $settings = new SettingsService($pdo);
        $settings->update([
            'messaging.max_message_bytes' => 24000,
            'realtime.enabled' => true,
            'realtime.signing_secret' => ['operation' => 'replace', 'value' => 'test-signing-secret'],
        ], $administrator['id']);
        $suite->same(24000, $settings->get('messaging.max_message_bytes'));
        $suite->same('test-signing-secret', $settings->get('realtime.signing_secret'));
        $public = $settings->publicSettings('integrations');
        $suite->same(null, $public['realtime.signing_secret']['value']);
        $suite->truthy($public['realtime.signing_secret']['configured']);

        $settings->update(['realtime.websocket_url' => 'wss://realtime.example.test/realtime'], $administrator['id']);
        $suite->same('wss://realtime.example.test/realtime', $settings->get('realtime.websocket_url'));
        $suite->throws('realtime.websocket_url must be an absolute URL', function () use ($settings, $administrator) {
            $settings->update(['realtime.websocket_url' => 'https://realtime.example.test/realtime'], $administrator['id']);
        });

        $settings->update(['realtime.base_url' => 'https://gateway.example.test/'], $administrator['id']);
        $suite->same('https://gateway.example.test/api/v1/events/publish', $settings->get('realtime.publish_url'));
        $suite->same('wss://gateway.example.test/realtime', $settings->get('realtime.websocket_url'));

        $settings->update([
            'realtime.base_url' => 'http://gateway.example.test',
            'realtime.websocket_url' => 'ws://socket.example.test/custom',
        ], $administrator['id']);
        $suite->same('http://gateway.example.test/api/v1/events/publish', $settings->get('realtime.publish_url'));
        $suite->same('ws://gateway.example.test/realtime', $settings->get('realtime.websocket_url'));

        $settings->update(['realtime.base_url' => 'https://gateway.example.test'], $administrator['id']);
        $suite->throws('realtime.websocket_url must use wss when realtime.base_url uses https', function () use ($settings, $administrator) {
            $settings->update(['realtime.websocket_url' => 'ws://gateway.example.test:8080/realtime'], $administrator['id']);
        });
    });

    $now = Db::now();
    $token = 'legacy_' . bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO chat_agents (project_name, token_prefix, token_hash, token_secret_version, role, is_active, created_at, updated_at) VALUES ('Legacy Agent', ?, ?, 'primary', 'agent', 1, ?, ?)")
        ->execute([substr($token, 0, 24), Db::hashToken($token), $now, $now]);
    $agentId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO chat_entries (entry_uuid, sender_agent_id, message_timestamp, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['00000000-0000-4000-8000-000000000001', $agentId, $now, 'Migrated broadcast', $now, $now]);
    $entryId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO chat_entry_revisions (entry_id, edited_by_agent_id, previous_body, new_body, edited_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$entryId, $agentId, 'Earlier body', 'Migrated broadcast', $now]);

    $suite->test('legacy migration preserves identities, tokens, messages, and revisions', function () use ($suite, $pdo, $administrator, $token) {
        $migration = new ExpansionMigrator($pdo);
        $result = $migration->migrateLegacyData($administrator['id'], 'PBB Coordination');
        $suite->same(true, $result['matches']['agents']);
        $suite->same(true, $result['matches']['messages']);
        $suite->same(true, $result['matches']['revisions']);
        $suite->truthy((new ChatRepository($pdo))->authenticate($token));
        $rerun = $migration->migrateLegacyData($administrator['id'], 'PBB Coordination');
        $suite->same(1, $rerun['canonical']['messages']);
    });

    $suite->test('legacy agent writes mirror into the migrated project without changing its token', function () use ($suite, $pdo, $token) {
        $repository = new ChatRepository($pdo);
        $agent = $repository->authenticate($token);
        $entry = $repository->createEntry($agent, ['body' => 'Compatibility write']);
        $statement = $pdo->prepare('SELECT id, body, deleted_at FROM messages WHERE legacy_entry_id = ?');
        $statement->execute([$entry['db_id']]);
        $canonical = $statement->fetch();
        $suite->same('Compatibility write', $canonical['body']);
        $repository->updateEntry($entry['db_id'], $agent, ['body' => 'Compatibility edit']);
        $statement->execute([$entry['db_id']]);
        $suite->same('Compatibility edit', $statement->fetch()['body']);
        $repository->deleteEntry($entry['db_id'], $agent);
        $statement->execute([$entry['db_id']]);
        $suite->truthy($statement->fetch()['deleted_at']);
    });

    $suite->test('final active administrator cannot be suspended or demoted', function () use ($suite, $pdo, $administrator) {
        $admin = new AdminService($pdo);
        $suite->throws('FINAL_ADMINISTRATOR', function () use ($admin, $administrator) {
            $admin->updateUser($administrator['id'], ['status' => 'suspended'], $administrator['id']);
        });
    });

    $suite->test('rate limiter blocks excess attempts', function () use ($suite, $pdo) {
        $limiter = new RateLimiter($pdo);
        $limiter->hit('test', 'identity', 2, 60, 60);
        $limiter->hit('test', 'identity', 2, 60, 60);
        $suite->throws('RATE_LIMITED', function () use ($limiter) { $limiter->hit('test', 'identity', 2, 60, 60); });
    });

    $projectAdmin = new AdminService($pdo);
    $member = $projectAdmin->createUser([
        'email' => 'member@example.test', 'username' => 'member', 'display_name' => 'Project Member',
        'password' => 'another correct horse battery staple',
    ], $administrator['id']);
    $management = new ProjectManagementService($pdo);

    $suite->test('project invitations create a unified human participant', function () use ($suite, $pdo, $administrator, $member, $management) {
        $project = $management->createProject($administrator['id'], ['name' => 'Shared Product']);
        $invitation = $management->invite($project['id'], $administrator['id'], ['email' => 'member@example.test', 'role' => 'member']);
        $accepted = $management->acceptInvitation($member['id'], $invitation['invitation_token']);
        $suite->same($project['id'], $accepted['id']);
        $statement = $pdo->prepare("SELECT COUNT(*) FROM project_participants WHERE project_id = ? AND user_id = ? AND kind = 'human' AND status = 'active'");
        $statement->execute([$project['id'], $member['id']]);
        $suite->same(1, (int) $statement->fetchColumn());
    });

    $suite->test('project agent claim is single use and project scoped', function () use ($suite, $pdo, $administrator, $management) {
        $project = $management->createProject($administrator['id'], ['name' => 'Agent Product']);
        $created = $management->createAgent($project['id'], $administrator['id'], ['display_name' => 'Review Agent']);
        $claim = $management->claimAgent($project['id'], $created['agent_id'], $created['claim_code']);
        $suite->truthy((new ChatRepository($pdo))->authenticate($claim['token']));
        $suite->same(null, (new ChatRepository($pdo))->authenticateLegacy($claim['token']));
        $suite->throws('INVALID_CLAIM', function () use ($management, $project, $created) {
            $management->claimAgent($project['id'], $created['agent_id'], $created['claim_code']);
        });
        $expired = $management->createAgent($project['id'], $administrator['id'], ['display_name' => 'Expired Agent']);
        $pdo->prepare('UPDATE chat_agents SET claim_expires_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 60), $expired['agent_id']]);
        $suite->throws('INVALID_CLAIM', function () use ($management, $project, $expired) {
            $management->claimAgent($project['id'], $expired['agent_id'], $expired['claim_code']);
        });
        $suite->throws('valid agent scope', function () use ($management, $project, $administrator) {
            $management->createAgent($project['id'], $administrator['id'], ['display_name' => 'Scope-less Agent', 'scopes' => []]);
        });
    });

    $suite->test('administrator bootstrap is a one-time operation', function () use ($suite, $auth) {
        $suite->throws('already been bootstrapped', function () use ($auth) {
            $auth->bootstrapAdministrator('second-admin@example.test', 'Second Bootstrap', 'another long bootstrap password');
        });
    });

    $suite->test('ownership cannot transfer to the current owner', function () use ($suite, $administrator, $management) {
        $project = $management->createProject($administrator['id'], ['name' => 'No-op Transfer']);
        $suite->throws('different user', function () use ($management, $project, $administrator) {
            $management->transferProject($project['id'], $administrator['id'], $administrator['id']);
        });
    });

    $suite->test('ownership transfer moves the project to the new owner workspace atomically', function () use ($suite, $pdo, $administrator, $member, $management) {
        $project = $management->createProject($administrator['id'], ['name' => 'Transfer Product']);
        $transferred = $management->transferProject($project['id'], $administrator['id'], $member['id']);
        $suite->same($member['id'], $transferred['owner_user_id']);
        $statement = $pdo->prepare('SELECT id FROM workspaces WHERE owner_user_id = ?');
        $statement->execute([$member['id']]);
        $suite->same((int) $statement->fetchColumn(), $transferred['workspace_id']);
    });
} finally {
    if (isset($adminPdo) && $adminPdo instanceof PDO) {
        if (!preg_match('/^syndicatum_expansion_test_[a-f0-9]{12}$/', $database)) { throw new RuntimeException('Refusing unsafe drop.'); }
        $adminPdo->exec('DROP DATABASE `' . $database . '`');
    }
}

exit($suite->finish());
