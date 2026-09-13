<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/ProjectRepository.php';

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

    public function bindings(array $device, $provider = 'codex')
    {
        $provider = $this->connectorProvider($provider);
        $driver = $this->connectorDriver($provider);
        $statement = $this->pdo->prepare(
            "SELECT b.project_id, p.name AS project_name, b.agent_id, pp.id AS participant_id,
                    pa.display_name AS agent_name, b.runtime_type, b.conversation_id,
                    b.working_directory, b.updated_at
             FROM agent_activation_bindings b
             JOIN projects p ON p.id = b.project_id AND p.status = 'active'
             JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? AND pm.status = 'active'
             JOIN project_agents pa ON pa.project_id = b.project_id AND pa.agent_id = b.agent_id AND pa.status = 'active'
             JOIN project_participants pp ON pp.project_id = b.project_id AND pp.agent_id = b.agent_id AND pp.kind = 'agent' AND pp.status = 'active'
             WHERE b.enabled = 1 AND b.created_by_user_id = ? AND b.runtime_type = ? AND b.activation_driver = ?
             ORDER BY b.project_id, b.agent_id"
        );
        $statement->execute([(int) $device['user_id'], (int) $device['user_id'], $provider, $driver]);
        return array_map(function ($row) {
            return [
                'project_id' => (int) $row['project_id'], 'project_name' => $row['project_name'],
                'agent_id' => (int) $row['agent_id'], 'participant_id' => (int) $row['participant_id'],
                'agent_name' => $row['agent_name'], 'runtime_type' => $row['runtime_type'], 'provider' => $row['runtime_type'],
                'conversation_id' => $row['conversation_id'], 'working_directory' => $row['working_directory'],
                'updated_at' => $row['updated_at'], 'binding_scope' => 'shared',
            ];
        }, $statement->fetchAll());
    }

    public function pendingNotifications(array $device, $provider = 'chatgpt', $limit = 200)
    {
        $provider = $this->connectorProvider($provider);
        $driver = $this->connectorDriver($provider);
        $limit = max(1, min(200, (int) $limit));
        $statement = $this->pdo->prepare(
            "SELECT b.project_id, p.name AS project_name, b.agent_id, target.id AS participant_id,
                    pa.display_name AS agent_name, b.runtime_type, b.conversation_id,
                    m.id AS message_id, m.message_uuid, m.project_sequence, m.sender_participant_id,
                    COALESCE(sender_user.display_name, sender_agent.display_name) AS sender_name, m.body,
                    ma.reason, m.created_at
             FROM agent_activation_bindings b
             JOIN projects p ON p.id = b.project_id AND p.status = 'active'
             JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? AND pm.status = 'active'
             JOIN project_agents pa ON pa.project_id = b.project_id AND pa.agent_id = b.agent_id AND pa.status = 'active'
             JOIN project_participants target ON target.project_id = b.project_id AND target.agent_id = b.agent_id
                AND target.kind = 'agent' AND target.status = 'active'
             JOIN message_addressees ma ON ma.participant_id = target.id AND ma.notified_at IS NULL AND ma.acknowledged_at IS NULL
             JOIN messages m ON m.id = ma.message_id AND m.project_id = b.project_id AND m.deleted_at IS NULL
             JOIN project_participants sender ON sender.id = m.sender_participant_id
             LEFT JOIN users sender_user ON sender_user.id = sender.user_id
             LEFT JOIN project_agents sender_agent ON sender_agent.project_id = sender.project_id AND sender_agent.agent_id = sender.agent_id
             WHERE b.enabled = 1 AND b.created_by_user_id = ? AND b.runtime_type = ? AND b.activation_driver = ?
                AND m.sender_participant_id <> target.id
             ORDER BY m.created_at, m.id
             LIMIT " . $limit
        );
        $statement->execute([(int) $device['user_id'], (int) $device['user_id'], $provider, $driver]);
        return array_map(function ($row) use ($provider) {
            $message = [
                'id' => (int) $row['message_id'],
                'uuid' => $row['message_uuid'],
                'project_id' => (int) $row['project_id'],
                'project_sequence' => (int) $row['project_sequence'],
                'sender' => ['participant_id' => (int) $row['sender_participant_id'], 'display_name' => $row['sender_name']],
                'addressees' => [['participant_id' => (int) $row['participant_id'], 'reason' => $row['reason']]],
                'created_at' => $row['created_at'],
            ];
            if ($provider === 'gemini') {
                $message['body'] = $row['body'];
            }
            return [
                'project_id' => (int) $row['project_id'],
                'project_name' => $row['project_name'],
                'agent_id' => (int) $row['agent_id'],
                'participant_id' => (int) $row['participant_id'],
                'agent_name' => $row['agent_name'],
                'provider' => $row['runtime_type'],
                'conversation_id' => $row['conversation_id'],
                'message' => $message,
            ];
        }, $statement->fetchAll());
    }

    public function markNotificationDelivered(array $device, $provider, $projectId, $agentId, $messageId)
    {
        $provider = $this->connectorProvider($provider);
        $driver = $this->connectorDriver($provider);
        $statement = $this->pdo->prepare(
            "UPDATE message_addressees ma
             JOIN project_participants target ON target.id = ma.participant_id AND target.kind = 'agent'
             JOIN agent_activation_bindings b ON b.project_id = target.project_id AND b.agent_id = target.agent_id
             JOIN project_members pm ON pm.project_id = b.project_id AND pm.user_id = ? AND pm.status = 'active'
             SET ma.notified_at = COALESCE(ma.notified_at, ?)
             WHERE ma.message_id = ? AND b.project_id = ? AND b.agent_id = ?
                AND b.enabled = 1 AND b.created_by_user_id = ? AND b.runtime_type = ? AND b.activation_driver = ?"
        );
        $statement->execute([(int) $device['user_id'], Db::now(), (int) $messageId, (int) $projectId, (int) $agentId,
            (int) $device['user_id'], $provider, $driver]);
        if ($statement->rowCount() === 0) {
            $check = $this->pdo->prepare(
                "SELECT COUNT(*) FROM message_addressees ma
                 JOIN project_participants target ON target.id = ma.participant_id AND target.kind = 'agent'
                 JOIN agent_activation_bindings b ON b.project_id = target.project_id AND b.agent_id = target.agent_id
                 WHERE ma.message_id = ? AND b.project_id = ? AND b.agent_id = ? AND b.created_by_user_id = ?
                    AND b.runtime_type = ? AND b.activation_driver = ?"
            );
            $check->execute([(int) $messageId, (int) $projectId, (int) $agentId, (int) $device['user_id'], $provider, $driver]);
            if ((int) $check->fetchColumn() === 0) { throw new RuntimeException('NOTIFICATION_NOT_FOUND'); }
        }
        return ['delivered' => true, 'message_id' => (int) $messageId];
    }

    public function submitAgentReply(array $device, $provider, $projectId, $agentId, $messageId, $body)
    {
        $provider = $this->connectorProvider($provider);
        if ($provider !== 'gemini') {
            throw new InvalidArgumentException('Browser response capture is currently supported only for Gemini.');
        }
        $projectId = (int) $projectId;
        $agentId = (int) $agentId;
        $messageId = (int) $messageId;
        $body = trim((string) $body);
        if ($projectId < 1 || $agentId < 1 || $messageId < 1) {
            throw new InvalidArgumentException('project_id, agent_id, and message_id are required.');
        }
        if ($body === '') {
            throw new InvalidArgumentException('A captured Gemini response is required.');
        }

        $statement = $this->pdo->prepare(
            "SELECT b.project_id, b.agent_id, target.id AS participant_id, p.status AS project_status,
                    a.is_active, pa.status AS project_agent_status, target.status AS participant_status,
                    m.message_uuid, m.sender_participant_id, m.correlation_id
             FROM agent_activation_bindings b
             JOIN projects p ON p.id = b.project_id AND p.status = 'active'
             JOIN project_members pm ON pm.project_id = b.project_id AND pm.user_id = ? AND pm.status = 'active'
             JOIN chat_agents a ON a.id = b.agent_id AND a.is_active = 1
             JOIN project_agents pa ON pa.project_id = b.project_id AND pa.agent_id = b.agent_id AND pa.status = 'active'
             JOIN project_participants target ON target.project_id = b.project_id AND target.agent_id = b.agent_id
                AND target.kind = 'agent' AND target.status = 'active'
             JOIN message_addressees ma ON ma.message_id = ? AND ma.participant_id = target.id
             JOIN messages m ON m.id = ma.message_id AND m.project_id = b.project_id AND m.deleted_at IS NULL
             WHERE b.project_id = ? AND b.agent_id = ? AND b.enabled = 1 AND b.created_by_user_id = ?
                AND b.runtime_type = ? AND b.activation_driver = 'browser_companion'
                AND m.sender_participant_id <> target.id
             LIMIT 1"
        );
        $statement->execute([(int) $device['user_id'], $messageId, $projectId, $agentId,
            (int) $device['user_id'], $provider]);
        $context = $statement->fetch();
        if (!$context) {
            throw new RuntimeException('NOTIFICATION_NOT_FOUND');
        }

        $access = [
            'project_id' => $projectId,
            'participant_id' => (int) $context['participant_id'],
            'project_status' => $context['project_status'],
            'role' => 'agent',
            'identity' => ['kind' => 'agent', 'agent' => [
                'id' => $agentId,
                'is_active' => (int) $context['is_active'],
                'project_agent_status' => $context['project_agent_status'],
                'participant_status' => $context['participant_status'],
            ]],
        ];
        $repository = new ProjectRepository($this->pdo);
        $created = $repository->createMessage($access, [
            'body' => $body,
            'direct_participant_ids' => [(int) $context['sender_participant_id']],
            'reply_to_message_id' => $messageId,
            'idempotency_key' => 'browser-reply:' . $provider . ':' . $context['message_uuid'],
            'correlation_id' => $context['correlation_id'],
        ]);
        $repository->acknowledge($access, $messageId);
        $this->markNotificationDelivered($device, $provider, $projectId, $agentId, $messageId);

        return [
            'replied' => true,
            'created' => $created['created'],
            'message' => $created['message'],
            'acknowledged_message_id' => $messageId,
        ];
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

    private function connectorProvider($provider)
    {
        $provider = strtolower(trim((string) $provider));
        if (!in_array($provider, ['codex', 'chatgpt', 'gemini'], true)) {
            throw new InvalidArgumentException('The connector provider is not supported.');
        }
        return $provider;
    }

    private function connectorDriver($provider)
    {
        return in_array($provider, ['chatgpt', 'gemini'], true) ? 'browser_companion' : 'connector';
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
