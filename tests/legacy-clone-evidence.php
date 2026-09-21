<?php

require_once dirname(__DIR__) . '/src/LegacySchemaFingerprint.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbb_agentchat;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'pbb_agentchat') {
    throw new RuntimeException('Evidence database identity differs from the disposable clone.');
}
$evidence = ['schema_sha256' => LegacySchemaFingerprint::sha256($pdo)];
$objects = LegacySchemaFingerprint::inventory($pdo);
foreach (['agents', 'chat_agents'] as $table) {
    $rows = $pdo->query('SELECT id, claim_secret_version, claim_expires_at FROM `' . $table . '` ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);
    $evidence[$table] = [
        'count' => count($rows),
        'claim_values_sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES)),
        'columns' => array_values(array_map(function ($column) {
            return $column['COLUMN_NAME'];
        }, array_filter($objects['columns'], function ($column) use ($table) {
            return $column['TABLE_NAME'] === $table;
        }))),
    ];
    foreach (['indexes', 'constraints', 'constraint_columns', 'references', 'triggers'] as $kind) {
        $filtered = array_values(array_filter($objects[$kind], function ($row) use ($table, $kind) {
            $key = $kind === 'triggers' ? 'EVENT_OBJECT_TABLE' : 'TABLE_NAME';
            return $row[$key] === $table;
        }));
        $evidence[$table][$kind . '_sha256'] = hash('sha256', json_encode($filtered, JSON_UNESCAPED_SLASHES));
    }
}
foreach (['users', 'projects', 'messages', 'message_addressees', 'project_participants',
    'responsibility_events', 'agent_webhook_deliveries', 'workspace_agent_trigger_deliveries',
    'responses_api_deliveries'] as $table) {
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
        AND table_name = '" . $table . "'")->fetchColumn();
    $evidence['business_counts'][$table] = $exists ? (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() : null;
}
$evidence['ledger_count'] = (int) $pdo->query('SELECT COUNT(*) FROM syndicatum_schema_migrations')->fetchColumn();
$evidence['identity_count'] = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
    AND table_name = 'syndicatum_installation_identity'")->fetchColumn()
    ? (int) $pdo->query('SELECT COUNT(*) FROM syndicatum_installation_identity')->fetchColumn() : null;
echo json_encode($evidence, JSON_UNESCAPED_SLASHES) . "\n";
