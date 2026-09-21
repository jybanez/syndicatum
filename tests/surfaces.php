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

    $suite->test('Apache denies repository, local work, and runtime paths', function () use ($suite, $root) {
        $rewrites = file_get_contents($root . '/.htaccess');
        $suite->true(
            strpos($rewrites, 'RewriteRule ^(?:\\.git|\\.agents|\\.codex|\\.playwright-cli|output|runtime)(?:/|$) - [F,L,NC]') !== false
                && strpos($rewrites, 'RewriteRule ^\\.env(?:\\..*)?$ - [F,L,NC]') !== false,
            'The application vhost must deny repository, local-work, environment, and runtime paths before the existing-file bypass.'
        );
    });

    $suite->test('public legal pages describe hosted privacy, Google sign-in, and service terms', function () use ($suite, $root) {
        $index = file_get_contents($root . '/index.php');
        $source = file_get_contents($root . '/assets/app.mjs');
        $privacy = file_get_contents($root . '/privacy.php');
        $terms = file_get_contents($root . '/terms.php');
        $rewrites = file_get_contents($root . '/.htaccess');
        $suite->true(strpos($index, 'href="privacy"') !== false && strpos($index, 'href="terms"') !== false, 'The application surface must link both public legal pages.');
        $suite->true(strpos($source, 'menuGroups: [') !== false, 'The authenticated account menu must group account, legal, and session actions.');
        $suite->true(strpos($source, '{ id: "privacy", label: "Privacy Policy" }') !== false && strpos($source, '{ id: "terms", label: "Terms of Service" }') !== false, 'The authenticated account menu must expose both legal pages.');
        $suite->true(strpos($source, 'items: [{ id: "signout", label: "Logout", danger: true }]') !== false, 'Logout must follow the legal action group.');
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
        $suite->true(strpos($connection, 'loadMessages("newer", projectGeneration)') !== false, 'A successful rejoin must perform one gap-recovery synchronization.');
        $suite->true(strpos($source, 'const delay = Math.min(60000, 5000 * (2 ** Math.min(state.realtimeRetryCount, 4)));') !== false, 'Realtime reconnects must use bounded exponential backoff.');
        $suite->true(is_file($root . '/vendor/pbb-realtime/js/sdk/index.js'), 'The same-origin PBB Realtime SDK is missing.');
    });

    $suite->test('Participant directory refreshes for remote creation events and polling fallback', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $suite->true(strpos($source, 'envelope.type === "syndicatum.participants.changed"') !== false, 'Realtime participant changes must be handled.');
        $suite->true(strpos($source, 'async function refreshParticipants(') !== false, 'The participant reload helper is missing.');
        $suite->true(strpos($source, 'Promise.all([loadMessages("newer"), refreshParticipants()])') !== false, 'Polling fallback must refresh participants.');
    });

    $suite->test('Broadcast messages render as broadcasts instead of mass tags', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $index = file_get_contents($root . '/index.php');
        $suite->true(strpos($source, 'function isBroadcastMessage(') !== false, 'Broadcast detection helper is missing.');
        $suite->true(strpos($source, 'entry.reason || "").toLowerCase() === "broadcast"') !== false, 'Broadcast detection must use addressee reason metadata.');
        $suite->true(strpos($source, 'message.addressees.length === 0') !== false, 'Historical unaddressed messages must render as project broadcasts.');
        $suite->true(strpos($source, 'chip.textContent = "Project timeline";') === false, 'Unaddressed messages must not render as a separate timeline addressing mode.');
        $suite->true(strpos($source, 'chip.textContent = "Project broadcast";') !== false, 'Broadcasts must collapse participant chips into one broadcast label.');
        $suite->true(strpos($source, 'identity.append(identityLine, renderAddresseeChips(current));') !== false, 'Message addressee chips must render under the sender identity.');
        $suite->true(strpos($source, 'footer.appendChild(chips);') === false, 'Message addressee chips must not render in the footer action row.');
        $suite->true(strpos($index, 'Everyone active in this project will be notified.') !== false, 'Composer broadcast warning must not describe broadcasts as response tagging.');
    });

    $suite->test('Message composer precedes filters and the newest-first timeline', function () use ($suite, $root) {
        $index = file_get_contents($root . '/index.php');
        $messagesColumn = strpos($index, 'class="surface-column project-messages-column"');
        $overview = strpos($index, 'class="project-overview timeline-project-overview"');
        $composer = strpos($index, 'id="composer-shell"');
        $filters = strpos($index, 'class="filter-bar"');
        $timeline = strpos($index, 'id="timeline-host"');
        $suite->true($messagesColumn !== false && $overview !== false && $composer !== false && $filters !== false && $timeline !== false, 'Project message controls are missing.');
        $suite->true($messagesColumn < $overview && $overview < $composer && $composer < $filters && $filters < $timeline, 'The project overview must replace the timeline header above the composer, filters, and timeline.');
        $suite->same(1, substr_count($index, 'class="project-overview timeline-project-overview"'), 'The project overview must render only in the message column.');
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
        $suite->true(strpos($loader, 'const UI_BUNDLE_REV = "0.21.174";') !== false, 'The vendored Helper bundle must include the approved agent-icon release.');
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
        $suite->true(strpos($source, 'el.addressing_row.hidden = hasAutomaticReplyRecipient;') !== false, 'Automatic reply addressing must hide the redundant controls.');
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
        $suite->true(strpos($source, 'await state.factories.uiAlert("Select at least one expected responder, or choose Broadcast."') !== false, 'Missing addressees must open an alert dialog.');
        $suite->true(strpos($source, 'if (error.status === 422)') !== false, 'API validation failures must be handled separately from operational failures.');
        $suite->true(strpos($source, 'title: "Message needs attention"') !== false, 'API validation alerts need a clear title.');
        $suite->true(strpos($source, 'toast.warn("Select at least one expected responder') === false, 'Composer validation must not fall back to the low-visibility toast.');
    });

    $suite->test('Real avatars replace the colored initials fallback', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
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

    $suite->test('Agent editing shows immediate Helper busy feedback while details load', function () use ($suite, $root) {
        $source = file_get_contents($root . '/assets/app.mjs');
        $start = strpos($source, 'async function openEditAgentModal(agent)');
        $end = strpos($source, "\nfunction adminRows(", $start);
        $suite->true($start !== false && $end !== false, 'Agent editor implementation is missing.');
        $editor = substr($source, $start, $end - $start);
        $overlay = strpos($editor, 'state.factories.createBusyOverlay({');
        $firstRequest = strpos($editor, 'await request(');
        $suite->true($overlay !== false && $firstRequest !== false && $overlay < $firstRequest, 'The busy overlay must appear before the first agent-detail request.');
        $suite->true(strpos($editor, 'finally {') !== false && strpos($editor, 'loadingOverlay.destroy();') !== false, 'The busy overlay must always be removed.');
        $suite->true(strpos($source, '"ui.busy.overlay"') !== false, 'The Helper busy overlay must be loaded through ui.loader.');
        $suite->true(strpos($source, 'createBusyOverlay: await uiLoader.get("ui.busy.overlay", options)') !== false, 'The app must use the Helper busy-overlay factory.');
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
        $suite->true(strpos($source, "'project_access_valid' => \$bindingContext !== null") !== false, 'Project authorization must be reported only for a successful discussion binding.');
        $suite->true(strpos($source, "'discussion_binding' => \$bindingContext ? 'Successful' : 'Required'") !== false, 'The diagnostic must distinguish Successful from Required discussion binding.');
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
