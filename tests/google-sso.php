<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/GoogleIntegration.php';

class GoogleTestSettings
{
    private $values;
    public function __construct(array $overrides = [])
    {
        $this->values = array_merge([
            'google.enabled' => true,
            'google.client_id' => 'google-client.example.apps.googleusercontent.com',
            'google.client_secret' => 'google-secret',
            'google.callback_url' => 'https://syndicatum.example.test/auth/google-callback.php',
            'google.scopes' => 'openid email profile',
            'google.timeout_seconds' => 10,
            'google.ca_bundle' => '',
        ], $overrides);
    }
    public function get($key)
    {
        if (!array_key_exists($key, $this->values)) { throw new InvalidArgumentException('Unknown setting key.'); }
        return $this->values[$key];
    }
}

class GoogleTestSuite
{
    private $passed = 0;
    private $failed = 0;
    public function test($name, $callback)
    {
        try { call_user_func($callback); $this->passed++; echo 'PASS  ' . $name . "\n"; }
        catch (Exception $exception) { $this->failed++; echo 'FAIL  ' . $name . ': ' . $exception->getMessage() . "\n"; }
    }
    public function same($expected, $actual)
    {
        if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    }
    public function truthy($value) { if (!$value) { throw new RuntimeException('Assertion failed.'); } }
    public function throws($callback, $contains)
    {
        try { call_user_func($callback); }
        catch (Exception $exception) {
            if (strpos($exception->getMessage(), $contains) !== false) { return; }
            throw new RuntimeException('Unexpected exception: ' . $exception->getMessage());
        }
        throw new RuntimeException('Expected exception containing: ' . $contains);
    }
    public function finish()
    {
        echo "\n" . $this->passed . ' passed, ' . $this->failed . " failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

function googleTestRandom($bytes)
{
    $strong = false;
    $value = openssl_random_pseudo_bytes($bytes, $strong);
    if ($value === false || !$strong) { throw new RuntimeException('Secure random source unavailable.'); }
    return $value;
}

function googleTestBase64Url($value)
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function googleTestIntegration(PDO $pdo, AuthService $auth, &$captured, array $claimOverrides = [])
{
    $transport = function ($method, $url, $payload) use (&$captured) {
        $nonce = isset($captured['nonce']) ? $captured['nonce'] : null;
        $captured = compact('method', 'url', 'payload');
        $captured['nonce'] = $nonce;
        return ['status' => 200, 'body' => json_encode(['id_token' => 'signed-google-token'])];
    };
    $verifier = function () use (&$captured, $claimOverrides) {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'google-client.example.apps.googleusercontent.com',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => $captured['nonce'],
            'sub' => 'google-subject-001',
            'email' => 'google.user@example.test',
            'email_verified' => true,
            'name' => 'Google User',
            'picture' => 'https://lh3.googleusercontent.com/avatar',
        ], $claimOverrides);
    };
    return new GoogleIntegration($pdo, new GoogleTestSettings(), $auth, $transport, $verifier);
}

function googleTestComplete(GoogleIntegration $integration, &$captured, $returnPath = '/projects/9')
{
    $attempt = $integration->beginAuthorization($returnPath);
    parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
    $captured['nonce'] = $query['nonce'];
    return $integration->completeCallback(['code' => 'one-time-code', 'state' => $query['state']], $attempt['attempt_token']);
}

$suite = new GoogleTestSuite();
$database = 'syndicatum_google_test_' . bin2hex(googleTestRandom(6));
$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
    putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
    putenv('PBB_AGENTCHAT_DB_USER=root');
    putenv('PBB_AGENTCHAT_DB_PASS=');
    putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(googleTestRandom(32)));
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo);

    $suite->test('authorization uses state, nonce, and PKCE S256', function () use ($suite, $pdo, $auth) {
        $captured = [];
        $integration = googleTestIntegration($pdo, $auth, $captured);
        $attempt = $integration->beginAuthorization('//outside.example');
        parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
        $suite->same('S256', $query['code_challenge_method']);
        $suite->same('openid email profile', $query['scope']);
        $suite->same('/', $pdo->query('SELECT return_path FROM google_oauth_attempts ORDER BY id DESC LIMIT 1')->fetchColumn());
        $suite->truthy(strlen($query['state']) >= 40 && strlen($query['nonce']) >= 40 && strlen($query['code_challenge']) >= 40);
    });

    $suite->test('callback creates ordinary Google user and explicit Google session', function () use ($suite, $pdo, $auth) {
        $captured = [];
        $result = googleTestComplete(googleTestIntegration($pdo, $auth, $captured), $captured);
        $userId = (int) $result['user']['id'];
        $suite->same('/projects/9', $result['return_path']);
        $suite->same('POST', $captured['method']);
        $suite->same(GoogleIntegration::TOKEN_URL, $captured['url']);
        $suite->truthy(isset($captured['payload']['code_verifier']));
        $suite->same('google', $pdo->query('SELECT auth_provider FROM syndicatum_sessions WHERE user_id = ' . $userId)->fetchColumn());
        $suite->same(['user'], $pdo->query('SELECT r.code FROM system_roles r JOIN user_system_roles ur ON ur.role_id = r.id WHERE ur.user_id = ' . $userId)->fetchAll(PDO::FETCH_COLUMN));
    });

    $suite->test('default verifier accepts a correctly signed Google identity token', function () use ($suite, $pdo, $auth) {
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'google-sso-test'], $privateKey, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        $certificatePem = '';
        openssl_x509_export($certificate, $certificatePem);
        $captured = [];
        $transport = function ($method, $url, $payload) use (&$captured, $privateKey, $certificatePem) {
            if ($method === 'GET') {
                return ['status' => 200, 'body' => json_encode(['test-key' => $certificatePem])];
            }
            $header = googleTestBase64Url(json_encode(['alg' => 'RS256', 'kid' => 'test-key', 'typ' => 'JWT']));
            $claims = googleTestBase64Url(json_encode([
                'iss' => 'https://accounts.google.com',
                'aud' => 'google-client.example.apps.googleusercontent.com',
                'exp' => time() + 300,
                'iat' => time(),
                'nonce' => $captured['nonce'],
                'sub' => 'google-signed-subject',
                'email' => 'signed.google@example.test',
                'email_verified' => true,
                'name' => 'Signed Google User',
            ]));
            openssl_sign($header . '.' . $claims, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            return ['status' => 200, 'body' => json_encode(['id_token' => $header . '.' . $claims . '.' . googleTestBase64Url($signature)])];
        };
        $integration = new GoogleIntegration($pdo, new GoogleTestSettings(), $auth, $transport);
        $attempt = $integration->beginAuthorization('/');
        parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
        $captured['nonce'] = $query['nonce'];
        $result = $integration->completeCallback(['code' => 'signed-code', 'state' => $query['state']], $attempt['attempt_token']);
        $suite->same('signed.google@example.test', $result['user']['email']);
    });

    $suite->test('Google identity binds by subject and callback is one-time', function () use ($suite, $pdo, $auth) {
        $captured = [];
        $integration = googleTestIntegration($pdo, $auth, $captured, ['name' => 'Updated Google User']);
        $attempt = $integration->beginAuthorization('/');
        parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
        $captured['nonce'] = $query['nonce'];
        $integration->completeCallback(['code' => 'returning', 'state' => $query['state']], $attempt['attempt_token']);
        $suite->same(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE google_subject = 'google-subject-001'")->fetchColumn());
        $suite->same('Updated Google User', $pdo->query("SELECT display_name FROM users WHERE google_subject = 'google-subject-001'")->fetchColumn());
        $suite->throws(function () use ($integration, $attempt, $query) {
            $integration->completeCallback(['code' => 'replay', 'state' => $query['state']], $attempt['attempt_token']);
        }, 'state is invalid');
    });

    $suite->test('verified-email collision is not silently linked', function () use ($suite, $pdo, $auth) {
        $now = Db::now();
        $pdo->prepare("INSERT INTO users (normalized_email, display_name, status, created_at, updated_at) VALUES (?, ?, 'active', ?, ?)")
            ->execute(['collision.google@example.test', 'Existing User', $now, $now]);
        $captured = [];
        $integration = googleTestIntegration($pdo, $auth, $captured, ['sub' => 'google-subject-collision', 'email' => 'collision.google@example.test']);
        $suite->throws(function () use ($integration, &$captured) { googleTestComplete($integration, $captured); }, 'linked deliberately');
        $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE google_subject = 'google-subject-collision'")->fetchColumn());
    });

    $suite->test('authenticated user deliberately links a matching Google identity', function () use ($suite, $pdo, $auth) {
        $native = $auth->register([
            'email' => 'link.google@example.test',
            'username' => 'link-google',
            'display_name' => 'Link Google',
            'password' => 'a secure native password',
            'password_confirmation' => 'a secure native password',
        ]);
        $_COOKIE[AuthService::SESSION_COOKIE] = $native['session']['token'];
        $captured = [];
        $integration = googleTestIntegration($pdo, $auth, $captured, [
            'sub' => 'google-subject-deliberate-link',
            'email' => 'link.google@example.test',
        ]);
        $attempt = $integration->beginLinkAuthorization($native['user']['id'], '/');
        parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
        $captured['nonce'] = $query['nonce'];
        $result = $integration->completeCallback(['code' => 'link-code', 'state' => $query['state']], $attempt['attempt_token']);
        $suite->same(true, $result['linked']);
        $suite->same(true, $result['user']['google_linked']);
        $suite->same(true, $result['avatar_synced']);
        $suite->same('https://lh3.googleusercontent.com/avatar', $result['user']['avatar_url']);
        $suite->same(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE google_subject = 'google-subject-deliberate-link'")->fetchColumn());
        $suite->same(1, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE normalized_email = 'link.google@example.test'")->fetchColumn());
        $refreshCaptured = [];
        $refresh = googleTestIntegration($pdo, $auth, $refreshCaptured, [
            'sub' => 'google-subject-deliberate-link',
            'email' => 'link.google@example.test',
            'picture' => 'https://lh3.googleusercontent.com/refreshed-avatar',
        ]);
        $refreshAttempt = $refresh->beginLinkAuthorization($native['user']['id'], '/');
        parse_str(parse_url($refreshAttempt['authorization_url'], PHP_URL_QUERY), $refreshQuery);
        $refreshCaptured['nonce'] = $refreshQuery['nonce'];
        $refreshed = $refresh->completeCallback(['code' => 'refresh-code', 'state' => $refreshQuery['state']], $refreshAttempt['attempt_token']);
        $suite->same('https://lh3.googleusercontent.com/refreshed-avatar', $refreshed['user']['avatar_url']);
        $wrongCaptured = [];
        $wrong = googleTestIntegration($pdo, $auth, $wrongCaptured, [
            'sub' => 'different-google-subject',
            'email' => 'different.google@example.test',
        ]);
        $wrongAttempt = $wrong->beginLinkAuthorization($native['user']['id'], '/');
        parse_str(parse_url($wrongAttempt['authorization_url'], PHP_URL_QUERY), $wrongQuery);
        $wrongCaptured['nonce'] = $wrongQuery['nonce'];
        $suite->throws(function () use ($wrong, $wrongAttempt, $wrongQuery) {
            $wrong->completeCallback(['code' => 'wrong-code', 'state' => $wrongQuery['state']], $wrongAttempt['attempt_token']);
        }, 'ALREADY_LINKED');
        unset($_COOKIE[AuthService::SESSION_COOKIE]);
    });

    $suite->test('Google linking preserves a manually uploaded avatar', function () use ($suite, $pdo, $auth) {
        $native = $auth->register([
            'email' => 'custom.avatar@example.test',
            'username' => 'custom-avatar',
            'display_name' => 'Custom Avatar',
            'password' => 'custom avatar password',
            'password_confirmation' => 'custom avatar password',
        ]);
        $localAvatar = 'api/v1/avatar.php?file=' . str_repeat('a', 40) . '.png';
        $pdo->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$localAvatar, $native['user']['id']]);
        $_COOKIE[AuthService::SESSION_COOKIE] = $native['session']['token'];
        $captured = [];
        $integration = googleTestIntegration($pdo, $auth, $captured, [
            'sub' => 'google-subject-custom-avatar',
            'email' => 'custom.avatar@example.test',
            'picture' => 'https://lh3.googleusercontent.com/google-avatar',
        ]);
        $attempt = $integration->beginLinkAuthorization($native['user']['id'], '/');
        parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
        $captured['nonce'] = $query['nonce'];
        $result = $integration->completeCallback(['code' => 'custom-avatar-code', 'state' => $query['state']], $attempt['attempt_token']);
        $suite->same(false, $result['avatar_synced']);
        $suite->same($localAvatar, $result['user']['avatar_url']);
        unset($_COOKIE[AuthService::SESSION_COOKIE]);
    });

    $suite->test('Google linking requires the initiating Syndicatum session at callback', function () use ($suite, $pdo, $auth) {
        $native = $auth->register([
            'email' => 'expired.link@example.test',
            'username' => 'expired-link',
            'display_name' => 'Expired Link',
            'password' => 'another secure native password',
            'password_confirmation' => 'another secure native password',
        ]);
        $captured = [];
        $integration = googleTestIntegration($pdo, $auth, $captured, [
            'sub' => 'google-subject-expired-link',
            'email' => 'expired.link@example.test',
        ]);
        $attempt = $integration->beginLinkAuthorization($native['user']['id'], '/');
        parse_str(parse_url($attempt['authorization_url'], PHP_URL_QUERY), $query);
        $captured['nonce'] = $query['nonce'];
        unset($_COOKIE[AuthService::SESSION_COOKIE]);
        $suite->throws(function () use ($integration, $attempt, $query) {
            $integration->completeCallback(['code' => 'expired-link-code', 'state' => $query['state']], $attempt['attempt_token']);
        }, 'LINK_SESSION_EXPIRED');
        $suite->same(0, (int) $pdo->query("SELECT COUNT(*) FROM users WHERE google_subject = 'google-subject-expired-link'")->fetchColumn());
    });
} finally {
    if ($admin instanceof PDO && preg_match('/^syndicatum_google_test_[a-f0-9]{12}$/', $database)) {
        $admin->exec('DROP DATABASE `' . $database . '`');
    }
}
exit($suite->finish());
