<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/AccountIntegration.php';

class AccountTestSettings
{
    private $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function get($key)
    {
        if (!array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException('Unknown setting key.');
        }
        return $this->values[$key];
    }
}

class AccountSsoTestSuite
{
    private $passed = 0;
    private $failed = 0;

    public function test($name, $callback)
    {
        try {
            call_user_func($callback);
            $this->passed++;
            echo 'PASS  ' . $name . "\n";
        } catch (Exception $exception) {
            $this->failed++;
            echo 'FAIL  ' . $name . ': ' . $exception->getMessage() . "\n";
        }
    }

    public function same($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : ('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)));
        }
    }

    public function truthy($condition, $message = 'Assertion failed.')
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function throws($callback, $contains)
    {
        try {
            call_user_func($callback);
        } catch (Exception $exception) {
            if (strpos($exception->getMessage(), $contains) === false) {
                throw new RuntimeException('Unexpected exception: ' . $exception->getMessage());
            }
            return;
        }
        throw new RuntimeException('Expected an exception containing: ' . $contains);
    }

    public function finish()
    {
        echo "\n" . $this->passed . ' passed, ' . $this->failed . " failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

function accountTestRandom($bytes)
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

function accountTestSettings(array $overrides = [])
{
    return new AccountTestSettings(array_merge([
        'account.enabled' => true,
        'account.base_url' => 'https://account.example.test',
        'account.client_id' => 'pbb-syndicatum',
        'account.client_secret' => 'oauth-secret',
        'account.callback_url' => 'https://syndicatum.example.test/auth/account-callback.php',
        'account.post_logout_url' => 'https://syndicatum.example.test/',
        'account.scopes' => 'openid profile',
        'account.timeout_seconds' => 10,
        'account.ca_bundle' => '',
    ], $overrides));
}

function accountTestCallback(AccountIntegration $integration, array $identity, $accountSessionId)
{
    $attempt = $integration->beginAuthorization('/projects/7');
    parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
    return $integration->completeCallback([
        'code' => 'one-time-code',
        'state' => $query['state'],
    ], $attempt['attempt_token']);
}

$suite = new AccountSsoTestSuite();
$database = 'syndicatum_account_test_' . bin2hex(accountTestRandom(6));
if (!preg_match('/^syndicatum_account_test_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe Account test database name.');
}

$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
    putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
    putenv('PBB_AGENTCHAT_DB_USER=root');
    putenv('PBB_AGENTCHAT_DB_PASS=');
    putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(accountTestRandom(32)));
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo);

    $suite->test('Account integration defaults disabled', function () use ($suite, $pdo, $auth) {
        $integration = new AccountIntegration($pdo, new AccountTestSettings([]), $auth);
        $suite->same(false, $integration->isEnabled());
    });

    $suite->test('authorization attempt is browser-bound, one-time, and nonce-bearing', function () use ($suite, $pdo, $auth) {
        $integration = new AccountIntegration($pdo, accountTestSettings(), $auth);
        $attempt = $integration->beginAuthorization('//evil.example/path');
        parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
        $suite->same('pbb-syndicatum', $query['client_id']);
        $suite->same('code', $query['response_type']);
        $suite->same(64, strlen($query['nonce']));
        $row = $pdo->query('SELECT * FROM account_oauth_attempts ORDER BY id DESC LIMIT 1')->fetch();
        $suite->same(hash('sha256', $attempt['attempt_token']), $row['attempt_hash']);
        $suite->same(hash('sha256', $query['state']), $row['state_hash']);
        $suite->same('/', $row['return_path']);
        $suite->truthy($row['attempt_hash'] !== $attempt['attempt_token']);
    });

    $suite->test('callback JIT creates only an ordinary user, workspace, and Account-bound local session', function () use ($suite, $pdo, $auth) {
        $transport = function ($url, $payload) use ($suite) {
            $suite->same('https://account.example.test/oauth/token', $url);
            $suite->same('oauth-secret', $payload['client_secret']);
            return ['status' => 200, 'body' => json_encode([
                'account_session_id' => 'account-session-one',
                'nonce' => $payload['nonce'],
                'user' => [
                    'pbb_user_id' => '01ACCOUNTUSER000000000001',
                    'name' => 'Account User',
                    'email' => 'account-user@example.test',
                    'avatar_url' => 'https://account.example.test/avatar.webp',
                    'status' => 'active',
                ],
            ])];
        };
        $integration = new AccountIntegration($pdo, accountTestSettings(), $auth, $transport);
        $result = accountTestCallback($integration, [], 'account-session-one');
        $userId = (int) $result['user']['id'];
        $suite->same('/projects/7', $result['return_path']);
        $suite->same(1, (int) $pdo->query('SELECT COUNT(*) FROM workspaces WHERE owner_user_id = ' . $userId)->fetchColumn());
        $roles = $pdo->query('SELECT r.code FROM system_roles r JOIN user_system_roles ur ON ur.role_id = r.id WHERE ur.user_id = ' . $userId)->fetchAll(PDO::FETCH_COLUMN);
        $suite->same(['user'], $roles);
        $session = $pdo->query('SELECT account_session_id FROM syndicatum_sessions WHERE user_id = ' . $userId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
        $suite->same('account-session-one', $session);
        $suite->truthy($auth->currentUser($result['session']['token']) !== null);
    });

    $suite->test('callback links only by pbb_user_id and rejects normalized-email collision', function () use ($suite, $pdo, $auth) {
        $now = Db::now();
        $pdo->prepare("INSERT INTO users (normalized_email, display_name, status, created_at, updated_at) VALUES (?, ?, 'active', ?, ?)")
            ->execute(['collision@example.test', 'Native User', $now, $now]);
        $nativeId = (int) $pdo->lastInsertId();
        $transport = function ($url, $payload) {
            return ['status' => 200, 'body' => json_encode([
                'account_session_id' => 'account-session-collision',
                'nonce' => $payload['nonce'],
                'user' => [
                    'pbb_user_id' => '01DIFFERENTACCOUNT000001',
                    'name' => 'Collision',
                    'email' => 'collision@example.test',
                    'status' => 'active',
                ],
            ])];
        };
        $integration = new AccountIntegration($pdo, accountTestSettings(), $auth, $transport);
        $suite->throws(function () use ($integration) {
            accountTestCallback($integration, [], 'account-session-collision');
        }, 'must be linked deliberately');
        $linked = $pdo->query('SELECT pbb_user_id FROM users WHERE id = ' . $nativeId)->fetchColumn();
        $suite->same(null, $linked);
        $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE pbb_user_id = '01DIFFERENTACCOUNT000001'")->fetchColumn());
    });

    $suite->test('returning Account identity preserves existing local authorization', function () use ($suite, $pdo, $auth) {
        $userId = (int) $pdo->query("SELECT id FROM users WHERE pbb_user_id = '01ACCOUNTUSER000000000001'")->fetchColumn();
        $now = Db::now();
        $pdo->prepare(
            "INSERT IGNORE INTO user_system_roles (user_id, role_id, granted_by_user_id, created_at)
             SELECT ?, id, NULL, ? FROM system_roles WHERE code = 'administrator'"
        )->execute([$userId, $now]);
        $transport = function ($url, $payload) {
            return ['status' => 200, 'body' => json_encode([
                'account_session_id' => 'account-session-returning',
                'nonce' => $payload['nonce'],
                'user' => [
                    'pbb_user_id' => '01ACCOUNTUSER000000000001',
                    'name' => 'Updated Account Name',
                    'email' => 'account-user@example.test',
                    'avatar_url' => 'javascript:alert(1)',
                    'status' => 'active',
                ],
            ])];
        };
        $integration = new AccountIntegration($pdo, accountTestSettings(), $auth, $transport);
        accountTestCallback($integration, [], 'account-session-returning');
        $suite->same(1, (int) $pdo->query("SELECT COUNT(*) FROM user_system_roles ur JOIN system_roles r ON r.id = ur.role_id WHERE ur.user_id = $userId AND r.code = 'administrator'")->fetchColumn());
        $suite->same(1, (int) $pdo->query('SELECT COUNT(*) FROM workspaces WHERE owner_user_id = ' . $userId)->fetchColumn());
        $row = $pdo->query('SELECT display_name, avatar_url FROM users WHERE id = ' . $userId)->fetch();
        $suite->same('Updated Account Name', $row['display_name']);
        $suite->same(null, $row['avatar_url']);
    });

    $suite->test('nonce mismatch and consumed callback attempts fail closed', function () use ($suite, $pdo, $auth) {
        $transport = function () {
            return ['status' => 200, 'body' => json_encode([
                'account_session_id' => 'account-session-bad-nonce',
                'nonce' => str_repeat('0', 64),
                'user' => ['pbb_user_id' => '01BADNONCE00000000000001', 'status' => 'active'],
            ])];
        };
        $integration = new AccountIntegration($pdo, accountTestSettings(), $auth, $transport);
        $attempt = $integration->beginAuthorization('/');
        parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
        $suite->throws(function () use ($integration, $attempt, $query) {
            $integration->completeCallback(['code' => 'code', 'state' => $query['state']], $attempt['attempt_token']);
        }, 'nonce is invalid');
        $suite->throws(function () use ($integration, $attempt, $query) {
            $integration->completeCallback(['code' => 'code', 'state' => $query['state']], $attempt['attempt_token']);
        }, 'state is invalid');
    });

    $suite->test('local linked-user status and roles remain authoritative', function () use ($suite, $pdo, $auth) {
        $now = Db::now();
        $pdo->prepare("INSERT INTO users (normalized_email, display_name, pbb_user_id, status, created_at, updated_at) VALUES (?, ?, ?, 'suspended', ?, ?)")
            ->execute(['suspended@example.test', 'Suspended', '01SUSPENDEDACCOUNT000001', $now, $now]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO user_system_roles (user_id, role_id, created_at) SELECT ?, id, ? FROM system_roles WHERE code = 'administrator'")
            ->execute([$userId, $now]);
        $transport = function ($url, $payload) {
            return ['status' => 200, 'body' => json_encode([
                'account_session_id' => 'account-session-suspended',
                'nonce' => $payload['nonce'],
                'user' => [
                    'pbb_user_id' => '01SUSPENDEDACCOUNT000001',
                    'name' => 'Changed Name',
                    'email' => 'suspended@example.test',
                    'status' => 'active',
                ],
            ])];
        };
        $integration = new AccountIntegration($pdo, accountTestSettings(), $auth, $transport);
        $suite->throws(function () use ($integration) {
            accountTestCallback($integration, [], 'account-session-suspended');
        }, 'Syndicatum user is not active');
        $suite->same(0, (int) $pdo->query('SELECT COUNT(*) FROM syndicatum_sessions WHERE user_id = ' . $userId)->fetchColumn());
        $suite->same(1, (int) $pdo->query("SELECT COUNT(*) FROM user_system_roles ur JOIN system_roles r ON r.id = ur.role_id WHERE ur.user_id = $userId AND r.code = 'administrator'")->fetchColumn());
    });

    $suite->test('logout URL uses exact configured Account boundary', function () use ($suite, $pdo, $auth) {
        $integration = new AccountIntegration($pdo, accountTestSettings(), $auth);
        $url = $integration->logoutUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $suite->same('/oauth/logout', parse_url($url, PHP_URL_PATH));
        $suite->same('pbb-syndicatum', $query['client_id']);
        $suite->same('https://syndicatum.example.test/', $query['post_logout_redirect_uri']);
    });
} finally {
    if ($admin instanceof PDO) {
        if (!preg_match('/^syndicatum_account_test_[a-f0-9]{12}$/', $database)) {
            throw new RuntimeException('Refusing to drop unsafe Account test database name.');
        }
        $admin->exec('DROP DATABASE `' . $database . '`');
    }
}

exit($suite->finish());
