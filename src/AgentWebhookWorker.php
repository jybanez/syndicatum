<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AgentWebhookService.php';

class AgentWebhookHttpException extends RuntimeException
{
    private $httpStatus;

    public function __construct($status)
    {
        $this->httpStatus = (int) $status;
        parent::__construct('Webhook endpoint returned HTTP ' . (int) $status . '.');
    }

    public function status() { return $this->httpStatus; }

    public function isPermanent()
    {
        return $this->httpStatus >= 400 && $this->httpStatus < 500
            && $this->httpStatus !== 408 && $this->httpStatus !== 429;
    }
}

class AgentWebhookWorker
{
    private $pdo;
    private $service;
    private $transport;

    public function __construct(PDO $pdo, $transport = null)
    {
        $this->pdo = $pdo;
        $this->service = new AgentWebhookService($pdo);
        $this->transport = $transport;
    }

    public function process($limit = 100)
    {
        $limit = max(1, min(500, (int) $limit));
        $result = ['processed' => 0, 'succeeded' => 0, 'retried' => 0, 'dead' => 0, 'skipped' => 0, 'locked' => false];
        if (!$this->acquireLock()) { return $result; }
        $result['locked'] = true;
        try {
            $now = Db::now();
            $this->pdo->prepare("UPDATE agent_webhook_deliveries SET status = 'retry'
                WHERE status = 'sending' AND next_attempt_at <= ?")->execute([$now]);
            $statement = $this->pdo->prepare(
                "SELECT d.*, w.endpoint_url, w.signing_secret_encrypted
                 FROM agent_webhook_deliveries d
                 JOIN agent_notification_webhooks w ON w.project_id = d.project_id AND w.agent_id = d.agent_id AND w.enabled = 1
                 WHERE d.status IN ('queued', 'retry') AND d.next_attempt_at <= ?
                 ORDER BY d.id LIMIT " . $limit
            );
            $statement->execute([$now]);
            foreach ($statement->fetchAll() as $row) {
                try {
                    if (!$this->attempt($row)) { $result['skipped']++; continue; }
                    $result['processed']++;
                    $result['succeeded']++;
                } catch (Exception $exception) {
                    $result['processed']++;
                    $attempt = (int) $row['attempt_count'] + 1;
                    $dead = $attempt >= 8 || ($exception instanceof AgentWebhookHttpException && $exception->isPermanent());
                    $this->markFailed($row, $exception, $dead);
                    if ($dead) { $result['dead']++; } else { $result['retried']++; }
                }
            }
        } finally {
            $this->releaseLock();
        }
        return $result;
    }

    private function attempt(array $row)
    {
        $claimed = $this->pdo->prepare(
            "UPDATE agent_webhook_deliveries SET status = 'sending', attempt_count = attempt_count + 1, next_attempt_at = ?
             WHERE id = ? AND status IN ('queued', 'retry')"
        );
        $claimed->execute([date('Y-m-d H:i:s', time() + 120), (int) $row['id']]);
        if ($claimed->rowCount() !== 1) {
            return false;
        }
        $resolved = AgentWebhookService::resolveEndpoint($row['endpoint_url']);
        $timestamp = (string) time();
        $secret = $this->service->decryptSigningSecret($row['signing_secret_encrypted']);
        $signature = hash_hmac('sha256', $timestamp . '.' . $row['payload_json'], $secret);
        $response = $this->send($row['endpoint_url'], $row['payload_json'], [
            'Content-Type: application/json',
            'User-Agent: Syndicatum-Webhook/1.0',
            'X-Syndicatum-Event: ' . $row['event_type'],
            'X-Syndicatum-Delivery: ' . $row['delivery_uuid'],
            'Idempotency-Key: ' . $row['delivery_uuid'],
            'X-Syndicatum-Timestamp: ' . $timestamp,
            'X-Syndicatum-Signature: sha256=' . $signature,
        ], $resolved);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        if ($status < 200 || $status >= 300) {
            throw new AgentWebhookHttpException($status);
        }
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $updated = $this->pdo->prepare("UPDATE agent_webhook_deliveries SET status = 'succeeded', response_status = ?, last_error = NULL, delivered_at = ? WHERE id = ? AND status = 'sending'");
            $updated->execute([$status, $now, (int) $row['id']]);
            if ($updated->rowCount() !== 1) { $this->pdo->rollBack(); return false; }
            $this->pdo->prepare('UPDATE agent_notification_webhooks SET last_success_at = ?, last_error = NULL WHERE project_id = ? AND agent_id = ?')
                ->execute([$now, (int) $row['project_id'], (int) $row['agent_id']]);
            $this->pdo->prepare(
                'UPDATE message_addressees ma
                 JOIN project_participants pp ON pp.id = ma.participant_id
                 SET ma.notified_at = COALESCE(ma.notified_at, ?)
                 WHERE ma.message_id = ? AND pp.project_id = ? AND pp.agent_id = ?'
            )->execute([$now, (int) $row['message_id'], (int) $row['project_id'], (int) $row['agent_id']]);
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
        return true;
    }

    private function markFailed(array $row, Exception $exception, $dead)
    {
        $error = substr(preg_replace('/[\r\n\t]+/', ' ', $exception->getMessage()), 0, 500);
        $attempt = (int) $row['attempt_count'] + 1;
        $delays = [5, 30, 120, 600, 1800, 3600, 7200];
        $delay = $delays[min(count($delays) - 1, max(0, $attempt - 1))];
        $status = $exception instanceof AgentWebhookHttpException ? $exception->status() : null;
        $this->pdo->prepare(
            "UPDATE agent_webhook_deliveries SET status = ?, next_attempt_at = ?, response_status = ?, last_error = ? WHERE id = ? AND status = 'sending'"
        )->execute([$dead ? 'dead' : 'retry', date('Y-m-d H:i:s', time() + $delay), $status, $error, (int) $row['id']]);
        $this->pdo->prepare('UPDATE agent_notification_webhooks SET last_failure_at = ?, last_error = ? WHERE project_id = ? AND agent_id = ?')
            ->execute([Db::now(), $error, (int) $row['project_id'], (int) $row['agent_id']]);
    }

    private function send($url, $payload, array $headers, array $resolved)
    {
        if (is_callable($this->transport)) {
            return call_user_func($this->transport, $url, $payload, $headers);
        }
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($handle, CURLOPT_TIMEOUT, 15);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($handle, CURLOPT_RESOLVE, [$resolved['host'] . ':' . $resolved['port'] . ':' . $resolved['addresses'][0]]);
        $responseBytes = 0;
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($handle, $chunk) use (&$responseBytes) {
            $responseBytes += strlen($chunk);
            return $responseBytes > 65536 ? 0 : strlen($chunk);
        });
        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('Webhook request failed: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return ['status' => $status, 'body' => $body];
    }

    private function acquireLock()
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return true; }
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute(['syndicatum_agent_webhook_worker']);
        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseLock()
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute(['syndicatum_agent_webhook_worker']);
    }
}
