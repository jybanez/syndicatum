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
    $setCookieHeaders = [];
    foreach (preg_split('/\r\n|\n|\r/', $headerText) as $line) {
        if (stripos($line, 'Set-Cookie:') !== 0) {
            continue;
        }
        $pair = trim(substr($line, strlen('Set-Cookie:')));
        $setCookieHeaders[] = $pair;
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
    return ['status' => $status, 'body' => $decoded, 'cookies' => $cookies, 'set_cookie_headers' => $setCookieHeaders, 'raw' => $responseBody];
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

function surfaceRemoveTree($path)
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') { surfaceRemoveTree($path . DIRECTORY_SEPARATOR . $name); }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) { @unlink($path); }
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
    $cookie = 'syndicatum_session=' . rawurlencode($token);
    if ($csrf !== null) {
        $cookie .= '; syndicatum_csrf=' . rawurlencode($csrf);
    }
    $headers = ['Cookie: ' . $cookie];
    if ($csrf !== null) { $headers[] = 'X-CSRF-Token: ' . $csrf; }
    return $headers;
}

function surfaceCreateProject(PDO $pdo, $ownerId, $name)
{
    $workspace = $pdo->prepare('SELECT id FROM workspaces WHERE owner_user_id = ?');
    $workspace->execute([$ownerId]);
    $now = Db::now();
    $pdo->prepare(
        "INSERT INTO projects (public_id, workspace_id, owner_user_id, name, slug, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'active', ?, ?)"
    )->execute([Db::uuidV4(), (int) $workspace->fetchColumn(), $ownerId, $name, strtolower(str_replace(' ', '-', $name)), $now, $now]);
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
$testDbHost = getenv('PBB_AGENTCHAT_TEST_DB_HOST');
$testDbHost = $testDbHost === false || trim((string) $testDbHost) === '' ? '127.0.0.1' : trim((string) $testDbHost);
$testDbPass = getenv('PBB_AGENTCHAT_TEST_DB_PASS');
$testDbPass = $testDbPass === false ? '' : (string) $testDbPass;
$adminPdo = new PDO('mysql:host=' . $testDbHost . ';charset=utf8mb4', 'root', $testDbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$adminPdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=' . $testDbHost);
putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root');
putenv('PBB_AGENTCHAT_DB_PASS=' . $testDbPass);
putenv('PBB_AGENTCHAT_SECRET=' . $secret);

$server = null;
$serverLog = null;
$recoveryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-surfaces-recovery-' . bin2hex(surfaceRandom(8));
foreach (['backups', 'staging', 'avatars'] as $name) { mkdir($recoveryRoot . DIRECTORY_SEPARATOR . $name, 0700, true); @chmod($recoveryRoot . DIRECTORY_SEPARATOR . $name, 0700); }
try {
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();

    $suite->test('public legal pages describe hosted privacy, Google sign-in, and service terms', function () use ($suite, $root) {
        $index = file_get_contents($root . '/index.php');
        $source = file_get_contents($root . '/assets/app.mjs');
        $privacy = file_get_contents($root . '/privacy.php');
        $terms = file_get_contents($root . '/terms.php');
        $rewrites = file_get_contents($root . '/.htaccess');
        $suite->true(strpos($index, 'href="privacy"') !== false && strpos($index, 'href="terms"') !== false, 'The application surface must link both public legal pages.');
        $suite->true(strpos($source, 'menuGroups: [') !== false, 'The authenticated account menu must group account, legal, and session actions.');
        $suite->true(strpos($source, 'id: "privacy", label: "Privacy Policy"') !== false && strpos($source, 'id: "terms", label: "Terms of Service"') !== false, 'The authenticated account menu must expose both legal pages.');
        $suite->true(strpos($source, 'id: "signout", label: "Logout"') !== false, 'Logout must follow the legal action group.');
        $suite->true(strpos($source, 'el.public_policy_links.hidden = state.mode === "expanded";') !== false, 'Bottom legal links must be hidden after sign-in.');
        $suite->true(strpos($source, 'name: "public_origin"') !== false && strpos($source, '"general.public_origin": values.public_origin') !== false, 'System Settings must expose the canonical public Syndicatum origin.');
        $suite->true(strpos($privacy, "\$legalPageTitle = 'Privacy Policy';") !== false, 'The public privacy page is missing.');
        $suite->true(strpos($privacy, 'openid') !== false && strpos($privacy, 'Google Drive') !== false, 'The privacy page must disclose the limited Google sign-in data scope.');
        $suite->true(strpos($terms, "\$legalPageTitle = 'Terms of Service';") !== false, 'The public terms page is missing.');
        $suite->true(strpos($terms, 'Software agents and generated output') !== false, 'The terms must address agent-generated output.');
        $suite->true(strpos($rewrites, '^privacy/?$ privacy.php') !== false && strpos($rewrites, '^terms/?$ terms.php') !== false, 'Clean public legal routes are missing.');
    });

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
        $suite->true(strpos($connection, 'Promise.all([loadMessages("newer", projectGeneration), loadTasks(projectGeneration)])') !== false,
            'A successful rejoin must perform one message-and-task gap-recovery synchronization.');
        $suite->true(strpos($source, 'const delay = Math.min(60000, 5000 * (2 ** Math.min(state.realtimeRetryCount, 4)));') !== false, 'Realtime reconnects must use bounded exponential backoff.');
        $suite->true(is_file($root . '/vendor/pbb-realtime/js/sdk/index.js'), 'The same-origin PBB Realtime SDK is missing.');
    });

    $suite->test('Participant directory refreshes for remote creation events and polling fallback', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $suite->true(strpos($source, 'envelope.type === "syndicatum.participants.changed"') !== false, 'Realtime participant changes must be handled.');
        $suite->true(strpos($source, 'async function refreshParticipants(') !== false, 'The participant reload helper is missing.');
        $suite->true(strpos($source, 'Promise.all([loadMessages("newer"), refreshParticipants(), loadTasks()])') !== false, 'Polling fallback must refresh participants and tasks.');
        $suite->true(strpos($source, 'function receiveRealtimeTask(source)') !== false
            && strpos($source, 'receiveRealtimeTask(envelope.payload.task)') !== false,
            'Realtime task snapshots must update the task rail without a list request.');
        $suite->true(strpos($source, 'receiveRealtimeTask(created)') !== false
            && strpos($source, 'receiveRealtimeTask(updated)') !== false,
            'HTTP task results must share the version-aware upsert path with Realtime events.');
        $suite->true(strpos($source, 'state.components.responsibilityInbox?.refreshFromRealtime(message.id);') !== false
            && strpos($source, 'query.set("changed_by_message_id", changedByMessageId);') !== false,
            'Realtime messages must request an exact responsibility projection refresh.');
        $inbox = file_get_contents($root . '/assets/responsibility-inbox.mjs');
        $suite->true(strpos($inbox, 'async function flushRealtimeRefresh()') !== false
            && strpos($inbox, 'renderPreservingViewport();') !== false
            && strpos($inbox, 'Updated automatically.') !== false,
            'Realtime responsibility updates must be coalesced and rendered without resetting the viewport.');
        $suite->true(strpos($source, 'responsibilityInbox?.markStale()') === false
            && strpos($inbox, 'markStale()') === false,
            'Realtime inbox updates must not fall back to a manual refresh notice.');
        $suite->true(strpos($source, 'startTaskPolling') === false && strpos($source, 'taskPollingTimer') === false,
            'Tasks must not retain a dedicated poller while project Realtime is active.');
    });

    $suite->test('Broadcast messages render as broadcasts instead of mass tags', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $index = file_get_contents($root . '/index.php');
        $suite->true(strpos($source, 'function isBroadcastMessage(') !== false, 'Broadcast detection helper is missing.');
        $suite->true(strpos($source, 'entry.reason || "").toLowerCase() === "broadcast"') !== false, 'Broadcast detection must use addressee reason metadata.');
        $suite->true(strpos($source, 'message.addressees.length === 0') !== false, 'Historical unaddressed messages must render as project broadcasts.');
        $suite->true(strpos($source, 'chip.textContent = "Project timeline";') === false, 'Unaddressed messages must not render as a separate timeline addressing mode.');
        $suite->true(strpos($source, 'if (isBroadcastMessage(message)) return "Project broadcast";') !== false, 'The live Helper timeline must label broadcasts once.');
        $suite->true(strpos($source, 'subtitle: messageAddresseesLabel(message)') !== false, 'The live Helper timeline must render addressee or broadcast context under the sender.');
        $suite->true(strpos($source, 'footer.appendChild(chips);') === false, 'Addressing must not render in the footer action row.');
        $suite->true(strpos($index, 'Everyone active in this project will be notified.') !== false, 'Composer broadcast warning must not describe broadcasts as response tagging.');
    });

    $suite->test('Message composer precedes filters and the newest-first timeline', function () use ($suite, $root) {
        $index = file_get_contents($root . '/index.php');
        $styles = file_get_contents($root . '/assets/app.css');
        $messagesColumn = strpos($index, 'class="surface-column project-messages-column"');
        $overview = strpos($index, 'class="project-overview timeline-project-overview"');
        $composer = strpos($index, 'id="composer-shell"');
        $filters = strpos($index, 'class="filter-bar"');
        $timeline = strpos($index, 'id="timeline-host"');
        $suite->true($messagesColumn !== false && $overview !== false && $composer !== false && $filters !== false && $timeline !== false, 'Project message controls are missing.');
        $suite->true($messagesColumn < $overview && $overview < $composer && $composer < $filters && $filters < $timeline, 'The project overview must replace the timeline header above the composer, filters, and timeline.');
        $suite->same(1, substr_count($index, 'class="project-overview timeline-project-overview"'), 'The project overview must render only in the message column.');
        $suite->true(strpos($styles, '#timeline-host .ui-timeline-group-label:not(.ui-timeline-floating-date)') !== false,
            'Application date styling must exclude the Helper-owned floating date label.');
        $suite->true(strpos($styles, '#timeline-host .ui-timeline-group-label { position: sticky;') === false,
            'Legacy sticky positioning must not override the Helper timeline floating-date implementation.');
    });

    $suite->test('Timeline search stays visible while structured filters use the Helper popover', function () use ($suite, $root) {
        $index = file_get_contents($root . '/index.php');
        $source = file_get_contents($root . '/assets/app.mjs');
        $loader = file_get_contents($root . '/vendor/pbb-helper/js/ui/ui.loader.js');
        $search = strpos($index, 'id="search-mount"');
        $trigger = strpos($index, 'id="filter-popover-trigger"');
        $refresh = strpos($index, 'id="refresh-button"');
        $panel = strpos($index, 'id="filter-popover-content"');
        $suite->true($search !== false && $trigger !== false && $refresh !== false && $panel !== false, 'Timeline search or action markup is missing.');
        $suite->true($search < $trigger && $trigger < $refresh && $refresh < $panel, 'Search must remain visible with adjacent filter and refresh actions.');
        $suite->true(strpos($source, 'createPopover: await uiLoader.get("ui.popover", options)') !== false, 'Timeline filters must use the supported Helper popover factory.');
        $suite->true(strpos($source, 'state.components.filterPopover = state.factories.createPopover') !== false, 'Timeline filters must mount through the Helper popover.');
        $suite->true(strpos($source, 'helperIconHtml("data.filter", 18)') !== false, 'The filter action must use the shared Helper icon registry.');
        $suite->true(strpos($source, 'helperIconHtml("actions.refresh", 18)') !== false, 'The refresh action must use the shared Helper icon registry.');
        $suite->true(strpos($loader, 'const UI_BUNDLE_REV = "0.21.205";') !== false, 'The integrated Helper bundle must retain the current live revision.');
    });

    $suite->test('Navigation uses the Syndicatum brand as the single workspace route', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $guide = file_get_contents($root . '/assets/user-guide-content.mjs');
        $suite->true(strpos($source, 'id: "mobile-projects"') === false,
            'The redundant mobile Projects navigation item must not be rendered.');
        $suite->true(strpos($source, 'item?.id === "mobile-projects"') === false,
            'The retired mobile Projects navigation handler must be removed.');
        $suite->true(strpos($source, 'id: "workspace", label: "Home"') === false
            && strpos($source, 'desktop-workspace-nav') === false,
            'The redundant desktop Home navigation item must not be rendered.');
        $suite->true(strpos($source, 'if (item?.id === "brand") showWorkspaceSurface();') !== false,
            'The Syndicatum brand must continue to route users back to the workspace.');
        $suite->true(strpos($source, '!["workspace", "project"].includes(state.surface) || !selectedProjectId()') !== false
            && strpos($source, 'state.surface !== "project") return;') === false,
            'The selected project view switch must remain interactive after brand navigation changes the route to the workspace.');
        $suite->true(strpos($guide, 'Select the Syndicatum logo to return to the workspace.') !== false
            && strpos($guide, 'logo and Home') === false,
            'The User Guide must describe the brand as the single workspace route.');
    });

    $suite->test('Signed-in users have a searchable contextual User Guide', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $content = file_get_contents($root . '/assets/user-guide-content.mjs');
        $inbox = file_get_contents($root . '/assets/responsibility-inbox.mjs');
        $index = file_get_contents($root . '/index.php');
        $styles = file_get_contents($root . '/assets/app.css');
        $rewrites = file_get_contents($root . '/.htaccess');
        $suite->true(strpos($source, '{ id: "guide", label: "User Guide"') !== false
            && strpos($source, 'else if (item?.id === "guide") showGuideSurface();') !== false,
            'The account menu must expose the User Guide to signed-in users outside administrator capability checks.');
        $suite->true(strpos($source, 'function showGuideSurface(') !== false
            && strpos($source, 'function renderGuideSurface()') !== false
            && strpos($source, 'searchGuide(state.guideQuery, { administrator: isAdministrator() })') !== false,
            'The guide must provide a dedicated searchable application surface.');
        foreach (['Getting started', 'Connections and setup', 'Communication and work', 'Responsibility Inbox', 'Tasks', 'Templates', 'Agents', 'Administration', 'Glossary and troubleshooting'] as $section) {
            $suite->true(strpos($content, 'title: "' . $section . '"') !== false, 'Missing guide section: ' . $section);
        }
        foreach (['setup-companion', 'setup-codex', 'setup-chatgpt', 'setup-gemini'] as $articleId) {
            $suite->true(strpos($content, 'id: "' . $articleId . '"') !== false, 'Missing provider setup article: ' . $articleId);
        }
        $suite->true(strpos($content, 'Never enter an agent claim code or agent token in the Companion.') !== false
            && strpos($content, 'Claim codes expire after 15 minutes, are single-use, and are shown only once.') !== false
            && strpos($content, 'Discussion Binding: Successful') !== false
            && strpos($content, 'It is not a Gemini-native MCP or command-line integration.') !== false,
            'Provider setup must preserve credential, binding, and integration boundaries.');
        $suite->true(strpos($content, 'https://github.com/jybanez/syndicatum/releases/latest') !== false
            && strpos($source, 'link.rel = "noopener noreferrer";') !== false
            && strpos($styles, '.guide-resource-link') !== false,
            'The Companion guide must expose the canonical latest-release download through a safely rendered resource link.');
        foreach (['codex plugin marketplace add jybanez/syndicatum --ref main', 'codex plugin add codex@syndicatum', 'syndicatum bind <project name> <agent identity>'] as $command) {
            $suite->true(strpos($content, 'command: "' . $command . '"') !== false, 'Missing copyable Codex setup command: ' . $command);
        }
        $suite->true(strpos($source, 'function guideCommandBlock(command)') !== false
            && strpos($source, 'navigator.clipboard.writeText(command)') !== false
            && strpos($source, 'helperIconHtml("actions.copy", 16)') !== false
            && strpos($source, 'Copy failed. Select the command and copy it manually.') !== false
            && strpos($styles, '.guide-command') !== false,
            'Structured guide commands must render as selectable blocks with accessible shared-icon copy actions and visible clipboard feedback.');
        $suite->true(substr_count($content, 'type: "article-link", articleId: "setup-companion"') === 2
            && strpos($source, 'function appendGuideRichText(container, parts = [])') !== false
            && strpos($source, 'selectGuideArticle(target.id);') !== false
            && strpos($source, 'link.href = `${applicationPath("guide")}#${encodeURIComponent(target.id)}`;') !== false
            && strpos($styles, '.guide-article-link') !== false,
            'References to another User Guide article must render as deep links and use the existing article-selection flow for immediate in-guide navigation.');
        $suite->true(strpos($content, 'audience: "administrator"') !== false
            && strpos($content, 'visibleGuideSections(options)') !== false
            && strpos($source, 'guideArticle(articleId, { administrator: isAdministrator() })') !== false,
            'Administration guidance must be excluded from regular-user navigation, search, and direct article selection.');
        $suite->true(strpos($inbox, 'Guide to these views') !== false
            && strpos($source, 'openGuide: (articleId) => showGuideSurface(articleId)') !== false
            && strpos($index, 'id="task-guide-trigger"') !== false
            && strpos($source, 'showGuideSurface("tasks-overview")') !== false,
            'Responsibility Inbox and Tasks must link directly to their contextual guide topics.');
        $suite->true(strpos($styles, '.guide-browser') !== false
            && strpos($styles, '.guide-tree') !== false
            && strpos($styles, '.guide-article') !== false,
            'The User Guide must use the responsive master-detail layout.');
        $suite->true(strpos($styles, '.guide-article { display: block;') !== false
            && strpos($styles, '.guide-article-body { display: grid; align-content: start; grid-auto-rows: max-content;') !== false,
            'Guide article content must retain natural top-aligned spacing instead of stretching to fill the pane.');
        $suite->true(strpos($styles, '.guide-tree { display: block;') !== false,
            'Guide navigation must override the shared panel grid so search and filtered results retain natural height.');
        $suite->true(strpos($rewrites, 'guide|users|agents|audit|templates') !== false,
            'Direct User Guide navigation must resolve through the application route.');
    });

    $suite->test('Templates navigation sits between Audit and Settings with a guarded route', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $rewrites = file_get_contents($root . '/.htaccess');
        $audit = strpos($source, 'id: "audit", label: "Audit"');
        $templates = strpos($source, 'id: "templates", label: "Templates"');
        $settings = strpos($source, 'id: "settings", label: "Settings"');
        $suite->true($audit !== false && $templates !== false && $settings !== false
            && $audit < $templates && $templates < $settings,
            'Templates must appear between Audit and Settings in the administrator menu.');
        $suite->true(strpos($source, '["templates", "delivery-health", "backup-restore"].includes(kind)') !== false,
            'Templates must use the administrator settings authorization boundary.');
        $suite->true(strpos($source, 'async function loadTemplatesSurface()') !== false
            && strpos($source, 'projectTemplates: "api/v1/admin/project-templates.php"') !== false
            && strpos($source, 'openTemplateAgentModal') !== false
            && strpos($source, 'label: "Category", required: true') !== false
            && strpos($source, 'className = "template-browser"') !== false
            && strpos($source, 'className = "template-tree ui-panel"') !== false
            && strpos($source, 'renderTemplateDetailPanel(selectedTemplate)') !== false
            && strpos($source, 'className = "template-detail-body"') !== false
            && strpos($source, 'className = "template-context-stack"') !== false
            && strpos($source, 'collapsedTemplateCategoryIds: new Set()') !== false
            && strpos($source, 'detailHost.replaceChildren(renderTemplateDetailPanel(template))') !== false
            && strpos($source, 'classList.toggle("is-templates", kind === "templates")') !== false,
            'The Templates route must open a real foundation surface rather than a dead navigation item.');
        $suite->true(strpos($source, 'function mountTemplateMobileNavigation(') !== false
            && strpos($source, 'const TEMPLATE_MOBILE_QUERY = "(max-width: 680px)";') !== false
            && strpos($source, 'mobileNavigation?.setView("preview", { focus: true })') !== false,
            'Selecting a template on mobile must open its preview through the shared responsive view switcher.');
        $suite->true(strpos($rewrites, 'audit|templates|delivery-health') !== false,
            'Direct Templates navigation must resolve through the application route.');
    });

    $suite->test('project creation uses the canonical template workflow stack', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $css = file_get_contents($root . '/assets/app.css');
        $suite->true(strpos($source, '"ui.navigation.stack"') !== false
            && strpos($source, 'createNavigationStack: await uiLoader.get("ui.navigation.stack", options)') !== false
            && strpos($source, 'state.factories.createNavigationStack(host, { chrome: false') !== false,
            'Project creation must use the complete Helper navigation stack inside its modal.');
        $suite->true(strpos($source, 'modal.open(); modal.setBusy(true, { message: "Loading project templates…"') !== false
            && strpos($source, 'request(API.projectTemplateLibrary, { signal: abortController.signal })') !== false,
            'The modal must open before template loading and cancel or ignore late initialization.');
        $suite->true(strpos($source, 'Please address the following issues before continuing:') !== false
            && strpos($source, 'Choose Codex, ChatGPT, or Gemini, or exclude this preset.') !== false,
            'Project and agent steps must validate before creating the project.');
        $suite->true(strpos($source, 'Continue without template') === false
            && strpos($source, 'label: "Next", variant: "primary"') !== false
            && strpos($source, 'no provider credential is copied') !== false
            && strpos($source, 'template_version: workflow.selectedTemplate.version') !== false,
            'The workflow must use explicit template application and provider-safe preset creation.');
        $suite->same(1, substr_count($source, 'badge.textContent = "Built-in"'),
            'Built-in metadata must remain in template details but not appear in either template navigation tree.');
        $suite->true(strpos($css, '.project-template-picker') !== false && strpos($css, '.project-agent-config-card') !== false,
            'Template selection and agent configuration need dedicated responsive modal layout contracts.');
        $suite->true(strpos($source, 'className: "project-template-modal"') !== false
            && strpos($css, '.template-mobile-navigation') !== false
            && strpos($css, '.template-browser[data-mobile-view="library"]') !== false
            && strpos($css, '.project-template-modal .ui-modal { width: 100vw;') !== false,
            'Templates and project creation must use a single-pane Library/Preview mobile layout with a full-height canonical modal.');
    });

    $suite->test('Helper 0.21.205 date and time controls retain native theme integration', function () use ($suite, $root) {
        $app = file_get_contents($root . '/assets/app.mjs');
        $setup = file_get_contents($root . '/assets/setup.mjs');
        $connector = file_get_contents($root . '/assets/connector-authorize.mjs');
        $bundleCss = file_get_contents($root . '/vendor/pbb-helper/dist/helpers.ui.bundle.min.css');
        $bundleJs = file_get_contents($root . '/vendor/pbb-helper/dist/helpers.ui.bundle.min.js');
        foreach ([$app, $setup, $connector] as $source) {
            $suite->true(strpos($source, 'helpers.ui.bundle.min.js?v=0.21.205') !== false,
                'Every Helper entry point must use the canonical 0.21.205 bundle revision.');
        }
        foreach (['claim.php', 'connector-authorize.php', 'legal-page.php', 'setup.php', 'oauth/authorize.php'] as $surface) {
            $surfaceSource = file_get_contents($root . '/' . $surface);
            $suite->true(strpos($surfaceSource, 'helpers.ui.bundle.min.css?v=0.21.205') !== false,
                $surface . ' must use the matching canonical 0.21.205 stylesheet revision.');
        }
        $suite->true(strpos($bundleCss, '--ui-datepicker-color-scheme: dark') !== false,
            'The Helper bundle must theme native date and time controls in dark themes.');
        $suite->true(strpos($bundleCss, '--ui-datepicker-color-scheme: light') !== false,
            'The Helper bundle must theme native date and time controls in light themes.');
        $suite->true(strpos($bundleCss, 'color-scheme:var(--ui-datepicker-color-scheme, dark)') !== false,
            'The Helper bundle must apply the datepicker color scheme to native time inputs.');
        $suite->true(strpos($bundleJs, 'onContextMenuAction') !== false
            && strpos($bundleJs, 'ui-timeline-menu-trigger') !== false,
            'The Helper bundle must expose the native timeline context-menu implementation.');
        $suite->true(strpos($bundleCss, '.ui-timeline-menu-trigger') !== false,
            'The matching Helper stylesheet must include native timeline menu presentation.');
    });

    $suite->test('Backup and restore actions use canonical Helper components and preserve recovery boundaries', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $styles = file_get_contents($root . '/assets/app.css');
        $recoveryApi = file_get_contents($root . '/api/v1/admin/_recovery.php');
        $backupApi = file_get_contents($root . '/api/v1/admin/backups.php');
        $backupDownloadsApi = file_get_contents($root . '/api/v1/admin/backup-downloads.php');
        $inspectionApi = file_get_contents($root . '/api/v1/admin/restore-inspections.php');
        $restoreApi = file_get_contents($root . '/api/v1/admin/staged-restores.php');
        $service = file_get_contents($root . '/src/AdminRecoveryService.php');
        $suite->true(strpos($source, 'label: "Backup / Restore"') !== false, 'Administrators need a visible Backup / Restore navigation entry.');
        $suite->true(strpos($source, 'createTabs: await uiLoader.get("ui.tabs", options)') !== false, 'The workflow must use the native Helper tabs component.');
        $suite->true(strpos($source, 'state.components.adminTabs = state.factories.createTabs') !== false, 'The Helper tabs component must mount the workflow sections.');
        $suite->true(strpos($source, "entry.dataset.tabId === String(activeId)") !== false && strpos($source, 'activeTab?.focus({ preventScroll: true })') !== false, 'Tab activation must restore focus after the canonical component rebuilds its tab buttons.');
        $suite->true(strpos($source, 'createActionModal: await uiLoader.get("ui.action.modal", options)') !== false, 'Restore file selection must use the canonical Helper action modal.');
        $suite->true(strpos($source, 'createFileUploader: await uiLoader.get("ui.file.uploader", options)') !== false, 'Restore file selection must use the canonical Helper uploader.');
        $suite->true(strpos($source, 'createDataInspector: await uiLoader.get("ui.data.inspector", options)') !== false, 'Verified receipts must use the canonical Helper data inspector.');
        $suite->true(strpos($source, 'recovery?.installation?.available ? "Verified" : "Unavailable"') !== false && strpos($source, 'recovery?.installation?.message') !== false, 'Installation identity must never be labeled verified when the backend reports it unavailable.');
        $suite->true(strpos($source, 'This running instance cannot build or mint canonical executable code.') !== false, 'The clean package flow must preserve the CI-only producer boundary.');
        $suite->true(strpos($source, 'Build encrypted backup') !== false && strpos($source, 'authenticated encrypted backup') !== false, 'The backup action must retain encryption and non-executable boundaries.');
        $suite->true(strpos($source, 'Type STAGE RESTORE to continue') !== false, 'Staged restore needs explicit typed confirmation.');
        $suite->true(strpos($source, 'pattern: "STAGE RESTORE"') !== false && strpos($source, 'Enter exactly STAGE RESTORE (uppercase, with one space).') !== false, 'Exact staged-restore confirmation must fail canonical field validation before managed busy begins with accessible corrective text.');
        $suite->true(strpos($source, 'invalidateInspectionContext') !== false && strpos($source, 'pendingTransition = { inspection, generation: contextGeneration }') !== false && strpos($source, 'transition.generation === contextGeneration') !== false, 'Dismissed or superseded inspection contexts must not open a stale restore confirmation.');
        $suite->true(strpos($source, 'onBeforeClose(meta)') !== false && strpos($source, 'meta?.reason !== "inspected"') !== false && strpos($source, 'uploader?.destroy()') !== false, 'Inspection dismissal must immediately invalidate pending transitions and cancel active uploader work.');
        $suite->true(strpos($source, 'onClose(meta)') !== false && strpos($source, 'if (completedInspection) openStageRestoreConfirmation(completedInspection);') !== false && strpos($source, 'initialFocus: \'[name="confirmation"]\'') !== false, 'The verified inspection handoff must wait for canonical close finalization before opening and focusing the confirmation form.');
        $suite->true(strpos($source, 'never overwrites the live database or cuts traffic over') !== false, 'The restore action must explicitly exclude live overwrite and automatic cutover.');
        $suite->true(strpos($source, 'reset/reissue data is intentionally omitted') !== false, 'The restore action must explain reset and credential reissue consequences.');
        $suite->true(strpos($source, 'outcome is unknown') !== false && strpos($source, 'Idempotency-Key') !== false, 'Unknown outcomes must reconcile with the same idempotency key.');
        $suite->true(strpos($source, 'maxFileSize: 256 * 1024 * 1024') !== false, 'The canonical uploader must enforce the server upload limit.');
        $suite->true(strpos($styles, '.backup-restore-overview-grid') !== false && strpos($styles, '.backup-restore-uploader') !== false, 'The live workflow needs responsive layout styling.');
        $suite->true(strpos($styles, '.recovery-stage-restore-modal .ui-form-modal-display-value') !== false && strpos($styles, 'overflow-wrap: anywhere') !== false && strpos($styles, '.recovery-stage-restore-modal .ui-form-modal-checkbox-label') !== false, 'The restore confirmation must wrap long verified metadata and acknowledgement text within narrow viewports.');
        $suite->true(strpos($recoveryApi, 'requireAdministrator()') !== false, 'Every recovery route must require an administrator session.');
        $suite->true(strpos($backupApi, 'validateCsrf') !== false && strpos($backupDownloadsApi, 'validateCsrf') !== false && strpos($inspectionApi, 'validateCsrf') !== false && strpos($restoreApi, 'validateCsrf') !== false, 'Every recovery mutation must validate CSRF before service construction.');
        $suite->true(strpos($source, 'Authorize another download') !== false && strpos($source, 'operation_id: operationId') !== false, 'Expired or interrupted backup downloads need a digest-rechecked reauthorization path.');
        $suite->true(strpos($source, 'operation_id: operation.operation_id') !== false && strpos($source, 'status: "uncertain"') !== false, 'Started operations need bounded receipt polling and an explicit uncertain state.');
        $suite->true(strpos($backupApi, 'does not accept client-controlled paths') !== false && strpos($restoreApi, 'does not accept client paths, database credentials, or cutover options') !== false, 'Recovery routes must reject browser-controlled filesystem, DSN, and cutover inputs.');
        $suite->true(strpos($service, 'RESTORE_TARGET_IS_SERVING_DATABASE') !== false && strpos($service, '@@server_uuid') !== false, 'Staged restore must independently reject the serving database.');
        $suite->true(strpos($service, "'automatic_cutover' => false") !== false && strpos($service, "'live_overwrite' => false") !== false, 'Server receipts must preserve no-overwrite and no-cutover facts.');
    });

    $suite->test('First-run setup preview uses the Helper stepper without enabling installation', function () use ($suite, $root) {
        $page = file_get_contents($root . '/setup.php');
        $source = file_get_contents($root . '/assets/setup.mjs');
        $styles = file_get_contents($root . '/assets/setup.css');
        $routes = file_get_contents($root . '/.htaccess');
        $suite->true(strpos($routes, 'RewriteRule ^setup/?$ setup.php') !== false, 'The setup preview needs a stable route.');
        $suite->true(strpos($page, 'First-run setup · UI preview') !== false, 'The setup shell must identify itself as a preview.');
        $suite->true(strpos($page, 'This preview cannot create a database, administrator, package, or installation.') !== false, 'The setup shell must state its capability boundary.');
        $suite->true(strpos($source, 'await uiLoader.get("ui.stepper", options)') !== false, 'The setup flow must use the native Helper stepper.');
        $suite->true(strpos($source, '{ id: "ownership", title: "Ownership"') !== false && strpos($source, '{ id: "completion", title: "Completion"') !== false, 'The preview must show the approved ownership-through-completion stage model.');
        $suite->true(strpos($source, 'next.textContent = currentIndex === reviewIndex ? "Begin installation"') !== false, 'The irreversible action needs an explicit installation label.');
        $suite->true(strpos($source, 'next.disabled = currentIndex >= reviewIndex;') !== false, 'Review/install and completion actions must fail closed.');
        $suite->true(strpos($source, 'Installation has not run') !== false, 'The completion preview must not imply a successful installation.');
        $suite->true(strpos($source, 'renderStep({ focusStepper: true })') !== false && strpos($source, '?.focus({ preventScroll: true });') !== false, 'Keyboard activation must retain focus in the Helper stepper.');
        $suite->true(strpos($styles, '.setup-workspace .ui-stepper--horizontal .ui-stepper-list') !== false && strpos($styles, 'grid-template-columns: repeat(2, minmax(0, 1fr));') !== false, 'The Helper stepper must reflow without horizontal overflow on mobile.');
        $suite->true(strpos($source, 'fetch(') === false, 'The UI-first setup preview must not call an invented backend.');
    });

    $suite->test('Reply context cannot widen the message composer', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $styles = file_get_contents($root . '/assets/app.css');
        $suite->true(strpos($source, 'copy.className = "reply-context-copy";') !== false, 'Reply previews need a dedicated constrained text element.');
        $suite->true(strpos($styles, '.composer-shell { display: grid; width: 100%; max-width: 100%; min-width: 0;') !== false, 'The composer shell must be constrained to its grid column.');
        $suite->true(strpos($styles, '.reply-context-copy { display: block; flex: 1 1 0; min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }') !== false, 'Long reply previews must shrink and ellipsize.');
    });

    $suite->test('Reply automatically addresses its sender and temporarily hides addressing controls', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $index = file_get_contents($root . '/index.php');
        $suite->true(strpos($index, 'class="addressing-row" id="addressing-row"') !== false, 'The addressing controls need a stable visibility target.');
        $suite->true(strpos($source, 'state.draft.addressees = senderId && senderId !== currentParticipantId ? [senderId] : fallbackRecipients;') !== false, 'Reply must automatically select the original sender.');
        $suite->true(strpos($source, 'el.address_mode.hidden = hasAutomaticReplyRecipient;') !== false
            && strpos($source, 'el.addressee_select.hidden = broadcast || hasAutomaticReplyRecipient;') !== false,
            'Automatic reply addressing must hide the redundant mode and recipient controls.');
        $suite->true(strpos($source, 'el.addressing_row.hidden = false;') !== false,
            'Replies must retain the intent control so an action request remains explicit.');
        $suite->true(strpos($source, 'function restoreNormalAddressing()') !== false, 'Cancelling or sending a reply must restore the previous addressing state.');
    });

    $suite->test('Project overview uses one upper-right action menu without status chrome', function () use ($suite, $root) {
        $index = file_get_contents($root . '/index.php');
        $source = file_get_contents($root . '/assets/app.mjs');
        $suite->true(strpos($index, 'id="project-actions-trigger"') !== false, 'The project overview action-menu trigger is missing.');
        $suite->true(strpos($index, '<p class="ui-eyebrow">Project</p>') === false, 'The redundant Project eyebrow must not render.');
        $suite->true(strpos($index, 'class="ui-badge" id="status-badge"') === false, 'Realtime state must not render as a visible pill.');
        $suite->true(strpos($source, 'state.components.projectActions = state.factories.createDropdown') !== false, 'Project management actions must use the supported Helper dropdown.');
        $suite->true(strpos($source, 'helperIconHtml("actions.more-horizontal", 18)') !== false, 'The project menu must use the shared Helper icon.');
        $suite->true(strpos($index, 'id="project-instructions"') === false && strpos($source, 'el.project_instructions') === false,
            'Operating instructions must not render in the timeline workspace.');
        $suite->true(strpos($source, '"Operating instructions", "Shared governance and optional project-specific guidance."') !== false
            && strpos($source, 'Governance baseline · version ${governance.version}') !== false
            && strpos($source, 'project-info-instructions-copy') !== false,
            'Project Info must distinguish the shared governance baseline from project-specific instructions.');
    });

    $suite->test('Project routes use public UUIDs while API state retains internal IDs', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $suite->true(strpos($source, 'updateApplicationRoute("project", state.project.public_id || nextId, historyMode);') !== false, 'Project routes must prefer the public UUID.');
        $suite->true(substr_count($source, 'project.public_id ===') >= 2, 'Initial navigation and browser history must resolve public UUID routes.');
        $suite->true(strpos($source, 'switchProject(requestedProject.id') !== false, 'Public routes must resolve back to the authorized internal project ID.');
    });

    $suite->test('UTC message timestamps render and filter in the browser local timezone', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $suite->true(strpos($source, 'function normalizeUtcTimestamp(value)') !== false, 'UTC timestamp normalization is missing.');
        $suite->true(strpos($source, 'timezoneLessDateTime.test(normalized) ? `${normalized}Z` : normalized') !== false, 'Timezone-less database timestamps must be marked as UTC without changing explicit offsets.');
        $suite->true(strpos($source, 'created_at: created,') !== false, 'Normalized UTC timestamps must reach the timeline.');
        $suite->true(strpos($source, 'new Intl.DateTimeFormat(undefined') !== false, 'Displayed timestamps must use the browser locale and timezone.');
        $suite->true(strpos($source, 'const day = localDateKey(message.created_at);') !== false, 'Date filters must compare the user-local calendar date.');
    });

    $suite->test('Composer validation uses the Helper alert dialog instead of a toast', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $suite->true(strpos($source, '"ui.dialog.alert"') !== false, 'The Helper alert dialog must be loaded.');
        $suite->true(strpos($source, 'uiAlert: await uiLoader.get("ui.dialog.alert", options)') !== false, 'The Helper alert factory must be resolved through the loader.');
        $suite->true(strpos($source, 'await state.factories.uiAlert("Select at least one recipient, or choose Broadcast."') !== false, 'Missing recipients must open an alert dialog.');
        $suite->true(strpos($source, 'if (error.status === 422)') !== false, 'API validation failures must be handled separately from operational failures.');
        $suite->true(strpos($source, 'title: "Message needs attention"') !== false, 'API validation alerts need a clear title.');
        $suite->true(strpos($source, 'toast.warn("Select at least one recipient') === false, 'Composer validation must not fall back to the low-visibility toast.');
    });

    $suite->test('Real avatars replace the colored initials fallback', function () use ($suite, $root) {
        $source = str_replace("\r\n", "\n", file_get_contents($root . '/assets/app.mjs'));
        $styles = file_get_contents($root . '/assets/app.css');
        $avatarStart = strpos($source, 'function makeAvatar(');
        $avatarEnd = strpos($source, "\n}\n", $avatarStart);
        $suite->true($avatarStart !== false && $avatarEnd !== false, 'Avatar rendering implementation is missing.');
        $avatar = substr($source, $avatarStart, $avatarEnd - $avatarStart);
        $suite->true(strpos($avatar, 'if (participant.avatar_url)') !== false, 'Avatar rendering must distinguish uploaded images from fallbacks.');
        $suite->true(strpos($avatar, 'image.addEventListener("error", () => image.replaceWith(fallback)') !== false, 'Broken avatar images must restore the initials fallback.');
        $suite->true(strpos($avatar, '} else {') !== false && strpos($avatar, 'wrap.appendChild(fallback);') !== false, 'The filled fallback must render only when no avatar exists.');
        $suite->true(strpos($styles, '.participant-avatar img { display: block; object-fit: cover; background: transparent; }') !== false, 'Real avatar images must render without a fallback-colored background.');
    });

    $suite->test('Human avatars do not carry a redundant kind badge', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $styles = file_get_contents($root . '/assets/app.css');
        $suite->true(strpos($source, 'if (participant.kind === "agent")') !== false, 'Kind badges must be limited to agents.');
        $suite->true(strpos($source, 'badge.className = "participant-kind-mark is-agent-icon"') !== false && strpos($source, 'helperIconHtml("people.agent", 11)') !== false, 'Agents must use the shared Helper robot identifier.');
        $suite->true(strpos($source, 'badge.setAttribute("aria-label", "Agent")') !== false, 'The agent marker must retain an accessible label.');
        $suite->true(strpos($source, 'participant.kind === "agent" ? "A" : "H"') === false, 'Human avatars must not render an H badge.');
        $suite->true(strpos($styles, '.participant-avatar.is-human .participant-kind-mark') === false, 'Human badge styling must be removed.');
        $suite->true(strpos($styles, '.participant-kind-mark.is-agent-icon svg') !== false, 'The shared robot marker needs compact badge styling.');
        $suite->true(strpos(file_get_contents($root . '/vendor/pbb-helper/dist/helpers.ui.bundle.min.js'), 'people.agent') !== false, 'The vendored Helper bundle must expose people.agent.');
    });

    $suite->test('Project search reserves a separate navigation action column', function () use ($suite, $root) {
        $styles = file_get_contents($root . '/assets/app.css');
        $suite->true(strpos($styles, '.project-filter-row { grid-template-columns: minmax(0, 1fr) 40px; }') !== false,
            'Project search and its actions trigger must occupy separate grid columns.');
        $suite->true(strpos($styles, '.project-search .ui-input { width: 100%; max-width: 100%; min-width: 0; box-sizing: border-box; }') !== false,
            'The project search input must shrink within its assigned grid column.');
    });

    $suite->test('Responsibility acknowledgement updates one card without reloading the list', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/responsibility-inbox.mjs');
        $suite->true(strpos($source, 'applyAcknowledgement(row, item, scrollTop);') !== false,
            'Responsibility acknowledgement must apply a targeted card update.');
        $suite->true(strpos($source, "await options.acknowledge(item);\n          await load();") === false,
            'The normal acknowledgement path must not reload and rebuild the entire responsibility list.');
        $suite->true(strpos($source, 'const scrollTop = host.scrollTop;') !== false
            && substr_count($source, 'host.scrollTop = scrollTop;') >= 2
            && strpos($source, 'focus({ preventScroll: true })') !== false,
            'The targeted acknowledgement update must preserve scroll position and focus without scrolling.');
        $suite->true(strpos($source, 'const scrollTop = host.scrollTop;')
            < strpos($source, 'await options.acknowledge(item);'),
            'The inbox scroll position must be captured before the acknowledgement request begins.');
        $styles = file_get_contents($root . '/assets/app.css');
        $suite->true(strpos($styles, '.responsibility-scroll { min-height: 0; overflow: auto; overflow-anchor: none;') !== false,
            'The responsibility scroll container must not let browser anchoring override the preserved position.');
    });

    $suite->test('Responsibility inbox automatically pages near the scroll boundary', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/responsibility-inbox.mjs');
        $suite->true(strpos($source, 'new IntersectionObserver') !== false
            && strpos($source, 'root: host, rootMargin: "0px 0px 240px 0px"') !== false,
            'Responsibility paging must observe a sentinel within the inbox scroll container.');
        $suite->true(strpos($source, 'host.addEventListener("scroll", scheduleAutomaticPaging, { passive: true });') !== false
            && strpos($source, 'host.scrollHeight - host.scrollTop - host.clientHeight') !== false
            && strpos($source, 'if (remaining <= 240) requestAutomaticPage();') !== false,
            'Responsibility paging must also respond directly to the actual inbox scroll boundary.');
        $suite->true(strpos($source, 'let autoPageArmed = true;') !== false
            && strpos($source, 'if (destroyed || loading || autoLoadFailed || !hasMore || !autoPageArmed) return;') !== false
            && strpos($source, 'autoPageArmed = false;') !== false
            && strpos($source, 'if (remaining > 360)') !== false,
            'Automatic paging must issue only one request per bottom-boundary arrival.');
        $suite->true(strpos($source, 'if (append) updatePagingControls();') !== false
            && strpos($source, 'for (const item of incoming) list.append(renderItem(item));') !== false,
            'Appending a page must preserve existing cards instead of rebuilding the list.');
        $suite->true(strpos($source, 'No matches in this page. Load older work to continue.') === false
            && strpos($source, 'No results for “${label}” yet.') !== false
            && stripos($source, 'checking older work') === false
            && strpos($source, 'No results for “${label}” in the loaded items. Retry loading older work.') !== false,
            'Empty states must name the selected view without exposing automatic pagination, while retaining a retry after failure.');
    });

    $suite->test('Responsibility original messages open in a loading modal without leaving the inbox', function () use ($suite, $root) {
        $source = str_replace("\r\n", "\n", file_get_contents($root . '/assets/app.mjs'));
        $start = strpos($source, 'function openResponsibilityMessage(messageId)');
        $end = strpos($source, "\nasync function jumpToMessage", $start);
        $suite->true($start !== false && $end !== false, 'The responsibility message modal workflow is missing.');
        $workflow = substr($source, $start, $end - $start);
        $open = strpos($workflow, 'modal.open();');
        $busy = strpos($workflow, 'modal.setBusy(true');
        $request = strpos($workflow, 'await request(`${API.message}');
        $suite->true($open !== false && $busy !== false && $request !== false && $open < $busy && $busy < $request,
            'The canonical modal must open and enter its loading state before requesting message data.');
        $suite->true(strpos($workflow, 'new AbortController()') !== false
            && strpos($workflow, 'cancelBusy: { label: "Cancel"') !== false,
            'Loading the original message must be cancellable and abort its request when dismissed.');
        $suite->true(strpos($workflow, 'label: "Retry"') !== false
            && strpos($workflow, 'Unable to load the original message.') !== false,
            'A failed message request must keep the modal open with an actionable retry.');
        $suite->true(strpos($workflow, 'showProjectView("timeline")') === false
            && strpos($workflow, 'scrollToItem') === false,
            'Viewing an original message must not navigate away from the Responsibility Inbox.');
        $suite->true(strpos($source, 'Exact project message. Viewing it here does not change the Responsibility Inbox or its scroll position.') === false,
            'The message modal must not repeat implementation-detail guidance above the evidence.');
        $suite->true(strpos($source, 'import { evidenceDetails } from "./responsibility-evidence.mjs?v=') !== false
            && strpos($source, 'showCanonicalEvidence(') === false,
            'The application must render canonical evidence inside the Helper action modal and cache-bust formatter updates.');
    });

    $suite->test('Shared tasks occupy the workspace between timeline and team', function () use ($suite, $root) {
        $index = file_get_contents($root . '/index.php');
        $source = file_get_contents($root . '/assets/app.mjs');
        $styles = file_get_contents($root . '/assets/app.css');
        $timeline = strpos($index, 'id="project-messages-column"');
        $tasks = strpos($index, 'id="project-tasks-column"');
        $team = strpos($index, 'id="project-participants-column"');
        $suite->true($timeline !== false && $tasks !== false && $team !== false && $timeline < $tasks && $tasks < $team,
            'The task rail must render between the timeline and team columns.');
        $suite->true(strpos($styles, '.task-card-title { font-size: 14px;') !== false,
            'Task-card titles must use compact rail typography without changing task detail headings.');
        $suite->true(strpos($source, 'Every active participant in this project can see this task.') !== false,
            'Task creation must explain project-wide visibility.');
        $suite->true(strpos($source, 'source_message_id: sourceMessage?.id || null') !== false,
            'Timeline messages must support traceable task creation.');
        $suite->true(strpos($source, 'convert_action_request: convertsActionRequest') !== false
            && strpos($source, 'Convert action request to task') !== false,
            'Action-request conversion must be explicit in the task write and modal.');
        $suite->true(strpos($source, 'title: sourceMessage ?') === false
            && strpos($source, 'description: sourceMessage ? `Created from timeline message') === false,
            'Message-linked task creation must leave the editable title and description blank.');
        $suite->true(strpos($source, 'contextMenu: messageContextMenu(message)') !== false
            && strpos($source, 'async onContextMenuAction(action, item)') !== false
            && strpos($source, 'id: "create-task", label: message.action_requested ? "Convert to task" : "Create task"') !== false,
            'Message secondary actions must use the native Helper timeline context-menu contract.');
        $suite->true(strpos($source, 'attachActionMenuTrigger') === false
            && strpos($source, 'message-action-menu-trigger') === false,
            'The retired application-owned timeline trigger workaround must not remain after native adoption.');
        $suite->true(strpos($source, 'actionButton("Create task"') === false,
            'Create task must remain in the native context menu rather than the expanding footer.');
        $suite->true(strpos($source, 'function canAcknowledgeMessage(message)') !== false
            && strpos($source, 'actionButton("Acknowledge"') !== false
            && strpos($source, 'actions.appendChild(acknowledge);') !== false,
            'Eligible messages must restore Acknowledge directly beside Reply.');
        $suite->true(strpos($source, 'View linked tasks (') !== false
            && strpos($source, 'function openLinkedMessageTasks(message)') !== false,
            'Messages must expose tasks linked through source_message_id.');
        $inbox = file_get_contents($root . '/assets/responsibility-inbox.mjs');
        $suite->true(strpos($inbox, '"Convert to task"') !== false
            && strpos($inbox, '"View linked task"') !== false
            && strpos($inbox, 'refreshTasks()') !== false,
            'Responsibility requests must expose conversion or their existing linked task without a reload.');
        $suite->true(strpos($source, 'View source message') !== false
            && strpos($source, 'openResponsibilityMessage(task.source_message_id)') !== false,
            'Task details must link back to their originating timeline message.');
        $suite->true(strpos($source, '`${API.tasks}?${new URLSearchParams({ project_id: selectedProjectId() })}`') !== false,
            'Task creation must bind authorization to the selected project in the request URL.');
        $suite->true(strpos($source, 'type: "ui.datepicker", name: "due_at"') !== false
            && strpos($source, 'showTime: true, timePrecision: "minute", valueMode: "wall-clock"') !== false,
            'Optional task due dates must use the native Helper date-and-time field.');
        $suite->true(strpos($source, 'label: "Supervising participant"') === false,
            'The authenticated task giver must not be exposed as an editable supervisor field.');
        $suite->true(strpos($source, 'el.new_task_trigger.addEventListener("click", () => openCreateTaskModal());') !== false,
            'The New task trigger must not leak its click event into source-message attribution.');
        $suite->true(strpos($source, 'modal.setBusy(true, { message: "Loading task details..." })') !== false,
            'Task detail modals must open before loading and expose a busy state.');
        $suite->true(strpos($source, 'modal.setActions(taskActions(task, modal));') !== false
            && strpos($source, 'modal.setContent(taskDetailContent(task));') !== false
            && strpos($source, 'loading.replaceWith(taskDetailContent(task));') === false,
            'Task details must update through the canonical modal API after actions rerender the modal.');
        $suite->true(strpos($source, 'const taskGiver = id(task.created_by_participant_id) === current;') !== false
            && strpos($source, 'if (taskGiver) actions.unshift({ id: "edit"') !== false,
            'Only the authenticated task giver may see the task-definition edit action.');
        $suite->true(strpos($source, 'addDetailSection("Responsibility"') !== false
            && strpos($source, 'addDetailSection("Schedule"') !== false
            && strpos($source, 'activityHeading.textContent = "Activity"') !== false,
            'Task details must organize responsibility, schedule, criteria, and activity into clear sections.');
    });

    $suite->test('Agent profiles foreground project assignment and responsibilities', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $styles = file_get_contents($root . '/assets/app.css');
        $start = strpos($source, 'function openParticipantInfoModal(participant)');
        $end = strpos($source, "\nfunction participantMembershipStatusLabel(", $start);
        $suite->true($start !== false && $end !== false, 'Participant profile implementation is missing.');
        $profile = substr($source, $start, $end - $start);
        $suite->true(strpos($profile, '"participant-profile-role-title"') !== false, 'The agent role title must appear with the identity.');
        $suite->true(strpos($profile, '"participant-profile-role-summary"') !== false, 'The role summary must appear with the identity.');
        $suite->true(strpos($profile, '"Reports to"') !== false && strpos($profile, '"No supervisor assigned"') !== false, 'The project assignment must state the supervisor relationship explicitly.');
        $suite->true(strpos($profile, '"Responsibilities"') !== false && strpos($profile, 'participantProfileInstructionBlock("Role instructions"') !== false, 'Role responsibilities must have a dedicated section.');
        $suite->true(strpos($profile, 'participantProfileInstructionBlock("Project instructions"') !== false, 'Project instructions must be available beside role instructions.');
        $suite->true(strpos($profile, '"Connection"') !== false, 'Provider information must use the Connection section.');
        $suite->true(strpos($profile, '"Role version"') !== false && strpos($profile, '"Project context version"') !== false, 'Version metadata must remain in administrator technical details.');
        $suite->true(strpos($styles, '.participant-profile-supervisor-link') !== false, 'Clickable supervisors need profile-link styling.');
    });

    $suite->test('Agent editing opens the canonical Helper modal before loading details', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $start = strpos($source, 'async function openEditAgentModal(agent)');
        $end = strpos($source, "\nfunction adminRows(", $start);
        $suite->true($start !== false && $end !== false, 'Agent editor implementation is missing.');
        $editor = substr($source, $start, $end - $start);
        $modal = strpos($editor, 'state.factories.createFormModal({');
        $open = strpos($editor, 'editModal.open();');
        $busy = strpos($editor, 'editModal.setBusy(true');
        $firstRequest = strpos($editor, 'await request(');
        $suite->true($modal !== false && $open !== false && $busy !== false && $firstRequest !== false, 'The agent editor must use the canonical form modal loading state.');
        $suite->true($modal < $open && $open < $busy && $busy < $firstRequest, 'The form modal must open and enter busy state before the first agent-detail request.');
        $suite->true(strpos($editor, 'editModal.setFormError(`Unable to load agent configuration.') !== false, 'Loading failures must remain visible in the form modal.');
    });

    $suite->test('Agent forms expose Gemini browser companion configuration', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $suite->true(strpos($source, 'function isBrowserCompanionProvider(provider)') !== false, 'Browser companion providers need shared form behavior.');
        $suite->true(strpos($source, '"gemini_discussion_reference", "Gemini discussion URL"') !== false, 'Gemini discussion URL fields are missing.');
        $suite->true(strpos($source, 'https://gemini.google.com/app/...') !== false, 'Gemini needs its canonical discussion URL example.');
        $suite->true(strpos($source, 'The Gemini discussion must have access to the Syndicatum integration') !== false, 'Gemini outbound-only requirements must be visible to administrators.');
    });

    $suite->test('ChatGPT MCP exposes a read-only connection diagnostic', function () use ($suite, $root) {
        $source = file_get_contents($root . '/mcp.php');
        $docs = file_get_contents($root . '/docs/chatgpt-plugin.md');
        $authorize = file_get_contents($root . '/oauth/authorize.php');
        $suite->true(strpos($source, "'diagnose_connection' => 'projects:read'") !== false, 'The diagnostic must require only project read access.');
        $suite->true(strpos($source, "'mcp_request_received' => true") !== false, 'The diagnostic must confirm that the server received the MCP call.');
        $suite->true(strpos($source, "'authentication_valid' => true") !== false, 'The diagnostic must report successful authentication.');
        $suite->true(strpos($source, '$contextAuthorized = $bindingContext !== null || $serviceTokenAccess;') !== false, 'Project authorization must require a confirmed binding or authenticated service-token context.');
        $suite->true(strpos($source, "'project_access_valid' => \$contextAuthorized") !== false, 'The diagnostic must report only established project authorization.');
        $suite->true(strpos($source, "'discussion_binding' => \$serviceTokenAccess ? 'Not required'") !== false
            && strpos($source, "\$bindingContext ? (\$contextType === 'interactive' ? 'Not changed' : 'Successful') : 'Required'") !== false,
            'The diagnostic must distinguish service-token, interactive, successful, and required binding states.');
        $suite->true(strpos($source, "'prepare_discussion_binding'") !== false, 'The MCP binding preparation tool is missing.');
        $suite->true(strpos($source, "'readOnlyHint' => true") !== false, 'Read tools must retain their read-only annotation.');
        $suite->true(strpos($docs, '@Syndicatum diagnose connection') !== false, 'The user-facing diagnostic prompt must be documented.');
        $suite->true(strpos($docs, 'client-side denial') !== false, 'The documentation must distinguish client-side denial from a server outage.');
        $suite->true(strpos($authorize, 'Projects and agent identities are selected separately') !== false, 'OAuth consent must explain account-level authorization.');
        $suite->true(strpos($authorize, 'Connect AI app to Syndicatum') !== false, 'Shared OAuth consent must use client-neutral wording.');
        $suite->true(strpos($authorize, 'Connect ChatGPT to Syndicatum') === false, 'Shared OAuth consent must not misidentify Codex as ChatGPT.');
        $suite->true(strpos($authorize, 'name="agent"') === false, 'OAuth consent must not select a project agent.');
    });

    $suite->test('Health identifies a compatible Syndicatum connector server', function () use ($suite, $root) {
        $source = file_get_contents($root . '/api/v1/health.php');
        $suite->true(strpos($source, "'id' => 'syndicatum'") !== false, 'Health must expose the Syndicatum service identity.');
        $suite->true(strpos($source, "'protocol' => 'syndicatum-connector-v1'") !== false, 'Health must expose the connector discovery protocol.');
        $suite->true(strpos($source, "'connector_device_authorization' => true") !== false, 'Health must advertise device authorization capability.');
    });

    $suite->test('Project participant controls expose guarded human and agent removal actions', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $suite->true(strpos($source, 'function confirmMemberRemoval(participant, managerModal)') !== false, 'Human removal confirmation is missing.');
        $suite->true(strpos($source, 'method: "DELETE"') !== false && strpos($source, 'API.projectMembers') !== false, 'Human removal must call the project-members DELETE endpoint.');
        $suite->true(strpos($source, 'participant.role !== "owner"') !== false, 'The owner removal action must not be presented.');
        $suite->true(strpos($source, 'function confirmAgentRemoval(agent, editModal)') !== false, 'Agent removal confirmation is missing.');
        $suite->true(strpos($source, 'id: "remove-agent", label: "Remove from project"') !== false, 'Agent actions must expose removal.');
        $suite->true(substr_count(strtolower($source), 'timeline messages will remain visible.') >= 2, 'Both confirmation dialogs must explain timeline retention.');
    });

    $suite->test('Project selection shows immediate Helper busy feedback while the project loads', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $start = strpos($source, 'async function openWorkspaceProject(project, trigger)');
        $end = strpos($source, "\nfunction modalTextField(", $start);
        $suite->true($start !== false && $end !== false, 'The workspace project selection handler is missing.');
        $selection = substr($source, $start, $end - $start);
        $overlay = strpos($selection, 'state.factories.createBusyOverlay({');
        $projectLoad = strpos($selection, 'await switchProject(project.id);');
        $suite->true($overlay !== false && $projectLoad !== false && $overlay < $projectLoad, 'The busy overlay must appear before project loading begins.');
        $suite->true(strpos($selection, 'trigger.disabled = true;') !== false, 'The selected project must reject duplicate clicks while loading.');
        $suite->true(strpos($selection, 'finally {') !== false && strpos($selection, 'loadingOverlay.destroy();') !== false, 'The project-selection overlay must always be removed.');
        $suite->true(strpos($source, 'card.addEventListener("click", () => void openWorkspaceProject(project, card));') !== false, 'Workspace project cards must use the feedback-enabled selection handler.');
    });

    $suite->test('Agent credential handoff provides inline copy actions with success feedback', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $styles = file_get_contents($root . '/assets/app.css');
        $suite->true(strpos($source, 'helperIconHtml("actions.copy", 18)') !== false, 'Credential copy actions must use the shared Helper copy icon.');
        $suite->true(strpos($source, 'mountCredentialCopyAction(modal, ".claim-code-copy-row", claimCode') !== false, 'The claim code needs its own copy action.');
        $suite->true(strpos($source, 'mountCredentialCopyAction(modal, ".agent-message-copy-row", agentInstruction') !== false, 'The agent instruction needs its own copy action.');
        $suite->true(strpos($source, 'await navigator.clipboard.writeText(value);') !== false, 'Copy actions must use the browser Clipboard API.');
        $suite->true(strpos($source, 'state.components.toast.success(successMessage);') !== false, 'Successful copies must show a toast.');
        $suite->true(strpos($source, 'with this one-time claim code: ${claimCode}') !== false, 'The copied agent instruction must contain the actual claim code.');
        $suite->true(strpos($source, 'plugin tool claim_agent_profile') !== false, 'The copied agent instruction must name the local Codex claim tool.');
        $suite->true(strpos($source, 'Do not use the ChatGPT OAuth-connected Syndicatum app') !== false, 'The copied agent instruction must distinguish the Codex claim tool from the ChatGPT OAuth app.');
        $suite->true(strpos($source, 'Do not enter this claim code in ChatGPT or the Companion') !== false, 'ChatGPT browser delivery must not be presented as a claim-code flow.');
        $suite->true(strpos($source, 'Do not enter this claim code in Gemini or the Companion') !== false, 'Gemini browser delivery must not be presented as a claim-code flow.');
        $suite->true(strpos($source, 'browser delivery does not use it') !== false, 'Browser-provider handoffs must explain that claim codes are unrelated to delivery.');
        $suite->true(strpos($styles, '.agent-credential-copy-row { display: grid;') !== false, 'Credential copy actions must remain aligned beside wrapping text.');
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
    $environment['PBB_AGENTCHAT_DB_HOST'] = $testDbHost;
    $environment['PBB_AGENTCHAT_DB_NAME'] = $database;
    $environment['PBB_AGENTCHAT_DB_USER'] = 'root';
    $environment['PBB_AGENTCHAT_DB_PASS'] = $testDbPass;
    $environment['PBB_AGENTCHAT_SECRET'] = $secret;
    $environment['SYNDICATUM_BACKUP_DIR'] = $recoveryRoot . DIRECTORY_SEPARATOR . 'backups';
    $environment['SYNDICATUM_STAGING_DIR'] = $recoveryRoot . DIRECTORY_SEPARATOR . 'staging';
    $environment['SYNDICATUM_AVATAR_DIR'] = $recoveryRoot . DIRECTORY_SEPARATOR . 'avatars';
    $environment['SYNDICATUM_RESTORE_DB_HOST'] = $testDbHost;
    $environment['SYNDICATUM_RESTORE_DB_NAME'] = $database;
    $environment['SYNDICATUM_RESTORE_DB_USER'] = 'root';
    $environment['SYNDICATUM_RESTORE_DB_PASS'] = $testDbPass;
    list($server, $baseUrl, $serverLog) = surfaceServer($root, surfacePort(), $environment);

    $suite->test('Recovery HTTP routes fail closed before mutation and reject the serving database as a target', function () use ($suite, $baseUrl, $tokens, $recoveryRoot) {
        $anonymous = surfaceRequest($baseUrl, 'GET', '/api/v1/admin/recovery-status.php');
        $normal = surfaceRequest($baseUrl, 'GET', '/api/v1/admin/recovery-status.php', surfaceHeaders($tokens['member']));
        $missingCsrf = surfaceRequest($baseUrl, 'POST', '/api/v1/admin/backups.php', surfaceHeaders($tokens['admin']), []);
        $badCsrf = surfaceRequest($baseUrl, 'POST', '/api/v1/admin/backup-downloads.php', surfaceHeaders($tokens['admin'], 'invalid-csrf'), ['operation_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
        $bootstrap = surfaceRequest($baseUrl, 'GET', '/api/v1/admin/_recovery.php');
        $suite->same(401, $anonymous['status'], 'Anonymous recovery status must require authentication.');
        $suite->same(403, $normal['status'], 'Non-administrator recovery status must be denied.');
        $suite->same(403, $missingCsrf['status'], 'Backup creation must reject missing CSRF.');
        $suite->same(403, $badCsrf['status'], 'Backup ticket reauthorization must reject invalid CSRF.');
        $suite->same(404, $bootstrap['status'], 'The shared recovery bootstrap must not be a public success route.');
        $suite->same(['.', '..'], scandir($recoveryRoot . DIRECTORY_SEPARATOR . 'backups'), 'Rejected recovery requests must not create operation storage.');
        $admin = surfaceRequest($baseUrl, 'GET', '/api/v1/admin/recovery-status.php', surfaceHeaders($tokens['admin']));
        $suite->same(200, $admin['status'], 'An administrator must be able to read the recovery contract. ' . $admin['raw']);
        $suite->same(true, $admin['body']['data']['restore_target']['configured']);
        $suite->same(false, $admin['body']['data']['restore_target']['ready'], 'The serving database must not be accepted as the restore target.');
        $suite->same(false, $admin['body']['data']['constraints']['live_overwrite']);
        $suite->same(false, $admin['body']['data']['constraints']['automatic_cutover']);
    });

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
        $persistentHeaders = array_values(array_filter($normal['set_cookie_headers'], function ($header) {
            return stripos($header, AuthService::SESSION_COOKIE . '=') === 0
                || stripos($header, AuthService::CSRF_COOKIE . '=') === 0;
        }));
        $suite->same(2, count($persistentHeaders), 'Authenticated use must renew both browser cookies.');
        foreach ($persistentHeaders as $header) {
            $suite->true(stripos($header, 'Expires=') !== false, 'Persistent cookie is missing Expires.');
            $suite->true(preg_match('/Max-Age=([0-9]+)/i', $header, $match) === 1, 'Persistent cookie is missing Max-Age.');
            $suite->true((int) $match[1] >= AuthService::PERSISTENT_COOKIE_LIFETIME_SECONDS - 5, 'Persistent cookie lifetime is unexpectedly short.');
        }
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
            $suite->same('vendor/pbb-realtime/js/sdk/index.js', $response['body']['data']['capabilities']['realtime']['sdk_module_url'], 'The SDK URL must remain relative so installations mounted below the web root do not request /vendor from the host root.');
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
        $suite->same('chatgpt', $providers['body']['data'][1]['code']);
        $suite->same(true, $providers['body']['data'][1]['proactive_activation']);
        $suite->same('browser_companion', $providers['body']['data'][1]['activation_kind']);
        $suite->same(false, $providers['body']['data'][1]['working_directory_supported']);
        $suite->same('gemini', $providers['body']['data'][2]['code']);
        $suite->same(true, $providers['body']['data'][2]['proactive_activation']);
        $suite->same('browser_companion', $providers['body']['data'][2]['activation_kind']);
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

    $suite->test('Google account linking requires an authenticated human session and CSRF', function () use ($suite, $baseUrl, $tokens, $csrf) {
        $anonymous = surfaceRequest($baseUrl, 'POST', '/api/v1/google-link.php', [], ['return_path' => '/']);
        $missingCsrf = surfaceRequest($baseUrl, 'POST', '/api/v1/google-link.php', surfaceHeaders($tokens['member']), ['return_path' => '/']);
        $disabled = surfaceRequest($baseUrl, 'POST', '/api/v1/google-link.php', surfaceHeaders($tokens['member'], $csrf['member']), ['return_path' => '/']);
        $suite->same(401, $anonymous['status']);
        $suite->same(403, $missingCsrf['status']);
        $suite->same(409, $disabled['status']);
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
    surfaceRemoveTree($recoveryRoot);
}

exit($suite->finish());
