<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';

class SurfaceContractSuite
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
            throw new RuntimeException(($message !== '' ? $message . ' ' : '')
                . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
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

function surfaceRandom($bytes)
{
    if (function_exists('random_bytes')) {
        return random_bytes($bytes);
    }
    $strong = false;
    $value = openssl_random_pseudo_bytes($bytes, $strong);
    if ($value === false || !$strong) {
        throw new RuntimeException('Secure random source unavailable.');
    }
    return $value;
}

function surfaceRequest($baseUrl, $method, $path, array $headers = [], $body = null)
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

    $headerText = substr($raw, 0, $headerSize);
    $cookies = [];
    foreach (preg_split('/\r\n|\n|\r/', $headerText) as $line) {
        if (stripos($line, 'Set-Cookie:') !== 0) {
            continue;
        }
        $pair = trim(substr($line, strlen('Set-Cookie:')));
        $pair = explode(';', $pair, 2)[0];
        $equals = strpos($pair, '=');
        if ($equals !== false) {
            $cookies[rawurldecode(substr($pair, 0, $equals))] = rawurldecode(substr($pair, $equals + 1));
        }
    }
    $responseBody = substr($raw, $headerSize);
    $decoded = $responseBody === '' ? null : json_decode($responseBody, true);
    if ($responseBody !== '' && !is_array($decoded)) {
        throw new RuntimeException('Response was not JSON: ' . substr($responseBody, 0, 200));
    }
    return ['status' => $status, 'body' => $decoded, 'cookies' => $cookies, 'raw' => $responseBody];
}

function surfacePort()
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
    if (!$socket) {
        throw new RuntimeException('Unable to reserve test port: ' . $message);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($name, ':'), 1);
}

function surfaceServer($root, $port, array $environment)
{
    $log = tempnam(sys_get_temp_dir(), 'syndicatum-surfaces-');
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root], [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ], $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start surface test server.');
    }
    fclose($pipes[0]);
    $baseUrl = 'http://127.0.0.1:' . $port;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        try {
            if (surfaceRequest($baseUrl, 'GET', '/api/v1/session.php')['status'] === 200) {
                return [$process, $baseUrl, $log];
            }
        } catch (Exception $ignored) {
        }
    }
    proc_terminate($process);
    throw new RuntimeException('Surface test server failed: ' . (is_file($log) ? file_get_contents($log) : ''));
}

function surfaceInsertUser(PDO $pdo, $email, $name, $password, array $roles, $pbbUserId = null)
{
    $now = Db::now();
    $statement = $pdo->prepare(
        "INSERT INTO users (normalized_email, username, password_hash, display_name, pbb_user_id, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'active', ?, ?)"
    );
    $statement->execute([
        strtolower($email), strstr($email, '@', true), $password === null ? null : password_hash($password, PASSWORD_DEFAULT),
        $name, $pbbUserId, $now, $now,
    ]);
    $userId = (int) $pdo->lastInsertId();
    $insertRole = $pdo->prepare(
        'INSERT INTO user_system_roles (user_id, role_id, created_at) SELECT ?, id, ? FROM system_roles WHERE code = ?'
    );
    foreach ($roles as $role) {
        $insertRole->execute([$userId, $now, $role]);
    }
    $pdo->prepare('INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $name . ' workspace', $now, $now]);
    return $userId;
}

function surfaceSession(PDO $pdo, $userId, $token, $csrf, $accountSessionId = null)
{
    $now = Db::now();
    $pdo->prepare(
        'INSERT INTO syndicatum_sessions
         (user_id, token_hash, csrf_token_hash, account_session_id, created_at, last_seen_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId, hash('sha256', $token), hash('sha256', $csrf), $accountSessionId,
        $now, $now, gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    return (int) $pdo->lastInsertId();
}

function surfaceHeaders($token, $csrf = null)
{
    $headers = ['Cookie: syndicatum_session=' . rawurlencode($token)];
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    return $headers;
}

function surfaceCreateProject(PDO $pdo, $ownerId, $name)
{
    $workspace = $pdo->prepare('SELECT id FROM workspaces WHERE owner_user_id = ?');
    $workspace->execute([$ownerId]);
    $now = Db::now();
    $pdo->prepare(
        "INSERT INTO projects (workspace_id, owner_user_id, name, slug, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'active', ?, ?)"
    )->execute([(int) $workspace->fetchColumn(), $ownerId, $name, strtolower(str_replace(' ', '-', $name)), $now, $now]);
    $projectId = (int) $pdo->lastInsertId();
    surfaceAddProjectMember($pdo, $projectId, $ownerId, 'owner');
    return $projectId;
}

function surfaceAddProjectMember(PDO $pdo, $projectId, $userId, $role)
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
}

function surfaceAssertCapabilities(SurfaceContractSuite $suite, array $actual, array $expected)
{
    foreach ($expected as $name => $value) {
        $suite->true(array_key_exists($name, $actual), 'Missing capability ' . $name . '.');
        $suite->same($value, $actual[$name], 'Capability ' . $name . ' mismatch.');
    }
}

$suite = new SurfaceContractSuite();
$root = dirname(__DIR__);
$database = 'syndicatum_surfaces_' . bin2hex(surfaceRandom(6));
if (!preg_match('/^syndicatum_surfaces_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe surface test database name.');
}
$secret = bin2hex(surfaceRandom(32));
$adminPdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$adminPdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
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

    $suite->test('Realtime-enabled timeline reconnects without periodic polling', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $start = strpos($source, 'async function connectRealtime(');
        $end = strpos($source, 'function scheduleRealtimeReconnect(', $start);
        $suite->true($start !== false && $end !== false && $end > $start, 'Realtime connection implementation was not found.');
        $connection = substr($source, $start, $end - $start);
        $suite->same(2, substr_count($connection, 'startPolling();'), 'Polling must be limited to explicitly disabled admission paths.');
        $suite->same(2, substr_count($connection, 'scheduleRealtimeReconnect(projectGeneration);'), 'Both socket closure and admission failure must reconnect.');
        $suite->true(strpos($connection, 'new sdk.RealtimeSocketClient') !== false, 'The timeline must use the supported PBB Realtime SDK client.');
        $suite->true(strpos($connection, 'error.realtimeConfiguration = true') !== false, 'Mixed-content WebSocket configuration must be treated as permanent rather than retried.');
        $suite->true(strpos($connection, 'new WebSocket(') === false, 'The timeline must not maintain a second hand-written WebSocket protocol client.');
        $suite->true(strpos($connection, 'loadMessages("newer", projectGeneration)') !== false, 'A successful rejoin must perform one gap-recovery synchronization.');
        $suite->true(strpos($source, 'const delay = Math.min(60000, 5000 * (2 ** Math.min(state.realtimeRetryCount, 4)));') !== false, 'Realtime reconnects must use bounded exponential backoff.');
        $suite->true(is_file($root . '/vendor/pbb-realtime/js/sdk/index.js'), 'The same-origin PBB Realtime SDK is missing.');
    });

    $suite->test('Broadcast messages render as broadcasts instead of mass tags', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $index = file_get_contents($root . '/index.php');
        $suite->true(strpos($source, 'function isBroadcastMessage(') !== false, 'Broadcast detection helper is missing.');
        $suite->true(strpos($source, 'entry.reason || "").toLowerCase() === "broadcast"') !== false, 'Broadcast detection must use addressee reason metadata.');
        $suite->true(strpos($source, 'chip.textContent = "Project broadcast";') !== false, 'Broadcasts must collapse participant chips into one broadcast label.');
        $suite->true(strpos($source, 'identity.append(identityLine, renderAddresseeChips(current));') !== false, 'Message addressee chips must render under the sender identity.');
        $suite->true(strpos($source, 'footer.appendChild(chips);') === false, 'Message addressee chips must not render in the footer action row.');
        $suite->true(strpos($index, 'Everyone active in this project will be notified.') !== false, 'Composer broadcast warning must not describe broadcasts as response tagging.');
    });

    $nativePassword = 'native password one';
    $adminId = surfaceInsertUser($pdo, 'admin@surfaces.test', 'Global Administrator', 'administrator password', ['user', 'administrator']);
    $ownerId = surfaceInsertUser($pdo, 'owner@surfaces.test', 'Project Owner', 'owner password value', ['user']);
    $projectAdminId = surfaceInsertUser($pdo, 'project-admin@surfaces.test', 'Project Administrator', 'project admin pass', ['user']);
    $memberId = surfaceInsertUser($pdo, 'member@surfaces.test', 'Project Member', $nativePassword, ['user']);
    $viewerId = surfaceInsertUser($pdo, 'viewer@surfaces.test', 'Project Viewer', 'project viewer pass', ['user']);
    $accountId = surfaceInsertUser($pdo, 'account@surfaces.test', 'Account User', null, ['user'], 'pbb-surface-account');

    $tokens = [];
    $csrf = [];
    foreach (['admin' => $adminId, 'owner' => $ownerId, 'project_admin' => $projectAdminId, 'member' => $memberId, 'viewer' => $viewerId] as $name => $id) {
        $tokens[$name] = $name . '_session_' . bin2hex(surfaceRandom(18));
        $csrf[$name] = $name . '_csrf_' . bin2hex(surfaceRandom(18));
        surfaceSession($pdo, $id, $tokens[$name], $csrf[$name]);
    }
    $otherMemberToken = 'member_other_' . bin2hex(surfaceRandom(18));
    $otherMemberCsrf = 'member_other_csrf_' . bin2hex(surfaceRandom(18));
    surfaceSession($pdo, $memberId, $otherMemberToken, $otherMemberCsrf);
    $accountToken = 'account_session_' . bin2hex(surfaceRandom(18));
    $accountCsrf = 'account_csrf_' . bin2hex(surfaceRandom(18));
    surfaceSession($pdo, $accountId, $accountToken, $accountCsrf, 'pbb-account-session-1');

    $projectId = surfaceCreateProject($pdo, $ownerId, 'Surface Contract Project');
    surfaceAddProjectMember($pdo, $projectId, $projectAdminId, 'admin');
    surfaceAddProjectMember($pdo, $projectId, $memberId, 'member');
    surfaceAddProjectMember($pdo, $projectId, $viewerId, 'viewer');

    $now = Db::now();
    $agentToken = 'surface_agent_' . bin2hex(surfaceRandom(18));
    $pdo->prepare(
        "INSERT INTO chat_agents
         (project_name, token_prefix, token_hash, token_secret_version, role, is_active, created_at, updated_at)
         VALUES ('Surface Agent', ?, ?, 'primary', 'agent', 1, ?, ?)"
    )->execute([substr($agentToken, 0, 24), hash_hmac('sha256', $agentToken, $secret), $now, $now]);
    $agentId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO project_agents (project_id, agent_id, display_name, provider, status, created_at, updated_at)
         VALUES (?, ?, 'Surface Agent', 'test-provider', 'active', ?, ?)"
    )->execute([$projectId, $agentId, $now, $now]);
    $pdo->prepare(
        "INSERT INTO project_participants (project_id, kind, agent_id, status, created_at, updated_at)
         VALUES (?, 'agent', ?, 'active', ?, ?)"
    )->execute([$projectId, $agentId, $now, $now]);

    $environment = getenv();
    $environment['PBB_AGENTCHAT_DB_HOST'] = '127.0.0.1';
    $environment['PBB_AGENTCHAT_DB_NAME'] = $database;
    $environment['PBB_AGENTCHAT_DB_USER'] = 'root';
    $environment['PBB_AGENTCHAT_DB_PASS'] = '';
    $environment['PBB_AGENTCHAT_SECRET'] = $secret;
    list($server, $baseUrl, $serverLog) = surfaceServer($root, surfacePort(), $environment);

    $suite->test('session exposes installation capabilities for anonymous, normal, and administrator users', function () use ($suite, $baseUrl, $tokens, $csrf) {
        $anonymous = surfaceRequest($baseUrl, 'GET', '/api/v1/session.php');
        $normal = surfaceRequest($baseUrl, 'GET', '/api/v1/session.php', surfaceHeaders($tokens['member'], $csrf['member']));
        $admin = surfaceRequest($baseUrl, 'GET', '/api/v1/session.php', surfaceHeaders($tokens['admin'], $csrf['admin']));
        $suite->same(200, $anonymous['status']);
        $suite->same(false, $anonymous['body']['data']['authenticated']);
        surfaceAssertCapabilities($suite, $anonymous['body']['capabilities'], [
            'workspace.view' => false, 'project.create' => false,
            'admin.users' => false, 'admin.agents' => false, 'admin.audit' => false, 'admin.settings' => false,
        ]);
        $suite->same(200, $normal['status']);
        $suite->same(true, $normal['body']['data']['authenticated']);
        $suite->same(true, $normal['body']['data']['user']['has_native_password']);
        surfaceAssertCapabilities($suite, $normal['body']['capabilities'], [
            'workspace.view' => true, 'project.create' => true,
            'admin.users' => false, 'admin.agents' => false, 'admin.audit' => false, 'admin.settings' => false,
        ]);
        $suite->same(200, $admin['status']);
        surfaceAssertCapabilities($suite, $admin['body']['capabilities'], [
            'workspace.view' => true, 'project.create' => true,
            'admin.users' => true, 'admin.agents' => true, 'admin.audit' => true, 'admin.settings' => true,
        ]);
    });

    $suite->test('project permissions are role-scoped and global administration grants no implicit project access', function () use ($suite, $baseUrl, $tokens, $agentToken, $projectId) {
        $cases = [
            'owner' => ['messages.read' => true, 'messages.write' => true, 'messages.acknowledge' => true, 'project.manage' => true, 'project.admin' => true, 'members.manage' => true, 'agents.manage' => true, 'ownership.transfer' => true],
            'project_admin' => ['messages.read' => true, 'messages.write' => true, 'messages.acknowledge' => true, 'project.manage' => true, 'project.admin' => true, 'members.manage' => true, 'agents.manage' => true, 'ownership.transfer' => false],
            'member' => ['messages.read' => true, 'messages.write' => true, 'messages.acknowledge' => true, 'project.manage' => false, 'project.admin' => false, 'members.manage' => false, 'agents.manage' => false, 'ownership.transfer' => false],
            'viewer' => ['messages.read' => true, 'messages.write' => false, 'messages.acknowledge' => true, 'project.manage' => false, 'project.admin' => false, 'members.manage' => false, 'agents.manage' => false, 'ownership.transfer' => false],
        ];
        foreach ($cases as $role => $expected) {
            $response = surfaceRequest($baseUrl, 'GET', '/api/v1/project.php?project_id=' . $projectId, surfaceHeaders($tokens[$role]));
            $suite->same(200, $response['status'], $role . ': ' . $response['raw']);
            $suite->same($role === 'project_admin' ? 'admin' : $role, $response['body']['data']['current_role']);
            $suite->same('/vendor/pbb-realtime/js/sdk/index.js', $response['body']['data']['capabilities']['realtime']['sdk_module_url']);
            surfaceAssertCapabilities($suite, $response['body']['data']['permissions'], $expected);
        }
        $agent = surfaceRequest($baseUrl, 'GET', '/api/v1/project.php?project_id=' . $projectId, ['Authorization: Bearer ' . $agentToken]);
        $suite->same(200, $agent['status'], $agent['raw']);
        $suite->same('agent', $agent['body']['data']['current_role']);
        surfaceAssertCapabilities($suite, $agent['body']['data']['permissions'], [
            'messages.read' => true, 'messages.write' => true, 'messages.acknowledge' => true,
            'project.manage' => false, 'project.admin' => false, 'members.manage' => false, 'agents.manage' => false, 'ownership.transfer' => false,
        ]);
        $globalAdmin = surfaceRequest($baseUrl, 'GET', '/api/v1/project.php?project_id=' . $projectId, surfaceHeaders($tokens['admin']));
        $suite->same(404, $globalAdmin['status'], 'Global administrator must not silently enter a project.');
    });

    $suite->test('project admins use provider references while an agent can read only its own binding', function () use ($suite, $baseUrl, $tokens, $csrf, $projectId, $agentId, $agentToken) {
        $query = '?project_id=' . $projectId . '&agent_id=' . $agentId;
        $providers = surfaceRequest($baseUrl, 'GET', '/api/v1/discussion-providers.php', surfaceHeaders($tokens['owner']));
        $initial = surfaceRequest($baseUrl, 'GET', '/api/v1/project-agent-activation.php' . $query, surfaceHeaders($tokens['owner']));
        $member = surfaceRequest($baseUrl, 'GET', '/api/v1/project-agent-activation.php' . $query, surfaceHeaders($tokens['member']));
        $missingCsrf = surfaceRequest($baseUrl, 'PATCH', '/api/v1/project-agent-activation.php', surfaceHeaders($tokens['owner']), [
            'project_id' => $projectId, 'agent_id' => $agentId, 'enabled' => true,
            'conversation_id' => '01a06d4b-077b-79c0-afc9-8373a6887483', 'working_directory' => 'C:\\workspace',
        ]);
        $invalid = surfaceRequest($baseUrl, 'PATCH', '/api/v1/project-agent-activation.php', surfaceHeaders($tokens['owner'], $csrf['owner']), [
            'project_id' => $projectId, 'agent_id' => $agentId, 'enabled' => true,
            'provider' => 'codex', 'discussion_reference' => 'https://example.test/thread',
        ]);
        $updated = surfaceRequest($baseUrl, 'PATCH', '/api/v1/project-agent-activation.php', surfaceHeaders($tokens['owner'], $csrf['owner']), [
            'project_id' => $projectId, 'agent_id' => $agentId, 'enabled' => true,
            'provider' => 'codex', 'discussion_reference' => 'codex://threads/01a06d4b-077b-79c0-afc9-8373a6887483', 'working_directory' => '',
        ]);
        $own = surfaceRequest($baseUrl, 'GET', '/api/v1/agent-activation-binding.php?project_id=' . $projectId, ['Authorization: Bearer ' . $agentToken]);
        $humanOwn = surfaceRequest($baseUrl, 'GET', '/api/v1/agent-activation-binding.php?project_id=' . $projectId, surfaceHeaders($tokens['owner']));
        $suite->same(200, $providers['status'], $providers['raw']);
        $suite->same('codex', $providers['body']['data'][0]['code']);
        $suite->same('Codex discussion deeplink', $providers['body']['data'][0]['reference_label']);
        $suite->same(200, $initial['status'], $initial['raw']);
        $suite->same(false, $initial['body']['data']['enabled']);
        $suite->same(404, $member['status']);
        $suite->same(403, $missingCsrf['status']);
        $suite->same(422, $invalid['status']);
        $suite->same(200, $updated['status'], $updated['raw']);
        $suite->same(true, $updated['body']['data']['enabled']);
        $suite->same('codex', $updated['body']['data']['provider']);
        $suite->same('codex://threads/01a06d4b-077b-79c0-afc9-8373a6887483', $updated['body']['data']['discussion_reference']);
        $suite->same(200, $own['status'], $own['raw']);
        $suite->same($agentId, $own['body']['data']['agent_id']);
        $suite->same('01a06d4b-077b-79c0-afc9-8373a6887483', $own['body']['data']['conversation_id']);
        $suite->same(404, $humanOwn['status']);
    });

    $suite->test('admin agent directory is authorized and omits credential material', function () use ($suite, $baseUrl, $tokens, $agentId) {
        $anonymous = surfaceRequest($baseUrl, 'GET', '/api/v1/admin/agents.php');
        $normal = surfaceRequest($baseUrl, 'GET', '/api/v1/admin/agents.php', surfaceHeaders($tokens['member']));
        $admin = surfaceRequest($baseUrl, 'GET', '/api/v1/admin/agents.php', surfaceHeaders($tokens['admin']));
        $suite->same(401, $anonymous['status']);
        $suite->same(403, $normal['status']);
        $suite->same(200, $admin['status'], $admin['raw']);
        $rows = $admin['body']['data'];
        $matching = array_values(array_filter($rows, function ($row) use ($agentId) { return (int) $row['id'] === $agentId; }));
        $suite->same(1, count($matching));
        $encoded = strtolower(json_encode($matching[0]));
        foreach (['token_hash', 'token_prefix', 'claim_hash', 'claim_prefix', 'secret'] as $forbidden) {
            $suite->true(strpos($encoded, $forbidden) === false, 'Agent directory leaked ' . $forbidden . '.');
        }
    });

    $suite->test('profile update requires human session and CSRF and changes only the current user', function () use ($suite, $baseUrl, $pdo, $tokens, $csrf, $agentToken, $memberId, $adminId) {
        $anonymous = surfaceRequest($baseUrl, 'PATCH', '/api/v1/profile.php', [], ['display_name' => 'No Session']);
        $agent = surfaceRequest($baseUrl, 'PATCH', '/api/v1/profile.php', ['Authorization: Bearer ' . $agentToken], ['display_name' => 'Agent Attempt']);
        $missingCsrf = surfaceRequest($baseUrl, 'PATCH', '/api/v1/profile.php', surfaceHeaders($tokens['member']), ['display_name' => 'No CSRF']);
        $beforeHash = $pdo->query('SELECT password_hash FROM users WHERE id = ' . $memberId)->fetchColumn();
        $adminBefore = $pdo->query('SELECT display_name FROM users WHERE id = ' . $adminId)->fetchColumn();
        $updated = surfaceRequest($baseUrl, 'PATCH', '/api/v1/profile.php', surfaceHeaders($tokens['member'], $csrf['member']), [
            'id' => $adminId,
            'email' => 'stolen@surfaces.test',
            'password_hash' => 'not-a-password-hash',
            'system_roles' => ['administrator'],
            'display_name' => 'Updated Project Member',
        ]);
        $suite->same(401, $anonymous['status']);
        $suite->same(401, $agent['status']);
        $suite->same(403, $missingCsrf['status']);
        $suite->same(200, $updated['status'], $updated['raw']);
        $suite->same($memberId, $updated['body']['data']['user']['id']);
        $suite->same('Updated Project Member', $updated['body']['data']['user']['display_name']);
        $row = $pdo->query('SELECT normalized_email, password_hash, display_name, avatar_url FROM users WHERE id = ' . $memberId)->fetch();
        $suite->same('member@surfaces.test', $row['normalized_email']);
        $suite->same($beforeHash, $row['password_hash']);
        $suite->same(null, $row['avatar_url']);
        $suite->same($adminBefore, $pdo->query('SELECT display_name FROM users WHERE id = ' . $adminId)->fetchColumn());
        $roles = $pdo->query('SELECT COUNT(*) FROM user_system_roles ur JOIN system_roles r ON r.id = ur.role_id WHERE ur.user_id = ' . $memberId . " AND r.code = 'administrator'")->fetchColumn();
        $suite->same(0, (int) $roles);
    });

    $suite->test('native password rejects bad verification, mismatch, and weak replacement without mutation', function () use ($suite, $baseUrl, $pdo, $tokens, $csrf, $memberId, $nativePassword) {
        $hash = $pdo->query('SELECT password_hash FROM users WHERE id = ' . $memberId)->fetchColumn();
        $wrong = surfaceRequest($baseUrl, 'POST', '/api/v1/password.php', surfaceHeaders($tokens['member'], $csrf['member']), [
            'current_password' => 'wrong password', 'new_password' => 'replacement password', 'new_password_confirmation' => 'replacement password',
        ]);
        $mismatch = surfaceRequest($baseUrl, 'POST', '/api/v1/password.php', surfaceHeaders($tokens['member'], $csrf['member']), [
            'current_password' => $nativePassword, 'new_password' => 'replacement password', 'new_password_confirmation' => 'different password value',
        ]);
        $weak = surfaceRequest($baseUrl, 'POST', '/api/v1/password.php', surfaceHeaders($tokens['member'], $csrf['member']), [
            'current_password' => $nativePassword, 'new_password' => 'short', 'new_password_confirmation' => 'short',
        ]);
        $suite->same(403, $wrong['status']);
        $suite->same(422, $mismatch['status']);
        $suite->same(422, $weak['status']);
        $suite->same($hash, $pdo->query('SELECT password_hash FROM users WHERE id = ' . $memberId)->fetchColumn());
        $active = $pdo->query('SELECT COUNT(*) FROM syndicatum_sessions WHERE user_id = ' . $memberId . ' AND revoked_at IS NULL')->fetchColumn();
        $suite->same(2, (int) $active);
    });

    $suite->test('native password success rotates current credentials and revokes other sessions', function () use ($suite, $baseUrl, $pdo, $tokens, $csrf, $memberId, $otherMemberToken, $nativePassword) {
        $replacement = 'replacement password';
        $response = surfaceRequest($baseUrl, 'POST', '/api/v1/password.php', surfaceHeaders($tokens['member'], $csrf['member']), [
            'current_password' => $nativePassword,
            'new_password' => $replacement,
            'new_password_confirmation' => $replacement,
        ]);
        $suite->same(200, $response['status'], $response['raw']);
        $suite->true(isset($response['cookies']['syndicatum_session']), 'Rotated session cookie missing.');
        $suite->true(isset($response['cookies']['syndicatum_csrf']), 'Rotated CSRF cookie missing.');
        $newToken = $response['cookies']['syndicatum_session'];
        $newCsrf = $response['cookies']['syndicatum_csrf'];
        $suite->true($newToken !== $tokens['member'], 'Session token was not rotated.');
        $suite->true($newCsrf !== $csrf['member'], 'CSRF token was not rotated.');
        $suite->same($newCsrf, $response['body']['data']['csrf_token']);
        $hash = $pdo->query('SELECT password_hash FROM users WHERE id = ' . $memberId)->fetchColumn();
        $suite->same(false, password_verify($nativePassword, $hash));
        $suite->same(true, password_verify($replacement, $hash));
        $auth = new AuthService($pdo);
        $suite->same(null, $auth->currentUser($tokens['member']));
        $suite->same(null, $auth->currentUser($otherMemberToken));
        $suite->true($auth->currentUser($newToken) !== null, 'Rotated session is not usable.');
        $active = $pdo->query('SELECT COUNT(*) FROM syndicatum_sessions WHERE user_id = ' . $memberId . ' AND revoked_at IS NULL')->fetchColumn();
        $suite->same(1, (int) $active);
        $session = surfaceRequest($baseUrl, 'GET', '/api/v1/session.php', surfaceHeaders($newToken, $newCsrf));
        $suite->same(200, $session['status']);
        $suite->same(true, $session['body']['data']['authenticated']);
        $audit = $pdo->query("SELECT metadata_json FROM administrative_audit_events WHERE actor_user_id = $memberId AND action = 'password.changed' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $suite->true($audit !== false, 'Password change audit event missing.');
        $suite->true(strpos((string) $audit, $nativePassword) === false && strpos((string) $audit, $replacement) === false, 'Audit event contains password material.');
    });

    $suite->test('PBB Account-only user is identified and cannot create a local password', function () use ($suite, $baseUrl, $pdo, $accountId, $accountToken, $accountCsrf) {
        $session = surfaceRequest($baseUrl, 'GET', '/api/v1/session.php', surfaceHeaders($accountToken, $accountCsrf));
        $suite->same(200, $session['status']);
        $suite->same('account', $session['body']['data']['user']['auth_source']);
        $suite->same(false, $session['body']['data']['user']['has_native_password']);
        $response = surfaceRequest($baseUrl, 'POST', '/api/v1/password.php', surfaceHeaders($accountToken, $accountCsrf), [
            'current_password' => 'irrelevant',
            'new_password' => 'new account password',
            'new_password_confirmation' => 'new account password',
        ]);
        $suite->same(409, $response['status'], 'Account-managed password response must be a conflict. ' . $response['raw']);
        $suite->same('PASSWORD_MANAGED_BY_ACCOUNT', strtoupper($response['body']['code']));
        $suite->same(null, $pdo->query('SELECT password_hash FROM users WHERE id = ' . $accountId)->fetchColumn());
        $active = $pdo->query('SELECT COUNT(*) FROM syndicatum_sessions WHERE user_id = ' . $accountId . ' AND revoked_at IS NULL')->fetchColumn();
        $suite->same(1, (int) $active);
    });
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($serverLog && is_file($serverLog)) {
        unlink($serverLog);
    }
    if (preg_match('/^syndicatum_surfaces_[a-f0-9]{12}$/', $database)) {
        $adminPdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }
}

exit($suite->finish());
