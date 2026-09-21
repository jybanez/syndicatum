<?php

/** Read-only locator; emits identifiers, never message/body contents. */
$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbb_agentchat;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$files = [
    'f369e4e85303a7ee2a7b00dae4d6db773c7194d4.png',
    '394244659609d53306e8baf92db5120557c5b44d.png',
    'd699341dfa06e9f17a5776c023bdc3253304459c.png',
];
$tables = $pdo->query("SELECT table_name FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")
    ->fetchAll(PDO::FETCH_COLUMN);
$results = [];
foreach ($tables as $table) {
    if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $table)) {
        throw new RuntimeException('Unexpected table identifier.');
    }
    $columns = $pdo->prepare("SELECT column_name, data_type FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position");
    $columns->execute([$table]);
    $textColumns = [];
    foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (in_array(strtolower($column['DATA_TYPE']), ['char', 'varchar', 'text', 'mediumtext', 'longtext', 'json'], true)) {
            $textColumns[] = $column['COLUMN_NAME'];
        }
    }
    foreach ($textColumns as $column) {
        if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $column)) {
            throw new RuntimeException('Unexpected column identifier.');
        }
        $idColumn = $table === 'messages' ? 'id' : 'id';
        $hasId = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = '" . $table . "' AND column_name = 'id'")
            ->fetchColumn() === 1;
        foreach ($files as $file) {
            $query = $pdo->prepare('SELECT ' . ($hasId ? '`id`' : 'COUNT(*)') . ' FROM `' . $table
                . '` WHERE `' . $column . '` LIKE ?');
            $query->execute(['%' . $file . '%']);
            $matches = $query->fetchAll(PDO::FETCH_COLUMN);
            if (!$hasId) {
                $matches = ((int) $matches[0]) > 0 ? ['count:' . $matches[0]] : [];
            }
            foreach ($matches as $id) {
                $results[] = ['file' => $file, 'table' => $table, 'column' => $column, 'row_id' => (string) $id];
            }
        }
    }
}
echo json_encode($results, JSON_UNESCAPED_SLASHES) . "\n";
