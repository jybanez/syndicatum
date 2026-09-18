<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/DeliveryFailureTaxonomy.php';

class WorkspaceAgentTriggerHttpException extends RuntimeException
{
    private $httpStatus;

    public function __construct($status)
    {
        $this->httpStatus = (int) $status;
        parent::__construct('Workspace Agent API returned HTTP ' . (int) $status . '.');
    }

    public function status() { return $this->httpStatus; }

    public function isPermanent()
    {
        return $this->httpStatus >= 400 && $this->httpStatus < 500
            && $this->httpStatus !== 408 && $this->httpStatus !== 429;
    }
}

class WorkspaceAgentTriggerService
{
    private $pdo;
    private $transport;

    public function __construct(PDO $pdo, $transport = null)
    {
        $this->pdo = $pdo;
        $this->transport = $transport;
    }

    public function enqueueMessageCreated($projectId, $messageId)
    {
        if (!Db::tableExists($this->pdo, 'workspace_agent_trigger_deliveries')
            || !Db::columnExists($this->pdo, 'agent_activation_bindings', 'workspace_agent_trigger_id')) {
            return;
        }
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT pp.agent_id
             FROM message_addressees ma
             JOIN project_participants pp ON pp.id = ma.participant_id AND pp.kind = 'agent' AND pp.status = 'active'
             JOIN messages m ON m.id = ma.message_id
             JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
                 AND pa.status = 'active' AND LOWER(COALESCE(pa.provider, '')) = 'chatgpt'
             JOIN agent_activation_bindings b ON b.project_id = pp.project_id AND b.agent_id = pp.agent_id
                 AND b.enabled = 1 AND b.runtime_type = 'chatgpt' AND b.activation_driver = 'workspace_agent'
                 AND b.workspace_agent_trigger_id IS NOT NULL AND b.workspace_agent_token_encrypted IS NOT NULL
             WHERE ma.message_id = ? AND pp.project_id = ? AND pp.id <> m.sender_participant_id"
        );
        $statement->execute([(int) $messageId, (int) $projectId]);
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO workspace_agent_trigger_deliveries
             (delivery_uuid, project_id, message_id, agent_id, status, attempt_count, next_attempt_at, created_at)
             VALUES (?, ?, ?, ?, 'queued', 0, ?, ?)"
        );
        $now = Db::now();
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $agentId) {
            $insert->execute([$this->uuidV4(), (int) $projectId, (int) $messageId, (int) $agentId, $now, $now]);
        }
    }

    public function process($limit = 100)
    {
        $limit = max(1, min(500, (int) $limit));
        $result = ['processed' => 0, 'succeeded' => 0, 'retried' => 0, 'dead' => 0, 'skipped' => 0, 'locked' => false];
        if (!Db::tableExists($this->pdo, 'workspace_agent_trigger_deliveries') || !$this->acquireLock()) { return $result; }
        $result['locked'] = true;
        try {
            $now = Db::now();
            $this->pdo->prepare("UPDATE workspace_agent_trigger_deliveries SET status = 'retry' WHERE status = 'sending' AND next_attempt_at <= ?")
                ->execute([$now]);
            $statement = $this->pdo->prepare(
                "SELECT d.*, b.workspace_agent_trigger_id, b.workspace_agent_conversation_key,
                        b.workspace_agent_token_encrypted, p.name AS project_name
                 FROM workspace_agent_trigger_deliveries d
                 JOIN agent_activation_bindings b ON b.project_id = d.project_id AND b.agent_id = d.agent_id
                    AND b.enabled = 1 AND b.runtime_type = 'chatgpt' AND b.activation_driver = 'workspace_agent'
                 JOIN projects p ON p.id = d.project_id
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
                    $dead = $attempt >= 8 || ($exception instanceof WorkspaceAgentTriggerHttpException && $exception->isPermanent());
                    $this->markFailed($row, $exception, $dead);
                    $result[$dead ? 'dead' : 'retried']++;
                }
            }
        } finally {
            $this->releaseLock();
        }
        return $result;
    }

    public function encryptAccessToken($plaintext)
    {
        $plaintext = trim((string) $plaintext);
        if ($plaintext === '') { throw new InvalidArgumentException('Workspace Agent access token is required.'); }
        $key = $this->encryptionKey(Db::config()['secret']);
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) { throw new RuntimeException('Unable to protect the Workspace Agent access token.'); }
        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        return base64_encode(chr(1) . $iv . $mac . $ciphertext);
    }

    public function decryptAccessToken($encoded)
    {
        $payload = base64_decode((string) $encoded, true);
        if ($payload === false || strlen($payload) < 49 || ord($payload[0]) !== 1) {
            throw new RuntimeException('Stored Workspace Agent access token is invalid.');
        }
        $iv = substr($payload, 1, 16);
        $mac = substr($payload, 17, 32);
        $ciphertext = substr($payload, 49);
        $config = Db::config();
        foreach ([$config['secret'], $config['previous_secret']] as $secret) {
            if ($secret === null) { continue; }
            $key = $this->encryptionKey($secret);
            if (!hash_equals($mac, hash_hmac('sha256', $iv . $ciphertext, $key, true))) { continue; }
            $token = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($token !== false) { return $token; }
        }
        throw new RuntimeException('Stored Workspace Agent access token failed integrity verification.');
    }

    private function attempt(array $row)
    {
        $claimed = $this->pdo->prepare(
            "UPDATE workspace_agent_trigger_deliveries SET status = 'sending', attempt_count = attempt_count + 1, last_attempt_at = ?, next_attempt_at = ?
             WHERE id = ? AND status IN ('queued', 'retry')"
        );
        $claimed->execute([Db::now(), date('Y-m-d H:i:s', time() + 120), (int) $row['id']]);
        if ($claimed->rowCount() !== 1) { return false; }

        $triggerId = trim((string) $row['workspace_agent_trigger_id']);
        if (!preg_match('/^agtch_[A-Za-z0-9_-]+$/', $triggerId)) {
            throw new RuntimeException('Stored Workspace Agent trigger ID is invalid.');
        }
        $payload = json_encode([
            'conversation_key' => (string) $row['workspace_agent_conversation_key'],
            'input' => 'A new Syndicatum message requires your attention. Project: ' . $row['project_name']
                . ' (ID ' . (int) $row['project_id'] . '); message ID ' . (int) $row['message_id']
                . '. Use the connected Syndicatum tools to read the authoritative project timeline, handle all pending messages addressed to your agent, reply when appropriate, and acknowledge each message only after it has been handled. Do not treat this activation notice as the project message body.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $this->send(
            'https://api.chatgpt.com/v1/workspace_agents/' . rawurlencode($triggerId) . '/trigger',
            $payload,
            [
                'Authorization: Bearer ' . $this->decryptAccessToken($row['workspace_agent_token_encrypted']),
                'Content-Type: application/json',
                'User-Agent: Syndicatum-Workspace-Agent/1.0',
                'Idempotency-Key: ' . $row['delivery_uuid'],
                'OpenAI-Beta: workspace_agent_runs=v1',
            ]
        );
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $decoded = json_decode(isset($response['body']) ? (string) $response['body'] : '', true);
        if ($status !== 202) {
            throw new WorkspaceAgentTriggerHttpException($status);
        }
        $conversationUrl = is_array($decoded) && isset($decoded['conversation_url']) ? (string) $decoded['conversation_url'] : null;
        $runId = is_array($decoded) && isset($decoded['agent_trigger_run_id']) ? (string) $decoded['agent_trigger_run_id'] : null;
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $updated = $this->pdo->prepare(
                "UPDATE workspace_agent_trigger_deliveries SET status = 'succeeded', response_status = 202,
                    run_id = ?, conversation_url = ?, last_error = NULL, last_failure_code = NULL, delivered_at = ?, terminal_at = NULL WHERE id = ? AND status = 'sending'"
            );
            $updated->execute([$runId, $conversationUrl, $now, (int) $row['id']]);
            if ($updated->rowCount() !== 1) { $this->pdo->rollBack(); return false; }
            $this->pdo->prepare(
                'UPDATE agent_activation_bindings SET workspace_agent_last_success_at = ?, workspace_agent_last_error = NULL WHERE project_id = ? AND agent_id = ?'
            )->execute([$now, (int) $row['project_id'], (int) $row['agent_id']]);
            $this->pdo->prepare(
                'UPDATE message_addressees ma JOIN project_participants pp ON pp.id = ma.participant_id
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

    private function send($url, $payload, array $headers)
    {
        if (is_callable($this->transport)) { return call_user_func($this->transport, $url, $payload, $headers); }
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($handle, CURLOPT_TIMEOUT, 30);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        $body = curl_exec($handle);
        if ($body === false) {
            $failureCode = curl_errno($handle) === 28 ? 'timeout' : 'transport';
            curl_close($handle);
            throw new DeliveryTransportException($failureCode);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return ['status' => $status, 'body' => $body];
    }

    private function markFailed(array $row, Exception $exception, $dead)
    {
        $attempt = (int) $row['attempt_count'] + 1;
        $delays = [5, 30, 120, 600, 1800, 3600, 7200];
        $delay = $delays[min(count($delays) - 1, max(0, $attempt - 1))];
        $status = $exception instanceof WorkspaceAgentTriggerHttpException ? $exception->status() : null;
        $code = DeliveryFailureTaxonomy::fromException($exception, $status);
        $error = DeliveryFailureTaxonomy::safeSummary($code, $status);
        $this->pdo->prepare(
            "UPDATE workspace_agent_trigger_deliveries SET status = ?, next_attempt_at = ?, response_status = ?, last_error = ?, last_failure_code = ?, terminal_at = ? WHERE id = ? AND status = 'sending'"
        )->execute([$dead ? 'dead' : 'retry', date('Y-m-d H:i:s', time() + $delay), $status, $error, $code, $dead ? Db::now() : null, (int) $row['id']]);
        $this->pdo->prepare(
            'UPDATE agent_activation_bindings SET workspace_agent_last_failure_at = ?, workspace_agent_last_error = ? WHERE project_id = ? AND agent_id = ?'
        )->execute([Db::now(), $error, (int) $row['project_id'], (int) $row['agent_id']]);
    }

    private function encryptionKey($serverSecret)
    {
        if ($serverSecret === null || trim((string) $serverSecret) === '') {
            throw new RuntimeException('PBB_AGENTCHAT_SECRET is required for Workspace Agent configuration.');
        }
        return hash('sha256', "syndicatum-workspace-agent\n" . $serverSecret, true);
    }

    private function acquireLock()
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return true; }
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute(['syndicatum_workspace_agent_trigger_worker']);
        return (int) $statement->fetchColumn() === 1;
    }

    private function releaseLock()
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute(['syndicatum_workspace_agent_trigger_worker']);
    }

    private function uuidV4()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
