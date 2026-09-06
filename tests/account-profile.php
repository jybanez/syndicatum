<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';

class AccountProfileTestSuite
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

    public function same($expected, $actual)
    {
        if ($expected !== $actual) {
            throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public function truthy($condition, $message = 'Assertion failed.')
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function throws($callback, $message)
    {
        try {
            call_user_func($callback);
        } catch (Exception $exception) {
            if (strpos($exception->getMessage(), $message) === false) {
                throw new RuntimeException('Unexpected exception: ' . $exception->getMessage());
            }
            return;
        }
        throw new RuntimeException('Expected exception containing ' . $message);
    }

    public function finish()
    {
        echo "\n" . $this->passed . ' passed, ' . $this->failed . " failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

function accountProfileRandom($bytes)
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

$suite = new AccountProfileTestSuite();
$database = 'syndicatum_account_profile_' . bin2hex(accountProfileRandom(6));
if (!preg_match('/^syndicatum_account_profile_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe account profile test database name.');
}
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root');
putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(accountProfileRandom(32)));

try {
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo);
    $administrator = $auth->bootstrapAdministrator('profile@example.test', 'Profile Admin', 'original password value');

    $suite->test('optional Account profile URL is safe, non-secret configuration', function () use ($suite, $pdo, $administrator) {
        $settings = new SettingsService($pdo);
        $suite->same('', $settings->get('account.profile_url'));
        $settings->update(['account.profile_url' => 'https://account.example.test/profile'], $administrator['id']);
        $suite->same('https://account.example.test/profile', $settings->get('account.profile_url'));
        $suite->same('https://account.example.test/profile', $settings->publicSettings('integrations')['account.profile_url']['value']);
        $suite->throws(function () use ($settings, $administrator) {
            $settings->update(['account.profile_url' => 'javascript:alert(1)'], $administrator['id']);
        }, 'absolute URL');
    });
    $firstLogin = $auth->login('profile@example.test', 'original password value');
    $secondLogin = $auth->login('profile@example.test', 'original password value');
    $currentUser = $auth->currentUser($firstLogin['session']['token']);

    $suite->test('current user profile updates only safe profile fields', function () use ($suite, $auth, $currentUser, $pdo) {
        $managedAvatar = 'api/v1/avatar.php?file=' . str_repeat('a', 40) . '.webp';
        $updated = $auth->updateProfile($currentUser, [
            'display_name' => 'Updated Profile',
            'avatar_url' => $managedAvatar,
        ]);
        $suite->same('Updated Profile', $updated['display_name']);
        $suite->same($managedAvatar, $updated['avatar_url']);
        $suite->same(true, $updated['has_native_password']);
        $suite->throws(function () use ($auth, $currentUser) {
            $auth->updateProfile($currentUser, ['display_name' => 'Unsafe', 'avatar_url' => 'https://images.example.test/profile.webp']);
        }, 'uploaded Syndicatum image');
        $suite->same('profile@example.test', $pdo->query('SELECT normalized_email FROM users WHERE id = ' . (int) $currentUser['id'])->fetchColumn());
    });

    $suite->test('password change validates current password and confirmation', function () use ($suite, $auth, $currentUser) {
        $suite->throws(function () use ($auth, $currentUser) {
            $auth->changePassword($currentUser, 'wrong password', 'replacement password', 'replacement password');
        }, 'INVALID_CURRENT_PASSWORD');
        $suite->throws(function () use ($auth, $currentUser) {
            $auth->changePassword($currentUser, 'original password value', 'replacement password', 'different password');
        }, 'must match');
    });

    $rotated = null;
    $suite->test('password change rotates current evidence and revokes every other session atomically', function () use ($suite, $auth, $currentUser, $firstLogin, $secondLogin, &$rotated, $pdo) {
        $rotated = $auth->changePassword($currentUser, 'original password value', 'replacement password', 'replacement password');
        $suite->same(null, $auth->currentUser($firstLogin['session']['token']));
        $suite->same(null, $auth->currentUser($secondLogin['session']['token']));
        $suite->truthy($auth->currentUser($rotated['session']['token']) !== null);
        $suite->throws(function () use ($auth) {
            $auth->login('profile@example.test', 'original password value');
        }, 'Invalid sign-in credentials');
        $suite->truthy($auth->login('profile@example.test', 'replacement password')['user']['has_native_password']);
        $audit = $pdo->query("SELECT metadata_json FROM administrative_audit_events WHERE action = 'password.changed' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $suite->truthy(strpos((string) $audit, 'password') === false, 'Audit metadata leaked password-labelled data.');
    });

    $suite->test('Account-only users cannot create a native password through password change', function () use ($suite, $pdo, $auth) {
        $now = Db::now();
        $pdo->prepare("INSERT INTO users (normalized_email, display_name, pbb_user_id, status, created_at, updated_at) VALUES (?, ?, ?, 'active', ?, ?)")
            ->execute(['account-only@example.test', 'Account Only', 'PBB-ACCOUNT-ONLY', $now, $now]);
        $userId = (int) $pdo->lastInsertId();
        $session = $auth->createSession($userId, 'account-session-only');
        $user = $auth->currentUser($session['token']);
        $suite->same(false, $user['has_native_password']);
        $suite->throws(function () use ($auth, $user) {
            $auth->changePassword($user, 'anything', 'new native password', 'new native password');
        }, 'PASSWORD_MANAGED_BY_ACCOUNT');
        $suite->same(null, $pdo->query('SELECT password_hash FROM users WHERE id = ' . $userId)->fetchColumn());
        $suite->truthy($auth->currentUser($session['token']) !== null);
    });
} finally {
    if (!preg_match('/^syndicatum_account_profile_[a-f0-9]{12}$/', $database)) {
        throw new RuntimeException('Refusing to drop unsafe account profile test database.');
    }
    $admin->exec('DROP DATABASE `' . $database . '`');
}

exit($suite->finish());
