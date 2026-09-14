<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';

class RegistrationTestSuite
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
    public function finish() { echo "\n{$this->passed} passed, {$this->failed} failed.\n"; return $this->failed === 0 ? 0 : 1; }
}

$suite = new RegistrationTestSuite();
$database = 'syndicatum_registration_test_' . bin2hex(random_bytes(6));
$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
    putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
    putenv('PBB_AGENTCHAT_DB_USER=root');
    putenv('PBB_AGENTCHAT_DB_PASS=');
    putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32)));
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo);

    $suite->test('registration creates only an ordinary user, workspace, and native session', function () use ($suite, $pdo, $auth) {
        $result = $auth->register([
            'email' => 'new.user@example.test', 'username' => 'new.user', 'display_name' => 'New User',
            'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
        ]);
        $userId = (int) $result['user']['id'];
        $suite->same(['user'], $result['user']['system_roles']);
        $suite->truthy($result['user']['workspace'] !== null);
        $suite->same('native', $pdo->query('SELECT auth_provider FROM syndicatum_sessions WHERE user_id = ' . $userId)->fetchColumn());
        $suite->truthy($auth->currentUser($result['session']['token']) !== null);
        $suite->same(AuthService::PERSISTENT_SESSION_EXPIRES_AT, $pdo->query('SELECT expires_at FROM syndicatum_sessions WHERE user_id = ' . $userId)->fetchColumn());

        $pdo->exec("UPDATE syndicatum_sessions SET expires_at = '2000-01-01 00:00:00' WHERE user_id = " . $userId);
        $suite->truthy($auth->currentUser($result['session']['token']) !== null);
        $suite->same(AuthService::PERSISTENT_SESSION_EXPIRES_AT, $pdo->query('SELECT expires_at FROM syndicatum_sessions WHERE user_id = ' . $userId)->fetchColumn());

        $auth->logout($result['session']['token']);
        $suite->same(null, $auth->currentUser($result['session']['token']));
    });

    $suite->test('registration rejects duplicate identities and mismatched confirmation', function () use ($suite, $auth) {
        $suite->throws(function () use ($auth) {
            $auth->register([
                'email' => 'new.user@example.test', 'username' => 'another-user', 'display_name' => 'Duplicate',
                'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
            ]);
        }, 'already registered');
        $suite->throws(function () use ($auth) {
            $auth->register([
                'email' => 'other@example.test', 'username' => 'other-user', 'display_name' => 'Other',
                'password' => 'a-secure-password', 'password_confirmation' => 'different-password',
            ]);
        }, 'does not match');
    });
} finally {
    if ($admin instanceof PDO && preg_match('/^syndicatum_registration_test_[a-f0-9]{12}$/', $database)) {
        $admin->exec('DROP DATABASE `' . $database . '`');
    }
}
exit($suite->finish());
