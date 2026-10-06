<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/MessageOutbox.php';

class NotificationHandlingService
{
    const MIN_LEASE_SECONDS = 60;
    const MAX_LEASE_SECONDS = 1800;
    const DEFAULT_LEASE_SECONDS = 300;

    private $pdo;
    private $outbox;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->outbox = new MessageOutbox($pdo);
    }

    public function setState(array $access, $messageId, $projectSequence, $state, $leaseSeconds = null)
    {
        $this->requireDiscussionAgent($access);
        $messageId = (int) $messageId;
        $projectSequence = (int) $projectSequence;
        $state = strtolower(trim((string) $state));
        if ($messageId < 1 || $projectSequence < 1) {
            throw new InvalidArgumentException('message_id and project_sequence must be positive integers.');
        }
        if (!in_array($state, ['responding', 'working', 'waiting', 'available'], true)) {
            throw new InvalidArgumentException('state must be responding, working, waiting, or available.');
        }
        $active = in_array($state, ['responding', 'working'], true);
        $leaseSeconds = $leaseSeconds === null ? self::DEFAULT_LEASE_SECONDS : (int) $leaseSeconds;
        if ($active && ($leaseSeconds < self::MIN_LEASE_SECONDS || $leaseSeconds > self::MAX_LEASE_SECONDS)) {
            throw new InvalidArgumentException('lease_seconds must be between 60 and 1800.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $context = $this->messageContext($access, $messageId, $projectSequence, true);
            $this->expireActive((int) $access['project_id'], (int) $context['agent_id'],
                (string) $context['provider'], (string) $context['conversation_id']);
            $existing = $this->rowForMessage($messageId, (int) $access['participant_id'], true);
            $now = Db::now();
            $handlingId = $existing ? $existing['handling_uuid'] : $this->uuid();
            $expiresAt = $active ? gmdate('Y-m-d H:i:s', time() + $leaseSeconds) : null;
            $completedAt = $active ? null : $now;
            $outcome = $active ? null : ($state === 'waiting' ? 'waiting' : 'no_action');

            if ($existing) {
                $statement = $this->pdo->prepare(
                    'UPDATE notification_handling_leases SET state = ?, active_slot = ?, lease_expires_at = ?,
                     completion_outcome = ?, updated_at = ?, completed_at = ? WHERE id = ?'
                );
                $statement->execute([$state, $active ? 1 : null, $expiresAt, $outcome,
                    $now, $completedAt, (int) $existing['id']]);
            } else {
                $statement = $this->pdo->prepare(
                    'INSERT INTO notification_handling_leases
                     (handling_uuid, project_id, participant_id, agent_id, message_id, project_sequence,
                      provider, conversation_id, conversation_hash, state, active_slot, lease_expires_at, completion_outcome,
                      created_at, updated_at, completed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([$handlingId, (int) $access['project_id'],
                    (int) $access['participant_id'], (int) $context['agent_id'], $messageId,
                    $projectSequence, (string) $context['provider'], (string) $context['conversation_id'],
                    hash('sha256', (string) $context['conversation_id']), $state, $active ? 1 : null,
                    $expiresAt, $outcome, $now, $now, $completedAt]);
            }
            $result = $this->rowByUuid($handlingId);
            $this->enqueueChanged($result);
            if ($ownsTransaction) { $this->pdo->commit(); }
            return $this->publicState($result);
        } catch (PDOException $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($exception->getCode() === '23000') { throw new RuntimeException('NOTIFICATION_HANDLING_ALREADY_ACTIVE'); }
            throw $exception;
        } catch (Exception $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function complete(array $access, $handlingId, $outcome)
    {
        $this->requireDiscussionAgent($access);
        $handlingId = strtolower(trim((string) $handlingId));
        $outcome = strtolower(trim((string) $outcome));
        if (!preg_match('/^[a-f0-9-]{36}$/', $handlingId)) {
            throw new InvalidArgumentException('handling_id is invalid.');
        }
        if (!in_array($outcome, ['responded', 'acknowledged', 'task_updated', 'completed', 'blocked', 'failed', 'no_action', 'waiting'], true)) {
            throw new InvalidArgumentException('handling outcome is invalid.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
        try {
            $statement = $this->pdo->prepare(
                'SELECT * FROM notification_handling_leases
                 WHERE handling_uuid = ? AND project_id = ? AND participant_id = ? AND agent_id = ?
                 LIMIT 1 FOR UPDATE'
            );
            $statement->execute([$handlingId, (int) $access['project_id'],
                (int) $access['participant_id'], (int) $access['identity']['agent']['authenticated_agent_id']]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new RuntimeException('NOTIFICATION_HANDLING_NOT_FOUND'); }
            if ($row['active_slot'] !== null || !in_array($row['state'], ['available', 'waiting'], true)) {
                $state = $outcome === 'waiting' ? 'waiting' : 'available';
                $now = Db::now();
                $update = $this->pdo->prepare(
                    'UPDATE notification_handling_leases SET state = ?, active_slot = NULL,
                     lease_expires_at = NULL, completion_outcome = ?, updated_at = ?, completed_at = ? WHERE id = ?'
                );
                $update->execute([$state, $outcome, $now, $now, (int) $row['id']]);
                $row = $this->rowByUuid($handlingId);
                $this->enqueueChanged($row);
            }
            if ($ownsTransaction) { $this->pdo->commit(); }
            return $this->publicState($row);
        } catch (Exception $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function activeForDevice(array $device, $provider)
    {
        $provider = strtolower(trim((string) $provider));
        if (!in_array($provider, ['chatgpt', 'gemini'], true)) {
            throw new InvalidArgumentException('The connector provider is not supported.');
        }
        $statement = $this->pdo->prepare(
            "SELECT h.* FROM notification_handling_leases h
             JOIN agent_activation_bindings b ON b.project_id = h.project_id AND b.agent_id = h.agent_id
                AND b.runtime_type = h.provider AND b.conversation_id = h.conversation_id
             JOIN project_members pm ON pm.project_id = h.project_id AND pm.user_id = ? AND pm.status = 'active'
             WHERE b.enabled = 1 AND b.created_by_user_id = ? AND b.activation_driver = 'browser_companion'
                AND h.provider = ? AND h.active_slot = 1 AND h.lease_expires_at > ?
             ORDER BY h.project_id, h.agent_id"
        );
        $statement->execute([(int) $device['user_id'], (int) $device['user_id'], $provider, Db::now()]);
        return array_map([$this, 'publicState'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function requireDiscussionAgent(array $access)
    {
        if (($access['identity']['kind'] ?? '') !== 'agent'
            || (($access['binding']['type'] ?? '') !== 'discussion')
            || trim((string) ($access['binding']['discussion_reference'] ?? '')) === '') {
            throw new RuntimeException('NOTIFICATION_HANDLING_NOT_AVAILABLE');
        }
    }

    private function messageContext(array $access, $messageId, $projectSequence, $lock)
    {
        $statement = $this->pdo->prepare(
            "SELECT m.project_sequence, ma.notified_at, pp.agent_id, b.runtime_type AS provider,
                    b.conversation_id
             FROM messages m
             JOIN message_addressees ma ON ma.message_id = m.id AND ma.participant_id = ?
             JOIN project_participants pp ON pp.id = ma.participant_id
                AND pp.project_id = m.project_id AND pp.kind = 'agent' AND pp.status = 'active'
             JOIN agent_activation_bindings b ON b.project_id = pp.project_id AND b.agent_id = pp.agent_id
                AND b.enabled = 1 AND b.runtime_type = 'chatgpt' AND b.activation_driver = 'browser_companion'
             WHERE m.id = ? AND m.project_id = ? AND m.deleted_at IS NULL
             LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([(int) $access['participant_id'], (int) $messageId, (int) $access['project_id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('MESSAGE_NOT_ADDRESSED_TO_PARTICIPANT'); }
        if ((int) $row['project_sequence'] !== (int) $projectSequence) { throw new RuntimeException('MESSAGE_SEQUENCE_MISMATCH'); }
        if ($row['notified_at'] === null) { throw new RuntimeException('NOTIFICATION_RECEIPT_REQUIRED'); }
        return $row;
    }

    private function expireActive($projectId, $agentId, $provider, $conversationId)
    {
        $this->pdo->prepare(
            "UPDATE notification_handling_leases SET state = 'expired', active_slot = NULL,
             completion_outcome = 'lease_expired', completed_at = ?, updated_at = ?
             WHERE project_id = ? AND agent_id = ? AND provider = ? AND conversation_hash = ?
                AND active_slot = 1 AND lease_expires_at <= ?"
        )->execute([Db::now(), Db::now(), $projectId, $agentId, $provider,
            hash('sha256', $conversationId), Db::now()]);
    }

    private function rowForMessage($messageId, $participantId, $lock)
    {
        $statement = $this->pdo->prepare('SELECT * FROM notification_handling_leases WHERE message_id = ? AND participant_id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute([(int) $messageId, (int) $participantId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function rowByUuid($handlingId)
    {
        $statement = $this->pdo->prepare('SELECT * FROM notification_handling_leases WHERE handling_uuid = ? LIMIT 1');
        $statement->execute([$handlingId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('NOTIFICATION_HANDLING_NOT_FOUND'); }
        return $row;
    }

    private function enqueueChanged(array $row)
    {
        $this->outbox->enqueueNotificationHandlingChanged($this->publicState($row));
    }

    public function publicState(array $row)
    {
        return [
            'handling_id' => $row['handling_uuid'],
            'provider' => $row['provider'],
            'project_id' => (int) $row['project_id'],
            'participant_id' => (int) $row['participant_id'],
            'agent_id' => (int) $row['agent_id'],
            'message_id' => (int) $row['message_id'],
            'project_sequence' => (int) $row['project_sequence'],
            'conversation_id' => $row['conversation_id'],
            'state' => $row['state'],
            'busy' => $row['active_slot'] !== null && strtotime((string) $row['lease_expires_at']) > time(),
            'lease_expires_at' => $row['lease_expires_at'],
            'completion_outcome' => $row['completion_outcome'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function uuid()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
