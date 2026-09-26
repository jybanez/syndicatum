<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/MessageOutbox.php';
require_once __DIR__ . '/ProjectRepository.php';
require_once __DIR__ . '/MessageSeverity.php';

/**
 * Writes immutable, structured project events into the canonical timeline.
 * Callers must already own the surrounding mutation transaction so the event
 * and the state change either commit together or not at all.
 */
class SystemMessageService
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

    public function taskCreated(array $access, array $task)
    {
        return $this->record($access, 'task.created', 'neutral',
            'Task #' . (int) $task['id'] . ' created: ' . $task['title'],
            $this->taskData($task));
    }

    public function taskStatusChanged(array $access, array $task, $fromStatus, $toStatus)
    {
        if ((string) $fromStatus === (string) $toStatus) { return null; }
        $data = $this->taskData($task);
        $data['from_status'] = (string) $fromStatus;
        $data['to_status'] = (string) $toStatus;
        return $this->record($access, 'task.status_changed', MessageSeverity::forTaskStatus($toStatus),
            'Task #' . (int) $task['id'] . ' changed from '
                . $this->statusLabel($fromStatus) . ' to ' . $this->statusLabel($toStatus)
                . ': ' . $task['title'], $data);
    }

    public function taskAssigned(array $access, array $task, $previousAssigneeId)
    {
        $nextAssigneeId = $task['assignee_participant_id'];
        if (($previousAssigneeId === null ? null : (int) $previousAssigneeId)
            === ($nextAssigneeId === null ? null : (int) $nextAssigneeId)) { return null; }
        $data = $this->taskData($task);
        $data['previous_assignee_participant_id'] = $previousAssigneeId === null ? null : (int) $previousAssigneeId;
        $target = $nextAssigneeId === null ? 'unassigned' : 'assigned to ' . $task['assignee_display_name'];
        return $this->record($access, 'task.assigned', 'neutral',
            'Task #' . (int) $task['id'] . ' ' . $target . ': ' . $task['title'], $data);
    }

    public function externalEvent(array $access, $eventType, $severity, $body, array $eventData, array $notificationParticipantIds = [])
    {
        return $this->record($access, 'integration.' . trim((string) $eventType), $severity, $body,
            $eventData, $notificationParticipantIds);
    }

    public function agentAdded(array $access, array $agent)
    {
        return $this->record($access, 'participant.agent_added', 'neutral',
            'Agent added: ' . trim((string) $agent['display_name']), [
                'subject_type' => 'agent',
                'agent_id' => (int) $agent['agent_id'],
                'participant_id' => (int) $agent['participant_id'],
                'display_name' => trim((string) $agent['display_name']),
                'provider' => isset($agent['provider']) && trim((string) $agent['provider']) !== ''
                    ? strtolower(trim((string) $agent['provider'])) : null,
            ]);
    }

    private function record(array $access, $eventType, $severity, $body, array $eventData, array $notificationParticipantIds = [])
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('SYSTEM_MESSAGE_TRANSACTION_REQUIRED');
        }
        $projectId = (int) $access['project_id'];
        $actorId = (int) $access['participant_id'];
        $sequence = $this->nextSequence($projectId);
        $now = Db::now();
        $severity = MessageSeverity::normalize($severity);
        $json = json_encode($eventData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) { throw new RuntimeException('Unable to encode system event metadata.'); }
        $insert = $this->pdo->prepare(
            "INSERT INTO messages
             (message_uuid, project_id, project_sequence, sender_participant_id,
              message_kind, severity, event_type, event_data_json, body, reply_depth,
              action_requested, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'system', ?, ?, ?, ?, 0, 0, ?, ?)"
        );
        $insert->execute([$this->uuidV4(), $projectId, $sequence, $actorId,
            $severity, trim((string) $eventType), $json, trim((string) $body), $now, $now]);
        $messageId = (int) $this->pdo->lastInsertId();
        if ($notificationParticipantIds) {
            $addressee = $this->pdo->prepare(
                "INSERT INTO message_addressees
                 (message_id, participant_id, reason, created_at) VALUES (?, ?, 'direct', ?)"
            );
            foreach (array_values(array_unique(array_map('intval', $notificationParticipantIds))) as $participantId) {
                if ($participantId > 0 && $participantId !== $actorId) {
                    $addressee->execute([$messageId, $participantId, $now]);
                }
            }
        }
        $message = (new ProjectRepository($this->pdo))->message($access, $messageId);
        if ($this->settings->get('realtime.enabled') === true) {
            $this->outbox->enqueueMessageCreated($projectId, $messageId, $sequence, $message);
        }
        return $message;
    }

    private function taskData(array $task)
    {
        return [
            'subject_type' => 'task',
            'task_id' => (int) $task['id'],
            'task_public_id' => isset($task['public_id']) ? $task['public_id'] : null,
            'task_title' => $task['title'],
            'status' => $task['status'],
            'priority' => $task['priority'],
            'assignee_participant_id' => $task['assignee_participant_id'] === null
                ? null : (int) $task['assignee_participant_id'],
        ];
    }

    private function statusLabel($status)
    {
        return ucwords(str_replace('_', ' ', (string) $status));
    }

    private function nextSequence($projectId)
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO project_message_sequences (project_id, next_sequence) VALUES (?, 1)'
        );
        $insert->execute([(int) $projectId]);
        $lock = $this->pdo->prepare(
            'SELECT next_sequence FROM project_message_sequences WHERE project_id = ? FOR UPDATE'
        );
        $lock->execute([(int) $projectId]);
        $sequence = (int) $lock->fetchColumn();
        $update = $this->pdo->prepare(
            'UPDATE project_message_sequences SET next_sequence = ? WHERE project_id = ?'
        );
        $update->execute([$sequence + 1, (int) $projectId]);
        return $sequence;
    }

    private function uuidV4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
