<?php

require_once dirname(__DIR__) . '/src/DeliveryOperatorView.php';

function expectOperator($actual, $expected, $label)
{
    if ($actual !== $expected) { throw new RuntimeException($label); }
}

$sample = DeliveryOperatorView::sample([
    ['status' => 'succeeded', 'attempt_count' => 2, 'last_attempt_at' => '2026-09-18 09:00:00',
        'last_failure_code' => null, 'next_attempt_at' => null, 'response_status' => 204],
    ['status' => 'retry', 'attempt_count' => 1, 'last_attempt_at' => '2026-09-18 08:00:00',
        'last_failure_code' => 'rate_limiting', 'next_attempt_at' => '2026-09-18 08:05:00', 'response_status' => 429,
        'body' => 'SECRET SHOULD NOT BE PROJECTED'],
    ['status' => 'dead', 'attempt_count' => 8, 'last_attempt_at' => '2026-09-18 07:00:00',
        'last_failure_code' => 'provider-secret', 'next_attempt_at' => null, 'response_status' => 500],
], 'webhook');
expectOperator($sample['scope'], 'newest_50_rows', 'Sample scope missing');
expectOperator($sample['retrying'], 1, 'Retry count wrong');
expectOperator($sample['terminal'], 1, 'Terminal count wrong');
expectOperator($sample['latest_failed_attempt']['failure_code'], 'rate_limiting', 'Failure category wrong');
expectOperator($sample['latest_failed_attempt']['queue_state'], 'retry', 'Failure disposition wrong');
expectOperator($sample['latest_failed_attempt']['http_status'], 429, 'HTTP status wrong');
if (strpos(json_encode($sample), 'SECRET') !== false) { throw new RuntimeException('Payload leaked'); }

$realtime = DeliveryOperatorView::sample([
    ['attempt_count' => 3, 'last_attempt_at' => '2026-09-18 09:30:00',
        'last_failure_code' => 'upstream_error', 'next_attempt_at' => null,
        'published_at' => null, 'failed_at' => '2026-09-18 09:30:01'],
], 'realtime');
expectOperator($realtime['terminal'], 1, 'Realtime terminal count wrong');
expectOperator($realtime['latest_failed_attempt']['queue_state'], 'dead', 'Realtime disposition wrong');
echo "Bounded delivery operator view passed.\n";
