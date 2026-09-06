<?php

require_once __DIR__ . '/Db.php';

class RateLimiter
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function hit($action, $identity, $limit, $windowSeconds, $blockSeconds = 0)
    {
        $bucket = hash('sha256', (string) $action . "\n" . (string) $identity);
        $now = time();
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT * FROM security_rate_limits WHERE bucket_key = ? FOR UPDATE');
            $select->execute([$bucket]);
            $row = $select->fetch();
            if ($row && $row['blocked_until'] !== null && strtotime($row['blocked_until']) > $now) {
                throw new RuntimeException('RATE_LIMITED');
            }

            $windowStart = $row ? strtotime($row['window_started_at']) : 0;
            $count = $row && ($now - $windowStart) < $windowSeconds ? (int) $row['hit_count'] + 1 : 1;
            $startedAt = $count === 1 ? gmdate('Y-m-d H:i:s', $now) : $row['window_started_at'];
            $blockedUntil = null;
            if ($count > $limit) {
                $blockedUntil = date('Y-m-d H:i:s', $now + max($blockSeconds, $windowSeconds));
            }

            $upsert = $this->pdo->prepare(
                'INSERT INTO security_rate_limits (bucket_key, action, window_started_at, hit_count, blocked_until, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE action = VALUES(action), window_started_at = VALUES(window_started_at),
                   hit_count = VALUES(hit_count), blocked_until = VALUES(blocked_until), updated_at = VALUES(updated_at)'
            );
            $upsert->execute([$bucket, $action, $startedAt, $count, $blockedUntil, Db::now()]);
            $this->pdo->commit();
            if ($blockedUntil !== null) {
                throw new RuntimeException('RATE_LIMITED');
            }
            return ['remaining' => max(0, $limit - $count), 'reset_at' => date(DATE_ATOM, strtotime($startedAt) + $windowSeconds)];
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
