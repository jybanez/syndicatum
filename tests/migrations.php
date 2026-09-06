<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/ConnectorDeviceService.php';

class MigrationTestSuite
{
    private $passed = 0;
    private $failed = 0;

    public function test($name, callable $callback)
    {
        try {
            $callback();
            $this->passed++;
            echo 'PASS  ' . $name . "\n";
        } catch (Exception $exception) {
            $this->failed++;
            echo 'FAIL  ' . $name . ': ' . $exception->getMessage() . "\n";
        }
    }

    public function assertTrue($condition, $message = 'Assertion failed.')
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function assertSame($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public function assertDatabaseRejects(callable $callback)
    {
        try {
            $callback();
        } catch (PDOException $exception) {
            return;
        }
        throw new RuntimeException('Expected the database constraint to reject the write.');
    }

    public function finish()
    {
        echo "\n" . $this->passed . ' passed, ' . $this->failed . " failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

$suite = new MigrationTestSuite();
$migrationCount = count(glob(dirname(__DIR__) . '/migrations/*.php'));
$database = 'syndicatum_migration_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_migration_test_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe migration test database name.');
}

$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root');
putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32)));

try {
    $pdo = Db::pdo();
    $repository = new ChatRepository($pdo);
    $repository->installSchema();

    $suite->test('expansion migration installs and records its version', function () use ($suite, $repository, $pdo, $migrationCount) {
        $suite->assertTrue($repository->hasExpansionSchema());
        $suite->assertSame($migrationCount, (int) $pdo->query('SELECT COUNT(*) FROM syndicatum_schema_migrations')->fetchColumn());
        $versions = $pdo->query('SELECT version FROM syndicatum_schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        $suite->assertSame('202609050001_expansion_foundation', $versions[0]);
        $roles = $pdo->query('SELECT code FROM system_roles ORDER BY code')->fetchAll(PDO::FETCH_COLUMN);
        $suite->assertSame(['administrator', 'user'], $roles);
    });

    $now = Db::now();
    $pdo->prepare('INSERT INTO users (normalized_email, display_name, created_at, updated_at) VALUES (?, ?, ?, ?)')
        ->execute(['owner@example.test', 'Owner', $now, $now]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, 'Owner workspace', $now, $now]);
    $workspaceId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO projects (workspace_id, owner_user_id, name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$workspaceId, $userId, 'Foundation project', 'foundation', $now, $now]);
    $projectId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, 'owner', 'active', ?, ?)")
        ->execute([$projectId, $userId, $now, $now]);

    $tokenHash = str_repeat('a', 64);
    $pdo->prepare("INSERT INTO chat_agents (project_name, token_prefix, token_hash, token_secret_version, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 'primary', 'agent', 1, ?, ?)")
        ->execute(['Legacy Agent', 'legacy_prefix', $tokenHash, $now, $now]);
    $agentId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO project_agents (project_id, agent_id, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$projectId, $agentId, 'Legacy Agent', $now, $now]);
    $pdo->prepare("INSERT INTO project_participants (project_id, kind, user_id, status, created_at, updated_at) VALUES (?, 'human', ?, 'active', ?, ?)")
        ->execute([$projectId, $userId, $now, $now]);
    $pdo->prepare("INSERT INTO project_participants (project_id, kind, agent_id, status, created_at, updated_at) VALUES (?, 'agent', ?, 'active', ?, ?)")
        ->execute([$projectId, $agentId, $now, $now]);

    $suite->test('project membership and agent identities produce unified participants', function () use ($suite, $pdo, $projectId) {
        $rows = $pdo->query('SELECT kind, user_id, agent_id FROM project_participants WHERE project_id = ' . $projectId . ' ORDER BY kind')->fetchAll();
        $suite->assertSame(2, count($rows));
        $kinds = array_values(array_unique(array_column($rows, 'kind')));
        sort($kinds);
        $suite->assertSame(['agent', 'human'], $kinds);
    });

    $suite->test('personal workspace and project-agent ownership constraints are enforced', function () use ($suite, $pdo, $userId, $workspaceId, $projectId, $agentId, $now) {
        $suite->assertDatabaseRejects(function () use ($pdo, $userId, $now) {
            $pdo->prepare('INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$userId, 'Duplicate workspace', $now, $now]);
        });

        $pdo->prepare('INSERT INTO projects (workspace_id, owner_user_id, name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$workspaceId, $userId, 'Second project', 'second', $now, $now]);
        $otherProjectId = (int) $pdo->lastInsertId();
        $suite->assertDatabaseRejects(function () use ($pdo, $otherProjectId, $agentId, $now) {
            $pdo->prepare('INSERT INTO project_agents (project_id, agent_id, display_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$otherProjectId, $agentId, 'Duplicated agent', $now, $now]);
        });

        $pdo->prepare('INSERT INTO users (normalized_email, display_name, created_at, updated_at) VALUES (?, ?, ?, ?)')
            ->execute(['other@example.test', 'Other owner', $now, $now]);
        $otherUserId = (int) $pdo->lastInsertId();
        $suite->assertDatabaseRejects(function () use ($pdo, $workspaceId, $otherUserId, $now) {
            $pdo->prepare('INSERT INTO projects (workspace_id, owner_user_id, name, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$workspaceId, $otherUserId, 'Mismatched owner', 'mismatched-owner', $now, $now]);
        });
    });

    $suite->test('human-approved connector device discovers its Codex agent bindings', function () use ($suite, $pdo, $userId, $projectId, $agentId, $now) {
        $service = new ConnectorDeviceService($pdo);
        $authorization = $service->begin(['device_name' => 'Office PC', 'platform' => 'windows']);
        $suite->assertTrue(strpos($authorization['device_code'], 'syndicatum_device_code_') === 0);
        $suite->assertTrue(preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $authorization['user_code']) === 1);
        $suite->assertSame('Office PC', $service->pendingForUser($authorization['user_code'])['device_name']);
        $service->approve($authorization['user_code'], ['id' => $userId]);
        $exchange = $service->exchange($authorization['device_code']);
        $suite->assertSame('authorized', $exchange['status']);
        $suite->assertTrue(strpos($exchange['access_token'], 'syndicatum_device_') === 0);
        $device = $service->authenticate($exchange['access_token']);
        $suite->assertSame($userId, (int) $device['user_id']);

        $pdo->prepare(
            "INSERT INTO agent_activation_bindings
             (agent_id, project_id, runtime_type, conversation_id, working_directory, enabled, created_by_user_id, created_at, updated_at)
             VALUES (?, ?, 'codex', ?, ?, 1, ?, ?, ?)"
        )->execute([$agentId, $projectId, 'thread-1', 'C:\\project', $userId, $now, $now]);
        $bindings = $service->bindings($device);
        $suite->assertSame(1, count($bindings));
        $suite->assertSame('thread-1', $bindings[0]['conversation_id']);
        $suite->assertSame($agentId, $bindings[0]['agent_id']);
        $suite->assertSame('codex', $bindings[0]['provider']);
        $suite->assertSame('shared', $bindings[0]['binding_scope']);

        $secondAuthorization = $service->begin(['device_name' => 'Laptop', 'platform' => 'windows']);
        $service->approve($secondAuthorization['user_code'], ['id' => $userId]);
        $secondExchange = $service->exchange($secondAuthorization['device_code']);
        $secondDevice = $service->authenticate($secondExchange['access_token']);
        $secondBindings = $service->bindings($secondDevice);
        $suite->assertSame('thread-1', $secondBindings[0]['conversation_id']);
        $suite->assertSame('shared', $secondBindings[0]['binding_scope']);

        $pdo->prepare('INSERT INTO users (normalized_email, display_name, created_at, updated_at) VALUES (?, ?, ?, ?)')
            ->execute(['outsider@example.test', 'Outsider', $now, $now]);
        $outsiderId = (int) $pdo->lastInsertId();
        $outsiderAuthorization = $service->begin(['device_name' => 'Outsider PC', 'platform' => 'windows']);
        $service->approve($outsiderAuthorization['user_code'], ['id' => $outsiderId]);
        $outsiderExchange = $service->exchange($outsiderAuthorization['device_code']);
        $outsiderDevice = $service->authenticate($outsiderExchange['access_token']);
        $suite->assertSame(0, count($service->bindings($outsiderDevice)));
    });

    $suite->test('rerunning migrations is idempotent and preserves existing credentials', function () use ($suite, $repository, $pdo, $agentId, $tokenHash, $migrationCount) {
        $repository->installSchema();
        $suite->assertSame($migrationCount, (int) $pdo->query('SELECT COUNT(*) FROM syndicatum_schema_migrations')->fetchColumn());
        $statement = $pdo->prepare('SELECT token_hash FROM chat_agents WHERE id = ?');
        $statement->execute([$agentId]);
        $suite->assertSame($tokenHash, $statement->fetchColumn());
    });
} finally {
    if (isset($admin) && $admin instanceof PDO) {
        if (!preg_match('/^syndicatum_migration_test_[a-f0-9]{12}$/', $database)) {
            throw new RuntimeException('Refusing to drop unsafe migration test database name.');
        }
        $admin->exec('DROP DATABASE `' . $database . '`');
    }
}

exit($suite->finish());
