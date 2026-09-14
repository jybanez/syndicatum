<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/AgentWebhookWorker.php';
require_once dirname(__DIR__) . '/src/WorkspaceAgentTriggerService.php';
require_once dirname(__DIR__) . '/src/ResponsesApiActivationService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit = isset($argv[1]) ? (int) $argv[1] : 100;
try {
    $pdo = Db::pdo();
    echo json_encode([
        'webhooks' => (new AgentWebhookWorker($pdo))->process($limit),
        'workspace_agents' => (new WorkspaceAgentTriggerService($pdo))->process($limit),
        'responses_api' => (new ResponsesApiActivationService($pdo))->process($limit),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Exception $exception) {
    fwrite(STDERR, 'Agent webhook worker failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
