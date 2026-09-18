<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/McpServiceTokenService.php';

class ProjectApiTestSuite
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
        } catch (Error $error) {
            $this->failed++;
            echo 'FAIL  ' . $name . ': ' . $error->getMessage() . "\n";
        }
    }

    public function same($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            throw new RuntimeException(($message ? $message . ' ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public function true($condition, $message = 'Expected condition to be true.')
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function finish()
    {
        echo "\n" . $this->passed . ' passed, ' . $this->failed . " failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

function projectApiRequest($baseUrl, $method, $path, array $headers = [], $body = null)
{
    $handle = curl_init($baseUrl . $path);
    curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_HEADER, true);
    curl_setopt($handle, CURLOPT_TIMEOUT, 10);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($handle);
    if ($raw === false) {
        $message = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('HTTP request failed: ' . $message);
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    $responseHeaders = [];
    foreach (preg_split('/\r\n|\n|\r/', trim(substr($raw, 0, $headerSize))) ?: [] as $line) {
        $position = strpos($line, ':');
        if ($position !== false) {
            $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
        }
    }
    $decoded = json_decode(substr($raw, $headerSize), true);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $decoded, 'raw' => substr($raw, $headerSize)];
}

function projectApiPort()
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    if (!$socket) {
        throw new RuntimeException('Unable to reserve test port: ' . $message);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($name, ':'), 1);
}

function projectApiServer($root, $port, array $environment)
{
    $log = tempnam(sys_get_temp_dir(), 'syndicatum-project-api-');
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ], $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start project API test server.');
    }
    fclose($pipes[0]);
    $baseUrl = 'http://127.0.0.1:' . $port;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        try {
            if (projectApiRequest($baseUrl, 'GET', '/')['status'] === 200) {
                return [$process, $baseUrl, $log];
            }
        } catch (Exception $ignored) {
        }
    }
    proc_terminate($process);
    throw new RuntimeException('Project API test server failed: ' . (is_file($log) ? file_get_contents($log) : ''));
}

function projectApiInsertUser(PDO $pdo, $email, $name)
{
    $now = Db::now();
    $statement = $pdo->prepare(
        "INSERT INTO users (normalized_email, username, password_hash, display_name, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'active', ?, ?)"
    );
    $statement->execute([$email, strstr($email, '@', true), password_hash('project-api-password', PASSWORD_DEFAULT), $name, $now, $now]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO user_system_roles (user_id, role_id, created_at) SELECT ?, id, ? FROM system_roles WHERE code = 'user'")
        ->execute([$id, $now]);
    $pdo->prepare('INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)')
        ->execute([$id, $name . ' workspace', $now, $now]);
    return $id;
}

function projectApiInsertProject(PDO $pdo, $userId, $name, $slug)
{
    $now = Db::now();
    $workspace = $pdo->prepare('SELECT id FROM workspaces WHERE owner_user_id = ?');
    $workspace->execute([$userId]);
    $workspaceId = (int) $workspace->fetchColumn();
    $pdo->prepare(
        "INSERT INTO projects (public_id, workspace_id, owner_user_id, name, slug, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'active', ?, ?)"
    )->execute([Db::uuidV4(), $workspaceId, $userId, $name, $slug, $now, $now]);
    $projectId = (int) $pdo->lastInsertId();
    projectApiAddMember($pdo, $projectId, $userId, 'owner');
    return $projectId;
}

function projectApiAddMember(PDO $pdo, $projectId, $userId, $role)
{
    $now = Db::now();
    $pdo->prepare(
        "INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at)
         VALUES (?, ?, ?, 'active', ?, ?)"
    )->execute([$projectId, $userId, $role, $now, $now]);
    $pdo->prepare(
        "INSERT INTO project_participants (project_id, kind, user_id, status, created_at, updated_at)
         VALUES (?, 'human', ?, 'active', ?, ?)"
    )->execute([$projectId, $userId, $now, $now]);
    return (int) $pdo->lastInsertId();
}

function projectApiInsertAgent(PDO $pdo, $projectId, $name, $token, $secret)
{
    $now = Db::now();
    $pdo->prepare(
        "INSERT INTO chat_agents
         (project_name, token_prefix, token_hash, token_secret_version, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, 'primary', 'agent', 1, ?, ?)"
    )->execute([$name, substr($token, 0, 24), hash_hmac('sha256', $token, $secret), $now, $now]);
    $agentId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO project_agents (project_id, agent_id, display_name, status, created_at, updated_at)
         VALUES (?, ?, ?, 'active', ?, ?)"
    )->execute([$projectId, $agentId, $name, $now, $now]);
    $pdo->prepare(
        "INSERT INTO project_participants (project_id, kind, agent_id, status, created_at, updated_at)
         VALUES (?, 'agent', ?, 'active', ?, ?)"
    )->execute([$projectId, $agentId, $now, $now]);
    return ['agent_id' => $agentId, 'participant_id' => (int) $pdo->lastInsertId()];
}

function projectApiSession(PDO $pdo, $userId, $token, $csrf)
{
    $now = Db::now();
    $pdo->prepare(
        'INSERT INTO syndicatum_sessions
         (user_id, token_hash, csrf_token_hash, created_at, last_seen_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$userId, hash('sha256', $token), hash('sha256', $csrf), $now, $now, gmdate('Y-m-d H:i:s', time() + 3600)]);
}

$suite = new ProjectApiTestSuite();
$contractSamples = [];
$root = dirname(__DIR__);
$database = 'syndicatum_project_api_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_project_api_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe project API test database name.');
}
$secret = bin2hex(random_bytes(32));
$agentOneToken = 'project_agent_one_' . bin2hex(random_bytes(20));
$agentTwoToken = 'project_agent_two_' . bin2hex(random_bytes(20));
$sessionToken = 'session_' . bin2hex(random_bytes(24));
$csrfToken = 'csrf_' . bin2hex(random_bytes(24));
$memberSessionToken = 'member_session_' . bin2hex(random_bytes(20));
$memberCsrfToken = 'member_csrf_' . bin2hex(random_bytes(20));
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root');
putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . $secret);
$server = null;
$serverLog = null;

try {
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    $ownerId = projectApiInsertUser($pdo, 'owner@project.test', 'Owner Human');
    $memberId = projectApiInsertUser($pdo, 'member@project.test', 'Member Human');
    $pdo->prepare("INSERT INTO user_system_roles (user_id, role_id, created_at)
        SELECT ?, id, ? FROM system_roles WHERE code = 'administrator'")
        ->execute([$ownerId, Db::now()]);
    $projectOne = projectApiInsertProject($pdo, $ownerId, 'Project One', 'project-one');
    $projectTwo = projectApiInsertProject($pdo, $memberId, 'Project Two', 'project-two');
    $ownerParticipant = (int) $pdo->query('SELECT id FROM project_participants WHERE project_id = ' . $projectOne . ' AND user_id = ' . $ownerId)->fetchColumn();
    $memberParticipant = projectApiAddMember($pdo, $projectOne, $memberId, 'member');
    $agentOne = projectApiInsertAgent($pdo, $projectOne, 'Project One Agent', $agentOneToken, $secret);
    $agentTwo = projectApiInsertAgent($pdo, $projectTwo, 'Project Two Agent', $agentTwoToken, $secret);
    projectApiSession($pdo, $ownerId, $sessionToken, $csrfToken);
    projectApiSession($pdo, $memberId, $memberSessionToken, $memberCsrfToken);

    $environment = getenv();
    $environment['PBB_AGENTCHAT_DB_HOST'] = '127.0.0.1';
    $environment['PBB_AGENTCHAT_DB_NAME'] = $database;
    $environment['PBB_AGENTCHAT_DB_USER'] = 'root';
    $environment['PBB_AGENTCHAT_DB_PASS'] = '';
    $environment['PBB_AGENTCHAT_SECRET'] = $secret;
    $serverPort = projectApiPort();
    $environment['SYNDICATUM_SETTING_GENERAL_PUBLIC_ORIGIN'] = 'http://127.0.0.1:' . $serverPort;
    list($server, $baseUrl, $serverLog) = projectApiServer($root, $serverPort, $environment);
    $agentOneHeaders = ['Authorization: Bearer ' . $agentOneToken];
    $agentTwoHeaders = ['Authorization: Bearer ' . $agentTwoToken];
    $humanHeaders = ['Cookie: syndicatum_session=' . $sessionToken, 'X-CSRF-Token: ' . $csrfToken];
    $memberHeaders = ['Cookie: syndicatum_session=' . $memberSessionToken, 'X-CSRF-Token: ' . $memberCsrfToken];

    $suite->test('provider-only agent profile update does not require or overwrite an activation binding', function () use ($suite, $baseUrl, $humanHeaders, $projectOne, $agentOne, $pdo) {
        $response = projectApiRequest($baseUrl, 'PATCH', '/api/v1/project-agents.php', $humanHeaders, [
            'project_id' => $projectOne,
            'agent_id' => $agentOne['agent_id'],
            'display_name' => 'Project One Agent',
            'provider' => 'gemini',
            'avatar_url' => null,
        ]);
        $suite->same(200, $response['status']);
        $suite->same('gemini', $response['body']['data']['provider']);
        $suite->true(strpos($response['raw'], '<b>Deprecated</b>') === false, 'Profile update emitted a PHP deprecation warning.');
        $statement = $pdo->prepare('SELECT COUNT(*) FROM agent_activation_bindings WHERE project_id = ? AND agent_id = ?');
        $statement->execute([$projectOne, $agentOne['agent_id']]);
        $suite->same(0, (int) $statement->fetchColumn(), 'A profile-only update must not create an activation binding.');
    });

    $suite->test('project administrator can remove an agent while preserving its participant history', function () use ($suite, $baseUrl, $humanHeaders, $projectOne, $secret, $pdo) {
        $token = 'removable_agent_' . bin2hex(random_bytes(20));
        $agent = projectApiInsertAgent($pdo, $projectOne, 'Removable Agent', $token, $secret);
        $response = projectApiRequest($baseUrl, 'DELETE', '/api/v1/project-agents.php', $humanHeaders, [
            'project_id' => $projectOne,
            'agent_id' => $agent['agent_id'],
        ]);
        $suite->same(200, $response['status'], $response['raw']);
        $suite->same(true, $response['body']['data']['removed']);
        $statement = $pdo->prepare('SELECT pa.status, pp.status AS participant_status, a.is_active, a.token_hash FROM project_agents pa JOIN project_participants pp ON pp.project_id = pa.project_id AND pp.agent_id = pa.agent_id JOIN chat_agents a ON a.id = pa.agent_id WHERE pa.project_id = ? AND pa.agent_id = ?');
        $statement->execute([$projectOne, $agent['agent_id']]);
        $removed = $statement->fetch();
        $suite->same('retired', $removed['status']);
        $suite->same('removed', $removed['participant_status']);
        $suite->same(0, (int) $removed['is_active']);
        $suite->same(null, $removed['token_hash']);
    });

    $suite->test('project administrator can remove a human member but cannot remove the owner', function () use ($suite, $baseUrl, $humanHeaders, $projectOne, $ownerId, $pdo) {
        $removableUserId = projectApiInsertUser($pdo, 'removable@project.test', 'Removable Human');
        projectApiAddMember($pdo, $projectOne, $removableUserId, 'viewer');
        $removed = projectApiRequest($baseUrl, 'DELETE', '/api/v1/project-members.php', $humanHeaders, [
            'project_id' => $projectOne,
            'user_id' => $removableUserId,
        ]);
        $suite->same(200, $removed['status'], $removed['raw']);
        $suite->same(true, $removed['body']['data']['removed']);
        $statement = $pdo->prepare('SELECT pm.status, pp.status AS participant_status FROM project_members pm JOIN project_participants pp ON pp.project_id = pm.project_id AND pp.user_id = pm.user_id WHERE pm.project_id = ? AND pm.user_id = ?');
        $statement->execute([$projectOne, $removableUserId]);
        $membership = $statement->fetch();
        $suite->same('removed', $membership['status']);
        $suite->same('removed', $membership['participant_status']);

        $ownerRemoval = projectApiRequest($baseUrl, 'DELETE', '/api/v1/project-members.php', $humanHeaders, [
            'project_id' => $projectOne,
            'user_id' => $ownerId,
        ]);
        $suite->same(403, $ownerRemoval['status']);
        $suite->same('OWNER_MEMBERSHIP_LOCKED', $ownerRemoval['body']['code']);
    });

    $suite->test('project discovery includes owned and shared projects and normalizes participants', function () use ($suite, $baseUrl, $agentOneHeaders, $humanHeaders, $memberHeaders, $projectOne, $memberId, &$contractSamples) {
        $agentProjects = projectApiRequest($baseUrl, 'GET', '/api/v1/projects.php', $agentOneHeaders);
        $humanProjects = projectApiRequest($baseUrl, 'GET', '/api/v1/projects.php', $humanHeaders);
        $memberProjects = projectApiRequest($baseUrl, 'GET', '/api/v1/projects.php', $memberHeaders);
        $participants = projectApiRequest($baseUrl, 'GET', '/api/v1/project-participants.php?project_id=' . $projectOne, $agentOneHeaders);
        $ownerParticipants = projectApiRequest($baseUrl, 'GET', '/api/v1/project-participants.php?project_id=' . $projectOne, $humanHeaders);
        $memberParticipants = projectApiRequest($baseUrl, 'GET', '/api/v1/project-participants.php?project_id=' . $projectOne, $memberHeaders);
        $ownerContext = projectApiRequest($baseUrl, 'GET', '/api/v1/project.php?project_id=' . $projectOne, $humanHeaders);
        $agentContext = projectApiRequest($baseUrl, 'GET', '/api/v1/project.php?project_id=' . $projectOne, $agentOneHeaders);
        foreach ([$agentProjects, $humanProjects, $memberProjects] as $response) {
            $contractSamples[] = ['schema' => 'ProjectListResponse', 'path' => '/api/v1/projects.php', 'method' => 'get', 'status' => 200, 'body' => $response['body']];
        }
        foreach ([$ownerContext, $agentContext] as $response) {
            $contractSamples[] = ['schema' => 'ProjectContextResponse', 'path' => '/api/v1/project.php', 'method' => 'get', 'status' => 200, 'body' => $response['body']];
        }
        foreach ([$participants, $ownerParticipants, $memberParticipants] as $response) {
            $contractSamples[] = ['schema' => 'ParticipantListResponse', 'path' => '/api/v1/project-participants.php', 'method' => 'get', 'status' => 200, 'body' => $response['body']];
        }
        $suite->same(200, $agentProjects['status']);
        $suite->same(1, count($agentProjects['body']['data']));
        $suite->same(2, $agentProjects['body']['data'][0]['human_count']);
        $suite->same(1, $agentProjects['body']['data'][0]['agent_count']);
        $suite->same(0, $agentProjects['body']['data'][0]['message_count']);
        $suite->true(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $agentProjects['body']['data'][0]['public_id']) === 1);
        $suite->same($agentProjects['body']['data'][0]['public_id'], $agentContext['body']['data']['project']['public_id']);
        $suite->same(1, count($humanProjects['body']['data']));
        $suite->same(2, count($memberProjects['body']['data']));
        $relationships = array_values(array_unique(array_column($memberProjects['body']['data'], 'relationship')));
        sort($relationships);
        $suite->same(['owned', 'shared'], $relationships);
        $suite->same(3, count($participants['body']['data']));
        $kinds = array_column($participants['body']['data'], 'kind');
        sort($kinds);
        $suite->same(['agent', 'human', 'human'], $kinds);
        foreach ($participants['body']['data'] as $participant) {
            $suite->true(array_key_exists('joined_at', $participant));
            $suite->true(array_key_exists('last_message_at', $participant));
            $suite->true(array_key_exists('message_count', $participant));
            $suite->true(!array_key_exists('email', $participant), 'Agents must not receive human account details.');
        }
        $ownerHumanProfiles = array_values(array_filter($ownerParticipants['body']['data'], function ($participant) { return $participant['kind'] === 'human'; }));
        $suite->true(count($ownerHumanProfiles) === 2);
        $suite->true(array_key_exists('email', $ownerHumanProfiles[0]) && array_key_exists('authentication_source', $ownerHumanProfiles[0]), 'Project managers must receive permission-scoped human account metadata.');
        $memberVisibleAccounts = array_values(array_filter($memberParticipants['body']['data'], function ($participant) { return array_key_exists('email', $participant); }));
        $suite->same(1, count($memberVisibleAccounts), 'Ordinary members must only receive their own human account metadata.');
        $suite->same($memberId, $memberVisibleAccounts[0]['identity_id']);
        $suite->same([
            'messages.read' => true,
            'messages.write' => true,
            'messages.acknowledge' => true,
            'project.manage' => true,
            'project.admin' => true,
            'members.manage' => true,
            'agents.manage' => true,
            'ownership.transfer' => true,
        ], $ownerContext['body']['data']['permissions']);
        $suite->same([
            'messages.read' => true,
            'messages.write' => true,
            'messages.acknowledge' => true,
            'project.manage' => false,
            'project.admin' => false,
            'members.manage' => false,
            'agents.manage' => false,
            'ownership.transfer' => false,
        ], $agentContext['body']['data']['permissions']);
    });

    $suite->test('MCP tool discovery preserves the bounded timeline adapter contract', function () use ($suite, $baseUrl) {
        $response = projectApiRequest($baseUrl, 'POST', '/mcp.php', [], [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass(),
        ]);
        $suite->same(200, $response['status'], $response['raw']);
        $tools = [];
        foreach ($response['body']['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool['inputSchema'];
        }
        foreach (['list_projects', 'get_project', 'list_participants', 'list_messages', 'get_message', 'post_message', 'acknowledge_message'] as $name) {
            $suite->true(isset($tools[$name]), 'Missing MCP tool: ' . $name);
            $suite->true(isset($tools[$name]['properties']['binding_context_id']), 'Missing binding context on ' . $name);
        }
        $suite->same(50, $tools['list_messages']['properties']['limit']['default']);
        $suite->same(200, $tools['list_messages']['properties']['limit']['maximum']);
        $suite->same(['body', 'idempotency_key'], $tools['post_message']['required']);
        $suite->true(!isset($tools['post_message']['properties']['correlation_id']), 'HTTP-only correlation_id must not be advertised by MCP.');
        $suite->same(['message_id'], $tools['acknowledge_message']['required']);
    });

    $messageId = null;
    $suite->test('agent creates canonical direct and mention message idempotently', function () use ($suite, $baseUrl, $agentOneHeaders, $projectOne, $ownerParticipant, $memberParticipant, &$messageId, $pdo, &$contractSamples) {
        $payload = [
            'body' => 'Review the project API.',
            'direct_participant_ids' => [$ownerParticipant],
            'mention_participant_ids' => [$memberParticipant, $ownerParticipant],
            'idempotency_key' => 'project-api-test-message',
        ];
        $created = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, $payload);
        $replayed = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, $payload);
        $suite->same(201, $created['status'], $created['raw']);
        $suite->same(200, $replayed['status']);
        $contractSamples[] = ['schema' => 'MessageWriteResponse', 'path' => '/api/v1/project-messages.php', 'method' => 'post', 'status' => 201, 'body' => $created['body']];
        $contractSamples[] = ['schema' => 'MessageWriteResponse', 'path' => '/api/v1/project-messages.php', 'method' => 'post', 'status' => 200, 'body' => $replayed['body']];
        $suite->same(true, $replayed['body']['idempotent_replay']);
        $suite->same($created['body']['data']['id'], $replayed['body']['data']['id']);
        $equivalentReplay = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders,
            array_merge($payload, ['direct_participant_ids' => [$ownerParticipant, $ownerParticipant],
                'mention_participant_ids' => [$ownerParticipant, $memberParticipant, $memberParticipant]]));
        $suite->same(200, $equivalentReplay['status']);
        $suite->same(true, $equivalentReplay['body']['idempotent_replay']);
        $changedReplay = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders,
            array_merge($payload, ['body' => 'This changed payload must not create another message.']));
        $suite->same(409, $changedReplay['status']);
        $suite->same('IDEMPOTENCY_KEY_CONFLICT', $changedReplay['body']['code']);
        $contractSamples[] = ['schema' => 'ApiError', 'path' => '/api/v1/project-messages.php', 'method' => 'post', 'status' => 409, 'body' => $changedReplay['body']];
        $changedAddressing = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders,
            array_merge($payload, ['broadcast' => true]));
        $suite->same(409, $changedAddressing['status']);
        $suite->same(2, count($created['body']['data']['addressees']));
        foreach ($created['body']['data']['addressees'] as $addressee) {
            if ((int) $addressee['participant_id'] === (int) $ownerParticipant) {
                $suite->same('direct', $addressee['reason']);
            }
        }
        $suite->same('agent', $created['body']['data']['sender']['kind']);
        $messageId = $created['body']['data']['id'];
        $suite->same(0, (int) $pdo->query('SELECT COUNT(*) FROM message_events_outbox')->fetchColumn());
        $lookup = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&idempotency_key=project-api-test-message', $agentOneHeaders);
        $suite->same(1, count($lookup['body']['data']));
        $suite->same($messageId, $lookup['body']['data'][0]['id']);
    });

    $suite->test('all project members see messages while addressed filters express responsibility', function () use ($suite, $baseUrl, $humanHeaders, $agentOneHeaders, $projectOne, $messageId, &$contractSamples) {
        $all = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders);
        $mine = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&addressed_to=me&acknowledged=false', $humanHeaders);
        $suite->same(200, $all['status']);
        $contractSamples[] = ['schema' => 'MessagePageResponse', 'path' => '/api/v1/project-messages.php', 'method' => 'get', 'status' => 200, 'body' => $all['body']];
        $emptyForward = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne
            . '&after=' . rawurlencode($all['body']['page']['newer_cursor']), $agentOneHeaders);
        $suite->same(200, $emptyForward['status']);
        $suite->same([], $emptyForward['body']['data']);
        $contractSamples[] = ['schema' => 'MessagePageResponse', 'path' => '/api/v1/project-messages.php', 'method' => 'get', 'status' => 200, 'body' => $emptyForward['body']];
        $suite->same($messageId, $all['body']['data'][0]['id']);
        $suite->same(1, count($mine['body']['data']));
        $unsupported = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&acknowledged=true', $humanHeaders);
        $suite->same(422, $unsupported['status']);
        $suite->same('VALIDATION_FAILED', $unsupported['body']['code']);
        $contractSamples[] = ['schema' => 'ApiError', 'path' => '/api/v1/project-messages.php', 'method' => 'get', 'status' => 422, 'body' => $unsupported['body']];
        $malformedProject = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . 'junk', $agentOneHeaders);
        $suite->same(422, $malformedProject['status']);
    });

    $suite->test('human addressee acknowledges using session and CSRF', function () use ($suite, $baseUrl, $humanHeaders, $projectOne, $messageId, &$contractSamples) {
        $response = projectApiRequest($baseUrl, 'POST', '/api/v1/project-message-acknowledge.php?project_id=' . $projectOne . '&id=' . $messageId, $humanHeaders, []);
        $suite->same(200, $response['status'], $response['raw']);
        $contractSamples[] = ['schema' => 'MessageResponse', 'path' => '/api/v1/project-message-acknowledge.php', 'method' => 'post', 'status' => 200, 'body' => $response['body']];
        $acknowledged = null;
        foreach ($response['body']['data']['addressees'] as $addressee) {
            if ($addressee['display_name'] === 'Owner Human') {
                $acknowledged = $addressee['acknowledged_at'];
            }
        }
        $suite->true($acknowledged !== null);
        $repeated = projectApiRequest($baseUrl, 'POST', '/api/v1/project-message-acknowledge.php?project_id=' . $projectOne . '&id=' . $messageId, $humanHeaders, []);
        $suite->same(200, $repeated['status']);
        foreach ($repeated['body']['data']['addressees'] as $addressee) {
            if ($addressee['display_name'] === 'Owner Human') {
                $suite->same($acknowledged, $addressee['acknowledged_at']);
            }
        }
    });

    $suite->test('delivery health is readable only by a system administrator', function () use ($suite, $baseUrl, $humanHeaders, $memberHeaders, $agentOneHeaders) {
        $path = '/api/v1/admin/delivery-health.php';
        $anonymous = projectApiRequest($baseUrl, 'GET', $path);
        $member = projectApiRequest($baseUrl, 'GET', $path, $memberHeaders);
        $agent = projectApiRequest($baseUrl, 'GET', $path, $agentOneHeaders);
        $administrator = projectApiRequest($baseUrl, 'GET', $path, $humanHeaders);
        $suite->same(401, $anonymous['status']);
        $suite->same(403, $member['status']);
        $suite->same(401, $agent['status']);
        $suite->same(200, $administrator['status'], $administrator['raw']);
        $suite->same('unknown', $administrator['body']['data']['state']);
        $suite->true(isset($administrator['body']['data']['paths']['realtime']));
        $suite->true(strpos($administrator['raw'], 'signing_secret') === false);
    });

    $suite->test('core API errors distinguish authentication, concealment, and addressee conflicts', function () use ($suite, $baseUrl, $agentOneHeaders, $agentTwoHeaders, $projectOne, $messageId, &$contractSamples) {
        $unauthenticated = projectApiRequest($baseUrl, 'GET', '/api/v1/projects.php');
        $foreign = projectApiRequest($baseUrl, 'GET', '/api/v1/project-message.php?project_id=' . $projectOne . '&id=' . $messageId, $agentTwoHeaders);
        $missing = projectApiRequest($baseUrl, 'GET', '/api/v1/project-message.php?project_id=' . $projectOne . '&id=2147483647', $agentOneHeaders);
        $notAddressed = projectApiRequest($baseUrl, 'POST', '/api/v1/project-message-acknowledge.php?project_id=' . $projectOne . '&id=' . $messageId, $agentOneHeaders, []);
        $suite->same(401, $unauthenticated['status']);
        $suite->same('AUTHENTICATION_REQUIRED', $unauthenticated['body']['code']);
        $suite->same(404, $foreign['status']);
        $suite->same('PROJECT_NOT_FOUND', $foreign['body']['code']);
        $suite->same(404, $missing['status']);
        $suite->same('MESSAGE_NOT_FOUND', $missing['body']['code']);
        $suite->same(409, $notAddressed['status']);
        $suite->same('MESSAGE_NOT_ADDRESSED_TO_PARTICIPANT', $notAddressed['body']['code']);
        $contractSamples[] = ['schema' => 'ApiError', 'path' => '/api/v1/projects.php', 'method' => 'get', 'status' => 401, 'body' => $unauthenticated['body']];
        $contractSamples[] = ['schema' => 'ApiError', 'path' => '/api/v1/project-message.php', 'method' => 'get', 'status' => 404, 'body' => $foreign['body']];
        $contractSamples[] = ['schema' => 'ApiError', 'path' => '/api/v1/project-message.php', 'method' => 'get', 'status' => 404, 'body' => $missing['body']];
        $contractSamples[] = ['schema' => 'ApiError', 'path' => '/api/v1/project-message-acknowledge.php', 'method' => 'post', 'status' => 409, 'body' => $notAddressed['body']];
    });

    $suite->test('broadcast resolves every participant and enabled Realtime receives a canonical outbox event', function () use ($suite, $baseUrl, $agentOneHeaders, $projectOne, $pdo) {
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, value_json, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)'
        )->execute(['realtime.enabled', 'true', Db::now()]);
        $response = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, [
            'body' => 'Project-wide announcement.',
            'broadcast' => true,
        ]);
        $suite->same(201, $response['status'], $response['raw']);
        $suite->same(2, count($response['body']['data']['addressees']));
        foreach ($response['body']['data']['addressees'] as $addressee) {
            $suite->same('broadcast', $addressee['reason']);
        }
        $event = $pdo->query('SELECT payload_json FROM message_events_outbox ORDER BY id DESC LIMIT 1')->fetchColumn();
        $event = json_decode($event, true);
        $suite->same('syndicatum.message.created', $event['type']);
        $suite->same($response['body']['data']['id'], $event['message']['id']);
    });

    $suite->test('omitted or empty addressing is normalized to a project broadcast', function () use ($suite, $baseUrl, $agentOneHeaders, $projectOne) {
        foreach ([
            ['body' => 'No addressing fields.'],
            ['body' => 'Empty addressing fields.', 'broadcast' => false, 'direct_participant_ids' => [], 'mention_participant_ids' => []],
        ] as $input) {
            $response = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, $input);
            $suite->same(201, $response['status'], $response['raw']);
            $suite->same(2, count($response['body']['data']['addressees']));
            foreach ($response['body']['data']['addressees'] as $addressee) {
                $suite->same('broadcast', $addressee['reason']);
            }
        }
    });

    $suite->test('newest-first cursors paginate without duplicate messages', function () use ($suite, $baseUrl, $agentOneHeaders, $projectOne) {
        for ($index = 0; $index < 3; $index++) {
            projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, [
                'body' => 'Paging message ' . $index,
            ]);
        }
        $first = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&limit=2', $agentOneHeaders);
        $second = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&limit=2&before=' . rawurlencode($first['body']['page']['older_cursor']), $agentOneHeaders);
        $suite->same(200, $first['status']);
        $suite->same(200, $second['status']);
        $suite->true($first['body']['data'][0]['project_sequence'] > $first['body']['data'][1]['project_sequence']);
        $suite->true(!in_array($first['body']['data'][0]['id'], array_column($second['body']['data'], 'id'), true));
    });

    $suite->test('message cursors reject reuse across projects', function () use ($suite, $baseUrl, $agentOneHeaders, $agentTwoHeaders, $projectOne, $projectTwo) {
        $origin = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&limit=1', $agentOneHeaders);
        $suite->same(200, $origin['status']);
        $cursor = $origin['body']['page']['older_cursor'];
        $suite->true(is_string($cursor) && $cursor !== '');
        $foreign = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectTwo
            . '&before=' . rawurlencode($cursor), $agentTwoHeaders);
        $suite->same(422, $foreign['status']);
        $suite->same('VALIDATION_FAILED', $foreign['body']['code']);
    });

    $suite->test('forward recovery returns the earliest missing windows without sequence gaps', function () use ($suite, $baseUrl, $agentOneHeaders, $projectOne, $agentOne, $pdo) {
        $baseline = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&limit=1', $agentOneHeaders);
        $cursor = $baseline['body']['page']['newer_cursor'];
        $next = (int) $pdo->query('SELECT next_sequence FROM project_message_sequences WHERE project_id = ' . (int) $projectOne)->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO messages (message_uuid, project_id, project_sequence, sender_participant_id, body, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        for ($offset = 0; $offset < 205; $offset++) {
            $hex = str_pad(dechex($offset + 1), 12, '0', STR_PAD_LEFT);
            $uuid = '20000000-0000-4000-8000-' . $hex;
            $insert->execute([$uuid, $projectOne, $next + $offset, $agentOne['participant_id'], 'Recovery ' . $offset, Db::now(), Db::now()]);
        }
        $pdo->prepare('UPDATE project_message_sequences SET next_sequence = ? WHERE project_id = ?')->execute([$next + 205, $projectOne]);
        $seenSequences = [];
        do {
            $page = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&limit=100&after=' . rawurlencode($cursor), $agentOneHeaders);
            foreach ($page['body']['data'] as $message) { $seenSequences[] = (int) $message['project_sequence']; }
            $cursor = $page['body']['page']['continuation_cursor'];
        } while ($page['body']['page']['has_more']);
        sort($seenSequences);
        $suite->same(range($next, $next + 204), $seenSequences);
    });

    $suite->test('message lists default to 50 while explicit recovery pages may request 200', function () use ($suite, $baseUrl, $agentOneHeaders, $projectOne) {
        $ordinary = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders);
        $recovery = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&limit=200', $agentOneHeaders);
        $suite->same(200, $ordinary['status']);
        $suite->same(50, $ordinary['body']['page']['limit']);
        $suite->same(50, count($ordinary['body']['data']));
        $suite->true($ordinary['body']['page']['has_more']);
        $suite->same(200, $recovery['status']);
        $suite->same(200, $recovery['body']['page']['limit']);
        $suite->same(200, count($recovery['body']['data']));
        $suite->true($recovery['body']['page']['has_more']);
    });

    $suite->test('project isolation conceals messages and rejects foreign addressees and replies', function () use ($suite, $baseUrl, $agentTwoHeaders, $agentOneHeaders, $projectOne, $projectTwo, $messageId, $agentTwo) {
        $foreignRead = projectApiRequest($baseUrl, 'GET', '/api/v1/project-message.php?project_id=' . $projectOne . '&id=' . $messageId, $agentTwoHeaders);
        $foreignAddressee = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, [
            'body' => 'Invalid target', 'direct_participant_ids' => [$agentTwo['participant_id']],
        ]);
        $otherMessage = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectTwo, $agentTwoHeaders, ['body' => 'Other project']);
        $foreignReply = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, [
            'body' => 'Invalid reply', 'reply_to_message_id' => $otherMessage['body']['data']['id'],
        ]);
        $suite->same(404, $foreignRead['status']);
        $suite->same(422, $foreignAddressee['status']);
        $suite->same(422, $foreignReply['status']);
    });

    $suite->test('global message-size and reply-depth settings govern project writes', function () use ($suite, $baseUrl, $agentOneHeaders, $projectOne, $pdo) {
        $upsert = $pdo->prepare(
            'INSERT INTO system_settings (setting_key, value_json, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)'
        );
        $upsert->execute(['messaging.max_message_bytes', '1024', Db::now()]);
        $tooLarge = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, [
            'body' => str_repeat('x', 1025),
        ]);
        $suite->same(422, $tooLarge['status']);

        $upsert->execute(['messaging.max_reply_depth', '1', Db::now()]);
        $root = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, ['body' => 'Depth root']);
        $firstReply = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, [
            'body' => 'Depth one', 'reply_to_message_id' => $root['body']['data']['id'],
        ]);
        $tooDeep = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, [
            'body' => 'Depth two', 'reply_to_message_id' => $firstReply['body']['data']['id'],
        ]);
        $suite->same(201, $firstReply['status']);
        $suite->same(422, $tooDeep['status']);
    });

    $suite->test('owner can revise and soft-delete a project message with history retained', function () use ($suite, $baseUrl, $humanHeaders, $projectOne, $messageId, $pdo, &$contractSamples) {
        $edited = projectApiRequest($baseUrl, 'PATCH', '/api/v1/project-message.php?project_id=' . $projectOne . '&id=' . $messageId, $humanHeaders, [
            'body' => 'Project administrator correction.',
        ]);
        $deleted = projectApiRequest($baseUrl, 'DELETE', '/api/v1/project-message.php?project_id=' . $projectOne . '&id=' . $messageId, $humanHeaders);
        $withRevisions = projectApiRequest($baseUrl, 'GET', '/api/v1/project-message.php?project_id=' . $projectOne . '&id=' . $messageId . '&include=revisions', $humanHeaders);
        $suite->same(200, $edited['status'], $edited['raw']);
        $suite->same(200, $deleted['status'], $deleted['raw']);
        $suite->same(200, $withRevisions['status'], $withRevisions['raw']);
        $contractSamples[] = ['schema' => 'MessageResponse', 'path' => '/api/v1/project-message.php', 'method' => 'patch', 'status' => 200, 'body' => $edited['body']];
        $contractSamples[] = ['schema' => 'MessageResponse', 'path' => '/api/v1/project-message.php', 'method' => 'delete', 'status' => 200, 'body' => $deleted['body']];
        $contractSamples[] = ['schema' => 'MessageResponse', 'path' => '/api/v1/project-message.php', 'method' => 'get', 'status' => 200, 'body' => $withRevisions['body']];
        $suite->same(null, $deleted['body']['data']['body']);
        $suite->true($deleted['body']['data']['deleted_at'] !== null);
        $suite->same(1, (int) $pdo->query('SELECT COUNT(*) FROM message_revisions WHERE message_id = ' . (int) $messageId)->fetchColumn());
    });

    $suite->test('human mutations reject missing CSRF evidence', function () use ($suite, $baseUrl, $sessionToken, $projectOne, &$contractSamples) {
        $response = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, [
            'Cookie: syndicatum_session=' . $sessionToken,
        ], ['body' => 'No CSRF']);
        $suite->same(403, $response['status']);
        $suite->same('CSRF_VALIDATION_FAILED', $response['body']['code']);
        $contractSamples[] = ['schema' => 'ApiError', 'path' => '/api/v1/project-messages.php', 'method' => 'post', 'status' => 403, 'body' => $response['body']];
    });

    $suite->test('remote MCP service token uses its pinned project and agent without a ChatGPT discussion binding', function () use ($suite, $baseUrl, $pdo, $projectOne, $projectTwo, $agentOne, $agentTwo, $ownerId) {
        $tokens = new McpServiceTokenService($pdo);
        $tokenOne = $tokens->issue($projectOne, $agentOne['agent_id'], $ownerId);
        $tokenTwo = $tokens->issue($projectTwo, $agentTwo['agent_id'], $ownerId);
        $call = function ($token, $name, array $arguments = []) use ($baseUrl) {
            return projectApiRequest($baseUrl, 'POST', '/mcp.php', ['Authorization: Bearer ' . $token], [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
        };

        $diagnosis = $call($tokenOne, 'diagnose_connection');
        $suite->same(200, $diagnosis['status'], $diagnosis['raw']);
        $suite->true(isset($diagnosis['body']['result']['isError']), $diagnosis['raw']);
        $suite->same(false, $diagnosis['body']['result']['isError']);
        $suite->same('service_token', $diagnosis['body']['result']['structuredContent']['result']['context_type']);
        $suite->same('Not required', $diagnosis['body']['result']['structuredContent']['result']['discussion_binding']);
        $suite->same($agentOne['agent_id'], $diagnosis['body']['result']['structuredContent']['result']['agent_identity']['agent_id']);

        $projects = $call($tokenOne, 'list_projects');
        $suite->same(200, $projects['status']);
        $suite->same(false, $projects['body']['result']['isError']);
        $suite->same([$projectOne], array_column($projects['body']['result']['structuredContent']['result'], 'id'));

        $posted = $call($tokenOne, 'post_message', [
            'body' => 'Remote MCP service-token contract probe.',
            'idempotency_key' => 'remote-mcp-service-token-contract-probe',
        ]);
        $suite->same(200, $posted['status'], $posted['raw']);
        $suite->same(false, $posted['body']['result']['isError']);
        $message = $posted['body']['result']['structuredContent']['result']['message'];
        $suite->same($agentOne['participant_id'], $message['sender']['participant_id']);
        $read = $call($tokenOne, 'get_message', ['message_id' => $message['id']]);
        $suite->same(false, $read['body']['result']['isError']);
        $suite->same($message['id'], $read['body']['result']['structuredContent']['result']['id']);

        $foreign = $call($tokenTwo, 'get_message', ['message_id' => $message['id']]);
        $suite->same(200, $foreign['status']);
        $suite->same(true, $foreign['body']['result']['isError']);
        $suite->same('MESSAGE_NOT_FOUND', $foreign['body']['result']['content'][0]['text']);

        $scopeInsert = $pdo->prepare('INSERT INTO agent_credential_scopes (agent_id, scope, created_at) VALUES (?, ?, ?)');
        $scopeInsert->execute([$agentTwo['agent_id'], 'profile:read', Db::now()]);
        $scopeInsert->execute([$agentTwo['agent_id'], 'messages:read', Db::now()]);
        $restrictedWrite = $call($tokenTwo, 'post_message', [
            'body' => 'This restricted agent must not post.',
            'idempotency_key' => 'remote-mcp-restricted-write-probe',
        ]);
        $suite->same(401, $restrictedWrite['status']);
        $suite->same(true, $restrictedWrite['body']['result']['isError']);

        $pdo->prepare('UPDATE mcp_service_tokens SET revoked_at = ? WHERE token_hash = ?')
            ->execute([Db::now(), hash('sha256', $tokenOne)]);
        $revoked = $call($tokenOne, 'list_projects');
        $suite->same(401, $revoked['status']);
        $suite->same(true, $revoked['body']['result']['isError']);
    });

    $suite->test('OAuth MCP timeline tools still require a confirmed binding context', function () use ($suite, $baseUrl, $pdo, $projectOne, $agentOne, $ownerId) {
        $clientId = 'contract-oauth-client';
        $token = 'contract_oauth_' . bin2hex(random_bytes(24));
        $pdo->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)')
            ->execute([$clientId, 'Contract test client', '[]', Db::now(), Db::now()]);
        $pdo->prepare('INSERT INTO oauth_access_tokens
            (token_hash, client_id, user_id, project_id, agent_id, resource_uri, scope_text, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $clientId, $ownerId, $projectOne, $agentOne['agent_id'],
                $baseUrl . '/mcp', implode(' ', ChatGptOAuthService::SCOPES), Db::now(),
                gmdate('Y-m-d H:i:s', time() + 3600)]);
        $call = function ($name, array $arguments = []) use ($baseUrl, $token) {
            return projectApiRequest($baseUrl, 'POST', '/mcp.php', ['Authorization: Bearer ' . $token], [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ]);
        };
        $unbound = $call('list_projects');
        $suite->same(200, $unbound['status'], $unbound['raw']);
        $suite->same(true, $unbound['body']['result']['isError']);
        $suite->same('DISCUSSION_BINDING_REQUIRED', $unbound['body']['result']['content'][0]['text']);
        $invalid = $call('list_projects', ['binding_context_id' => 'not-a-confirmed-context']);
        $suite->same(true, $invalid['body']['result']['isError']);
        $suite->same('DISCUSSION_BINDING_REQUIRED', $invalid['body']['result']['content'][0]['text']);
        $diagnosis = $call('diagnose_connection');
        $suite->same(false, $diagnosis['body']['result']['structuredContent']['result']['checks']['project_access_valid']);
        $suite->same('Required', $diagnosis['body']['result']['structuredContent']['result']['discussion_binding']);
    });

    $suite->test('legacy API can be disabled through the controlled operations setting', function () use ($suite, $baseUrl, $pdo) {
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, value_json, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)'
        )->execute(['operations.legacy_api_enabled', 'false', Db::now()]);
        $response = projectApiRequest($baseUrl, 'GET', '/api/chat-context.php');
        $suite->same(410, $response['status']);
        $suite->same('LEGACY_API_DISABLED', $response['body']['code']);
        $suite->same('true', $response['headers']['deprecation']);
        $suite->true(strpos($response['headers']['link'], 'rel="successor-version"') !== false);
        $usage = $pdo->query(
            "SELECT request_count FROM legacy_api_usage_daily
             WHERE endpoint = '/api/chat-context.php' AND method = 'GET'"
        )->fetchColumn();
        $suite->same(1, (int) $usage);
    });
    $capturePath = getenv('SYNDICATUM_CONTRACT_CAPTURE');
    if ($capturePath !== false && $capturePath !== '') {
        file_put_contents($capturePath, json_encode($contractSamples, JSON_UNESCAPED_SLASHES));
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($serverLog && is_file($serverLog)) {
        unlink($serverLog);
    }
    if (isset($admin) && $admin instanceof PDO) {
        if (!preg_match('/^syndicatum_project_api_[a-f0-9]{12}$/', $database)) {
            throw new RuntimeException('Refusing to drop unsafe project API test database.');
        }
        $admin->exec('DROP DATABASE `' . $database . '`');
    }
}

exit($suite->finish());
