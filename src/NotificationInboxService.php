<?php

require_once __DIR__ . '/Db.php';

class NotificationInboxService
{
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function listForUser($userId)
    {
        $statement = $this->pdo->prepare(
            "SELECT i.id, i.project_id, p.name AS project_name, inviter.display_name AS inviter_name,
                    i.role, i.expires_at, i.created_at, i.notification_read_at
             FROM users recipient
             JOIN project_invitations i ON LOWER(i.invited_email) = LOWER(recipient.normalized_email)
             JOIN projects p ON p.id = i.project_id
             JOIN users inviter ON inviter.id = i.invited_by_user_id
             WHERE recipient.id = ? AND i.status = 'pending' AND i.expires_at > ?
             ORDER BY i.created_at DESC, i.id DESC"
        );
        $statement->execute([(int) $userId, Db::now()]);
        $items = [];
        $unread = 0;
        $labels = ['admin' => 'Administrator', 'member' => 'Member', 'viewer' => 'Viewer'];
        foreach ($statement->fetchAll() as $row) {
            if ($row['notification_read_at'] === null) { $unread++; }
            $items[] = [
                'id' => 'project_invitation:' . (int) $row['id'],
                'type' => 'project_invitation',
                'invitation_id' => (int) $row['id'],
                'project_id' => (int) $row['project_id'],
                'project_name' => (string) $row['project_name'],
                'inviter_name' => (string) $row['inviter_name'],
                'role' => (string) $row['role'],
                'role_label' => isset($labels[$row['role']]) ? $labels[$row['role']] : ucfirst((string) $row['role']),
                'expires_at' => (string) $row['expires_at'],
                'created_at' => (string) $row['created_at'],
                'read' => $row['notification_read_at'] !== null,
            ];
        }
        return ['items' => $items, 'unread_count' => $unread, 'refreshed_at' => Db::now()];
    }

    public function markRead($userId, array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) { return $id > 0; })));
        if (!$ids) { return $this->listForUser($userId); }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $eligible = $this->pdo->prepare(
            "SELECT i.id FROM project_invitations i JOIN users u ON u.id = ?
             WHERE i.id IN ($placeholders) AND LOWER(i.invited_email) = LOWER(u.normalized_email)"
        );
        $eligible->execute(array_merge([(int) $userId], $ids));
        $now = Db::now();
        $write = $this->pdo->prepare('UPDATE project_invitations SET notification_read_at = ? WHERE id = ?');
        foreach ($eligible->fetchAll(PDO::FETCH_COLUMN) as $id) { $write->execute([$now, (int) $id]); }
        return $this->listForUser($userId);
    }
}
