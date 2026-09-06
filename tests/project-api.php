<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

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
    $decoded = json_decode(substr($raw, $headerSize), true);
    return ['status' => $status, 'body' => $decoded, 'raw' => substr($raw, $headerSize)];
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
        "INSERT INTO projects (workspace_id, owner_user_id, name, slug, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'active', ?, ?)"
    )->execute([$workspaceId, $userId, $name, $slug, $now, $now]);
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
    list($server, $baseUrl, $serverLog) = projectApiServer($root, projectApiPort(), $environment);
    $agentOneHeaders = ['Authorization: Bearer ' . $agentOneToken];
    $agentTwoHeaders = ['Authorization: Bearer ' . $agentTwoToken];
    $humanHeaders = ['Cookie: syndicatum_session=' . $sessionToken, 'X-CSRF-Token: ' . $csrfToken];
    $memberHeaders = ['Cookie: syndicatum_session=' . $memberSessionToken, 'X-CSRF-Token: ' . $memberCsrfToken];

    $suite->test('project discovery includes owned and shared projects and normalizes participants', function () use ($suite, $baseUrl, $agentOneHeaders, $humanHeaders, $memberHeaders, $projectOne) {
        $agentProjects = projectApiRequest($baseUrl, 'GET', '/api/v1/projects.php', $agentOneHeaders);
        $humanProjects = projectApiRequest($baseUrl, 'GET', '/api/v1/projects.php', $humanHeaders);
        $memberProjects = projectApiRequest($baseUrl, 'GET', '/api/v1/projects.php', $memberHeaders);
        $participants = projectApiRequest($baseUrl, 'GET', '/api/v1/project-participants.php?project_id=' . $projectOne, $agentOneHeaders);
        $ownerContext = projectApiRequest($baseUrl, 'GET', '/api/v1/project.php?project_id=' . $projectOne, $humanHeaders);
        $agentContext = projectApiRequest($baseUrl, 'GET', '/api/v1/project.php?project_id=' . $projectOne, $agentOneHeaders);
        $suite->same(200, $agentProjects['status']);
        $suite->same(1, count($agentProjects['body']['data']));
        $suite->same(1, count($humanProjects['body']['data']));
        $suite->same(2, count($memberProjects['body']['data']));
        $relationships = array_values(array_unique(array_column($memberProjects['body']['data'], 'relationship')));
        sort($relationships);
        $suite->same(['owned', 'shared'], $relationships);
        $suite->same(3, count($participants['body']['data']));
        $kinds = array_column($participants['body']['data'], 'kind');
        sort($kinds);
        $suite->same(['agent', 'human', 'human'], $kinds);
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

    $messageId = null;
    $suite->test('agent creates canonical direct and mention message idempotently', function () use ($suite, $baseUrl, $agentOneHeaders, $projectOne, $ownerParticipant, $memberParticipant, &$messageId, $pdo) {
        $payload = [
            'body' => 'Review the project API.',
            'direct_participant_ids' => [$ownerParticipant],
            'mention_participant_ids' => [$memberParticipant],
            'idempotency_key' => 'project-api-test-message',
        ];
        $created = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, $payload);
        $replayed = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders, $payload);
        $suite->same(201, $created['status'], $created['raw']);
        $suite->same(200, $replayed['status']);
        $suite->same(true, $replayed['body']['idempotent_replay']);
        $suite->same($created['body']['data']['id'], $replayed['body']['data']['id']);
        $suite->same(2, count($created['body']['data']['addressees']));
        $suite->same('agent', $created['body']['data']['sender']['kind']);
        $messageId = $created['body']['data']['id'];
        $suite->same(0, (int) $pdo->query('SELECT COUNT(*) FROM message_events_outbox')->fetchColumn());
        $lookup = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&idempotency_key=project-api-test-message', $agentOneHeaders);
        $suite->same(1, count($lookup['body']['data']));
        $suite->same($messageId, $lookup['body']['data'][0]['id']);
    });

    $suite->test('all project members see messages while addressed filters express responsibility', function () use ($suite, $baseUrl, $humanHeaders, $agentOneHeaders, $projectOne, $messageId) {
        $all = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne, $agentOneHeaders);
        $mine = projectApiRequest($baseUrl, 'GET', '/api/v1/project-messages.php?project_id=' . $projectOne . '&addressed_to=me&acknowledged=false', $humanHeaders);
        $suite->same(200, $all['status']);
        $suite->same($messageId, $all['body']['data'][0]['id']);
        $suite->same(1, count($mine['body']['data']));
    });

    $suite->test('human addressee acknowledges using session and CSRF', function () use ($suite, $baseUrl, $humanHeaders, $projectOne, $messageId) {
        $response = projectApiRequest($baseUrl, 'POST', '/api/v1/project-message-acknowledge.php?project_id=' . $projectOne . '&id=' . $messageId, $humanHeaders, []);
        $suite->same(200, $response['status'], $response['raw']);
        $acknowledged = null;
        foreach ($response['body']['data']['addressees'] as $addressee) {
            if ($addressee['display_name'] === 'Owner Human') {
                $acknowledged = $addressee['acknowledged_at'];
            }
        }
        $suite->true($acknowledged !== null);
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

    $suite->test('owner can revise and soft-delete a project message with history retained', function () use ($suite, $baseUrl, $humanHeaders, $projectOne, $messageId, $pdo) {
        $edited = projectApiRequest($baseUrl, 'PATCH', '/api/v1/project-message.php?project_id=' . $projectOne . '&id=' . $messageId, $humanHeaders, [
            'body' => 'Project administrator correction.',
        ]);
        $deleted = projectApiRequest($baseUrl, 'DELETE', '/api/v1/project-message.php?project_id=' . $projectOne . '&id=' . $messageId, $humanHeaders);
        $suite->same(200, $edited['status'], $edited['raw']);
        $suite->same(200, $deleted['status'], $deleted['raw']);
        $suite->same(null, $deleted['body']['data']['body']);
        $suite->true($deleted['body']['data']['deleted_at'] !== null);
        $suite->same(1, (int) $pdo->query('SELECT COUNT(*) FROM message_revisions WHERE message_id = ' . (int) $messageId)->fetchColumn());
    });

    $suite->test('human mutations reject missing CSRF evidence', function () use ($suite, $baseUrl, $sessionToken, $projectOne) {
        $response = projectApiRequest($baseUrl, 'POST', '/api/v1/project-messages.php?project_id=' . $projectOne, [
            'Cookie: syndicatum_session=' . $sessionToken,
        ], ['body' => 'No CSRF']);
        $suite->same(403, $response['status']);
        $suite->same('CSRF_VALIDATION_FAILED', $response['body']['code']);
    });

    $suite->test('legacy API can be disabled through the controlled operations setting', function () use ($suite, $baseUrl, $pdo) {
        $pdo->prepare(
            'INSERT INTO system_settings (setting_key, value_json, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_at = VALUES(updated_at)'
        )->execute(['operations.legacy_api_enabled', 'false', Db::now()]);
        $response = projectApiRequest($baseUrl, 'GET', '/api/chat-context.php');
        $suite->same(410, $response['status']);
        $suite->same('LEGACY_API_DISABLED', $response['body']['code']);
    });
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
