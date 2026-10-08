<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/MessageOutbox.php';
require_once __DIR__ . '/AgentWebhookService.php';
require_once __DIR__ . '/WorkspaceAgentTriggerService.php';
require_once __DIR__ . '/ResponsesApiActivationService.php';
require_once __DIR__ . '/ResponsibilityEventService.php';
require_once __DIR__ . '/ProjectGovernancePolicy.php';
require_once __DIR__ . '/MessageSeverity.php';
require_once __DIR__ . '/NotificationHandlingService.php';

class ProjectRepository
{
    const MAX_MESSAGE_ATTACHMENTS = 20;

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
            'SELECT p.id, p.public_id, p.workspace_id, p.owner_user_id, p.name, p.slug, p.description, p.instructions, p.google_drive_url,
                    p.context_version, p.status,
                    p.created_at, p.updated_at, u.display_name AS owner_display_name
             FROM projects p JOIN users u ON u.id = p.owner_user_id WHERE p.id = ?'
        );
        $project->execute([$projectId]);
        $row = $project->fetch();
        $sequence = $this->pdo->prepare('SELECT next_sequence FROM project_message_sequences WHERE project_id = ?');
        $sequence->execute([$projectId]);
        $next = $sequence->fetchColumn();

        $governance = ProjectGovernancePolicy::current();
        return [
            'project' => [
                'id' => (int) $row['id'],
                'public_id' => $row['public_id'],
                'workspace_id' => (int) $row['workspace_id'],
                'owner_user_id' => (int) $row['owner_user_id'],
                'owner_display_name' => $row['owner_display_name'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'description' => $row['description'],
                'instructions' => $row['instructions'],
                'google_drive_url' => $row['google_drive_url'],
                'context_version' => (int) $row['context_version'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            'current_participant_id' => (int) $access['participant_id'],
            'current_role' => $access['role'],
            'governance' => $governance,
            'effective_instructions' => ProjectGovernancePolicy::effectiveInstructions($row['instructions'], $row['google_drive_url']),
            'permissions' => $this->projectPermissions($access),
            'latest_sequence' => $next === false ? 0 : max(0, (int) $next - 1),
            'capabilities' => [
                'realtime' => [
                    'enabled' => $this->settings->get('realtime.enabled') === true,
                    'admission_url' => 'api/v1/realtime-admission.php?project_id=' . $projectId,
                    'sdk_module_url' => 'vendor/pbb-realtime/js/sdk/index.js',
                ],
            ],
        ];
    }

    public function bootstrapContext(array $access)
    {
        $context = $this->projectContext($access);
        $governance = $context['governance'];
        $assignment = null;
        foreach ($this->participants($access) as $participant) {
            if ((int) $participant['id'] === (int) $access['participant_id']) {
                $assignment = $participant;
                break;
            }
        }
        if ($assignment === null) {
            throw new RuntimeException('PROJECT_NOT_FOUND');
        }
        $unacknowledged = $this->pdo->prepare(
            'SELECT COUNT(*) FROM message_addressees ma
             JOIN messages m ON m.id = ma.message_id
             WHERE m.project_id = ? AND ma.participant_id = ? AND ma.acknowledged_at IS NULL
               AND m.deleted_at IS NULL'
        );
        $unacknowledged->execute([(int) $access['project_id'], (int) $access['participant_id']]);
        return [
            'project' => $context['project'],
            'governance' => $governance,
            'effective_instructions' => ProjectGovernancePolicy::effectiveInstructions(
                $context['project']['instructions'],
                $context['project']['google_drive_url']
            ),
            'assignment' => $assignment,
            'permissions' => $context['permissions'],
            'work' => $this->taskBootstrapSummary($access),
            'timeline' => [
                'latest_sequence' => (int) $context['latest_sequence'],
                'unacknowledged_count' => (int) $unacknowledged->fetchColumn(),
            ],
        ];
    }

    private function taskBootstrapSummary(array $access)
    {
        if (!Db::tableExists($this->pdo, 'project_tasks')) {
            return ['tasks_available' => false, 'assigned_open_task_count' => null, 'requires_attention_count' => null];
        }
        $statement = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN assignee_participant_id = ? AND status NOT IN ('completed','cancelled') THEN 1 ELSE 0 END) AS assigned_open,
                SUM(CASE WHEN (assignee_participant_id = ? OR supervising_participant_id = ?)
                    AND status IN ('blocked','in_review') THEN 1 ELSE 0 END) AS requires_attention
             FROM project_tasks WHERE project_id = ?"
        );
        $participantId = (int) $access['participant_id'];
        $statement->execute([$participantId, $participantId, $participantId, (int) $access['project_id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return [
            'tasks_available' => true,
            'assigned_open_task_count' => (int) $row['assigned_open'],
            'requires_attention_count' => (int) $row['requires_attention'],
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
        $canUpdatePlanProgress = $humanManager || (!$isHuman
            && $this->agentHasPermissionScope((int) $access['identity']['agent']['id'], 'plan:progress'));
        return [
            'messages.read' => true,
            'messages.write' => $canWrite,
            'messages.acknowledge' => $canAcknowledge,
            'plan.progress.update' => $canUpdatePlanProgress,
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
            if (!in_array($filters['kind'], ['human', 'agent', 'integration'], true)) {
                throw new InvalidArgumentException('Invalid participant kind filter.');
            }
            $where[] = 'pp.kind = ?';
            $parameters[] = $filters['kind'];
        }

        $statement = $this->pdo->prepare(
            "SELECT pp.id, pp.project_id, pp.kind, pp.status, pp.created_at AS joined_at,
                    (SELECT COUNT(*) FROM messages participant_messages
                     WHERE participant_messages.project_id = pp.project_id
                       AND participant_messages.sender_participant_id = pp.id
                       AND participant_messages.deleted_at IS NULL) AS message_count,
                    (SELECT MAX(participant_messages.created_at) FROM messages participant_messages
                     WHERE participant_messages.project_id = pp.project_id
                       AND participant_messages.sender_participant_id = pp.id
                       AND participant_messages.deleted_at IS NULL) AS last_message_at,
                    u.id AS user_id, u.display_name AS user_display_name, u.avatar_url AS user_avatar_url,
                    u.normalized_email AS user_email,
                    CASE
                        WHEN u.google_subject IS NOT NULL THEN 'google'
                        WHEN u.pbb_user_id IS NOT NULL THEN 'pbb_account'
                        WHEN u.password_hash IS NOT NULL THEN 'password'
                        ELSE NULL
                    END AS authentication_source,
                    pm.role AS human_role,
                    a.id AS agent_id, pa.display_name AS agent_display_name, pa.avatar_url AS agent_avatar_url,
                    pa.provider, pa.runtime_name, pa.capabilities_json, pa.role_title, pa.role_summary,
                    pa.role_instructions, pa.role_version, pa.supervising_participant_id,
                    ic.id AS integration_id, ic.display_name AS integration_display_name,
                    ic.provider AS integration_provider, ic.description AS integration_description,
                    ic.external_reference AS integration_external_reference,
                    ic.capabilities_json AS integration_capabilities_json,
                    EXISTS(SELECT 1 FROM integration_credentials integration_credential
                        WHERE integration_credential.integration_id = ic.id
                          AND integration_credential.status = 'active') AS integration_credential_configured,
                    (SELECT MAX(integration_credential.last_used_at)
                     FROM integration_credentials integration_credential
                     WHERE integration_credential.integration_id = ic.id) AS integration_credential_last_used_at,
                    supervisor.kind AS supervisor_kind, supervisor.status AS supervisor_status,
                    COALESCE(supervisor_user.display_name, supervisor_agent.display_name) AS supervisor_display_name
             FROM project_participants pp
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_members pm ON pm.project_id = pp.project_id AND pm.user_id = pp.user_id
             LEFT JOIN chat_agents a ON a.id = pp.agent_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             LEFT JOIN integration_connections ic ON ic.project_id = pp.project_id
                AND ic.id = pp.integration_id AND pp.kind = 'integration'
             LEFT JOIN project_participants supervisor ON supervisor.project_id = pp.project_id
                AND supervisor.id = pa.supervising_participant_id
             LEFT JOIN users supervisor_user ON supervisor_user.id = supervisor.user_id
             LEFT JOIN project_agents supervisor_agent ON supervisor_agent.project_id = supervisor.project_id
                AND supervisor_agent.agent_id = supervisor.agent_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY COALESCE(u.display_name, pa.display_name, ic.display_name), pp.id"
        );
        $statement->execute($parameters);
        $identity = isset($access['identity']) && is_array($access['identity']) ? $access['identity'] : [];
        $viewerIsHuman = isset($identity['kind']) && $identity['kind'] === 'human';
        $viewerUserId = $viewerIsHuman && isset($identity['user']['id']) ? (int) $identity['user']['id'] : 0;
        $viewerCanManage = $viewerIsHuman && in_array($access['role'], ['owner', 'admin'], true);
        return array_map(function ($row) use ($viewerUserId, $viewerCanManage) {
            $participant = $this->normalizeParticipant($row);
            if ($row['kind'] === 'human' && ($viewerCanManage || (int) $row['user_id'] === $viewerUserId)) {
                $participant['email'] = $row['user_email'];
                $participant['authentication_source'] = $row['authentication_source'];
            }
            return $participant;
        }, $statement->fetchAll());
    }

    public function messagePage(array $access, array $filters = [])
    {
        $projectId = (int) $access['project_id'];
        $acknowledged = isset($filters['acknowledged']) ? trim((string) $filters['acknowledged']) : '';
        if ($acknowledged !== '' && $acknowledged !== 'false') {
            throw new InvalidArgumentException('acknowledged only supports false.');
        }
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 50;
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
            $senderIds = [];
            foreach (explode(',', (string) $filters['sender']) as $senderId) {
                $senderId = trim($senderId);
                if (!preg_match('/^[1-9][0-9]*$/', $senderId)) {
                    throw new InvalidArgumentException('sender must contain positive participant IDs separated by commas.');
                }
                $senderIds[(int) $senderId] = (int) $senderId;
            }
            if (count($senderIds) > 100) {
                throw new InvalidArgumentException('sender supports at most 100 participant IDs.');
            }
            $where[] = 'm.sender_participant_id IN (' . implode(', ', array_fill(0, count($senderIds), '?')) . ')';
            array_push($parameters, ...array_values($senderIds));
        }
        if (!empty($filters['message_kind'])) {
            $messageKind = trim((string) $filters['message_kind']);
            if (!in_array($messageKind, ['participant', 'system'], true)) {
                throw new InvalidArgumentException('message_kind must be participant or system.');
            }
            $where[] = 'm.message_kind = ?';
            $parameters[] = $messageKind;
        }
        if (!empty($filters['severity'])) {
            $where[] = 'm.severity = ?';
            $parameters[] = MessageSeverity::normalize($filters['severity']);
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
        if (!empty($filters['addressed_to_me']) || $acknowledged === 'false') {
            $where[] = 'EXISTS (SELECT 1 FROM message_addressees mine WHERE mine.message_id = m.id AND mine.participant_id = ?'
                . ($acknowledged === 'false' ? ' AND mine.acknowledged_at IS NULL' : '') . ')';
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

    public function markProjectRead(array $access, $lastReadSequence)
    {
        if (!is_int($lastReadSequence) && !ctype_digit((string) $lastReadSequence)) {
            throw new InvalidArgumentException('last_read_sequence must be a non-negative integer.');
        }
        $lastReadSequence = (int) $lastReadSequence;
        if ($lastReadSequence < 0) {
            throw new InvalidArgumentException('last_read_sequence must be a non-negative integer.');
        }

        $latest = $this->pdo->prepare(
            'SELECT COALESCE(MAX(project_sequence), 0) FROM messages WHERE project_id = ? AND deleted_at IS NULL'
        );
        $latest->execute([(int) $access['project_id']]);
        $safeSequence = min($lastReadSequence, (int) $latest->fetchColumn());
        $update = $this->pdo->prepare(
            'UPDATE project_participants
             SET last_read_sequence = GREATEST(last_read_sequence, ?)
             WHERE id = ? AND project_id = ? AND status = \'active\''
        );
        $update->execute([$safeSequence, (int) $access['participant_id'], (int) $access['project_id']]);

        $state = $this->pdo->prepare(
            'SELECT pp.last_read_sequence,
                    (SELECT COUNT(*) FROM messages m
                     WHERE m.project_id = pp.project_id AND m.deleted_at IS NULL
                       AND (m.sender_participant_id IS NULL OR m.sender_participant_id <> pp.id)
                       AND m.project_sequence > pp.last_read_sequence
                       AND m.created_at >= pp.created_at) AS unread_message_count
             FROM project_participants pp
             WHERE pp.id = ? AND pp.project_id = ? AND pp.status = \'active\''
        );
        $state->execute([(int) $access['participant_id'], (int) $access['project_id']]);
        $row = $state->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('PROJECT_NOT_FOUND');
        }
        return [
            'project_id' => (int) $access['project_id'],
            'last_read_sequence' => (int) $row['last_read_sequence'],
            'unread_message_count' => (int) $row['unread_message_count'],
        ];
    }

    public function revisions(array $access, $messageId)
    {
        $this->message($access, $messageId);
        $statement = $this->pdo->prepare(
            "SELECT mr.id, mr.previous_body, mr.new_body, mr.edited_at, pp.id AS participant_id, pp.kind,
                    COALESCE(u.display_name, pa.display_name, ic.display_name) AS display_name,
                    COALESCE(u.avatar_url, pa.avatar_url) AS avatar_url
             FROM message_revisions mr
             JOIN project_participants pp ON pp.id = mr.editor_participant_id
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             LEFT JOIN integration_connections ic ON ic.project_id = pp.project_id AND ic.id = pp.integration_id
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
        if (array_key_exists('action_requested', $input)
            && !is_bool($input['action_requested'])) {
            throw new InvalidArgumentException('action_requested must be a boolean.');
        }
        $severity = MessageSeverity::normalize(isset($input['severity']) ? $input['severity'] : 'neutral');
        $input['severity'] = $severity;
        $isResponsibilityEvent = isset($input['responsibility_event']);
        if ($isResponsibilityEvent && !is_array($input['responsibility_event'])) {
            throw new InvalidArgumentException('responsibility_event must be an object.');
        }
        $actionRequested = !$isResponsibilityEvent && !empty($input['action_requested']);
        $actionRequestType = null;
        if (!$isResponsibilityEvent && array_key_exists('action_request_type', $input)
            && $input['action_request_type'] !== null
            && !is_string($input['action_request_type'])) {
            throw new InvalidArgumentException('action_request_type must be a string.');
        }
        if (!$isResponsibilityEvent && array_key_exists('action_request_type', $input)
            && $input['action_request_type'] !== null
            && trim((string) $input['action_request_type']) !== '') {
            $actionRequestType = strtolower(trim((string) $input['action_request_type']));
            if (!in_array($actionRequestType, ['work', 'approval', 'review'], true)) {
                throw new InvalidArgumentException(
                    'action_request_type must be work, approval, or review.');
            }
        }
        if ($actionRequested) {
            $actionRequestType = $actionRequestType ?: 'work';
        } elseif ($actionRequestType !== null) {
            throw new InvalidArgumentException(
                'action_request_type is valid only when action_requested is true.');
        }
        $attachmentPublicIds = $isResponsibilityEvent
            ? [] : $this->normalizeMessageAttachmentIds($input);
        $input['attachment_file_ids'] = $attachmentPublicIds;
        $requestFingerprint = $idempotencyKey === '' ? null
            : $this->messageRequestFingerprint($senderId, $body, $input);
        $preAttachmentRequestFingerprint = $idempotencyKey === '' ? null
            : $this->messageRequestFingerprint($senderId, $body, $input, true, false);
        $legacyRequestFingerprint = $idempotencyKey === '' ? null
            : $this->messageRequestFingerprint($senderId, $body, $input, false, false);

        if ($idempotencyKey !== '') {
            $existing = $this->pdo->prepare(
                'SELECT id, request_fingerprint FROM messages WHERE project_id = ? AND sender_participant_id = ? AND client_idempotency_key = ? LIMIT 1'
            );
            $existing->execute([$projectId, $senderId, $idempotencyKey]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingRow !== false) {
                $this->assertMatchingMessageRequest($existingRow, $requestFingerprint,
                    [$preAttachmentRequestFingerprint, $legacyRequestFingerprint]);
                $handlingService = new NotificationHandlingService($this->pdo);
                $handling = !empty($input['complete_handling_id'])
                    ? $handlingService->complete($access, $input['complete_handling_id'],
                        (($input['handling_outcome'] ?? '') === 'waiting') ? 'waiting' : 'responded')
                    : $handlingService->completeCurrent($access, 'responded');
                return ['message' => $this->message($access, $existingRow['id']),
                    'created' => false, 'notification_handling' => $handling];
            }
        }

        $replyTo = $isResponsibilityEvent
            ? (int) (isset($input['responsibility_event']['request_message_id'])
                ? $input['responsibility_event']['request_message_id'] : 0)
            : (!empty($input['reply_to_message_id']) ? (int) $input['reply_to_message_id'] : null);
        if ($replyTo < 1) {
            $replyTo = null;
        }
        $replyDepth = 0;
        if ($replyTo !== null) {
            $reply = $this->pdo->prepare('SELECT reply_depth FROM messages WHERE id = ? AND project_id = ? AND deleted_at IS NULL');
            $reply->execute([$replyTo, $projectId]);
            $parentDepth = $reply->fetchColumn();
            if ($parentDepth === false) {
                if ($isResponsibilityEvent) {
                    throw new RuntimeException('MESSAGE_NOT_FOUND');
                }
                throw new InvalidArgumentException('Reply target does not belong to this project.');
            }
            $replyDepth = (int) $parentDepth + 1;
            $maxReplyDepth = (int) $this->settings->get('messaging.max_reply_depth');
            // The depth limit protects participant-authored conversation trees.
            // A server-owned workflow event must still be recorded against its
            // request when that request already sits at the configured limit.
            if (!$isResponsibilityEvent && $replyDepth > $maxReplyDepth) {
                throw new InvalidArgumentException('Maximum reply depth exceeded.');
            }
        }
        // Responsibility-event recipients are derived by the server after the
        // reducer validates the authoritative state. Missing client recipients
        // must never fall through to the ordinary broadcast default.
        $addressees = $isResponsibilityEvent
            ? [] : $this->resolveAddressees($projectId, $senderId, $input);
        if ($actionRequested
            && !in_array('direct', array_values($addressees), true)) {
            throw new InvalidArgumentException(
                'Action requests require at least one direct addressee.');
        }

        $this->pdo->beginTransaction();
        try {
            $attachmentFiles = $this->resolveMessageAttachmentFiles(
                $projectId, $attachmentPublicIds);
            $sequence = $this->nextSequence($projectId);
            $uuid = $this->uuidV4();
            $now = Db::now();
            $messageKind = $isResponsibilityEvent ? 'system' : 'participant';
            $eventType = null;
            $eventDataJson = null;
            if ($isResponsibilityEvent) {
                $eventType = 'responsibility.'
                    . trim((string) (isset($input['responsibility_event']['kind'])
                        ? $input['responsibility_event']['kind'] : 'unknown'));
                $eventDataJson = json_encode([
                    'subject_type' => 'responsibility_request',
                    'request_message_id' => $replyTo,
                    'initial_responder_participant_id' => isset($input['responsibility_event']['initial_responder_participant_id'])
                        ? (int) $input['responsibility_event']['initial_responder_participant_id'] : null,
                    'kind' => isset($input['responsibility_event']['kind'])
                        ? trim((string) $input['responsibility_event']['kind']) : null,
                    'actor_participant_id' => $senderId,
                    'target_participant_id' => isset($input['responsibility_event']['target_participant_id'])
                        ? (int) $input['responsibility_event']['target_participant_id'] : null,
                    'reference_event_message_id' => isset($input['responsibility_event']['reference_event_id'])
                        ? (int) $input['responsibility_event']['reference_event_id'] : null,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($eventDataJson === false) {
                    throw new RuntimeException('Unable to encode responsibility event metadata.');
                }
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO messages
                 (message_uuid, project_id, project_sequence, sender_participant_id, message_kind, severity,
                   event_type, event_data_json, reply_to_message_id, body,
                   client_idempotency_key, request_fingerprint, correlation_id, reply_depth,
                   action_requested, action_request_type, created_at, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $uuid, $projectId, $sequence, $senderId, $messageKind, $severity,
                $eventType, $eventDataJson, $replyTo, $body,
                $idempotencyKey === '' ? null : $idempotencyKey,
                $requestFingerprint,
                 empty($input['correlation_id']) ? null : substr((string) $input['correlation_id'], 0, 160),
                 $replyDepth, $actionRequested ? 1 : 0, $actionRequestType,
                 $now, $now,
             ]);
             $messageId = (int) $this->pdo->lastInsertId();

            if ($isResponsibilityEvent) {
                $eventResult = (new ResponsibilityEventService($this->pdo))->record($access,
                    $input['responsibility_event'], $messageId, $idempotencyKey, $body);
                foreach ($eventResult['notification_participant_ids'] as $participantId) {
                    $addressees[(int) $participantId] = 'direct';
                }
            }

            $add = $this->pdo->prepare(
                'INSERT INTO message_addressees
                 (message_id, participant_id, reason, responsibility_status_generation, created_at)
                 SELECT ?, pp.id, ?, CASE WHEN ? = ? THEN pp.status_generation ELSE NULL END, ?
                 FROM project_participants pp WHERE pp.id = ? AND pp.project_id = ?'
            );
            foreach ($addressees as $participantId => $reason) {
                $add->execute([$messageId, $reason, $reason, 'direct', $now,
                    $participantId, $projectId]);
            }

            if ($attachmentFiles) {
                $attach = $this->pdo->prepare(
                    'INSERT INTO message_file_attachments
                     (project_id, message_id, project_file_id, attached_by_participant_id, position, created_at)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($attachmentFiles as $position => $file) {
                    $attach->execute([$projectId, $messageId, (int) $file['id'],
                        $senderId, $position, $now]);
                }
            }

            $message = $this->messagesByIds($projectId, [$messageId]);
            $message = $message[0];
            if ($this->settings->get('realtime.enabled') === true) {
                $this->outbox->enqueueMessageCreated($projectId, $messageId, $sequence, $message);
            }
            (new AgentWebhookService($this->pdo))->enqueueMessageCreated($projectId, $messageId, $message);
            (new WorkspaceAgentTriggerService($this->pdo))->enqueueMessageCreated($projectId, $messageId);
            (new ResponsesApiActivationService($this->pdo))->enqueueMessageCreated($projectId, $messageId);
            $handlingService = new NotificationHandlingService($this->pdo);
            $handling = !empty($input['complete_handling_id'])
                ? $handlingService->complete($access, $input['complete_handling_id'],
                    (($input['handling_outcome'] ?? '') === 'waiting') ? 'waiting' : 'responded')
                : $handlingService->completeCurrent($access, 'responded');
            $this->pdo->commit();
            return ['message' => $message, 'created' => true,
                'notification_handling' => $handling];
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($idempotencyKey !== '' && $exception->getCode() === '23000') {
                $existing = $this->pdo->prepare(
                    'SELECT id, request_fingerprint FROM messages WHERE project_id = ? AND sender_participant_id = ? AND client_idempotency_key = ? LIMIT 1'
                );
                $existing->execute([$projectId, $senderId, $idempotencyKey]);
                $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
                if ($existingRow !== false) {
                    $this->assertMatchingMessageRequest($existingRow, $requestFingerprint,
                        [$preAttachmentRequestFingerprint, $legacyRequestFingerprint]);
                    $handlingService = new NotificationHandlingService($this->pdo);
                    $handling = !empty($input['complete_handling_id'])
                        ? $handlingService->complete($access, $input['complete_handling_id'],
                            (($input['handling_outcome'] ?? '') === 'waiting') ? 'waiting' : 'responded')
                        : $handlingService->completeCurrent($access, 'responded');
                    return ['message' => $this->message($access, $existingRow['id']),
                        'created' => false, 'notification_handling' => $handling];
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

    private function assertMatchingMessageRequest(array $existing, $requestFingerprint,
        array $legacyRequestFingerprints = [])
    {
        // Pre-migration messages have no original request fingerprint. Preserve
        // their historical replay behavior rather than comparing edited content.
        if ($existing['request_fingerprint'] !== null
            && !hash_equals($existing['request_fingerprint'], $requestFingerprint)
            && !$this->matchesAnyFingerprint($existing['request_fingerprint'], $legacyRequestFingerprints)) {
            throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
        }
    }

    private function matchesAnyFingerprint($existing, array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && hash_equals($existing, $candidate)) { return true; }
        }
        return false;
    }

    private function messageRequestFingerprint($senderId, $body, array $input,
        $includeActionRequested = true, $includeAttachments = true)
    {
        $broadcast = !empty($input['broadcast']);
        $direct = [];
        $mention = [];
        if (!$broadcast) {
            foreach (['direct_participant_ids', 'mention_participant_ids'] as $field) {
                foreach (isset($input[$field]) && is_array($input[$field]) ? $input[$field] : [] as $value) {
                    $id = (int) $value;
                    if ($id > 0 && $id !== $senderId) {
                        if ($field === 'direct_participant_ids') { $direct[$id] = $id; }
                        else { $mention[$id] = $id; }
                    }
                }
            }
            foreach ($direct as $id) { unset($mention[$id]); }
            if (!$direct && !$mention) { $broadcast = true; }
        }
        sort($direct, SORT_NUMERIC);
        sort($mention, SORT_NUMERIC);
        $fingerprintInput = [
            'body' => $body,
            'reply_to_message_id' => !empty($input['reply_to_message_id']) ? (int) $input['reply_to_message_id'] : null,
            'correlation_id' => empty($input['correlation_id']) ? null : substr((string) $input['correlation_id'], 0, 160),
            'broadcast' => $broadcast,
            'direct_participant_ids' => $broadcast ? [] : array_values($direct),
            'mention_participant_ids' => $broadcast ? [] : array_values($mention),
        ];
        if ($includeAttachments) {
            $fingerprintInput['attachment_file_ids'] = isset($input['attachment_file_ids'])
                && is_array($input['attachment_file_ids'])
                ? array_values($input['attachment_file_ids']) : [];
        }
        if ($includeActionRequested) {
            $fingerprintInput['action_requested'] = !isset($input['responsibility_event'])
                && !empty($input['action_requested']);
        }
        if (array_key_exists('action_request_type', $input)) {
            $fingerprintInput['action_request_type'] = $input['action_request_type'] === null
                ? null : strtolower(trim((string) $input['action_request_type']));
        }
        $severity = MessageSeverity::normalize(isset($input['severity']) ? $input['severity'] : 'neutral');
        if ($severity !== 'neutral') {
            $fingerprintInput['severity'] = $severity;
        }
        if (isset($input['responsibility_event'])) {
            if (!is_array($input['responsibility_event'])) {
                throw new InvalidArgumentException('responsibility_event must be an object.');
            }
            $event = $input['responsibility_event'];
            ksort($event);
            $fingerprintInput['responsibility_event'] = $event;
        }
        return hash('sha256', json_encode($fingerprintInput,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function acknowledge(array $access, $messageId, $completeHandlingId = null)
    {
        $this->pdo->beginTransaction();
        try {
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
            $message = $this->message($access, $messageId);
            $handlingService = new NotificationHandlingService($this->pdo);
            $handling = $completeHandlingId === null
                ? $handlingService->completeCurrent($access, 'acknowledged', $messageId)
                : $handlingService->complete($access, $completeHandlingId, 'acknowledged');
            $this->pdo->commit();
            return $completeHandlingId === null ? $message
                : ['message' => $message, 'notification_handling' => $handling];
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function confirmNotificationReceipt(array $access, $messageId, $projectSequence)
    {
        if (($access['identity']['kind'] ?? '') !== 'agent') {
            throw new RuntimeException('NOTIFICATION_RECEIPT_NOT_AVAILABLE');
        }
        $messageId = (int) $messageId;
        $projectSequence = (int) $projectSequence;
        if ($messageId < 1 || $projectSequence < 1) {
            throw new InvalidArgumentException('message_id and project_sequence must be positive integers.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT m.project_sequence, ma.notified_at, pp.agent_id, b.runtime_type AS provider
                 FROM messages m
                 JOIN message_addressees ma ON ma.message_id = m.id AND ma.participant_id = ?
                 JOIN project_participants pp ON pp.id = ma.participant_id
                    AND pp.project_id = m.project_id AND pp.kind = 'agent' AND pp.status = 'active'
                 JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
                    AND pa.status = 'active'
                 JOIN agent_activation_bindings b ON b.project_id = pp.project_id
                    AND b.agent_id = pp.agent_id AND b.enabled = 1
                    AND b.runtime_type = 'chatgpt' AND b.activation_driver = 'browser_companion'
                 WHERE m.id = ? AND m.project_id = ? AND m.deleted_at IS NULL
                 LIMIT 1 FOR UPDATE"
            );
            $statement->execute([
                (int) $access['participant_id'], $messageId,
                (int) $access['project_id'],
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('MESSAGE_NOT_ADDRESSED_TO_PARTICIPANT');
            }
            if ((int) $row['project_sequence'] !== $projectSequence) {
                throw new RuntimeException('MESSAGE_SEQUENCE_MISMATCH');
            }
            if (strtolower(trim((string) $row['provider'])) !== 'chatgpt') {
                throw new RuntimeException('NOTIFICATION_RECEIPT_NOT_AVAILABLE');
            }

            $created = $row['notified_at'] === null;
            $receivedAt = $created ? Db::now() : $row['notified_at'];
            if ($created) {
                $update = $this->pdo->prepare(
                    'UPDATE message_addressees SET notified_at = ?
                     WHERE message_id = ? AND participant_id = ? AND notified_at IS NULL'
                );
                $update->execute([
                    $receivedAt, $messageId, (int) $access['participant_id'],
                ]);
                if ($this->settings->get('realtime.enabled') === true) {
                    $this->outbox->enqueueNotificationReceived(
                        (int) $access['project_id'], $messageId, $projectSequence,
                        (int) $row['agent_id'], (int) $access['participant_id'],
                        $receivedAt
                    );
                }
            }
            $handling = (new NotificationHandlingService($this->pdo))->ensureResponding(
                $access, $messageId, $projectSequence);
            $this->pdo->commit();
            return [
                'received' => true,
                'created' => $created,
                'provider' => 'chatgpt',
                'project_id' => (int) $access['project_id'],
                'agent_id' => (int) $row['agent_id'],
                'participant_id' => (int) $access['participant_id'],
                'message_id' => $messageId,
                'project_sequence' => $projectSequence,
                'received_at' => $receivedAt,
                'notification_handling' => $handling,
            ];
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateMessage(array $access, $messageId, array $input)
    {
        $this->requireWritableProject($access);
        $current = $this->message($access, $messageId);
        if ($current['message_kind'] !== 'participant') { throw new RuntimeException('SYSTEM_MESSAGE_IMMUTABLE'); }
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
        if ($current['message_kind'] !== 'participant') { throw new RuntimeException('SYSTEM_MESSAGE_IMMUTABLE'); }
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
                    COALESCE(u.display_name, pa.display_name, ic.display_name) AS sender_display_name,
                    COALESCE(u.avatar_url, pa.avatar_url) AS sender_avatar_url
             FROM messages m
             JOIN project_participants pp ON pp.id = m.sender_participant_id
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             LEFT JOIN integration_connections ic ON ic.project_id = pp.project_id AND ic.id = pp.integration_id
             WHERE m.project_id = ? AND m.id IN ($placeholders)
             ORDER BY m.project_sequence DESC"
        );
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        $messageIds = array_map(function ($row) { return (int) $row['id']; }, $rows);
        $addressees = $this->loadAddressees($messageIds);
        $attachments = $this->loadMessageAttachments($messageIds);
        return array_map(function ($row) use ($addressees, $attachments) {
            $id = (int) $row['id'];
            return [
                'id' => $id,
                'uuid' => $row['message_uuid'],
                'project_id' => (int) $row['project_id'],
                'project_sequence' => (int) $row['project_sequence'],
                'message_kind' => isset($row['message_kind']) ? $row['message_kind'] : 'participant',
                'severity' => isset($row['severity']) ? MessageSeverity::normalize($row['severity']) : 'neutral',
                'system_event' => isset($row['message_kind']) && $row['message_kind'] === 'system' ? [
                    'type' => $row['event_type'],
                    'data' => $row['event_data_json'] === null ? null : json_decode($row['event_data_json'], true),
                ] : null,
                'sender' => [
                    'participant_id' => (int) $row['sender_participant_id'],
                    'kind' => $row['sender_kind'],
                    'display_name' => $row['sender_display_name'],
                    'avatar_url' => $row['sender_avatar_url'],
                ],
                'reply_to_message_id' => $row['reply_to_message_id'] === null ? null : (int) $row['reply_to_message_id'],
                'body' => $row['deleted_at'] === null ? $row['body'] : null,
                'addressees' => isset($addressees[$id]) ? $addressees[$id] : [],
                'attachments' => $row['deleted_at'] === null && isset($attachments[$id])
                    ? $attachments[$id] : [],
                'correlation_id' => $row['correlation_id'],
                 'reply_depth' => (int) $row['reply_depth'],
                'action_requested' => (bool) $row['action_requested'],
                'action_request_type' => empty($row['action_request_type'])
                    ? null : $row['action_request_type'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'deleted_at' => $row['deleted_at'],
                'edited' => $row['updated_at'] !== $row['created_at'],
                'revision_count' => (int) $row['revision_count'],
            ];
        }, $rows);
    }

    private function normalizeMessageAttachmentIds(array $input)
    {
        if (!array_key_exists('attachment_file_ids', $input)
            || $input['attachment_file_ids'] === null) {
            return [];
        }
        if (!is_array($input['attachment_file_ids'])) {
            throw new InvalidArgumentException('attachment_file_ids must be an array.');
        }
        if (count($input['attachment_file_ids']) > self::MAX_MESSAGE_ATTACHMENTS) {
            throw new InvalidArgumentException('A message supports at most '
                . self::MAX_MESSAGE_ATTACHMENTS . ' attachments.');
        }
        $result = [];
        foreach ($input['attachment_file_ids'] as $value) {
            $publicId = strtolower(trim((string) $value));
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $publicId)) {
                throw new InvalidArgumentException(
                    'Each attachment_file_ids value must be a canonical project file ID.');
            }
            if (isset($result[$publicId])) {
                throw new InvalidArgumentException('A file can be attached to a message only once.');
            }
            $result[$publicId] = $publicId;
        }
        return array_values($result);
    }

    private function resolveMessageAttachmentFiles($projectId, array $publicIds)
    {
        if (!$publicIds) {
            return [];
        }
        if (!Db::tableExists($this->pdo, 'message_file_attachments')) {
            throw new RuntimeException('MESSAGE_ATTACHMENTS_NOT_INSTALLED');
        }
        $placeholders = implode(',', array_fill(0, count($publicIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id, public_id FROM project_files
             WHERE project_id = ? AND public_id IN ($placeholders)
               AND state = 'available' AND deleted_at IS NULL FOR UPDATE"
        );
        $statement->execute(array_merge([(int) $projectId], $publicIds));
        $byPublicId = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byPublicId[strtolower($row['public_id'])] = $row;
        }
        $ordered = [];
        foreach ($publicIds as $publicId) {
            if (!isset($byPublicId[$publicId])) {
                throw new InvalidArgumentException(
                    'An attachment is unavailable or does not belong to this project.');
            }
            $ordered[] = $byPublicId[$publicId];
        }
        return $ordered;
    }

    private function loadMessageAttachments(array $messageIds)
    {
        if (!$messageIds || !Db::tableExists($this->pdo, 'message_file_attachments')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT mfa.message_id, mfa.position, pf.public_id, pf.display_name,
                    pf.original_name, pf.mime_type, pf.size_bytes, pf.sha256,
                    pf.state, pf.version, pf.created_at, pf.updated_at, pf.deleted_at
             FROM message_file_attachments mfa
             JOIN project_files pf ON pf.id = mfa.project_file_id
             WHERE mfa.message_id IN ($placeholders)
             ORDER BY mfa.message_id, mfa.position, mfa.id"
        );
        $statement->execute($messageIds);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $messageId = (int) $row['message_id'];
            if (!isset($result[$messageId])) {
                $result[$messageId] = [];
            }
            $available = $row['state'] === 'available' && $row['deleted_at'] === null;
            $result[$messageId][] = [
                'id' => $row['public_id'],
                'name' => $row['display_name'],
                'original_name' => $row['original_name'],
                'url' => $available ? 'files/' . $row['public_id'] : null,
                'mime_type' => $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'sha256' => $row['sha256'],
                'state' => $row['state'],
                'available' => $available,
                'version' => (int) $row['version'],
                'position' => (int) $row['position'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return $result;
    }

    private function loadAddressees(array $messageIds)
    {
        if (!$messageIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT ma.*, pp.kind,
                    COALESCE(u.display_name, pa.display_name, ic.display_name) AS display_name,
                    COALESCE(u.avatar_url, pa.avatar_url) AS avatar_url
             FROM message_addressees ma
             JOIN project_participants pp ON pp.id = ma.participant_id
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             LEFT JOIN integration_connections ic ON ic.project_id = pp.project_id AND ic.id = pp.integration_id
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
        $isAgent = $row['kind'] === 'agent';
        $capabilitiesJson = $isAgent ? $row['capabilities_json']
            : ($row['kind'] === 'integration' ? $row['integration_capabilities_json'] : null);
        $capabilities = empty($capabilitiesJson) ? [] : json_decode($capabilitiesJson, true);
        $identityId = $isHuman ? $row['user_id'] : ($isAgent ? $row['agent_id'] : $row['integration_id']);
        $displayName = $isHuman ? $row['user_display_name']
            : ($isAgent ? $row['agent_display_name'] : $row['integration_display_name']);
        $avatarUrl = $isHuman ? $row['user_avatar_url'] : ($isAgent ? $row['agent_avatar_url'] : null);
        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'kind' => $row['kind'],
            'identity_id' => (int) $identityId,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'status' => $row['status'],
            'role' => $isHuman ? $row['human_role'] : ($isAgent ? 'agent' : 'integration'),
            'joined_at' => $row['joined_at'],
            'last_message_at' => $row['last_message_at'],
            'message_count' => (int) $row['message_count'],
            'provider' => $isAgent ? $row['provider'] : ($row['kind'] === 'integration' ? $row['integration_provider'] : null),
            'runtime' => $isAgent ? $row['runtime_name'] : null,
            'capabilities' => is_array($capabilities) ? $capabilities : [],
            'description' => $row['kind'] === 'integration' ? $row['integration_description'] : null,
            'external_reference' => $row['kind'] === 'integration' ? $row['integration_external_reference'] : null,
            'credential_configured' => $row['kind'] === 'integration'
                ? (bool) $row['integration_credential_configured'] : false,
            'credential_last_used_at' => $row['kind'] === 'integration'
                ? $row['integration_credential_last_used_at'] : null,
            'role_title' => $isAgent ? $row['role_title'] : null,
            'role_summary' => $isAgent ? $row['role_summary'] : null,
            'role_instructions' => $isAgent ? $row['role_instructions'] : null,
            'role_version' => $isAgent ? (int) $row['role_version'] : null,
            'supervisor' => !$isAgent || $row['supervising_participant_id'] === null ? null : [
                'participant_id' => (int) $row['supervising_participant_id'],
                'kind' => $row['supervisor_kind'],
                'display_name' => $row['supervisor_display_name'],
                'active' => $row['supervisor_status'] === 'active',
            ],
        ];
    }

    private function resolveAddressees($projectId, $senderId, array $input)
    {
        $resolved = [];
        if (!empty($input['broadcast'])) {
            $statement = $this->pdo->prepare(
                "SELECT id FROM project_participants
                 WHERE project_id = ? AND status = 'active' AND kind IN ('human', 'agent') AND id <> ?"
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
            $statement = $this->pdo->prepare(
                "SELECT id FROM project_participants
                 WHERE project_id = ? AND status = 'active' AND kind IN ('human', 'agent') AND id <> ?"
            );
            $statement->execute([$projectId, $senderId]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $participantId) {
                $resolved[(int) $participantId] = 'broadcast';
            }
            return $resolved;
        }
        $ids = array_keys($resolved);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM project_participants
             WHERE project_id = ? AND status = 'active' AND kind IN ('human', 'agent') AND id IN ($placeholders)"
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
        $exists = $this->pdo->prepare(
            'SELECT next_sequence FROM project_message_sequences WHERE project_id = ?'
        );
        $exists->execute([$projectId]);
        if ($exists->fetchColumn() === false) {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO project_message_sequences (project_id, next_sequence) VALUES (?, 1)'
            );
            $insert->execute([$projectId]);
        }
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
