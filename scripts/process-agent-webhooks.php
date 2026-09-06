<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/AgentWebhookWorker.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit = isset($argv[1]) ? (int) $argv[1] : 100;
try {
    echo json_encode((new AgentWebhookWorker(Db::pdo()))->process($limit), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Exception $exception) {
    fwrite(STDERR, 'Agent webhook worker failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
