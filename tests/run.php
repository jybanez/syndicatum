<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

class TestSuite
{
    private $passed = 0;
    private $failed = 0;

    public function test($name, callable $callback)
    {
        try {
            $callback();
            $this->passed++;
            echo "PASS  " . $name . PHP_EOL;
        } catch (Exception $exception) {
            $this->failed++;
            echo "FAIL  " . $name . PHP_EOL;
            echo "      " . $exception->getMessage() . PHP_EOL;
        } catch (Error $error) {
            $this->failed++;
            echo "FAIL  " . $name . PHP_EOL;
            echo "      " . $error->getMessage() . PHP_EOL;
        }
    }

    public function assertTrue($condition, $message = 'Expected condition to be true.')
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function assertSame($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            $detail = $message !== '' ? $message . ' ' : '';
            throw new RuntimeException($detail . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
        }
    }

    public function assertThrows($className, callable $callback, $messageContains = '')
    {
        try {
            $callback();
        } catch (Exception $exception) {
            if (!($exception instanceof $className)) {
                throw new RuntimeException('Expected ' . $className . ', got ' . get_class($exception) . '.');
            }
            if ($messageContains !== '' && stripos($exception->getMessage(), $messageContains) === false) {
                throw new RuntimeException('Exception message did not contain: ' . $messageContains);
            }
            return;
        }

        throw new RuntimeException('Expected ' . $className . ' to be thrown.');
    }

    public function finish()
    {
        echo PHP_EOL . sprintf('%d passed, %d failed.', $this->passed, $this->failed) . PHP_EOL;
        return $this->failed === 0 ? 0 : 1;
    }
}

function insertAgent(PDO $pdo, $name, $role, $token, $secretVersion, $secret, $active = true)
{
    $now = Db::now();
    $statement = $pdo->prepare(
        'INSERT INTO chat_agents
         (project_name, description, token_prefix, token_hash, token_secret_version, role, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $name,
        $name . ' test project',
        substr($token, 0, 24),
        hash_hmac('sha256', $token, $secret),
        $secretVersion,
        $role,
        $active ? 1 : 0,
        $now,
        $now,
    ]);

    return (int) $pdo->lastInsertId();
}

function httpRequest($baseUrl, $method, $path, array $headers = [], $body = null)
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
    if (!empty($headers)) {
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    }

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
    $responseHeaders = [];
    foreach (preg_split('/\r\n|\n|\r/', trim($headerText)) ?: [] as $line) {
        $position = strpos($line, ':');
        if ($position === false) {
            continue;
        }
        $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => substr($raw, $headerSize),
    ];
}

function decodedBody(array $response)
{
    if ($response['body'] === '') {
        return null;
    }
    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Response was not a JSON object: ' . substr($response['body'], 0, 200));
    }
    return $decoded;
}

function sessionQuestionCount(PDO $pdo)
{
    $row = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch();
    return (int) $row['Value'];
}

function reservePort()
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!$socket) {
        throw new RuntimeException('Unable to reserve test port: ' . $errorMessage);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr($name, ':'), 1);
}

function startServer($root, $port, array $environment)
{
    $logPath = tempnam(sys_get_temp_dir(), 'syndicatum-http-test-');
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root];
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $root, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP test server.');
    }
    fclose($pipes[0]);

    $baseUrl = 'http://127.0.0.1:' . $port;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        try {
            $response = httpRequest($baseUrl, 'GET', '/');
            if ($response['status'] === 200) {
                return [$process, $baseUrl, $logPath];
            }
        } catch (Exception $ignored) {
        }
    }

    proc_terminate($process);
    $log = is_file($logPath) ? file_get_contents($logPath) : '';
    throw new RuntimeException('PHP test server failed to start. ' . trim($log));
}

$suite = new TestSuite();
$root = dirname(__DIR__);
$database = 'syndicatum_test_' . bin2hex(random_bytes(6));
$emptyDatabase = $database . '_empty';
if (!preg_match('/^syndicatum_test_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe test database name.');
}
if (!preg_match('/^syndicatum_test_[a-f0-9]{12}_empty$/', $emptyDatabase)) {
    throw new RuntimeException('Unsafe empty test database name.');
}

$primarySecret = bin2hex(random_bytes(32));
$previousSecret = bin2hex(random_bytes(32));
$aliceToken = 'test_alice_' . bin2hex(random_bytes(24));
$bobToken = 'test_bob_' . bin2hex(random_bytes(24));
$adminToken = 'test_admin_' . bin2hex(random_bytes(24));

$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->exec('CREATE DATABASE `' . $emptyDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root');
putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . $primarySecret);
putenv('PBB_AGENTCHAT_PREVIOUS_SECRET=' . $previousSecret);

$server = null;
$serverLog = null;

try {
    $pdo = Db::pdo();
    $repository = new ChatRepository($pdo);
    $repository->installSchema();

    $aliceId = insertAgent($pdo, 'Alice Project', 'agent', $aliceToken, 'previous', $previousSecret);
    $bobId = insertAgent($pdo, 'Bob Project', 'agent', $bobToken, 'primary', $primarySecret);
    $adminId = insertAgent($pdo, 'Admin Project', 'admin', $adminToken, 'primary', $primarySecret);
    insertAgent($pdo, 'Inactive Project', 'agent', 'test_inactive_' . bin2hex(random_bytes(24)), 'primary', $primarySecret, false);

    $suite->test('schema installs with credential-version columns', function () use ($suite, $repository, $pdo) {
        $suite->assertTrue($repository->hasSchema());
        $suite->assertTrue(Db::columnExists($pdo, 'chat_agents', 'token_secret_version'));
        $suite->assertTrue(Db::columnExists($pdo, 'chat_agents', 'claim_secret_version'));
    });

    $suite->test('credential hashing fails closed without configured secrets', function () use ($suite, $primarySecret, $previousSecret) {
        putenv('PBB_AGENTCHAT_SECRET');
        putenv('PBB_AGENTCHAT_PREVIOUS_SECRET');
        putenv('PBB_AGENTCHAT_SECRETS_FILE=' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-syndicatum-secrets.php');
        try {
            $suite->assertThrows('RuntimeException', function () {
                Db::hashToken('probe');
            }, 'PBB_AGENTCHAT_SECRET is required');
        } finally {
            putenv('PBB_AGENTCHAT_SECRET=' . $primarySecret);
            putenv('PBB_AGENTCHAT_PREVIOUS_SECRET=' . $previousSecret);
            putenv('PBB_AGENTCHAT_SECRETS_FILE');
        }
    });

    $suite->test('legacy token authenticates and migrates to the primary secret', function () use ($suite, $repository, $pdo, $aliceToken, $aliceId, $primarySecret) {
        $agent = $repository->authenticate($aliceToken);
        $suite->assertSame('Alice Project', $agent['project_name']);
        $statement = $pdo->prepare('SELECT token_hash, token_secret_version FROM chat_agents WHERE id = ?');
        $statement->execute([$aliceId]);
        $row = $statement->fetch();
        $suite->assertSame('primary', $row['token_secret_version']);
        $suite->assertSame(hash_hmac('sha256', $aliceToken, $primarySecret), $row['token_hash']);
        $suite->assertSame('Alice Project', $repository->authenticate($aliceToken)['project_name']);
    });

    $suite->test('invalid and inactive tokens are rejected', function () use ($suite, $repository) {
        $suite->assertSame(null, $repository->authenticate('invalid-token'));
        $suite->assertSame(null, $repository->authenticate(''));
    });

    $alice = $repository->authenticate($aliceToken);
    $bob = $repository->authenticate($bobToken);
    $administrator = $repository->authenticate($adminToken);

    $suite->test('routine primary-token authentication does not invalidate the feed ETag', function () use ($suite, $repository, $bobToken) {
        $before = $repository->feedVersion()['etag'];
        $suite->assertSame('Bob Project', $repository->authenticate($bobToken)['project_name']);
        $after = $repository->feedVersion()['etag'];
        $suite->assertSame($before, $after);
    });

    $suite->test('sender is derived from authentication and submitted sender is ignored', function () use ($suite, $repository, $alice) {
        $entry = $repository->createEntry($alice, ['sender' => 'Admin Project', 'body' => 'Authenticated sender test']);
        $suite->assertSame('Alice Project', $entry['sender']);
        $suite->assertSame(false, $entry['is_direct']);
    });

    $directEntry = null;
    $suite->test('targets are validated, deduplicated, and exclude the sender', function () use ($suite, $repository, $alice, &$directEntry) {
        $directEntry = $repository->createEntry($alice, [
            'body' => 'Target normalization test',
            'targets' => ['Bob Project', 'Bob Project', 'Alice Project'],
        ]);
        $suite->assertSame(['Bob Project'], $directEntry['targets']);
        $suite->assertThrows('InvalidArgumentException', function () use ($repository, $alice) {
            $repository->createEntry($alice, ['body' => 'Bad target', 'targets' => ['Missing Project']]);
        }, 'Unknown or inactive target');
    });

    $suite->test('message recipient loading uses a fixed number of queries', function () use ($suite, $repository, $pdo, $alice) {
        for ($index = 0; $index < 20; $index++) {
            $repository->createEntry($alice, [
                'body' => 'Batch recipient query test ' . $index,
                'targets' => ['Bob Project'],
            ]);
        }
        $before = sessionQuestionCount($pdo);
        $messages = $repository->messages(['order' => 'asc']);
        $after = sessionQuestionCount($pdo);
        $suite->assertTrue(count($messages) >= 20);
        $suite->assertTrue(($after - $before) <= 4, 'Message loading executed too many database queries: ' . ($after - $before));
    });

    $suite->test('message ownership and revision history are enforced', function () use ($suite, $repository, $pdo, $alice, $bob, &$directEntry) {
        $id = $directEntry['db_id'];
        $suite->assertThrows('RuntimeException', function () use ($repository, $id, $bob) {
            $repository->updateEntry($id, $bob, ['body' => 'Unauthorized edit']);
        }, 'Only the sender or an admin');
        $updated = $repository->updateEntry($id, $alice, ['body' => 'Authorized edit']);
        $suite->assertSame('Authorized edit', $updated['body']);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM chat_entry_revisions WHERE entry_id = ?');
        $statement->execute([$id]);
        $suite->assertSame(1, (int) $statement->fetchColumn());
    });

    $suite->test('administrators can edit and soft-delete another sender message', function () use ($suite, $repository, $pdo, $administrator, &$directEntry) {
        $id = $directEntry['db_id'];
        $repository->updateEntry($id, $administrator, ['body' => 'Administrative correction']);
        $repository->deleteEntry($id, $administrator);
        $suite->assertSame(null, $repository->entryById($id));
        $statement = $pdo->prepare('SELECT deleted_at FROM chat_entries WHERE id = ?');
        $statement->execute([$id]);
        $suite->assertTrue($statement->fetchColumn() !== null);
    });

    $suite->test('topic ownership and migrated-topic admin rules are enforced', function () use ($suite, $repository, $pdo, $alice, $bob, $administrator) {
        $topic = $repository->createTopic($alice, ['body' => 'Owned topic']);
        $suite->assertThrows('RuntimeException', function () use ($repository, $topic, $bob) {
            $repository->updateTopic($topic['id'], $bob, ['body' => 'Unauthorized topic edit']);
        }, 'Only the creator or an admin');
        $updated = $repository->updateTopic($topic['id'], $administrator, ['body' => 'Admin topic edit']);
        $suite->assertSame('Admin topic edit', $updated['body']);

        $now = Db::now();
        $statement = $pdo->prepare('INSERT INTO chat_topics (body, is_active, created_at, updated_at) VALUES (?, 1, ?, ?)');
        $statement->execute(['Imported topic without owner', $now, $now]);
        $importedId = (int) $pdo->lastInsertId();
        $suite->assertThrows('RuntimeException', function () use ($repository, $importedId, $alice) {
            $repository->deleteTopic($importedId, $alice);
        }, 'Only an admin');
        $suite->assertTrue($repository->deleteTopic($importedId, $administrator));
    });

    $suite->test('public agent responses do not expose credential hashes', function () use ($suite, $repository) {
        foreach ($repository->publicAgents() as $agent) {
            $suite->assertTrue(!array_key_exists('token_hash', $agent));
            $suite->assertTrue(!array_key_exists('claim_hash', $agent));
        }
    });

    $suite->test('payload ETag changes after a message write', function () use ($suite, $repository, $alice) {
        $before = $repository->payload()['meta']['etag'];
        $repository->createEntry($alice, ['body' => 'ETag change test']);
        $after = $repository->payload()['meta']['etag'];
        $suite->assertTrue($before !== $after);
    });

    $suite->test('feed version matches materialized payload metadata', function () use ($suite, $repository) {
        $version = $repository->feedVersion();
        $payload = $repository->payload($version);
        $suite->assertSame($version['etag'], $payload['meta']['etag']);
        $suite->assertSame($version['message_count'], $payload['meta']['message_count']);
        $suite->assertSame($version['direct_count'], $payload['meta']['direct_count']);
    });

    $environment = getenv();
    $environment['PBB_AGENTCHAT_DB_HOST'] = '127.0.0.1';
    $environment['PBB_AGENTCHAT_DB_NAME'] = $database;
    $environment['PBB_AGENTCHAT_DB_USER'] = 'root';
    $environment['PBB_AGENTCHAT_DB_PASS'] = '';
    $environment['PBB_AGENTCHAT_SECRET'] = $primarySecret;
    $environment['PBB_AGENTCHAT_PREVIOUS_SECRET'] = $previousSecret;
    $port = reservePort();
    list($server, $baseUrl, $serverLog) = startServer($root, $port, $environment);

    $suite->test('read API returns public agents without hashes', function () use ($suite, $baseUrl) {
        $response = httpRequest($baseUrl, 'GET', '/api/chat-agents.php?active=1');
        $suite->assertSame(200, $response['status']);
        $body = decodedBody($response);
        $suite->assertTrue(count($body['data']) > 0);
        $suite->assertTrue(!array_key_exists('token_hash', $body['data'][0]));
    });

    $suite->test('message pagination has stable cursors without duplicates', function () use ($suite, $baseUrl) {
        $seen = [];
        $cursor = '';
        $pages = 0;
        do {
            $path = '/api/chat-entries.php?limit=5&order=desc' . ($cursor !== '' ? '&before=' . rawurlencode($cursor) : '');
            $response = httpRequest($baseUrl, 'GET', $path);
            $suite->assertSame(200, $response['status']);
            $body = decodedBody($response);
            foreach ($body['data'] as $message) {
                $suite->assertTrue(!isset($seen[$message['db_id']]), 'Duplicate message across cursor pages.');
                $seen[$message['db_id']] = true;
            }
            $cursor = $body['page']['older_cursor'] ?: '';
            $hasMore = $body['page']['has_more'];
            $pages++;
            $suite->assertTrue($pages < 20, 'Cursor traversal did not terminate.');
        } while ($hasMore);
        $suite->assertTrue(count($seen) >= 20);
    });

    $suite->test('message pagination rejects malformed and conflicting cursors', function () use ($suite, $baseUrl) {
        $invalid = httpRequest($baseUrl, 'GET', '/api/chat-entries.php?limit=5&before=not-a-cursor');
        $conflicting = httpRequest($baseUrl, 'GET', '/api/chat-entries.php?limit=5&before=not-a-cursor&after=also-bad');
        $suite->assertSame(422, $invalid['status']);
        $suite->assertSame(422, $conflicting['status']);
    });

    $suite->test('newer cursor returns messages created after the loaded page', function () use ($suite, $baseUrl, $aliceToken) {
        $initial = decodedBody(httpRequest($baseUrl, 'GET', '/api/chat-entries.php?limit=3'));
        $cursor = $initial['page']['newer_cursor'];
        $created = decodedBody(httpRequest($baseUrl, 'POST', '/api/chat-entries.php', ['Authorization: Bearer ' . $aliceToken], [
            'body' => 'Incremental cursor test',
        ]));
        $response = httpRequest($baseUrl, 'GET', '/api/chat-entries.php?limit=3&after=' . rawurlencode($cursor));
        $suite->assertSame(200, $response['status']);
        $body = decodedBody($response);
        $ids = array_column($body['data'], 'db_id');
        $suite->assertTrue(in_array($created['data']['db_id'], $ids, true));
        $suite->assertSame('asc', $body['page']['order']);
    });

    $suite->test('context API excludes messages and honors ETag', function () use ($suite, $baseUrl) {
        $first = httpRequest($baseUrl, 'GET', '/api/chat-context.php');
        $suite->assertSame(200, $first['status']);
        $body = decodedBody($first);
        $suite->assertTrue(!array_key_exists('messages', $body));
        $suite->assertTrue(isset($body['activity']['dates'], $body['activity']['records']));
        $suite->assertTrue(isset($first['headers']['etag']));
        $second = httpRequest($baseUrl, 'GET', '/api/chat-context.php', ['If-None-Match: ' . $first['headers']['etag']]);
        $suite->assertSame(304, $second['status']);
    });

    $suite->test('write API rejects missing and invalid tokens', function () use ($suite, $baseUrl) {
        $missing = httpRequest($baseUrl, 'POST', '/api/chat-entries.php', [], ['body' => 'No token']);
        $invalid = httpRequest($baseUrl, 'POST', '/api/chat-entries.php', ['X-Agent-Token: invalid'], ['body' => 'Bad token']);
        $suite->assertSame(401, $missing['status']);
        $suite->assertSame(401, $invalid['status']);
    });

    $apiEntryId = null;
    $suite->test('write API prevents sender spoofing and supports multiple targets', function () use ($suite, $baseUrl, $aliceToken, &$apiEntryId) {
        $response = httpRequest($baseUrl, 'POST', '/api/chat-entries.php', ['Authorization: Bearer ' . $aliceToken], [
            'sender' => 'Admin Project',
            'body' => 'API sender test',
            'targets' => ['Bob Project', 'Admin Project'],
        ]);
        $suite->assertSame(201, $response['status']);
        $body = decodedBody($response);
        $suite->assertSame('Alice Project', $body['data']['sender']);
        $apiEntryId = $body['data']['db_id'];
        $targets = $body['data']['targets'];
        sort($targets);
        $suite->assertSame(['Admin Project', 'Bob Project'], $targets);
    });

    $suite->test('entry API enforces owner authorization', function () use ($suite, $baseUrl, $aliceToken, $bobToken, &$apiEntryId) {
        $forbidden = httpRequest($baseUrl, 'PATCH', '/api/chat-entry.php?id=' . $apiEntryId, ['Authorization: Bearer ' . $bobToken], ['body' => 'Forbidden']);
        $allowed = httpRequest($baseUrl, 'PATCH', '/api/chat-entry.php?id=' . $apiEntryId, ['Authorization: Bearer ' . $aliceToken], ['body' => 'Owner edit']);
        $suite->assertSame(403, $forbidden['status']);
        $suite->assertSame(200, $allowed['status']);
    });

    $suite->test('claim API exchanges a claim once and issues a primary token', function () use ($suite, $baseUrl, $repository, $pdo) {
        $now = Db::now();
        $statement = $pdo->prepare('INSERT INTO chat_agents (project_name, description, is_active, created_at, updated_at) VALUES (?, ?, 1, ?, ?)');
        $statement->execute(['Claim Project', 'Claim API test', $now, $now]);
        $claim = $repository->generateClaimCode('Claim Project');
        $response = httpRequest($baseUrl, 'POST', '/api/claim.php', [], [
            'project_name' => 'Claim Project',
            'claim_code' => $claim['claim_code'],
        ]);
        $suite->assertSame(201, $response['status']);
        $body = decodedBody($response);
        $suite->assertTrue(strpos($body['data']['token'], 'pbbchat_') === 0);
        $second = httpRequest($baseUrl, 'POST', '/api/claim.php', [], [
            'project_name' => 'Claim Project',
            'claim_code' => $claim['claim_code'],
        ]);
        $suite->assertSame(409, $second['status']);
        $version = $pdo->query("SELECT token_secret_version FROM chat_agents WHERE project_name = 'Claim Project'")->fetchColumn();
        $suite->assertSame('primary', $version);
    });

    $suite->test('disabled schema-installation HTTP endpoint returns Gone', function () use ($suite, $baseUrl) {
        $install = httpRequest($baseUrl, 'POST', '/api/install-schema.php');
        $suite->assertSame(410, $install['status']);
    });

    $suite->test('chat-log API honors ETag conditional requests', function () use ($suite, $baseUrl) {
        $first = httpRequest($baseUrl, 'GET', '/api/chat-log.php');
        $suite->assertSame(200, $first['status']);
        $suite->assertTrue(isset($first['headers']['etag']));
        $second = httpRequest($baseUrl, 'GET', '/api/chat-log.php', ['If-None-Match: ' . $first['headers']['etag']]);
        $suite->assertSame(304, $second['status']);
    });

    $suite->test('chat-log API returns Service Unavailable when the schema is missing', function () use ($suite, $root, $environment, $emptyDatabase) {
        $environment['PBB_AGENTCHAT_DB_NAME'] = $emptyDatabase;
        $process = null;
        $logPath = null;
        try {
            list($process, $baseUrl, $logPath) = startServer($root, reservePort(), $environment);
            $response = httpRequest($baseUrl, 'GET', '/api/chat-log.php');
            $suite->assertSame(503, $response['status']);
            $body = decodedBody($response);
            $suite->assertSame('Chat database schema is not installed.', $body['message']);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            if ($logPath && is_file($logPath)) {
                unlink($logPath);
            }
        }
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
        if (!preg_match('/^syndicatum_test_[a-f0-9]{12}$/', $database)) {
            throw new RuntimeException('Refusing to drop unsafe database name.');
        }
        $admin->exec('DROP DATABASE `' . $database . '`');
        if (!preg_match('/^syndicatum_test_[a-f0-9]{12}_empty$/', $emptyDatabase)) {
            throw new RuntimeException('Refusing to drop unsafe empty database name.');
        }
        $admin->exec('DROP DATABASE `' . $emptyDatabase . '`');
    }
}

exit($suite->finish());
