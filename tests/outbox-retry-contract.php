<?php

require_once dirname(__DIR__) . '/src/MessageOutbox.php';

if (MessageOutbox::DEFAULT_MAX_ATTEMPTS !== 8) {
    throw new RuntimeException('The V1 default Realtime attempt limit changed.');
}
$expected = [5, 30, 120, 600, 1800, 3600, 3600, 3600];
foreach ($expected as $index => $delay) {
    if (MessageOutbox::retryDelay($index + 1) !== $delay) {
        throw new RuntimeException('Unexpected V1 Realtime retry delay at attempt ' . ($index + 1));
    }
}
echo "Realtime outbox default retry contract passed.\n";
