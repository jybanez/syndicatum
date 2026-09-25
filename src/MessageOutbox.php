<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/DeliveryFailureTaxonomy.php';

class MessageOutbox
{
    const EVENT_MESSAGE_CREATED = 'syndicatum.message.created';
    const EVENT_PARTICIPANTS_CHANGED = 'syndicatum.participants.changed';
    const EVENT_TASK_UPDATED = 'syndicatum.task.updated';
    const DEFAULT_MAX_ATTEMPTS = 8;

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Enqueue a full canonical message. Call this inside the message transaction.
     */
    public function enqueueMessageCreated($projectId, $messageId, $projectSequence, array $message)
    {
        $eventUuid = self::uuidV4();
        $payload = [
            'event_id' => $eventUuid,
            'type' => self::EVENT_MESSAGE_CREATED,
            'project_id' => (int) $projectId,
            'sequence' => (int) $projectSequence,
            'message' => $message,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            throw new RuntimeException('Unable to encode the message outbox event.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO message_events_outbox
             (event_uuid, project_id, message_id, event_type, project_sequence, payload_json,
              attempt_count, available_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $now = Db::now();
        $statement->execute([
            $eventUuid,
            (int) $projectId,
            (int) $messageId,
            self::EVENT_MESSAGE_CREATED,
            (int) $projectSequence,
            $payloadJson,
            $now,
            $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    /**
     * Notify connected project surfaces that their authoritative participant
     * directory must be reloaded. Call this inside the participant mutation.
     */
    public function enqueueParticipantsChanged($projectId, $change)
    {
        $eventUuid = self::uuidV4();
        $payload = [
            'event_id' => $eventUuid,
            'type' => self::EVENT_PARTICIPANTS_CHANGED,
            'project_id' => (int) $projectId,
            'change' => trim((string) $change),
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            throw new RuntimeException('Unable to encode the participant outbox event.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO message_events_outbox
             (event_uuid, project_id, message_id, event_type, project_sequence, payload_json,
              attempt_count, available_at, created_at)
             VALUES (?, ?, NULL, ?, NULL, ?, 0, ?, ?)'
        );
        $now = Db::now();
        $statement->execute([
            $eventUuid,
            (int) $projectId,
            self::EVENT_PARTICIPANTS_CHANGED,
            $payloadJson,
            $now,
            $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    /**
     * Publish the authoritative task snapshot after a create or update. Call
     * this inside the same transaction as the task mutation.
     */
    public function enqueueTaskUpdated($projectId, $taskId, array $task, $change)
    {
        $eventUuid = self::uuidV4();
        $payload = [
            'event_id' => $eventUuid,
            'type' => self::EVENT_TASK_UPDATED,
            'project_id' => (int) $projectId,
            'task_id' => (int) $taskId,
            'change' => trim((string) $change),
            'task' => $task,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            throw new RuntimeException('Unable to encode the task outbox event.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO message_events_outbox
             (event_uuid, project_id, message_id, event_type, project_sequence, payload_json,
              attempt_count, available_at, created_at)
             VALUES (?, ?, NULL, ?, NULL, ?, 0, ?, ?)'
        );
        $now = Db::now();
        $statement->execute([
            $eventUuid,
            (int) $projectId,
            self::EVENT_TASK_UPDATED,
            $payloadJson,
            $now,
            $now,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function pending($limit)
    {
        $limit = max(1, min(500, (int) $limit));
        $statement = $this->pdo->prepare(
            'SELECT * FROM message_events_outbox
             WHERE published_at IS NULL AND failed_at IS NULL AND available_at <= ?
             ORDER BY id ASC LIMIT ' . $limit
        );
        $statement->execute([Db::now()]);
        return $statement->fetchAll();
    }

    public function beginAttempt($id)
    {
        $statement = $this->pdo->prepare(
            'UPDATE message_events_outbox
             SET attempt_count = attempt_count + 1, last_attempt_at = ?
             WHERE id = ? AND published_at IS NULL AND failed_at IS NULL'
        );
        $statement->execute([Db::now(), (int) $id]);
        return $this->findById((int) $id);
    }

    public function markPublished($id)
    {
        $statement = $this->pdo->prepare(
            'UPDATE message_events_outbox
             SET published_at = ?, last_error = NULL, last_failure_code = NULL
             WHERE id = ? AND published_at IS NULL AND failed_at IS NULL'
        );
        $statement->execute([Db::now(), (int) $id]);
    }

    public function markRetry($id, $error, $delaySeconds, $failureCode = null)
    {
        $availableAt = date('Y-m-d H:i:s', time() + max(1, (int) $delaySeconds));
        $statement = $this->pdo->prepare(
            'UPDATE message_events_outbox
             SET available_at = ?, last_error = ?, last_failure_code = ?
             WHERE id = ? AND published_at IS NULL AND failed_at IS NULL'
        );
        $statement->execute([$availableAt, self::safeError($error), self::safeFailureCode($failureCode), (int) $id]);
    }

    public function markDead($id, $error, $failureCode = null)
    {
        $statement = $this->pdo->prepare(
            'UPDATE message_events_outbox
             SET failed_at = ?, last_error = ?, last_failure_code = ?
             WHERE id = ? AND published_at IS NULL AND failed_at IS NULL'
        );
        $statement->execute([Db::now(), self::safeError($error), self::safeFailureCode($failureCode), (int) $id]);
    }

    public function acquireWorkerLock($timeoutSeconds)
    {
        $statement = $this->pdo->prepare("SELECT GET_LOCK(CONCAT('syndicatum:realtime-outbox:', DATABASE()), ?)");
        $statement->execute([max(0, (int) $timeoutSeconds)]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function releaseWorkerLock()
    {
        try {
            $this->pdo->query("SELECT RELEASE_LOCK(CONCAT('syndicatum:realtime-outbox:', DATABASE()))");
        } catch (Exception $ignored) {
            // MySQL also releases named locks when the connection closes.
        }
    }

    public static function retryDelay($attemptCount)
    {
        $delays = self::retryScheduleSeconds();
        $index = max(0, min(count($delays) - 1, (int) $attemptCount - 1));
        return $delays[$index];
    }

    public static function retryScheduleSeconds()
    {
        return [5, 30, 120, 600, 1800, 3600];
    }

    private function findById($id)
    {
        $statement = $this->pdo->prepare('SELECT * FROM message_events_outbox WHERE id = ?');
        $statement->execute([(int) $id]);
        return $statement->fetch();
    }

    private static function safeError($error)
    {
        $error = trim((string) $error);
        if (preg_match('/^Realtime publish returned HTTP [1-5][0-9]{2}\.$/', $error)) {
            return $error;
        }
        if (in_array($error, [
            'Realtime integration is disabled.',
            'Realtime publish transport failed.',
            'Realtime publish request was invalid.',
            'Realtime publish failed before a response was received.',
            'Realtime publish failed.',
        ], true)) {
            return $error;
        }
        // This is a durable operational field. Never persist arbitrary text
        // from a transport, exception, or future worker caller.
        return 'Realtime delivery failed.';
    }

    private static function safeFailureCode($code)
    {
        return DeliveryFailureTaxonomy::safeCode($code);
    }

    private static function uuidV4()
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
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
