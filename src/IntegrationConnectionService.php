<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/MessageOutbox.php';

/**
 * Owns project-scoped identities for external systems. Phase 1 deliberately
 * grants no read, addressing, responsibility, task, or interactive identity.
 * The only declared capability is events.write; Phase 2 supplies the inbound
 * authentication and event-ingestion path.
 */
class IntegrationConnectionService
{
    private $pdo;
    private $auth;
    private $settings;
    private $outbox;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
        $this->settings = new SettingsService($pdo);
        $this->outbox = new MessageOutbox($pdo);
    }

    public function create($projectId, $actorUserId, array $input)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $displayName = trim(isset($input['display_name']) ? (string) $input['display_name'] : '');
        if ($displayName === '' || strlen($displayName) > 120) {
            throw new InvalidArgumentException('Integration display name must contain 1 to 120 characters.');
        }
        $provider = strtolower(trim(isset($input['provider']) ? (string) $input['provider'] : 'generic'));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $provider)) {
            throw new InvalidArgumentException('Integration provider must use 2 to 80 lowercase letters, numbers, underscores, or hyphens.');
        }
        $description = $this->optionalText($input, 'description', 500, 'Integration description');
        $externalReference = $this->optionalText($input, 'external_reference', 255, 'External reference');
        $notificationParticipantIds = $this->validateNotificationParticipants($projectId,
            array_key_exists('notification_participant_ids', $input)
                ? $input['notification_participant_ids'] : $this->defaultNotificationParticipantIds($projectId));
        $now = Db::now();
        $publicId = $this->uuidV4();
        $capabilities = ['events.write'];

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO integration_connections
                 (public_id, project_id, provider, display_name, description, external_reference,
                  capabilities_json, status, created_by_user_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)"
            );
            $insert->execute([$publicId, (int) $projectId, $provider, $displayName,
                $description, $externalReference, json_encode($capabilities), (int) $actorUserId, $now, $now]);
            $integrationId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                "INSERT INTO project_participants
                 (project_id, kind, integration_id, status, created_at, updated_at)
                 VALUES (?, 'integration', ?, 'active', ?, ?)"
            )->execute([(int) $projectId, $integrationId, $now, $now]);
            $this->replaceNotificationParticipants($projectId, $integrationId, $notificationParticipantIds, $now);
            $this->auth->audit((int) $actorUserId, 'project.integration_created',
                'integration_connection', (string) $integrationId,
                ['project_id' => (int) $projectId, 'provider' => $provider, 'capabilities' => $capabilities]);
            $this->enqueueParticipantsChanged($projectId, 'integration_created');
            $this->pdo->commit();
            return $this->connection($projectId, $actorUserId, $integrationId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function listConnections($projectId, $actorUserId, $includeRemoved = false)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $sql = $this->baseSelect() . ' WHERE ic.project_id = ?';
        if (!$includeRemoved) { $sql .= " AND ic.status <> 'removed'"; }
        $sql .= ' ORDER BY ic.display_name, ic.id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute([(int) $projectId]);
        return array_map([$this, 'normalize'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function connection($projectId, $actorUserId, $integrationId)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $statement = $this->pdo->prepare($this->baseSelect() . ' WHERE ic.project_id = ? AND ic.id = ? LIMIT 1');
        $statement->execute([(int) $projectId, (int) $integrationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('INTEGRATION_NOT_FOUND'); }
        return $this->normalize($row);
    }

    public function update($projectId, $actorUserId, $integrationId, array $input)
    {
        $current = $this->connection($projectId, $actorUserId, $integrationId);
        if ($current['status'] === 'removed') { throw new RuntimeException('INTEGRATION_REMOVED'); }
        $displayName = array_key_exists('display_name', $input)
            ? trim((string) $input['display_name']) : $current['display_name'];
        if ($displayName === '' || strlen($displayName) > 120) {
            throw new InvalidArgumentException('Integration display name must contain 1 to 120 characters.');
        }
        $description = array_key_exists('description', $input)
            ? $this->optionalText($input, 'description', 500, 'Integration description') : $current['description'];
        $externalReference = array_key_exists('external_reference', $input)
            ? $this->optionalText($input, 'external_reference', 255, 'External reference') : $current['external_reference'];
        $status = array_key_exists('status', $input) ? strtolower(trim((string) $input['status'])) : $current['status'];
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new InvalidArgumentException('Integration status must be active or disabled.');
        }
        $notificationParticipantIds = array_key_exists('notification_participant_ids', $input)
            ? $this->validateNotificationParticipants($projectId, $input['notification_participant_ids'])
            : $current['notification_participant_ids'];
        return $this->writeUpdate($projectId, $actorUserId, $integrationId,
            $displayName, $description, $externalReference, $status, 'integration_updated', $notificationParticipantIds);
    }

    public function remove($projectId, $actorUserId, $integrationId)
    {
        $current = $this->connection($projectId, $actorUserId, $integrationId);
        if ($current['status'] === 'removed') { return $current; }
        return $this->writeUpdate($projectId, $actorUserId, $integrationId,
            $current['display_name'], $current['description'], $current['external_reference'],
            'removed', 'integration_removed', $current['notification_participant_ids']);
    }

    private function writeUpdate($projectId, $actorUserId, $integrationId,
        $displayName, $description, $externalReference, $status, $change, array $notificationParticipantIds)
    {
        $participantStatus = $status === 'active' ? 'active' : ($status === 'removed' ? 'removed' : 'suspended');
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                'SELECT status FROM integration_connections WHERE project_id = ? AND id = ? FOR UPDATE'
            );
            $lock->execute([(int) $projectId, (int) $integrationId]);
            $persistedStatus = $lock->fetchColumn();
            if ($persistedStatus === false) { throw new RuntimeException('INTEGRATION_NOT_FOUND'); }
            if ($persistedStatus === 'removed' && $status !== 'removed') {
                throw new RuntimeException('INTEGRATION_REMOVED');
            }
            $this->pdo->prepare(
                'UPDATE integration_connections
                 SET display_name = ?, description = ?, external_reference = ?, status = ?, updated_at = ?
                 WHERE project_id = ? AND id = ?'
            )->execute([$displayName, $description, $externalReference, $status, $now,
                (int) $projectId, (int) $integrationId]);
            $this->replaceNotificationParticipants($projectId, $integrationId, $notificationParticipantIds, $now);
            $this->pdo->prepare(
                'UPDATE project_participants
                 SET status_generation = status_generation + CASE WHEN status <> ? THEN 1 ELSE 0 END,
                     status = ?, updated_at = ?
                 WHERE project_id = ? AND integration_id = ?'
            )->execute([$participantStatus, $participantStatus, $now, (int) $projectId, (int) $integrationId]);
            if ($status === 'removed' && Db::tableExists($this->pdo, 'integration_credentials')) {
                $this->pdo->prepare(
                    "UPDATE integration_credentials SET status = 'revoked', revoked_at = ?
                     WHERE integration_id = ? AND status = 'active'"
                )->execute([$now, (int) $integrationId]);
            }
            $this->auth->audit((int) $actorUserId, 'project.' . $change,
                'integration_connection', (string) ((int) $integrationId),
                ['project_id' => (int) $projectId, 'status' => $status]);
            $this->enqueueParticipantsChanged($projectId, $change);
            $this->pdo->commit();
            return $this->connection($projectId, $actorUserId, $integrationId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    private function baseSelect()
    {
        return "SELECT ic.*, pp.id AS participant_id, pp.status AS participant_status,
                       pp.status_generation,
                       EXISTS(SELECT 1 FROM integration_credentials credential
                           WHERE credential.integration_id = ic.id AND credential.status = 'active') AS credential_configured,
                       (SELECT MAX(credential.last_used_at) FROM integration_credentials credential
                           WHERE credential.integration_id = ic.id) AS credential_last_used_at
                FROM integration_connections ic
                JOIN project_participants pp ON pp.project_id = ic.project_id
                    AND pp.integration_id = ic.id AND pp.kind = 'integration'";
    }

    private function normalize(array $row)
    {
        $capabilities = json_decode($row['capabilities_json'], true);
        return [
            'id' => (int) $row['id'],
            'public_id' => $row['public_id'],
            'project_id' => (int) $row['project_id'],
            'participant_id' => (int) $row['participant_id'],
            'kind' => 'integration',
            'provider' => $row['provider'],
            'display_name' => $row['display_name'],
            'description' => $row['description'],
            'external_reference' => $row['external_reference'],
            'capabilities' => is_array($capabilities) ? $capabilities : [],
            'status' => $row['status'],
            'participant_status' => $row['participant_status'],
            'status_generation' => (int) $row['status_generation'],
            'credential_configured' => (bool) $row['credential_configured'],
            'credential_last_used_at' => $row['credential_last_used_at'],
            'notification_participant_ids' => $this->notificationParticipantIds((int) $row['project_id'], (int) $row['id']),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function requireProjectAdmin($projectId, $userId)
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM projects p
             JOIN project_members pm ON pm.project_id = p.id
             WHERE p.id = ? AND pm.user_id = ? AND pm.status = 'active'
               AND pm.role IN ('owner', 'admin')"
        );
        $statement->execute([(int) $projectId, (int) $userId]);
        if ((int) $statement->fetchColumn() !== 1) { throw new RuntimeException('PROJECT_NOT_FOUND'); }
    }

    private function optionalText(array $input, $key, $limit, $label)
    {
        $value = isset($input[$key]) ? trim((string) $input[$key]) : '';
        if (strlen($value) > $limit) {
            throw new InvalidArgumentException($label . ' must not exceed ' . $limit . ' characters.');
        }
        return $value === '' ? null : $value;
    }

    private function validateNotificationParticipants($projectId, $participantIds)
    {
        if (!is_array($participantIds)) {
            throw new InvalidArgumentException('Notify participants must be a list.');
        }
        $ids = [];
        foreach ($participantIds as $participantId) {
            if (!is_int($participantId) && !ctype_digit((string) $participantId)) {
                throw new InvalidArgumentException('Notify participants contains an invalid participant.');
            }
            $participantId = (int) $participantId;
            if ($participantId < 1) {
                throw new InvalidArgumentException('Notify participants contains an invalid participant.');
            }
            $ids[$participantId] = $participantId;
        }
        $ids = array_values($ids);
        if (!$ids) { throw new InvalidArgumentException('Select at least one participant to notify.'); }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM project_participants
             WHERE project_id = ? AND status = 'active' AND kind IN ('human', 'agent')
               AND id IN ($placeholders)"
        );
        $statement->execute(array_merge([(int) $projectId], $ids));
        $valid = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        sort($valid); sort($ids);
        if ($valid !== $ids) {
            throw new InvalidArgumentException('Notify participants must be active people or agents in this project.');
        }
        return $ids;
    }

    private function defaultNotificationParticipantIds($projectId)
    {
        $statement = $this->pdo->prepare(
            "SELECT participant.id
             FROM projects project
             JOIN project_participants participant ON participant.project_id = project.id
                 AND participant.kind = 'human' AND participant.user_id = project.owner_user_id
                 AND participant.status = 'active'
             WHERE project.id = ? LIMIT 1"
        );
        $statement->execute([(int) $projectId]);
        $participantId = (int) $statement->fetchColumn();
        return $participantId > 0 ? [$participantId] : [];
    }

    private function replaceNotificationParticipants($projectId, $integrationId, array $participantIds, $now)
    {
        $this->pdo->prepare('DELETE FROM integration_notification_recipients WHERE integration_id = ?')
            ->execute([(int) $integrationId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO integration_notification_recipients
             (project_id, integration_id, participant_id, created_at) VALUES (?, ?, ?, ?)'
        );
        foreach ($participantIds as $participantId) {
            $insert->execute([(int) $projectId, (int) $integrationId, (int) $participantId, $now]);
        }
    }

    private function notificationParticipantIds($projectId, $integrationId)
    {
        $statement = $this->pdo->prepare(
            'SELECT participant_id FROM integration_notification_recipients
             WHERE project_id = ? AND integration_id = ? ORDER BY participant_id'
        );
        $statement->execute([(int) $projectId, (int) $integrationId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function enqueueParticipantsChanged($projectId, $change)
    {
        if ($this->settings->get('realtime.enabled') === true) {
            $this->outbox->enqueueParticipantsChanged((int) $projectId, $change);
        }
    }

    private function uuidV4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
