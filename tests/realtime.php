<?php

require_once dirname(__DIR__) . '/src/RealtimeIntegration.php';
require_once dirname(__DIR__) . '/src/MessageOutbox.php';

class RealtimeTestSettings
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

class RealtimeTestSuite
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

    public function finish()
    {
        echo "\n" . $this->passed . ' passed, ' . $this->failed . " failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

function realtimeTestConfig(array $overrides = [])
{
    return array_merge([
        'realtime.enabled' => true,
        'realtime.base_url' => 'https://realtime.example.test',
        'realtime.publish_url' => 'https://realtime.example.test/api/v1/events/publish',
        'realtime.websocket_url' => 'wss://realtime.example.test/realtime',
        'realtime.issuer' => 'https://syndicatum.example.test',
        'realtime.audience' => 'pbb-realtime',
        'realtime.client_code' => 'clt_syndicatum',
        'realtime.project_code' => 'prj_syndicatum',
        'realtime.connector_authorization_project_code' => 'prj_connector_authorization',
        'realtime.signing_secret' => str_repeat('s', 64),
        'realtime.backend_ingress_secret' => 'backend-ingress-secret',
        'realtime.token_ttl_seconds' => 300,
        'realtime.connect_timeout_seconds' => 3,
        'realtime.timeout_seconds' => 5,
        'realtime.ca_bundle' => '',
    ], $overrides);
}

function realtimeDecodeJwtPayload($jwt)
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('JWT does not contain three segments.');
    }
    $encoded = strtr($parts[1], '-_', '+/');
    $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
    return json_decode(base64_decode($encoded), true);
}

$suite = new RealtimeTestSuite();

$suite->test('Realtime defaults closed when enabled setting is absent', function () use ($suite) {
    $integration = new RealtimeIntegration(new RealtimeTestSettings([]));
    $suite->same(false, $integration->isEnabled());
});

$suite->test('admission is exact-room and subscribe-only', function () use ($suite) {
    $integration = new RealtimeIntegration(new RealtimeTestSettings(realtimeTestConfig()));
    $admission = $integration->buildAdmission(['id' => 91, 'display_name' => 'Review Agent'], 'project_42');
    $claims = realtimeDecodeJwtPayload($admission['token']);
    $suite->same('syndicatum.project.project_42', $admission['raw_room']);
    $suite->same('chat.thread.syndicatum.project.project_42', $admission['room']);
    $suite->same('prj_syndicatum', $admission['project_code']);
    $suite->same(['session.connect', 'room.join'], $claims['capabilities']);
    $suite->same([$admission['room']], $claims['allowed_rooms']);
    $suite->same('participant:91', $claims['user_id']);
    $suite->truthy(!in_array('chat.publish', $claims['capabilities'], true));
});

$suite->test('connector authorization admission is exact-room, short-lived, and subscribe-only', function () use ($suite) {
    $integration = new RealtimeIntegration(new RealtimeTestSettings(realtimeTestConfig()));
    $authorizationId = str_repeat('a', 32);
    $admission = $integration->buildConnectorAuthorizationAdmission($authorizationId, 'Office PC', gmdate('c', time() + 600));
    $claims = realtimeDecodeJwtPayload($admission['token']);
    $suite->same('syndicatum.connector.authorization.' . $authorizationId, $admission['room']);
    $suite->same('prj_connector_authorization', $admission['project_code']);
    $suite->same([$admission['room']], $claims['allowed_rooms']);
    $suite->same(['session.connect', 'room.join'], $claims['capabilities']);
    $suite->truthy($claims['exp'] <= time() + 600);
});

$suite->test('connector approval publisher sends only authorization metadata', function () use ($suite) {
    $captured = null;
    $integration = new RealtimeIntegration(new RealtimeTestSettings(realtimeTestConfig()), function ($config, $request) use (&$captured) {
        $captured = $request; return ['status' => 202, 'body' => '{"status":"accepted"}'];
    });
    $authorizationId = str_repeat('b', 32);
    $suite->same(true, $integration->publishConnectorAuthorizationApproved($authorizationId));
    $suite->same('connector.authorization.approved', $captured['event_type']);
    $suite->same('prj_connector_authorization', $captured['project_code']);
    $suite->same(['authorization_id' => $authorizationId, 'status' => 'approved'], $captured['payload']);
    $suite->truthy(!isset($captured['payload']['access_token']));
});

$capturedRequest = null;
$suite->test('publisher sends the complete canonical outbox payload', function () use ($suite, &$capturedRequest) {
    $transport = function ($config, $request) use (&$capturedRequest) {
        $capturedRequest = $request;
        return ['status' => 202, 'body' => '{"status":"accepted"}'];
    };
    $integration = new RealtimeIntegration(new RealtimeTestSettings(realtimeTestConfig()), $transport);
    $eventPayload = [
        'event_id' => 'event-1',
        'type' => 'syndicatum.message.created',
        'project_id' => 42,
        'sequence' => 7,
        'message' => ['id' => 'message-7', 'body' => 'Complete message', 'addressees' => []],
    ];
    $result = $integration->publishOutboxEvent([
        'event_uuid' => 'event-1',
        'project_id' => 42,
        'event_type' => 'syndicatum.message.created',
        'payload_json' => json_encode($eventPayload),
    ]);
    $suite->same('accepted', $result['status']);
    $suite->same($eventPayload, $capturedRequest['payload']);
    $suite->same('chat.thread.syndicatum.project.42', $capturedRequest['room']);
    $suite->same('event-1', $capturedRequest['event_id']);
});

$suite->test('publisher retries transient responses and rejects permanent responses', function () use ($suite) {
    $event = [
        'event_uuid' => 'event-2',
        'project_id' => 1,
        'event_type' => 'syndicatum.message.created',
        'payload_json' => '{}',
    ];
    $transient = new RealtimeIntegration(new RealtimeTestSettings(realtimeTestConfig()), function () {
        return ['status' => 429, 'body' => '{"reason":"rate-limit-exceeded"}'];
    });
    $permanent = new RealtimeIntegration(new RealtimeTestSettings(realtimeTestConfig()), function () {
        return ['status' => 403, 'body' => '{"reason":"room-not-allowed"}'];
    });
    $suite->same(true, $transient->publishOutboxEvent($event)['retryable']);
    $suite->same(false, $permanent->publishOutboxEvent($event)['retryable']);
});

$database = 'syndicatum_realtime_test_' . bin2hex(function_exists('random_bytes') ? random_bytes(6) : openssl_random_pseudo_bytes(6));
if (!preg_match('/^syndicatum_realtime_test_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe Realtime test database name.');
}
$admin = null;
try {
    $admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $database . ';charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE message_events_outbox (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_uuid CHAR(36) NOT NULL UNIQUE,
        project_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NULL,
        event_type VARCHAR(100) NOT NULL,
        project_sequence BIGINT UNSIGNED NULL,
        payload_json MEDIUMTEXT NOT NULL,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NOT NULL,
        published_at DATETIME NULL,
        failed_at DATETIME NULL,
        last_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $suite->test('outbox stores a full message and tracks delivery state', function () use ($suite, $pdo) {
        $outbox = new MessageOutbox($pdo);
        $event = $outbox->enqueueMessageCreated(3, 8, 12, ['id' => 8, 'body' => 'Stored message']);
        $payload = json_decode($event['payload_json'], true);
        $suite->same(12, $payload['sequence']);
        $suite->same('Stored message', $payload['message']['body']);
        $attempt = $outbox->beginAttempt($event['id']);
        $suite->same(1, (int) $attempt['attempt_count']);
        $outbox->markRetry($event['id'], "temporary\nerror", 5);
        $suite->same('temporary error', $pdo->query('SELECT last_error FROM message_events_outbox')->fetchColumn());
        $outbox->markPublished($event['id']);
        $suite->truthy($pdo->query('SELECT published_at FROM message_events_outbox')->fetchColumn() !== null);
    });
} catch (PDOException $exception) {
    echo 'SKIP  outbox database test: ' . $exception->getMessage() . "\n";
} finally {
    if ($admin instanceof PDO) {
        if (!preg_match('/^syndicatum_realtime_test_[a-f0-9]{12}$/', $database)) {
            throw new RuntimeException('Refusing to drop unsafe Realtime test database name.');
        }
        $admin->exec('DROP DATABASE `' . $database . '`');
    }
}

exit($suite->finish());
