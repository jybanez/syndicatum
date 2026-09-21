<?php

/** Read-only comparison evidence; run once on source clone and once after uplift. */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbb_agentchat;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'pbb_agentchat') {
    throw new RuntimeException('Unexpected evidence database.');
}
$ledger = $pdo->query('SELECT version, description, checksum, applied_at
    FROM syndicatum_schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
$out = [
    'historical_ledger_count' => 25,
    'historical_ledger_sha256' => hash('sha256', json_encode(array_slice($ledger, 0, 25), JSON_UNESCAPED_SLASHES)),
    'ledger_total' => count($ledger),
];
foreach (['agent_webhook_deliveries', 'workspace_agent_trigger_deliveries',
    'responses_api_deliveries'] as $table) {
    $rows = $pdo->query('SELECT id, status FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $counts = [];
    foreach ($rows as $row) {
        $status = $row['status'];
        $counts[$status] = (isset($counts[$status]) ? $counts[$status] : 0) + 1;
    }
    ksort($counts);
    $out[$table] = ['count' => count($rows), 'status_counts' => $counts,
        'identity_status_sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES))];
}
$rows = $pdo->query('SELECT id, published_at, failed_at, attempt_count
    FROM message_events_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$counts = ['pending' => 0, 'published' => 0, 'failed' => 0];
foreach ($rows as $row) {
    $state = $row['published_at'] !== null ? 'published' : ($row['failed_at'] !== null ? 'failed' : 'pending');
    ++$counts[$state];
}
$out['message_events_outbox'] = ['count' => count($rows), 'status_counts' => $counts,
    'identity_state_sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES))];
echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
