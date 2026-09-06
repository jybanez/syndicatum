<?php

require_once dirname(dirname(dirname(__DIR__))) . '/src/Api.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/Db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/src/AuthService.php';

try {
    if (Api::method() !== 'GET') {
        Api::json(['error' => true, 'code' => 'method_not_allowed', 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET']);
    }
    $pdo = Db::pdo();
    (new AuthService($pdo))->requireAdministrator();
    $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 100;
    $before = isset($_GET['before']) ? max(0, (int) $_GET['before']) : 0;
    $sql = 'SELECT a.id, a.actor_user_id, u.display_name AS actor_display_name, a.action, a.subject_type,
                   a.subject_id, a.metadata_json, a.ip_address, a.created_at
            FROM administrative_audit_events a LEFT JOIN users u ON u.id = a.actor_user_id';
    $params = [];
    if ($before > 0) {
        $sql .= ' WHERE a.id < ?';
        $params[] = $before;
    }
    $sql .= ' ORDER BY a.id DESC LIMIT ' . $limit;
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (string) $row['id'];
        $row['actor_user_id'] = $row['actor_user_id'] === null ? null : (string) $row['actor_user_id'];
        $row['metadata'] = $row['metadata_json'] ? json_decode($row['metadata_json'], true) : null;
        unset($row['metadata_json']);
    }
    unset($row);
    Api::json(['data' => $rows, 'page' => ['next_before' => empty($rows) ? null : end($rows)['id']]]);
} catch (RuntimeException $exception) {
    $status = $exception->getMessage() === 'AUTHENTICATION_REQUIRED' ? 401 : 403;
    Api::json(['error' => true, 'code' => strtolower($exception->getMessage()), 'message' => 'Administrator access is required.'], $status);
} catch (Exception $exception) {
    Api::json(['error' => true, 'code' => 'server_error', 'message' => 'Unable to load the audit log.'], 500);
}
