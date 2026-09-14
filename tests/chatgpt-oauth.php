<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/AgentActivationService.php';
require_once dirname(__DIR__) . '/src/ChatGptOAuthService.php';

class ChatGptOAuthTests
{
    private $passed = 0; private $failed = 0;
    public function test($name, callable $callback) { try { $callback(); $this->passed++; echo "PASS  $name\n"; } catch (Exception $e) { $this->failed++; echo "FAIL  $name: {$e->getMessage()}\n"; } }
    public function same($expected, $actual) { if ($expected !== $actual) { throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); } }
    public function true($value, $message = 'Expected true.') { if (!$value) { throw new RuntimeException($message); } }
    public function throws(callable $callback, $expected) { try { $callback(); } catch (Exception $e) { if ($e->getMessage() === $expected) { return; } throw $e; } throw new RuntimeException('Expected ' . $expected); }
    public function finish() { echo "\n{$this->passed} passed, {$this->failed} failed.\n"; return $this->failed ? 1 : 0; }
}

function chatGptBase64Url($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

$suite = new ChatGptOAuthTests();
$database = 'syndicatum_chatgpt_test_' . bin2hex(random_bytes(5));
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1'); putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root'); putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32)));

try {
    $pdo = Db::pdo(); (new ChatRepository($pdo))->installSchema();
    $auth = new AuthService($pdo);
    $owner = $auth->bootstrapAdministrator('chatgpt-owner@example.test', 'ChatGPT Owner', 'correct horse battery staple');
    $management = new ProjectManagementService($pdo);
    $project = $management->createProject($owner['id'], ['name' => 'ChatGPT Project']);
    $agent = $management->createAgent($project['id'], $owner['id'], ['display_name' => 'ChatGPT Agent', 'provider' => 'chatgpt']);
    $activation = new AgentActivationService($pdo);
    $binding = $activation->configure($project['id'], $agent['agent_id'], $owner['id'], [
        'enabled' => false, 'provider' => 'chatgpt',
        'discussion_reference' => 'https://chatgpt.com/c/46604e19-202c-4224-bb05-d2ddf5f58c9f',
        'working_directory' => 'C:\\ignored',
    ]);
    $oauth = new ChatGptOAuthService($pdo, 'https://syndicatum.example.test');

    $suite->test('ChatGPT provider can use OAuth while proactive activation remains disabled', function () use ($suite, $binding) {
        $suite->same('chatgpt', $binding['provider']);
        $suite->same(false, $binding['enabled']);
        $suite->same('https://chatgpt.com/c/46604e19-202c-4224-bb05-d2ddf5f58c9f', $binding['conversation_id']);
        $suite->same('', $binding['working_directory']);
    });

    $suite->test('only manageable ChatGPT agents are offered for consent', function () use ($suite, $oauth, $owner, $project, $agent) {
        $agents = $oauth->manageableChatGptAgents($owner['id']);
        $suite->same(1, count($agents));
        $suite->same($project['id'], (int) $agents[0]['project_id']);
        $suite->same($agent['agent_id'], (int) $agents[0]['agent_id']);
    });

    $client = $oauth->registerClient(['client_name' => 'ChatGPT test', 'redirect_uris' => ['https://chatgpt.com/aip/oauth/callback']]);
    $suite->test('dynamic registration accepts ChatGPT and rejects arbitrary HTTPS callbacks', function () use ($suite, $oauth, $client) {
        $suite->true(strpos($client['client_id'], 'syndicatum-') === 0);
        $suite->throws(function () use ($oauth) { $oauth->registerClient(['redirect_uris' => ['https://attacker.example/callback']]); }, 'invalid_redirect_uri');
    });

    $verifier = chatGptBase64Url(random_bytes(48));
    $challenge = chatGptBase64Url(hash('sha256', $verifier, true));
    $requestInput = ['response_type' => 'code', 'client_id' => $client['client_id'],
        'redirect_uri' => $client['redirect_uris'][0], 'resource' => $oauth->resource(),
        'scope' => implode(' ', ChatGptOAuthService::SCOPES), 'state' => 'opaque-state',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
    $request = $oauth->authorizationRequest($requestInput);
    $code = $oauth->issueAuthorizationCode($request, $owner['id'], $project['id'], $agent['agent_id']);
    $tokenInput = ['grant_type' => 'authorization_code', 'client_id' => $client['client_id'],
        'redirect_uri' => $client['redirect_uris'][0], 'resource' => $oauth->resource(),
        'code' => $code, 'code_verifier' => $verifier];
    $tokens = $oauth->exchangeAuthorizationCode($tokenInput);

    $suite->test('authorization code is PKCE-bound, one-time, and project-agent scoped', function () use ($suite, $oauth, $tokens, $tokenInput, $project, $agent) {
        $access = $oauth->authenticate($tokens['access_token']);
        $suite->same($project['id'], $access['project_id']);
        $suite->same($agent['agent_id'], (int) $access['identity']['agent']['authenticated_agent_id']);
        $suite->same(ChatGptOAuthService::SCOPES, $access['scope']);
        $suite->throws(function () use ($oauth, $tokenInput) { $oauth->exchangeAuthorizationCode($tokenInput); }, 'invalid_grant');
    });

    $refreshed = $oauth->refresh(['grant_type' => 'refresh_token', 'client_id' => $client['client_id'],
        'resource' => $oauth->resource(), 'refresh_token' => $tokens['refresh_token']]);
    $suite->test('refresh rotation revokes the previous access and refresh tokens', function () use ($suite, $oauth, $tokens, $refreshed, $client) {
        $suite->same(null, $oauth->authenticate($tokens['access_token']));
        $suite->true($oauth->authenticate($refreshed['access_token']) !== null);
        $suite->throws(function () use ($oauth, $tokens, $client) {
            $oauth->refresh(['client_id' => $client['client_id'], 'resource' => $oauth->resource(), 'refresh_token' => $tokens['refresh_token']]);
        }, 'invalid_grant');
    });

    $suite->test('revocation invalidates an issued token family', function () use ($suite, $oauth, $refreshed, $client) {
        $oauth->revoke(['token' => $refreshed['refresh_token'], 'client_id' => $client['client_id']]);
        $suite->same(null, $oauth->authenticate($refreshed['access_token']));
    });

    exit($suite->finish());
} finally {
    if (isset($pdo)) { $pdo = null; }
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
