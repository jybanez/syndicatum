<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';

class ConnectorDeviceService
{
    private $pdo;
    private $auth;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
    }

    public function begin(array $input)
    {
        $name = trim(isset($input['device_name']) ? (string) $input['device_name'] : 'Codex device');
        $platform = strtolower(trim(isset($input['platform']) ? (string) $input['platform'] : 'unknown'));
        if ($name === '' || strlen($name) > 120) { throw new InvalidArgumentException('Device name must contain 1 to 120 characters.'); }
        if (!preg_match('/^[a-z0-9._-]{1,40}$/', $platform)) { throw new InvalidArgumentException('Platform is invalid.'); }
        $deviceCode = 'syndicatum_device_code_' . AuthService::randomToken(32);
        $userCode = $this->userCode();
        $notificationChannel = bin2hex(random_bytes(16));
        $now = Db::now();
        $expires = gmdate('Y-m-d H:i:s', time() + 600);
        $statement = $this->pdo->prepare(
            "INSERT INTO connector_device_authorizations
             (device_code_hash, user_code_hash, device_name, platform, notification_channel, status, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)"
        );
        $statement->execute([hash('sha256', $deviceCode), hash('sha256', $userCode), $name, $platform, $notificationChannel, $now, $expires]);
        return [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'verification_uri' => '/connector-authorize.php?code=' . rawurlencode($userCode),
            'expires_at' => gmdate('c', strtotime($expires)),
            'interval_seconds' => 3,
            'authorization_id' => $notificationChannel,
        ];
    }

    public function pendingForUser($userCode)
    {
        $row = $this->authorization($userCode, true);
        return ['device_name' => $row['device_name'], 'platform' => $row['platform'], 'expires_at' => gmdate('c', strtotime($row['expires_at']))];
    }

    public function approve($userCode, array $user)
    {
        $row = $this->authorization($userCode, true);
        $statement = $this->pdo->prepare(
            "UPDATE connector_device_authorizations SET status = 'approved', approved_user_id = ?, approved_at = ?
             WHERE id = ? AND status = 'pending' AND expires_at > ?"
        );
        $statement->execute([(int) $user['id'], Db::now(), (int) $row['id'], Db::now()]);
        if ($statement->rowCount() !== 1) { throw new RuntimeException('DEVICE_AUTHORIZATION_EXPIRED'); }
        $this->auth->audit((int) $user['id'], 'connector.device_authorized', 'connector_authorization', (string) $row['id']);
        return ['approved' => true, 'device_name' => $row['device_name'], 'authorization_id' => $row['notification_channel']];
    }

    public function exchange($deviceCode)
    {
        $hash = hash('sha256', trim((string) $deviceCode));
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM connector_device_authorizations WHERE device_code_hash = ? FOR UPDATE');
            $statement->execute([$hash]);
            $row = $statement->fetch();
            if (!$row || strtotime($row['expires_at']) <= time()) { throw new RuntimeException('DEVICE_AUTHORIZATION_EXPIRED'); }
            if ($row['status'] === 'pending') { $this->pdo->rollBack(); return ['status' => 'pending']; }
            if ($row['status'] !== 'approved' || !$row['approved_user_id']) { throw new RuntimeException('DEVICE_AUTHORIZATION_INVALID'); }
            $token = 'syndicatum_device_' . AuthService::randomToken(32);
            $deviceId = $this->uuid();
            $now = Db::now();
            $expires = gmdate('Y-m-d H:i:s', time() + (90 * 86400));
            $insert = $this->pdo->prepare(
                'INSERT INTO connector_devices (id, user_id, display_name, platform, token_prefix, token_hash, created_at, last_seen_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$deviceId, (int) $row['approved_user_id'], $row['device_name'], $row['platform'], substr($token, 0, 24), hash('sha256', $token), $now, $now, $expires]);
            $this->pdo->prepare("UPDATE connector_device_authorizations SET status = 'consumed', consumed_at = ? WHERE id = ?")
                ->execute([$now, (int) $row['id']]);
            $this->auth->audit((int) $row['approved_user_id'], 'connector.device_registered', 'connector_device', $deviceId);
            $this->pdo->commit();
            return ['status' => 'authorized', 'access_token' => $token, 'device_id' => $deviceId, 'expires_at' => gmdate('c', strtotime($expires))];
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function authenticate($token)
    {
        $statement = $this->pdo->prepare(
            "SELECT d.*, u.display_name AS user_display_name FROM connector_devices d JOIN users u ON u.id = d.user_id
             WHERE d.token_hash = ? AND d.revoked_at IS NULL AND d.expires_at > ? AND u.status = 'active' AND u.deleted_at IS NULL LIMIT 1"
        );
        $statement->execute([hash('sha256', trim((string) $token)), Db::now()]);
        $device = $statement->fetch();
        if (!$device) { throw new RuntimeException('AUTHENTICATION_REQUIRED'); }
        $this->pdo->prepare('UPDATE connector_devices SET last_seen_at = ? WHERE id = ?')->execute([Db::now(), $device['id']]);
        return $device;
    }

    public function bindings(array $device)
    {
        $hasDeviceRoutes = Db::tableExists($this->pdo, 'connector_device_activation_routes');
        $routeColumns = $hasDeviceRoutes
            ? "COALESCE(r.runtime_type, b.runtime_type) AS runtime_type,
                    COALESCE(r.conversation_id, b.conversation_id) AS conversation_id,
                    COALESCE(r.working_directory, b.working_directory) AS working_directory,
                    COALESCE(r.updated_at, b.updated_at) AS updated_at,
                    CASE WHEN r.device_id IS NULL THEN 'legacy' ELSE 'device' END AS route_source"
            : "b.runtime_type, b.conversation_id, b.working_directory, b.updated_at, 'legacy' AS route_source";
        $routeJoin = $hasDeviceRoutes
            ? "LEFT JOIN connector_device_activation_routes r ON r.device_id = ? AND r.project_id = b.project_id
                AND r.agent_id = b.agent_id AND r.enabled = 1"
            : '';
        $statement = $this->pdo->prepare(
            "SELECT b.project_id, p.name AS project_name, b.agent_id, pp.id AS participant_id,
                    pa.display_name AS agent_name,
                    " . $routeColumns . "
             FROM agent_activation_bindings b
             JOIN projects p ON p.id = b.project_id AND p.status = 'active'
             JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? AND pm.status = 'active'
             JOIN project_agents pa ON pa.project_id = b.project_id AND pa.agent_id = b.agent_id AND pa.status = 'active'
             JOIN project_participants pp ON pp.project_id = b.project_id AND pp.agent_id = b.agent_id AND pp.kind = 'agent' AND pp.status = 'active'
             " . $routeJoin . "
             WHERE b.enabled = 1 AND b.created_by_user_id = ? AND b.runtime_type = 'codex'
             ORDER BY b.project_id, b.agent_id"
        );
        $parameters = [(int) $device['user_id']];
        if ($hasDeviceRoutes) { $parameters[] = $device['id']; }
        $parameters[] = (int) $device['user_id'];
        $statement->execute($parameters);
        return array_map(function ($row) {
            return [
                'project_id' => (int) $row['project_id'], 'project_name' => $row['project_name'],
                'agent_id' => (int) $row['agent_id'], 'participant_id' => (int) $row['participant_id'],
                'agent_name' => $row['agent_name'], 'runtime_type' => $row['runtime_type'],
                'conversation_id' => $row['conversation_id'], 'working_directory' => $row['working_directory'],
                'updated_at' => $row['updated_at'], 'route_source' => $row['route_source'],
            ];
        }, $statement->fetchAll());
    }

    public function configureBinding(array $device, array $input)
    {
        if (!Db::tableExists($this->pdo, 'connector_device_activation_routes')) { throw new RuntimeException('CONNECTOR_SCHEMA_REQUIRED'); }
        $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
        $agentId = isset($input['agent_id']) ? (int) $input['agent_id'] : 0;
        $conversationId = trim(isset($input['conversation_id']) ? (string) $input['conversation_id'] : '');
        $workingDirectory = trim(isset($input['working_directory']) ? (string) $input['working_directory'] : '');
        if ($projectId < 1 || $agentId < 1) { throw new InvalidArgumentException('project_id and agent_id are required.'); }
        $this->validateConversationId($conversationId);
        $this->validateWorkingDirectory($workingDirectory);
        if ($conversationId === '' || $workingDirectory === '') { throw new InvalidArgumentException('conversation_id and working_directory are required.'); }

        $eligible = $this->pdo->prepare(
            "SELECT COUNT(*) FROM agent_activation_bindings b
             JOIN projects p ON p.id = b.project_id AND p.status = 'active'
             JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? AND pm.status = 'active'
             JOIN project_agents pa ON pa.project_id = b.project_id AND pa.agent_id = b.agent_id AND pa.status = 'active'
             WHERE b.project_id = ? AND b.agent_id = ? AND b.enabled = 1
               AND b.created_by_user_id = ? AND b.runtime_type = 'codex'"
        );
        $eligible->execute([(int) $device['user_id'], $projectId, $agentId, (int) $device['user_id']]);
        if ((int) $eligible->fetchColumn() !== 1) { throw new RuntimeException('ACTIVATION_BINDING_NOT_FOUND'); }

        $now = Db::now();
        $statement = $this->pdo->prepare(
            "INSERT INTO connector_device_activation_routes
             (device_id, agent_id, project_id, runtime_type, conversation_id, working_directory, enabled, created_at, updated_at)
             VALUES (?, ?, ?, 'codex', ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE project_id = VALUES(project_id), runtime_type = VALUES(runtime_type),
               conversation_id = VALUES(conversation_id), working_directory = VALUES(working_directory),
               enabled = 1, updated_at = VALUES(updated_at)"
        );
        $statement->execute([$device['id'], $agentId, $projectId, $conversationId, $workingDirectory, $now, $now]);
        $this->auth->audit((int) $device['user_id'], 'connector.device_route_configured', 'connector_device', $device['id'], [
            'project_id' => $projectId,
            'agent_id' => $agentId,
            'conversation_configured' => true,
            'working_directory_configured' => true,
        ]);
        return ['project_id' => $projectId, 'agent_id' => $agentId, 'runtime_type' => 'codex',
            'conversation_id' => $conversationId, 'working_directory' => $workingDirectory,
            'enabled' => true, 'route_source' => 'device', 'updated_at' => $now];
    }

    private function validateConversationId($value)
    {
        if (strlen($value) > 255 || ($value !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value))) {
            throw new InvalidArgumentException('Conversation ID is invalid.');
        }
    }

    private function validateWorkingDirectory($value)
    {
        if (strlen($value) > 1024 || ($value !== '' && (preg_match('/[\x00-\x1F\x7F]/', $value)
            || !(preg_match('/^[A-Za-z]:[\\\\\/]/', $value) || strpos($value, '\\\\') === 0 || strpos($value, '/') === 0)))) {
            throw new InvalidArgumentException('Working directory must be an absolute path.');
        }
    }

    public function humanParticipant(array $device, $projectId)
    {
        $statement = $this->pdo->prepare(
            "SELECT pp.id, u.display_name FROM project_members pm
             JOIN project_participants pp ON pp.project_id = pm.project_id AND pp.user_id = pm.user_id AND pp.kind = 'human' AND pp.status = 'active'
             JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = ? AND pm.user_id = ? AND pm.status = 'active' LIMIT 1"
        );
        $statement->execute([(int) $projectId, (int) $device['user_id']]);
        $participant = $statement->fetch();
        if (!$participant) { throw new RuntimeException('PROJECT_NOT_FOUND'); }
        return ['id' => (int) $participant['id'], 'display_name' => $participant['display_name'] . ' connector'];
    }

    private function authorization($userCode, $pendingOnly)
    {
        $sql = 'SELECT * FROM connector_device_authorizations WHERE user_code_hash = ? AND expires_at > ?';
        if ($pendingOnly) { $sql .= " AND status = 'pending'"; }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute([hash('sha256', strtoupper(trim((string) $userCode))), Db::now()]);
        $row = $statement->fetch();
        if (!$row) { throw new RuntimeException('DEVICE_AUTHORIZATION_EXPIRED'); }
        return $row;
    }

    private function userCode()
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $raw = ''; for ($i = 0; $i < 8; $i++) { $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
            $code = substr($raw, 0, 4) . '-' . substr($raw, 4);
            $check = $this->pdo->prepare('SELECT COUNT(*) FROM connector_device_authorizations WHERE user_code_hash = ?');
            $check->execute([hash('sha256', $code)]);
        } while ((int) $check->fetchColumn() > 0);
        return $code;
    }

    private function uuid()
    {
        $data = random_bytes(16); $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
