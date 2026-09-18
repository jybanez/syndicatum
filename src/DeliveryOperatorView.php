<?php

require_once __DIR__ . '/DeliveryFailureTaxonomy.php';

class DeliveryOperatorView
{
    public static function sample(array $rows, $path)
    {
        $result = [
            'scope' => 'newest_50_rows',
            'sampled_rows' => count($rows),
            'retrying' => 0,
            'terminal' => 0,
            'latest_failed_attempt' => null,
        ];
        foreach ($rows as $row) {
            $queueState = self::queueState($row, $path);
            if ($queueState === 'retry') { $result['retrying']++; }
            if ($queueState === 'dead') { $result['terminal']++; }
            if (empty($row['last_failure_code'])) { continue; }
            $attemptAt = isset($row['last_attempt_at']) ? $row['last_attempt_at'] : null;
            $latest = $result['latest_failed_attempt'];
            if ($latest !== null && strcmp((string) $attemptAt, (string) $latest['at']) <= 0) { continue; }
            $status = isset($row['response_status']) && is_numeric($row['response_status'])
                ? (int) $row['response_status'] : null;
            $result['latest_failed_attempt'] = [
                'at' => $attemptAt,
                'failure_code' => DeliveryFailureTaxonomy::safeCode($row['last_failure_code']),
                'http_status' => $status !== null && $status >= 100 && $status <= 599 ? $status : null,
                'queue_state' => $queueState,
                'attempt_count' => (int) $row['attempt_count'],
                'next_retry_at' => $queueState === 'retry' ? $row['next_attempt_at'] : null,
            ];
        }
        return $result;
    }

    private static function queueState(array $row, $path)
    {
        if ($path === 'realtime') {
            if (!empty($row['failed_at'])) { return 'dead'; }
            if (!empty($row['published_at'])) { return 'succeeded'; }
            return (int) $row['attempt_count'] > 0 ? 'retry' : 'queued';
        }
        return in_array($row['status'], ['queued', 'sending', 'waiting', 'retry', 'succeeded', 'dead'], true)
            ? $row['status'] : 'unknown';
    }
}
