<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/SettingsService.php';
require_once dirname(__DIR__) . '/src/RealtimeIntegration.php';
require_once dirname(__DIR__) . '/src/MessageOutbox.php';
require_once dirname(__DIR__) . '/src/WorkspaceAgentTriggerService.php';
require_once dirname(__DIR__) . '/src/ResponsesApiActivationService.php';
require_once dirname(__DIR__) . '/src/DiscussionProviderRegistry.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker may only run from the command line.\n");
    exit(1);
}

$options = getopt('', ['limit::', 'max-attempts::', 'lock-timeout::', 'watch', 'idle-ms::']);
$limit = isset($options['limit']) ? max(1, min(500, (int) $options['limit'])) : 100;
$maxAttempts = isset($options['max-attempts']) ? max(1, (int) $options['max-attempts']) : MessageOutbox::DEFAULT_MAX_ATTEMPTS;
$lockTimeout = isset($options['lock-timeout']) ? max(0, (int) $options['lock-timeout']) : 0;
$watch = array_key_exists('watch', $options);
$idleMilliseconds = isset($options['idle-ms']) ? max(100, min(60000, (int) $options['idle-ms'])) : 500;

$pdo = Db::pdo();
$settings = new SettingsService($pdo);
$realtime = new RealtimeIntegration($settings);
$outbox = new MessageOutbox($pdo);
$chatGptActivationEnabled = !empty((new DiscussionProviderRegistry())->definition('chatgpt')['proactive_activation']);
$workspaceAgents = $chatGptActivationEnabled ? new WorkspaceAgentTriggerService($pdo) : null;
$responsesAgents = $chatGptActivationEnabled ? new ResponsesApiActivationService($pdo) : null;

if (!$realtime->isEnabled()) {
    echo "Realtime integration is disabled; no events processed.\n";
    exit(0);
}

if (!$outbox->acquireWorkerLock($lockTimeout)) {
    fwrite(STDERR, "Another Realtime outbox worker holds the database lock.\n");
    exit(2);
}

$totals = ['processed' => 0, 'published' => 0, 'retried' => 0, 'dead' => 0];

try {
    do {
        $batch = ['processed' => 0, 'published' => 0, 'retried' => 0, 'dead' => 0];
        foreach ($outbox->pending($limit) as $pending) {
            $event = $outbox->beginAttempt($pending['id']);
            if (!$event || $event['published_at'] !== null || $event['failed_at'] !== null) {
                continue;
            }

            $batch['processed']++;
            $result = $realtime->publishOutboxEvent($event);
            if ($result['status'] === 'accepted') {
                $outbox->markPublished($event['id']);
                $batch['published']++;
                continue;
            }

            $attemptCount = (int) $event['attempt_count'];
            $error = isset($result['error']) ? $result['error'] : 'Realtime publish failed.';
            if (empty($result['retryable']) || $attemptCount >= $maxAttempts) {
                $outbox->markDead($event['id'], $error);
                $batch['dead']++;
                continue;
            }

            $outbox->markRetry($event['id'], $error, MessageOutbox::retryDelay($attemptCount));
            $batch['retried']++;
        }

        $disabledActivationBatch = ['processed' => 0, 'succeeded' => 0, 'retried' => 0, 'dead' => 0];
        $workspaceBatch = $workspaceAgents ? $workspaceAgents->process($limit) : $disabledActivationBatch;
        $responsesBatch = $responsesAgents ? $responsesAgents->process($limit) : $disabledActivationBatch;

        foreach ($totals as $key => $value) {
            $totals[$key] += $batch[$key];
        }
        if (!$watch || $batch['processed'] > 0 || $workspaceBatch['processed'] > 0 || $responsesBatch['processed'] > 0) {
            echo json_encode(['realtime' => $batch, 'workspace_agents' => $workspaceBatch, 'responses_api' => $responsesBatch], JSON_UNESCAPED_SLASHES) . "\n";
            if (function_exists('flush')) { flush(); }
        }
        if ($watch) { usleep($idleMilliseconds * 1000); }
    } while ($watch);
} catch (Exception $exception) {
    fwrite(STDERR, 'Realtime outbox worker failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    $outbox->releaseWorkerLock();
}

exit(!$watch && $totals['dead'] > 0 ? 3 : 0);
