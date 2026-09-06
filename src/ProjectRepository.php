<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/MessageOutbox.php';
require_once __DIR__ . '/AgentWebhookService.php';

class ProjectRepository
{
    private $pdo;
    private $settings;
    private $outbox;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->settings = new SettingsService($pdo);
        $this->outbox = new MessageOutbox($pdo);
    }

    public function projectContext(array $access)
    {
        $projectId = (int) $access['project_id'];
        $project = $this->pdo->prepare(
            'SELECT p.id, p.workspace_id, p.owner_user_id, p.name, p.slug, p.description, p.instructions, p.status,
                    p.created_at, p.updated_at, u.display_name AS owner_display_name
             FROM projects p JOIN users u ON u.id = p.owner_user_id WHERE p.id = ?'
        );
        $project->execute([$projectId]);
        $row = $project->fetch();
        $sequence = $this->pdo->prepare('SELECT next_sequence FROM project_message_sequences WHERE project_id = ?');
        $sequence->execute([$projectId]);
        $next = $sequence->fetchColumn();

        return [
            'project' => [
                'id' => (int) $row['id'],
                'workspace_id' => (int) $row['workspace_id'],
                'owner_user_id' => (int) $row['owner_user_id'],
                'owner_display_name' => $row['owner_display_name'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'description' => $row['description'],
                'instructions' => $row['instructions'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            'current_participant_id' => (int) $access['participant_id'],
            'current_role' => $access['role'],
            'permissions' => $this->projectPermissions($access),
            'latest_sequence' => $next === false ? 0 : max(0, (int) $next - 1),
            'capabilities' => [
                'realtime' => [
                    'enabled' => $this->settings->get('realtime.enabled') === true,
                    'admission_url' => '/api/v1/realtime-admission.php?project_id=' . $projectId,
                    'sdk_module_url' => '/vendor/pbb-realtime/js/sdk/index.js',
                ],
            ],
        ];
    }

    private function projectPermissions(array $access)
    {
        $humanManager = $access['identity']['kind'] === 'human'
            && in_array($access['role'], ['owner', 'admin'], true);
        $isHuman = $access['identity']['kind'] === 'human';
        $canWrite = $isHuman
            ? $access['role'] !== 'viewer'
            : $this->agentHasPermissionScope((int) $access['identity']['agent']['id'], 'messages:write');
        $canAcknowledge = $isHuman
            ? true
            : $this->agentHasPermissionScope((int) $access['identity']['agent']['id'], 'messages:acknowledge');
        return [
            'messages.read' => true,
            'messages.write' => $canWrite,
            'messages.acknowledge' => $canAcknowledge,
            'project.manage' => $humanManager,
            'project.admin' => $humanManager,
            'members.manage' => $humanManager,
            'agents.manage' => $humanManager,
            'ownership.transfer' => $access['identity']['kind'] === 'human' && $access['role'] === 'owner',
        ];
    }

    private function agentHasPermissionScope($agentId, $scope)
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM agent_credential_scopes WHERE agent_id = ?');
        $count->execute([(int) $agentId]);
        if ((int) $count->fetchColumn() === 0) {
            return in_array($scope, ['messages:read', 'messages:write', 'messages:acknowledge', 'profile:read'], true);
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM agent_credential_scopes WHERE agent_id = ? AND scope = ?');
        $statement->execute([(int) $agentId, $scope]);
        return (int) $statement->fetchColumn() > 0;
    }

    public function participants(array $access, array $filters = [])
    {
        $parameters = [(int) $access['project_id']];
        $where = ["pp.project_id = ?"];
        if (!empty($filters['status'])) {
            if (!in_array($filters['status'], ['active', 'suspended', 'removed'], true)) {
                throw new InvalidArgumentException('Invalid participant status filter.');
            }
            $where[] = 'pp.status = ?';
            $parameters[] = $filters['status'];
        }
        if (!empty($filters['kind'])) {
            if (!in_array($filters['kind'], ['human', 'agent'], true)) {
                throw new InvalidArgumentException('Invalid participant kind filter.');
            }
            $where[] = 'pp.kind = ?';
            $parameters[] = $filters['kind'];
        }

        $statement = $this->pdo->prepare(
            "SELECT pp.id, pp.project_id, pp.kind, pp.status,
                    u.id AS user_id, u.display_name AS user_display_name, u.avatar_url AS user_avatar_url,
                    pm.role AS human_role,
                    a.id AS agent_id, pa.display_name AS agent_display_name, pa.avatar_url AS agent_avatar_url,
                    pa.provider, pa.runtime_name, pa.capabilities_json
             FROM project_participants pp
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_members pm ON pm.project_id = pp.project_id AND pm.user_id = pp.user_id
             LEFT JOIN chat_agents a ON a.id = pp.agent_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY COALESCE(u.display_name, pa.display_name), pp.id"
        );
        $statement->execute($parameters);
        return array_map([$this, 'normalizeParticipant'], $statement->fetchAll());
    }

    public function messagePage(array $access, array $filters = [])
    {
        $projectId = (int) $access['project_id'];
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 100;
        $limit = max(1, min(200, $limit));
        $before = empty($filters['before']) ? null : $this->decodeCursor($filters['before'], $projectId);
        $after = empty($filters['after']) ? null : $this->decodeCursor($filters['after'], $projectId);
        if ($before !== null && $after !== null) {
            throw new InvalidArgumentException('before and after cannot be combined.');
        }

        $where = ['m.project_id = ?'];
        $parameters = [$projectId];
        if ($before !== null) {
            $where[] = 'm.project_sequence < ?';
            $parameters[] = $before;
        }
        if ($after !== null) {
            $where[] = 'm.project_sequence > ?';
            $parameters[] = $after;
        }
        if (!empty($filters['sender'])) {
            $where[] = 'm.sender_participant_id = ?';
            $parameters[] = (int) $filters['sender'];
        }
        if (!empty($filters['idempotency_key'])) {
            $idempotencyKey = trim((string) $filters['idempotency_key']);
            if (strlen($idempotencyKey) > 160) { throw new InvalidArgumentException('idempotency_key exceeds 160 characters.'); }
            $where[] = 'm.sender_participant_id = ? AND m.client_idempotency_key = ?';
            $parameters[] = (int) $access['participant_id'];
            $parameters[] = $idempotencyKey;
        }
        if (!empty($filters['q'])) {
            $where[] = 'm.body LIKE ?';
            $parameters[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['q'])) . '%';
        }
        if (!empty($filters['from'])) {
            $where[] = 'm.created_at >= ?';
            $parameters[] = $this->validatedDate($filters['from'], 'from');
        }
        if (!empty($filters['to'])) {
            $where[] = 'm.created_at <= ?';
            $parameters[] = $this->validatedDate($filters['to'], 'to', true);
        }
        if (!empty($filters['addressed_to_me']) || !empty($filters['acknowledged'])) {
            $where[] = 'EXISTS (SELECT 1 FROM message_addressees mine WHERE mine.message_id = m.id AND mine.participant_id = ?'
                . ((!empty($filters['acknowledged']) && $filters['acknowledged'] === 'false') ? ' AND mine.acknowledged_at IS NULL' : '') . ')';
            $parameters[] = (int) $access['participant_id'];
        }

        $fetchOrder = $after !== null ? 'ASC' : 'DESC';
        $sql = 'SELECT m.id FROM messages m WHERE ' . implode(' AND ', $where)
            . ' ORDER BY m.project_sequence ' . $fetchOrder . ' LIMIT ' . ($limit + 1);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $hasMore = count($ids) > $limit;
        if ($hasMore) {
            array_pop($ids);
        }
        if (!empty($ids)) {
            $seenPlaceholders = implode(',', array_fill(0, count($ids), '?'));
            $seen = $this->pdo->prepare(
                "UPDATE message_addressees SET seen_at = COALESCE(seen_at, ?)
                 WHERE participant_id = ? AND message_id IN ($seenPlaceholders)"
            );
            $seen->execute(array_merge([Db::now(), (int) $access['participant_id']], $ids));
        }
        $messages = $this->messagesByIds($projectId, $ids);
        $first = count($messages) ? $messages[0]['project_sequence'] : null;
        $last = count($messages) ? $messages[count($messages) - 1]['project_sequence'] : null;

        return [
            'data' => $messages,
            'page' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'newer_cursor' => $first === null ? null : $this->encodeCursor($projectId, $first),
                'older_cursor' => $last === null ? null : $this->encodeCursor($projectId, $last),
                'order' => 'desc',
                'mode' => $after !== null ? 'forward' : 'history',
                'continuation_cursor' => $after !== null ? ($first === null ? null : $this->encodeCursor($projectId, $first)) : ($last === null ? null : $this->encodeCursor($projectId, $last)),
            ],
        ];
    }

    public function message(array $access, $messageId)
    {
        $messages = $this->messagesByIds((int) $access['project_id'], [(int) $messageId]);
        if (!$messages) {
            throw new RuntimeException('MESSAGE_NOT_FOUND');
        }
        return $messages[0];
    }

    public function revisions(array $access, $messageId)
    {
        $this->message($access, $messageId);
        $statement = $this->pdo->prepare(
            "SELECT mr.id, mr.previous_body, mr.new_body, mr.edited_at, pp.id AS participant_id, pp.kind,
                    COALESCE(u.display_name, pa.display_name) AS display_name,
                    COALESCE(u.avatar_url, pa.avatar_url) AS avatar_url
             FROM message_revisions mr
             JOIN project_participants pp ON pp.id = mr.editor_participant_id
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             WHERE mr.message_id = ? ORDER BY mr.id DESC"
        );
        $statement->execute([(int) $messageId]);
        return array_map(function ($row) {
            return [
                'id' => (int) $row['id'], 'previous_body' => $row['previous_body'], 'new_body' => $row['new_body'],
                'edited_at' => $row['edited_at'], 'editor' => ['participant_id' => (int) $row['participant_id'],
                    'kind' => $row['kind'], 'display_name' => $row['display_name'], 'avatar_url' => $row['avatar_url']],
            ];
        }, $statement->fetchAll());
    }

    public function createMessage(array $access, array $input)
    {
        $this->requireWritableProject($access);
        $projectId = (int) $access['project_id'];
        $senderId = (int) $access['participant_id'];
        $body = trim(isset($input['body']) ? (string) $input['body'] : '');
        if ($body === '') {
            throw new InvalidArgumentException('Message body is required.');
        }
        $maxMessageBytes = (int) $this->settings->get('messaging.max_message_bytes');
        if (strlen($body) > $maxMessageBytes) {
            throw new InvalidArgumentException('Message body exceeds ' . $maxMessageBytes . ' bytes.');
        }
        $idempotencyKey = isset($input['idempotency_key']) ? trim((string) $input['idempotency_key']) : '';
        if (strlen($idempotencyKey) > 160) {
            throw new InvalidArgumentException('idempotency_key exceeds 160 characters.');
        }

        if ($idempotencyKey !== '') {
            $existing = $this->pdo->prepare(
                'SELECT id FROM messages WHERE project_id = ? AND sender_participant_id = ? AND client_idempotency_key = ? LIMIT 1'
            );
            $existing->execute([$projectId, $senderId, $idempotencyKey]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                return ['message' => $this->message($access, $existingId), 'created' => false];
            }
        }

        $replyTo = !empty($input['reply_to_message_id']) ? (int) $input['reply_to_message_id'] : null;
        $replyDepth = 0;
        if ($replyTo !== null) {
            $reply = $this->pdo->prepare('SELECT reply_depth FROM messages WHERE id = ? AND project_id = ? AND deleted_at IS NULL');
            $reply->execute([$replyTo, $projectId]);
            $parentDepth = $reply->fetchColumn();
            if ($parentDepth === false) {
                throw new InvalidArgumentException('Reply target does not belong to this project.');
            }
            $replyDepth = (int) $parentDepth + 1;
            $maxReplyDepth = (int) $this->settings->get('messaging.max_reply_depth');
            if ($replyDepth > $maxReplyDepth) {
                throw new InvalidArgumentException('Maximum reply depth exceeded.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $sequence = $this->nextSequence($projectId);
            $uuid = $this->uuidV4();
            $now = Db::now();
            $insert = $this->pdo->prepare(
                'INSERT INTO messages
                 (message_uuid, project_id, project_sequence, sender_participant_id, reply_to_message_id, body,
                  client_idempotency_key, correlation_id, reply_depth, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $uuid, $projectId, $sequence, $senderId, $replyTo, $body,
                $idempotencyKey === '' ? null : $idempotencyKey,
                empty($input['correlation_id']) ? null : substr((string) $input['correlation_id'], 0, 160),
                $replyDepth, $now, $now,
            ]);
            $messageId = (int) $this->pdo->lastInsertId();
            $addressees = $this->resolveAddressees($projectId, $senderId, $input);
            $add = $this->pdo->prepare(
                'INSERT INTO message_addressees (message_id, participant_id, reason, created_at) VALUES (?, ?, ?, ?)'
            );
            foreach ($addressees as $participantId => $reason) {
                $add->execute([$messageId, $participantId, $reason, $now]);
            }

            $message = $this->messagesByIds($projectId, [$messageId]);
            $message = $message[0];
            if ($this->settings->get('realtime.enabled') === true) {
                $this->outbox->enqueueMessageCreated($projectId, $messageId, $sequence, $message);
            }
            (new AgentWebhookService($this->pdo))->enqueueMessageCreated($projectId, $messageId, $message);
            $this->pdo->commit();
            return ['message' => $message, 'created' => true];
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($idempotencyKey !== '' && $exception->getCode() === '23000') {
                $existing = $this->pdo->prepare(
                    'SELECT id FROM messages WHERE project_id = ? AND sender_participant_id = ? AND client_idempotency_key = ? LIMIT 1'
                );
                $existing->execute([$projectId, $senderId, $idempotencyKey]);
                $existingId = $existing->fetchColumn();
                if ($existingId !== false) {
                    return ['message' => $this->message($access, $existingId), 'created' => false];
                }
            }
            throw $exception;
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function acknowledge(array $access, $messageId)
    {
        $this->message($access, $messageId);
        $now = Db::now();
        $statement = $this->pdo->prepare(
            'UPDATE message_addressees SET seen_at = COALESCE(seen_at, ?), acknowledged_at = COALESCE(acknowledged_at, ?)
             WHERE message_id = ? AND participant_id = ?'
        );
        $statement->execute([$now, $now, (int) $messageId, (int) $access['participant_id']]);
        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM message_addressees WHERE message_id = ? AND participant_id = ?');
            $exists->execute([(int) $messageId, (int) $access['participant_id']]);
            if ((int) $exists->fetchColumn() === 0) {
                throw new RuntimeException('MESSAGE_NOT_ADDRESSED_TO_PARTICIPANT');
            }
        }
        return $this->message($access, $messageId);
    }

    public function updateMessage(array $access, $messageId, array $input)
    {
        $this->requireWritableProject($access);
        $current = $this->message($access, $messageId);
        $this->requireMessageOwnerOrModerator($access, $current);
        if ($current['deleted_at'] !== null) {
            throw new RuntimeException('MESSAGE_NOT_FOUND');
        }
        $body = trim(isset($input['body']) ? (string) $input['body'] : '');
        $maxMessageBytes = (int) $this->settings->get('messaging.max_message_bytes');
        if ($body === '' || strlen($body) > $maxMessageBytes) {
            throw new InvalidArgumentException('Message body must contain between 1 and ' . $maxMessageBytes . ' bytes.');
        }
        if ($body === $current['body']) {
            return $current;
        }
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $revision = $this->pdo->prepare(
                'INSERT INTO message_revisions (message_id, editor_participant_id, previous_body, new_body, edited_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $revision->execute([(int) $messageId, (int) $access['participant_id'], $current['body'], $body, $now]);
            $update = $this->pdo->prepare('UPDATE messages SET body = ?, updated_at = ? WHERE id = ? AND project_id = ?');
            $update->execute([$body, $now, (int) $messageId, (int) $access['project_id']]);
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $this->message($access, $messageId);
    }

    public function deleteMessage(array $access, $messageId)
    {
        $this->requireWritableProject($access);
        $current = $this->message($access, $messageId);
        $this->requireMessageOwnerOrModerator($access, $current);
        if ($current['deleted_at'] === null) {
            $statement = $this->pdo->prepare('UPDATE messages SET deleted_at = ?, updated_at = ? WHERE id = ? AND project_id = ?');
            $now = Db::now();
            $statement->execute([$now, $now, (int) $messageId, (int) $access['project_id']]);
        }
        return $this->message($access, $messageId);
    }

    private function messagesByIds($projectId, array $ids)
    {
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $parameters = array_merge([(int) $projectId], $ids);
        $statement = $this->pdo->prepare(
            "SELECT m.*, pp.kind AS sender_kind,
                    (SELECT COUNT(*) FROM message_revisions mr WHERE mr.message_id = m.id) AS revision_count,
                    COALESCE(u.display_name, pa.display_name) AS sender_display_name,
                    COALESCE(u.avatar_url, pa.avatar_url) AS sender_avatar_url
             FROM messages m
             JOIN project_participants pp ON pp.id = m.sender_participant_id
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             WHERE m.project_id = ? AND m.id IN ($placeholders)
             ORDER BY m.project_sequence DESC"
        );
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        $messageIds = array_map(function ($row) { return (int) $row['id']; }, $rows);
        $addressees = $this->loadAddressees($messageIds);
        return array_map(function ($row) use ($addressees) {
            $id = (int) $row['id'];
            return [
                'id' => $id,
                'uuid' => $row['message_uuid'],
                'project_id' => (int) $row['project_id'],
                'project_sequence' => (int) $row['project_sequence'],
                'sender' => [
                    'participant_id' => (int) $row['sender_participant_id'],
                    'kind' => $row['sender_kind'],
                    'display_name' => $row['sender_display_name'],
                    'avatar_url' => $row['sender_avatar_url'],
                ],
                'reply_to_message_id' => $row['reply_to_message_id'] === null ? null : (int) $row['reply_to_message_id'],
                'body' => $row['deleted_at'] === null ? $row['body'] : null,
                'addressees' => isset($addressees[$id]) ? $addressees[$id] : [],
                'correlation_id' => $row['correlation_id'],
                'reply_depth' => (int) $row['reply_depth'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'deleted_at' => $row['deleted_at'],
                'edited' => $row['updated_at'] !== $row['created_at'],
                'revision_count' => (int) $row['revision_count'],
            ];
        }, $rows);
    }

    private function loadAddressees(array $messageIds)
    {
        if (!$messageIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT ma.*, pp.kind,
                    COALESCE(u.display_name, pa.display_name) AS display_name,
                    COALESCE(u.avatar_url, pa.avatar_url) AS avatar_url
             FROM message_addressees ma
             JOIN project_participants pp ON pp.id = ma.participant_id
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             WHERE ma.message_id IN ($placeholders) ORDER BY ma.message_id, display_name, ma.participant_id"
        );
        $statement->execute($messageIds);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $messageId = (int) $row['message_id'];
            if (!isset($result[$messageId])) {
                $result[$messageId] = [];
            }
            $result[$messageId][] = [
                'participant_id' => (int) $row['participant_id'],
                'kind' => $row['kind'],
                'display_name' => $row['display_name'],
                'avatar_url' => $row['avatar_url'],
                'reason' => $row['reason'],
                'notified_at' => $row['notified_at'],
                'seen_at' => $row['seen_at'],
                'acknowledged_at' => $row['acknowledged_at'],
            ];
        }
        return $result;
    }

    private function normalizeParticipant($row)
    {
        $isHuman = $row['kind'] === 'human';
        $capabilities = $isHuman || empty($row['capabilities_json']) ? [] : json_decode($row['capabilities_json'], true);
        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'kind' => $row['kind'],
            'identity_id' => (int) ($isHuman ? $row['user_id'] : $row['agent_id']),
            'display_name' => $isHuman ? $row['user_display_name'] : $row['agent_display_name'],
            'avatar_url' => $isHuman ? $row['user_avatar_url'] : $row['agent_avatar_url'],
            'status' => $row['status'],
            'role' => $isHuman ? $row['human_role'] : 'agent',
            'provider' => $isHuman ? null : $row['provider'],
            'runtime' => $isHuman ? null : $row['runtime_name'],
            'capabilities' => is_array($capabilities) ? $capabilities : [],
        ];
    }

    private function resolveAddressees($projectId, $senderId, array $input)
    {
        $resolved = [];
        if (!empty($input['broadcast'])) {
            $statement = $this->pdo->prepare(
                "SELECT id FROM project_participants WHERE project_id = ? AND status = 'active' AND id <> ?"
            );
            $statement->execute([$projectId, $senderId]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $participantId) {
                $resolved[(int) $participantId] = 'broadcast';
            }
            return $resolved;
        }

        foreach (['direct_participant_ids' => 'direct', 'mention_participant_ids' => 'mention'] as $field => $reason) {
            $values = isset($input[$field]) && is_array($input[$field]) ? $input[$field] : [];
            foreach ($values as $value) {
                $participantId = (int) $value;
                if ($participantId > 0 && $participantId !== $senderId && !isset($resolved[$participantId])) {
                    $resolved[$participantId] = $reason;
                }
            }
        }
        if (!$resolved) {
            return [];
        }
        $ids = array_keys($resolved);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM project_participants WHERE project_id = ? AND status = 'active' AND id IN ($placeholders)"
        );
        $statement->execute(array_merge([$projectId], $ids));
        $found = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($found);
        $expected = $ids;
        sort($expected);
        if ($found !== $expected) {
            throw new InvalidArgumentException('One or more addressees do not belong to this project.');
        }
        return $resolved;
    }

    private function nextSequence($projectId)
    {
        $insert = $this->pdo->prepare('INSERT IGNORE INTO project_message_sequences (project_id, next_sequence) VALUES (?, 1)');
        $insert->execute([$projectId]);
        $lock = $this->pdo->prepare('SELECT next_sequence FROM project_message_sequences WHERE project_id = ? FOR UPDATE');
        $lock->execute([$projectId]);
        $sequence = (int) $lock->fetchColumn();
        $update = $this->pdo->prepare('UPDATE project_message_sequences SET next_sequence = ? WHERE project_id = ?');
        $update->execute([$sequence + 1, $projectId]);
        return $sequence;
    }

    private function requireWritableProject(array $access)
    {
        if ($access['project_status'] !== 'active') {
            throw new RuntimeException('PROJECT_ARCHIVED');
        }
    }

    private function requireMessageOwnerOrModerator(array $access, array $message)
    {
        if ((int) $message['sender']['participant_id'] === (int) $access['participant_id']) {
            return;
        }
        if ($access['identity']['kind'] === 'human' && in_array($access['role'], ['owner', 'admin'], true)) {
            return;
        }
        throw new RuntimeException('MESSAGE_WRITE_FORBIDDEN');
    }

    private function encodeCursor($projectId, $sequence)
    {
        return rtrim(strtr(base64_encode(json_encode(['p' => (int) $projectId, 's' => (int) $sequence])), '+/', '-_'), '=');
    }

    private function decodeCursor($cursor, $projectId)
    {
        $padded = strtr((string) $cursor, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = json_decode(base64_decode($padded, true), true);
        if (!is_array($decoded) || !isset($decoded['p'], $decoded['s'])
            || (int) $decoded['p'] !== (int) $projectId || (int) $decoded['s'] < 1) {
            throw new InvalidArgumentException('Invalid message cursor.');
        }
        return (int) $decoded['s'];
    }

    private function validatedDate($value, $field, $endOfDay = false)
    {
        $value = trim((string) $value);
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid ' . $field . ' date; expected YYYY-MM-DD.');
        }
        return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }

    private function uuidV4()
    {
        if (function_exists('random_bytes')) {
            $bytes = random_bytes(16);
        } else {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes(16, $strong);
            if ($bytes === false || !$strong) {
                throw new RuntimeException('A cryptographically secure random source is required.');
            }
        }
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
