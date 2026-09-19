<?php

require_once dirname(__DIR__) . '/src/Db.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $statement = Db::pdo()->prepare(
        "INSERT INTO delivery_worker_heartbeats (worker_name, last_success_at)
         VALUES ('delivery', UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE last_success_at = UTC_TIMESTAMP()"
    );
    $statement->execute();
} catch (Exception $exception) {
    fwrite(STDERR, 'Delivery worker heartbeat could not be recorded.' . PHP_EOL);
    exit(1);
}
