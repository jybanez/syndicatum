<?php

require_once dirname(__DIR__) . '/src/MessageDeliveryStatus.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$options = getopt('', ['message-id:']);
$value = isset($options['message-id']) ? (string) $options['message-id'] : '';
if (!preg_match('/^[1-9][0-9]*$/', $value)) {
    fwrite(STDERR, "Usage: php scripts/plugin-message-delivery-status.php --message-id=NUMBER\n");
    exit(1);
}
try {
    $status = MessageDeliveryStatus::inspect(Db::pdo(), (int) $value);
    echo json_encode($status, JSON_UNESCAPED_SLASHES) . "\n";
    exit($status['found'] ? 0 : 2);
} catch (Exception $exception) {
    fwrite(STDERR, "Unable to inspect message delivery status.\n");
    exit(3);
}
