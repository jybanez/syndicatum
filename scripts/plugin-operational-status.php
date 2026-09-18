<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/OperationalHealth.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function operationalScalar(PDO $pdo, $sql)
{
    return (int) $pdo->query($sql)->fetchColumn();
}

function operationalOldestAge(PDO $pdo, $table, $where)
{
    $value = $pdo->query("SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP())), 0) FROM {$table} WHERE {$where}")->fetchColumn();
    return (int) $value;
}

function operationalLastSuccess(PDO $pdo, $table, $column)
{
    $value = $pdo->query("SELECT MAX({$column}) FROM {$table}")->fetchColumn();
    return $value === false || $value === null ? null : str_replace(' ', 'T', (string) $value) . 'Z';
}

function operationalUnknownDelivery()
{
    return ['state' => 'unknown', 'pending' => null, 'dead_last_24h' => null,
        'oldest_pending_seconds' => null, 'last_success_at' => null];
}

function operationalWorkerStaleThreshold()
{
    $raw = getenv('SYNDICATUM_WORKER_STALE_SECONDS');
    if ($raw === false) {
        return 120;
    }
    if (!ctype_digit((string) $raw) || (int) $raw < 30 || (int) $raw > 3600) {
        throw new InvalidArgumentException('Invalid worker stale threshold');
    }
    return (int) $raw;
}

try {
    $workerStaleSeconds = operationalWorkerStaleThreshold();
    $pdo = Db::pdo();
    $pdo->query('SELECT 1')->fetchColumn();
    $status = [
        'checked_at' => gmdate('c'),
        'database' => 'ok',
        'state' => 'ok',
        'missing_delivery_tables' => [],
        'worker' => ['state' => 'unknown', 'last_success_at' => null,
            'age_seconds' => null, 'stale_after_seconds' => $workerStaleSeconds],
        'rate_limits' => ['active_blocks' => 0, 'recent_hits' => 0],
        'realtime_outbox' => ['state' => 'ok', 'pending' => 0, 'failed_last_24h' => 0,
            'oldest_pending_seconds' => 0, 'last_success_at' => null],
        'agent_webhooks' => ['state' => 'ok', 'pending' => 0, 'dead_last_24h' => 0,
            'oldest_pending_seconds' => 0, 'last_success_at' => null],
        'workspace_agent_triggers' => ['state' => 'ok', 'pending' => 0, 'dead_last_24h' => 0,
            'oldest_pending_seconds' => 0, 'last_success_at' => null],
        'responses_api_activations' => ['state' => 'ok', 'pending' => 0, 'dead_last_24h' => 0,
            'oldest_pending_seconds' => 0, 'last_success_at' => null],
        'attention' => false,
    ];

    if (Db::tableExists($pdo, 'security_rate_limits')) {
        $status['rate_limits']['active_blocks'] = operationalScalar($pdo,
            'SELECT COUNT(*) FROM security_rate_limits WHERE blocked_until > UTC_TIMESTAMP()');
        $status['rate_limits']['recent_hits'] = operationalScalar($pdo,
            'SELECT COALESCE(SUM(hit_count), 0) FROM security_rate_limits WHERE updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)');
    }
    if (Db::tableExists($pdo, 'delivery_worker_heartbeats')) {
        $workerRow = $pdo->query(
            "SELECT last_success_at,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, last_success_at, UTC_TIMESTAMP())) AS age_seconds
             FROM delivery_worker_heartbeats WHERE worker_name = 'delivery'"
        )->fetch(PDO::FETCH_ASSOC);
        if ($workerRow) {
            $ageSeconds = (int) $workerRow['age_seconds'];
            $status['worker']['state'] = operationalWorkerState($ageSeconds, $workerStaleSeconds);
            $status['worker']['age_seconds'] = $ageSeconds;
            $status['worker']['last_success_at'] = str_replace(' ', 'T', $workerRow['last_success_at']) . 'Z';
        }
    }
    if (Db::tableExists($pdo, 'message_events_outbox')) {
        $pending = 'published_at IS NULL AND failed_at IS NULL';
        $status['realtime_outbox']['pending'] = operationalScalar($pdo, "SELECT COUNT(*) FROM message_events_outbox WHERE {$pending}");
        $status['realtime_outbox']['failed_last_24h'] = operationalScalar($pdo,
            'SELECT COUNT(*) FROM message_events_outbox WHERE failed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)');
        $status['realtime_outbox']['oldest_pending_seconds'] = operationalOldestAge($pdo, 'message_events_outbox', $pending);
        $status['realtime_outbox']['last_success_at'] = operationalLastSuccess($pdo, 'message_events_outbox', 'published_at');
        $status['realtime_outbox']['state'] = operationalDeliveryState(
            $status['realtime_outbox']['failed_last_24h'], $status['realtime_outbox']['oldest_pending_seconds']);
    } else {
        $status['missing_delivery_tables'][] = 'message_events_outbox';
        $status['realtime_outbox'] = operationalUnknownDelivery();
        $status['realtime_outbox']['failed_last_24h'] = null;
    }
    if (Db::tableExists($pdo, 'agent_webhook_deliveries')) {
        $pending = "status IN ('queued','sending','retry')";
        $status['agent_webhooks']['pending'] = operationalScalar($pdo, "SELECT COUNT(*) FROM agent_webhook_deliveries WHERE {$pending}");
        $status['agent_webhooks']['dead_last_24h'] = operationalScalar($pdo,
            "SELECT COUNT(*) FROM agent_webhook_deliveries WHERE status = 'dead' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)");
        $status['agent_webhooks']['oldest_pending_seconds'] = operationalOldestAge($pdo, 'agent_webhook_deliveries', $pending);
        $status['agent_webhooks']['last_success_at'] = operationalLastSuccess($pdo, 'agent_webhook_deliveries', 'delivered_at');
        $status['agent_webhooks']['state'] = operationalDeliveryState(
            $status['agent_webhooks']['dead_last_24h'], $status['agent_webhooks']['oldest_pending_seconds']);
    } else {
        $status['missing_delivery_tables'][] = 'agent_webhook_deliveries';
        $status['agent_webhooks'] = operationalUnknownDelivery();
    }
    if (Db::tableExists($pdo, 'workspace_agent_trigger_deliveries')) {
        $pending = "status IN ('queued','sending','retry')";
        $status['workspace_agent_triggers']['pending'] = operationalScalar($pdo, "SELECT COUNT(*) FROM workspace_agent_trigger_deliveries WHERE {$pending}");
        $status['workspace_agent_triggers']['dead_last_24h'] = operationalScalar($pdo,
            "SELECT COUNT(*) FROM workspace_agent_trigger_deliveries WHERE status = 'dead' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)");
        $status['workspace_agent_triggers']['oldest_pending_seconds'] = operationalOldestAge($pdo, 'workspace_agent_trigger_deliveries', $pending);
        $status['workspace_agent_triggers']['last_success_at'] = operationalLastSuccess($pdo, 'workspace_agent_trigger_deliveries', 'delivered_at');
        $status['workspace_agent_triggers']['state'] = operationalDeliveryState(
            $status['workspace_agent_triggers']['dead_last_24h'], $status['workspace_agent_triggers']['oldest_pending_seconds']);
    } else {
        $status['missing_delivery_tables'][] = 'workspace_agent_trigger_deliveries';
        $status['workspace_agent_triggers'] = operationalUnknownDelivery();
    }
    if (Db::tableExists($pdo, 'responses_api_deliveries')) {
        $pending = "status IN ('queued','sending','waiting','retry')";
        $status['responses_api_activations']['pending'] = operationalScalar($pdo, "SELECT COUNT(*) FROM responses_api_deliveries WHERE {$pending}");
        $status['responses_api_activations']['dead_last_24h'] = operationalScalar($pdo,
            "SELECT COUNT(*) FROM responses_api_deliveries WHERE status = 'dead' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)");
        $status['responses_api_activations']['oldest_pending_seconds'] = operationalOldestAge($pdo, 'responses_api_deliveries', $pending);
        $status['responses_api_activations']['last_success_at'] = operationalLastSuccess($pdo, 'responses_api_deliveries', 'delivered_at');
        $status['responses_api_activations']['state'] = operationalDeliveryState(
            $status['responses_api_activations']['dead_last_24h'], $status['responses_api_activations']['oldest_pending_seconds']);
    } else {
        $status['missing_delivery_tables'][] = 'responses_api_deliveries';
        $status['responses_api_activations'] = operationalUnknownDelivery();
    }
    $componentStates = [$status['worker']['state']];
    foreach (['realtime_outbox', 'agent_webhooks', 'workspace_agent_triggers', 'responses_api_activations'] as $component) {
        $componentStates[] = $status[$component]['state'];
    }
    $status['state'] = operationalAggregateState($componentStates);
    $status['attention'] = $status['state'] !== 'ok';

    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($status['attention'] ? 2 : 0);
} catch (Exception $exception) {
    fwrite(STDERR, json_encode(['checked_at' => gmdate('c'), 'database' => 'unavailable',
        'state' => 'unknown', 'attention' => true]) . PHP_EOL);
    exit(3);
}
