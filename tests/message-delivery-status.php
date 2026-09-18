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
echo "Message delivery projection contract passed.\n";
