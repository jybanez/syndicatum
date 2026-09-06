<?php

require_once __DIR__ . '/Db.php';

class MessageOutbox
{
    const EVENT_MESSAGE_CREATED = 'syndicatum.message.created';

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
             SET attempt_count = attempt_count + 1
             WHERE id = ? AND published_at IS NULL AND failed_at IS NULL'
        );
        $statement->execute([(int) $id]);
        return $this->findById((int) $id);
    }

    public function markPublished($id)
    {
        $event = $this->findById((int) $id);
        $statement = $this->pdo->prepare(
            'UPDATE message_events_outbox
             SET published_at = ?, last_error = NULL
             WHERE id = ? AND published_at IS NULL AND failed_at IS NULL'
        );
        $statement->execute([Db::now(), (int) $id]);
        if ($event && !empty($event['message_id']) && Db::tableExists($this->pdo, 'message_addressees')) {
            $this->pdo->prepare('UPDATE message_addressees SET notified_at = COALESCE(notified_at, ?) WHERE message_id = ?')
                ->execute([Db::now(), (int) $event['message_id']]);
        }
    }

    public function markRetry($id, $error, $delaySeconds)
    {
        $availableAt = date('Y-m-d H:i:s', time() + max(1, (int) $delaySeconds));
        $statement = $this->pdo->prepare(
            'UPDATE message_events_outbox
             SET available_at = ?, last_error = ?
             WHERE id = ? AND published_at IS NULL AND failed_at IS NULL'
        );
        $statement->execute([$availableAt, self::safeError($error), (int) $id]);
    }

    public function markDead($id, $error)
    {
        $statement = $this->pdo->prepare(
            'UPDATE message_events_outbox
             SET failed_at = ?, last_error = ?
             WHERE id = ? AND published_at IS NULL AND failed_at IS NULL'
        );
        $statement->execute([Db::now(), self::safeError($error), (int) $id]);
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
        $delays = [5, 30, 120, 600, 1800, 3600];
        $index = max(0, min(count($delays) - 1, (int) $attemptCount - 1));
        return $delays[$index];
    }

    private function findById($id)
    {
        $statement = $this->pdo->prepare('SELECT * FROM message_events_outbox WHERE id = ?');
        $statement->execute([(int) $id]);
        return $statement->fetch();
    }

    private static function safeError($error)
    {
        $error = preg_replace('/[\r\n\t]+/', ' ', trim((string) $error));
        return substr((string) $error, 0, 500);
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
