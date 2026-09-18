<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/OperationalHealth.php';
require_once __DIR__ . '/DeliveryOperatorView.php';

/** Read-only, content-free delivery health for authenticated administrators. */
class AdminDeliveryHealth
{
    public static function snapshot(PDO $pdo)
    {
        $worker = self::worker($pdo);
        $paths = [];
        foreach (self::definitions() as $name => $definition) {
            $paths[$name] = self::path($pdo, $definition);
        }
        $components = [$worker['state']];
        foreach ($paths as $path) { $components[] = $path['state']; }
        return [
            'checked_at' => gmdate('c'),
            'state' => operationalAggregateState($components),
            'worker' => $worker,
            'paths' => $paths,
        ];
    }

    private static function definitions()
    {
        return [
            'realtime' => ['message_events_outbox', 'realtime'],
            'webhook' => ['agent_webhook_deliveries', 'webhook'],
            'workspace_agent' => ['workspace_agent_trigger_deliveries', 'workspace_agent'],
            'responses_api' => ['responses_api_deliveries', 'responses_api'],
        ];
    }

    private static function unknown($reason)
    {
        return [
            'state' => 'unknown', 'unavailable_reason' => $reason,
            'pending' => null, 'retrying' => null, 'waiting' => null, 'terminal' => null,
            'terminal_last_24h' => null, 'oldest_pending_seconds' => null,
            'last_attempt_at' => null, 'last_success_at' => null,
            'diagnostic_sample' => ['scope' => 'unavailable'],
        ];
    }

    private static function path(PDO $pdo, array $definition)
    {
        list($table, $path) = $definition;
        if (!Db::tableExists($pdo, $table)) { return self::unknown('table_missing'); }
        if (!Db::columnExists($pdo, $table, 'last_attempt_at')
            || !Db::columnExists($pdo, $table, 'last_failure_code')) {
            return self::unknown('diagnostic_migration_missing');
        }
        $realtime = $path === 'realtime';
        $pending = $realtime ? 'published_at IS NULL AND failed_at IS NULL'
            : "status IN ('queued','sending','waiting','retry')";
        $retry = $realtime ? "({$pending}) AND attempt_count > 0" : "status = 'retry'";
        $waiting = $path === 'responses_api' ? "status = 'waiting'" : '1 = 0';
        $terminal = $realtime ? 'failed_at IS NOT NULL' : "status = 'dead'";
        $success = $realtime ? 'published_at' : 'delivered_at';
        // Recent terminal count is based on row creation, because these queues
        // do not yet persist a separate terminal-transition timestamp.
        $sql = "SELECT
            COALESCE(SUM(CASE WHEN {$pending} THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN {$retry} THEN 1 ELSE 0 END), 0) AS retrying,
            COALESCE(SUM(CASE WHEN {$waiting} THEN 1 ELSE 0 END), 0) AS waiting,
            COALESCE(SUM(CASE WHEN {$terminal} THEN 1 ELSE 0 END), 0) AS terminal,
            COALESCE(SUM(CASE WHEN {$terminal} AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END), 0) AS terminal_last_24h,
            COALESCE(MAX(CASE WHEN {$pending} THEN GREATEST(0, TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP())) ELSE 0 END), 0) AS oldest_pending_seconds,
            MAX(last_attempt_at) AS last_attempt_at,
            MAX({$success}) AS last_success_at
            FROM {$table}";
        $metrics = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        $columns = $realtime
            ? 'attempt_count, last_attempt_at, last_failure_code, available_at AS next_attempt_at, published_at, failed_at'
            : 'status, attempt_count, last_attempt_at, last_failure_code, next_attempt_at, response_status';
        if ($path === 'responses_api') { $columns .= ', response_state'; }
        $rows = $pdo->query("SELECT {$columns} FROM {$table} ORDER BY id DESC LIMIT 50")
            ->fetchAll(PDO::FETCH_ASSOC);
        $oldest = (int) $metrics['oldest_pending_seconds'];
        return [
            'state' => operationalDeliveryState((int) $metrics['terminal_last_24h'], $oldest),
            'unavailable_reason' => null,
            'pending' => (int) $metrics['pending'],
            'retrying' => (int) $metrics['retrying'],
            'waiting' => (int) $metrics['waiting'],
            'terminal' => (int) $metrics['terminal'],
            'terminal_last_24h' => (int) $metrics['terminal_last_24h'],
            'oldest_pending_seconds' => $oldest,
            'last_attempt_at' => $metrics['last_attempt_at'],
            'last_success_at' => $metrics['last_success_at'],
            'activation_dependency' => in_array($path, ['workspace_agent', 'responses_api'], true)
                ? 'disabled_in_v1' : 'worker',
            'diagnostic_sample' => DeliveryOperatorView::sample($rows, $path),
        ];
    }

    private static function worker(PDO $pdo)
    {
        if (!Db::tableExists($pdo, 'delivery_worker_heartbeats')) {
            return ['state' => 'unknown', 'age_seconds' => null, 'last_success_at' => null,
                'unavailable_reason' => 'table_missing'];
        }
        $row = $pdo->query("SELECT last_success_at,
            GREATEST(0, TIMESTAMPDIFF(SECOND, last_success_at, UTC_TIMESTAMP())) AS age_seconds
            FROM delivery_worker_heartbeats WHERE worker_name = 'delivery'")->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['last_success_at'] === null) {
            return ['state' => 'unknown', 'age_seconds' => null, 'last_success_at' => null,
                'unavailable_reason' => 'heartbeat_missing'];
        }
        $raw = getenv('SYNDICATUM_WORKER_STALE_SECONDS');
        if ($raw !== false && (!ctype_digit((string) $raw) || (int) $raw < 30 || (int) $raw > 3600)) {
            return ['state' => 'unknown', 'age_seconds' => (int) $row['age_seconds'],
                'last_success_at' => $row['last_success_at'], 'stale_after_seconds' => null,
                'unavailable_reason' => 'invalid_worker_threshold'];
        }
        $threshold = $raw === false ? 120 : (int) $raw;
        $age = (int) $row['age_seconds'];
        return ['state' => operationalWorkerState($age, $threshold), 'age_seconds' => $age,
            'last_success_at' => $row['last_success_at'], 'stale_after_seconds' => $threshold,
            'unavailable_reason' => null];
    }
}
