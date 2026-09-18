<?php

require_once dirname(__DIR__) . '/src/MessageDeliveryStatus.php';

function expectState($actual, $expected)
{
    if ($actual !== $expected) {
        throw new RuntimeException('Expected ' . $expected . ', got ' . $actual);
    }
}

expectState(MessageDeliveryStatus::handlingState([]), 'unconfirmed');
expectState(MessageDeliveryStatus::handlingState(['notified_at' => 'now']), 'notified_not_seen');
expectState(MessageDeliveryStatus::handlingState(['seen_at' => 'now']), 'seen_not_acknowledged');
expectState(MessageDeliveryStatus::handlingState(['acknowledged_at' => 'now']), 'acknowledged');
expectState(MessageDeliveryStatus::deliveryState(['status' => 'retry']), 'pending');
expectState(MessageDeliveryStatus::deliveryState(['status' => 'dead']), 'failed');
expectState(MessageDeliveryStatus::deliveryState(['status' => 'succeeded']), 'accepted');
expectState(MessageDeliveryStatus::deliveryState(['failed_at' => 'now']), 'failed');
$retry = MessageDeliveryStatus::realtimeEvent([
    'event_uuid' => 'event-1', 'event_type' => 'syndicatum.message.created',
    'attempt_count' => 2, 'available_at' => '2026-09-18 07:10:00',
    'last_attempt_at' => '2026-09-18 07:09:00', 'last_failure_code' => 'rate_limiting',
    'published_at' => null, 'failed_at' => null,
]);
expectState($retry['state'], 'pending');
expectState($retry['last_attempt_at'], '2026-09-18 07:09:00');
expectState($retry['next_retry_at'], '2026-09-18 07:10:00');
expectState($retry['failure_code'], 'rate_limiting');
expectState($retry['terminal_outcome'], null);
$accepted = MessageDeliveryStatus::realtimeEvent([
    'event_uuid' => 'event-2', 'event_type' => 'syndicatum.message.created',
    'attempt_count' => 3, 'available_at' => '2026-09-18 07:10:00',
    'last_attempt_at' => '2026-09-18 07:10:00', 'last_failure_code' => null,
    'published_at' => '2026-09-18 07:10:01', 'failed_at' => null,
]);
expectState($accepted['terminal_outcome'], 'accepted');
expectState($accepted['next_retry_at'], null);
$activation = MessageDeliveryStatus::activationEvent([
    'delivery_uuid' => 'delivery-1', 'status' => 'retry', 'attempt_count' => 2,
    'next_attempt_at' => '2026-09-18 07:12:00', 'last_attempt_at' => '2026-09-18 07:10:00',
    'last_failure_code' => 'timeout', 'response_status' => null, 'delivered_at' => null,
]);
expectState($activation['state'], 'pending');
expectState($activation['next_retry_at'], '2026-09-18 07:12:00');
expectState($activation['failure_code'], 'timeout');
expectState($activation['terminal_outcome'], null);
echo "Message delivery projection contract passed.\n";
