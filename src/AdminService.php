<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';

class AdminService
{
    private $pdo;
    private $auth;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
    }

    public function users()
    {
        $rows = $this->pdo->query(
            "SELECT u.id, u.normalized_email, u.username, u.display_name, u.avatar_url, u.status,
                    u.pbb_user_id, u.created_at, u.updated_at, w.id AS workspace_id, w.name AS workspace_name,
                    GROUP_CONCAT(r.code ORDER BY r.code SEPARATOR ',') AS role_codes
             FROM users u
             LEFT JOIN workspaces w ON w.owner_user_id = u.id
             LEFT JOIN user_system_roles ur ON ur.user_id = u.id
             LEFT JOIN system_roles r ON r.id = ur.role_id
             GROUP BY u.id, u.normalized_email, u.username, u.display_name, u.avatar_url, u.status,
                      u.pbb_user_id, u.created_at, u.updated_at, w.id, w.name
             ORDER BY u.display_name, u.id"
        )->fetchAll();
        return array_map([$this, 'normalizeUserRow'], $rows);
    }

    public function agents()
    {
        $rows = $this->pdo->query(
            "SELECT a.id, a.project_name AS legacy_name, a.description, a.is_active, a.last_used_at,
                    pa.project_id, pa.display_name, pa.avatar_url, pa.provider, pa.runtime_name, pa.status,
                    p.name AS project_display_name,
                    GROUP_CONCAT(s.scope ORDER BY s.scope SEPARATOR ',') AS scope_codes
             FROM chat_agents a
             LEFT JOIN project_agents pa ON pa.agent_id = a.id
             LEFT JOIN projects p ON p.id = pa.project_id
             LEFT JOIN agent_credential_scopes s ON s.agent_id = a.id
             GROUP BY a.id, a.project_name, a.description, a.is_active, a.last_used_at,
                      pa.project_id, pa.display_name, pa.avatar_url, pa.provider, pa.runtime_name, pa.status, p.name
             ORDER BY COALESCE(pa.display_name, a.project_name), a.id"
        )->fetchAll();

        return array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'project_id' => $row['project_id'] === null ? null : (int) $row['project_id'],
                'project_name' => $row['project_display_name'],
                'display_name' => $row['display_name'] ?: $row['legacy_name'],
                'description' => $row['description'],
                'avatar_url' => $row['avatar_url'],
                'provider' => $row['provider'],
                'runtime_name' => $row['runtime_name'],
                'status' => $row['status'] ?: ((int) $row['is_active'] === 1 ? 'active' : 'suspended'),
                'is_active' => (int) $row['is_active'] === 1,
                'last_used_at' => $row['last_used_at'],
                'scopes' => $row['scope_codes'] ? explode(',', $row['scope_codes']) : [],
            ];
        }, $rows);
    }

    public function createUser(array $input, $actorUserId)
    {
        $email = strtolower(trim(isset($input['email']) ? (string) $input['email'] : ''));
        $username = strtolower(trim(isset($input['username']) ? (string) $input['username'] : ''));
        $displayName = trim(isset($input['display_name']) ? (string) $input['display_name'] : '');
        $password = isset($input['password']) ? (string) $input['password'] : '';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email is invalid.');
        }
        if ($email === '' && $username === '') {
            throw new InvalidArgumentException('Email or username is required.');
        }
        if ($displayName === '' || strlen($password) < 12) {
            throw new InvalidArgumentException('Display name and a password of at least 12 characters are required.');
        }
        $roles = isset($input['system_roles']) && is_array($input['system_roles']) ? $input['system_roles'] : ['user'];
        $roles = array_values(array_unique(array_intersect($roles, ['user', 'administrator'])));
        if (!in_array('user', $roles, true)) {
            $roles[] = 'user';
        }
        $this->pdo->beginTransaction();
        try {
            $now = Db::now();
            $statement = $this->pdo->prepare(
                "INSERT INTO users (normalized_email, username, password_hash, display_name, avatar_url, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'active', ?, ?)"
            );
            $statement->execute([$email === '' ? null : $email, $username === '' ? null : $username,
                password_hash($password, PASSWORD_DEFAULT), $displayName,
                $this->avatarUrl(isset($input['avatar_url']) ? $input['avatar_url'] : null),
                $now, $now]);
            $userId = (int) $this->pdo->lastInsertId();
            $workspace = $this->pdo->prepare('INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)');
            $workspace->execute([$userId, $displayName . "'s workspace", $now, $now]);
            $roleStatement = $this->pdo->prepare(
                'INSERT INTO user_system_roles (user_id, role_id, granted_by_user_id, created_at)
                 SELECT ?, id, ?, ? FROM system_roles WHERE code = ?'
            );
            foreach ($roles as $role) {
                $roleStatement->execute([$userId, $actorUserId, $now, $role]);
            }
            $this->auth->audit($actorUserId, 'user.created', 'user', (string) $userId, ['roles' => $roles]);
            $this->pdo->commit();
            return $this->auth->publicUser($userId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateUser($userId, array $input, $actorUserId)
    {
        $userId = (int) $userId;
        $current = $this->auth->publicUser($userId);
        if (!$current) {
            throw new RuntimeException('NOT_FOUND');
        }
        $status = isset($input['status']) ? (string) $input['status'] : $current['status'];
        if (!in_array($status, ['active', 'suspended', 'deleted'], true)) {
            throw new InvalidArgumentException('Invalid user status.');
        }
        $roles = isset($input['system_roles']) && is_array($input['system_roles'])
            ? array_values(array_unique(array_intersect($input['system_roles'], ['user', 'administrator'])))
            : $current['system_roles'];
        if (!in_array('user', $roles, true)) {
            $roles[] = 'user';
        }
        $currentRoles = $current['system_roles'];
        sort($currentRoles);
        $nextRoles = $roles;
        sort($nextRoles);
        $rolesChanged = $currentRoles !== $nextRoles;
        $removesAdmin = in_array('administrator', $current['system_roles'], true) && !in_array('administrator', $roles, true);
        $disablesAdmin = in_array('administrator', $current['system_roles'], true) && $status !== 'active';
        $displayName = isset($input['display_name']) ? trim((string) $input['display_name']) : $current['display_name'];
        if ($displayName === '') {
            throw new InvalidArgumentException('Display name is required.');
        }
        $avatarUrl = array_key_exists('avatar_url', $input) ? $this->avatarUrl($input['avatar_url']) : $current['avatar_url'];

        $this->pdo->beginTransaction();
        try {
            if (($removesAdmin || $disablesAdmin) && $this->lockActiveAdministratorCount() <= 1) {
                throw new RuntimeException('FINAL_ADMINISTRATOR');
            }
            $now = Db::now();
            $update = $this->pdo->prepare('UPDATE users SET display_name = ?, avatar_url = ?, status = ?, deleted_at = ?, updated_at = ? WHERE id = ?');
            $update->execute([$displayName, $avatarUrl, $status, $status === 'deleted' ? $now : null, $now, $userId]);
            $this->pdo->prepare('DELETE FROM user_system_roles WHERE user_id = ?')->execute([$userId]);
            $roleStatement = $this->pdo->prepare(
                'INSERT INTO user_system_roles (user_id, role_id, granted_by_user_id, created_at)
                 SELECT ?, id, ?, ? FROM system_roles WHERE code = ?'
            );
            foreach ($roles as $role) {
                $roleStatement->execute([$userId, $actorUserId, $now, $role]);
            }
            if ($status !== 'active' || $rolesChanged) {
                $this->pdo->prepare('UPDATE syndicatum_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL')->execute([$now, $userId]);
            }
            $this->auth->audit($actorUserId, 'user.updated', 'user', (string) $userId, ['status' => $status, 'roles' => $roles]);
            $this->pdo->commit();
            return $this->auth->publicUser($userId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function resetPassword($userId, $password, $actorUserId)
    {
        if (strlen((string) $password) < 12) {
            throw new InvalidArgumentException('Password must be at least 12 characters.');
        }
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $statement->execute([password_hash((string) $password, PASSWORD_DEFAULT), Db::now(), (int) $userId]);
            if ($statement->rowCount() < 1) { throw new RuntimeException('NOT_FOUND'); }
            $this->pdo->prepare('UPDATE syndicatum_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL')->execute([Db::now(), (int) $userId]);
            $this->auth->audit($actorUserId, 'user.password_reset', 'user', (string) ((int) $userId));
            $this->pdo->commit();
        } catch (Exception $exception) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $exception; }
    }

    public function suspendAgent($agentId, $suspended, $revokeToken, $actorUserId)
    {
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $sql = 'UPDATE chat_agents SET is_active = ?, updated_at = ?';
            $params = [$suspended ? 0 : 1, $now];
            if ($revokeToken) {
                $sql .= ', token_prefix = NULL, token_hash = NULL, token_secret_version = NULL';
            }
            $sql .= ' WHERE id = ?';
            $params[] = (int) $agentId;
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            if ($statement->rowCount() < 1) {
                throw new RuntimeException('NOT_FOUND');
            }
            $this->pdo->prepare("UPDATE project_agents SET status = ?, updated_at = ? WHERE agent_id = ?")
                ->execute([$suspended ? 'suspended' : 'active', $now, (int) $agentId]);
            $this->pdo->prepare("UPDATE project_participants SET status = ?, updated_at = ? WHERE agent_id = ?")
                ->execute([$suspended ? 'suspended' : 'active', $now, (int) $agentId]);
            $this->auth->audit($actorUserId, $revokeToken ? 'agent.suspended_and_revoked' : 'agent.status_changed', 'agent', (string) ((int) $agentId), ['suspended' => (bool) $suspended]);
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function activeAdministratorCount()
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_system_roles ur ON ur.user_id = u.id
             JOIN system_roles r ON r.id = ur.role_id AND r.code = 'administrator'
             WHERE u.status = 'active' AND u.deleted_at IS NULL"
        )->fetchColumn();
    }

    private function lockActiveAdministratorCount()
    {
        $rows = $this->pdo->query(
            "SELECT u.id FROM users u
             JOIN user_system_roles ur ON ur.user_id = u.id
             JOIN system_roles r ON r.id = ur.role_id AND r.code = 'administrator'
             WHERE u.status = 'active' AND u.deleted_at IS NULL FOR UPDATE"
        )->fetchAll(PDO::FETCH_COLUMN);
        return count(array_unique($rows));
    }

    private function normalizeUserRow(array $row)
    {
        return [
            'id' => (int) $row['id'], 'email' => $row['normalized_email'], 'username' => $row['username'],
            'display_name' => $row['display_name'], 'avatar_url' => $row['avatar_url'], 'status' => $row['status'],
            'pbb_user_id' => $row['pbb_user_id'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            'workspace' => $row['workspace_id'] ? ['id' => (int) $row['workspace_id'], 'name' => $row['workspace_name']] : null,
            'system_roles' => $row['role_codes'] ? explode(',', $row['role_codes']) : [],
        ];
    }

    private function avatarUrl($value)
    {
        $value = trim((string) $value);
        if ($value === '') { return null; }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Avatar URL must use HTTP or HTTPS.');
        }
        return $value;
    }
}
