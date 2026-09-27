<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';

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
$mailCaptureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-registration-mail-' . bin2hex(random_bytes(6));
$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
    putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
    putenv('PBB_AGENTCHAT_DB_USER=root');
    putenv('PBB_AGENTCHAT_DB_PASS=');
    putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32)));
    putenv('SYNDICATUM_MAIL_CAPTURE_DIR=' . $mailCaptureRoot);
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo);
    $settings = new SettingsService($pdo);
    $settings->update([
        'general.public_origin' => 'https://syndicatum.example.test',
        'mail.enabled' => true,
        'mail.sender_name' => 'Syndicatum',
        'mail.sender_address' => 'notifications@example.test',
    ], null);

    $suite->test('native registration requires email activation before creating a session and sends one welcome message', function () use ($suite, $pdo, $auth, $mailCaptureRoot) {
        $result = $auth->registerForActivation([
            'email' => 'new.user@example.test', 'username' => 'new.user', 'display_name' => 'New User',
            'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
        ]);
        $suite->same(true, $result['pending_activation']);
        $userId = (int) $pdo->query("SELECT id FROM users WHERE normalized_email = 'new.user@example.test'")->fetchColumn();
        $suite->same(0, (int) $pdo->query('SELECT COUNT(*) FROM syndicatum_sessions WHERE user_id = ' . $userId)->fetchColumn());
        $suite->throws(function () use ($auth) {
            $auth->login('new.user@example.test', 'a-secure-password');
        }, 'ACCOUNT_ACTIVATION_REQUIRED');
        $captures = glob($mailCaptureRoot . DIRECTORY_SEPARATOR . '*.eml') ?: [];
        $suite->same(1, count($captures));
        $activationEmail = file_get_contents($captures[0]);
        $suite->truthy(strpos($activationEmail, 'registration_activation; version=1') !== false);
        $suite->truthy(preg_match('/#activate=([A-Za-z0-9_-]+)/', $activationEmail, $match) === 1);
        $firstToken = $match[1];
        $auth->registerForActivation([
            'email' => 'new.user@example.test', 'username' => 'new.user', 'display_name' => 'New User',
            'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
        ]);
        $replacementToken = null;
        foreach (glob($mailCaptureRoot . DIRECTORY_SEPARATOR . '*.eml') ?: [] as $capture) {
            if (preg_match('/#activate=([A-Za-z0-9_-]+)/', file_get_contents($capture), $candidate) === 1
                && $candidate[1] !== $firstToken) {
                $replacementToken = $candidate[1];
            }
        }
        $suite->truthy(is_string($replacementToken));
        $suite->same(2, count(glob($mailCaptureRoot . DIRECTORY_SEPARATOR . '*.eml') ?: []));
        $suite->throws(function () use ($auth, $firstToken) { $auth->activateRegistration($firstToken); }, 'invalid or expired');
        $activated = $auth->activateRegistration($replacementToken);
        $suite->same('new.user@example.test', $activated['user']['email']);
        $suite->same(['user'], $activated['user']['system_roles']);
        $suite->truthy($activated['user']['workspace'] !== null);
        $suite->truthy($auth->currentUser($activated['session']['token']) !== null);
        $suite->same('native', $pdo->query('SELECT auth_provider FROM syndicatum_sessions WHERE user_id = ' . $userId)->fetchColumn());
        $suite->truthy($pdo->query('SELECT consumed_at FROM user_registration_activations WHERE user_id = ' . $userId)->fetchColumn() !== null);
        $suite->throws(function () use ($auth, $replacementToken) { $auth->activateRegistration($replacementToken); }, 'invalid or expired');
        $captures = glob($mailCaptureRoot . DIRECTORY_SEPARATOR . '*.eml') ?: [];
        $suite->same(3, count($captures));
        $allMail = implode("\n", array_map('file_get_contents', $captures));
        $suite->truthy(strpos($allMail, 'welcome; version=1') !== false);
        $auth->logout($activated['session']['token']);
        $login = $auth->login('new.user@example.test', 'a-secure-password');
        $suite->truthy($auth->currentUser($login['session']['token']) !== null);
        $suite->same(3, count(glob($mailCaptureRoot . DIRECTORY_SEPARATOR . '*.eml') ?: []));
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
    foreach (glob($mailCaptureRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $path) { @unlink($path); }
    @rmdir($mailCaptureRoot);
    putenv('SYNDICATUM_MAIL_CAPTURE_DIR');
}
exit($suite->finish());
