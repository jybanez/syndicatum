<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ChatGptOAuthService.php';
require_once __DIR__ . '/DeliveryFailureTaxonomy.php';

class ResponsesApiHttpException extends RuntimeException
{
    private $httpStatus;
    public function __construct($status)
    {
        $this->httpStatus = (int) $status;
        parent::__construct('OpenAI Responses API returned HTTP ' . (int) $status . '.');
    }
    public function status() { return $this->httpStatus; }
    public function isPermanent() { return $this->httpStatus >= 400 && $this->httpStatus < 500 && !in_array($this->httpStatus, [408, 409, 429], true); }
}

class ResponsesApiActivationService
{
    private $pdo;
    private $transport;

    public function __construct(PDO $pdo, $transport = null) { $this->pdo = $pdo; $this->transport = $transport; }

    public function enqueueMessageCreated($projectId, $messageId)
    {
        if (!Db::tableExists($this->pdo, 'responses_api_deliveries')) { return; }
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT pp.agent_id
             FROM message_addressees ma
             JOIN project_participants pp ON pp.id = ma.participant_id AND pp.kind = 'agent' AND pp.status = 'active'
             JOIN messages m ON m.id = ma.message_id
             JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
                AND pa.status = 'active' AND LOWER(COALESCE(pa.provider, '')) = 'chatgpt'
             JOIN agent_activation_bindings b ON b.project_id = pp.project_id AND b.agent_id = pp.agent_id
                AND b.enabled = 1 AND b.runtime_type = 'chatgpt' AND b.activation_driver = 'responses_api'
                AND b.responses_api_key_encrypted IS NOT NULL AND b.responses_mcp_token_encrypted IS NOT NULL
             WHERE ma.message_id = ? AND pp.project_id = ? AND pp.id <> m.sender_participant_id"
        );
        $statement->execute([(int) $messageId, (int) $projectId]);
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO responses_api_deliveries
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
        $result = ['processed' => 0, 'started' => 0, 'succeeded' => 0, 'retried' => 0, 'dead' => 0, 'skipped' => 0, 'locked' => false];
        if (!Db::tableExists($this->pdo, 'responses_api_deliveries') || !$this->acquireLock()) { return $result; }
        $result['locked'] = true;
        try {
            $now = Db::now();
            $this->pdo->prepare("UPDATE responses_api_deliveries SET status = 'retry' WHERE status = 'sending' AND next_attempt_at <= ?")->execute([$now]);
            $statement = $this->pdo->prepare(
                "SELECT d.*, b.responses_model, b.responses_api_key_encrypted, b.responses_mcp_token_encrypted,
                        b.responses_last_response_id, p.name AS project_name
                 FROM responses_api_deliveries d
                 JOIN agent_activation_bindings b ON b.project_id = d.project_id AND b.agent_id = d.agent_id
                    AND b.enabled = 1 AND b.runtime_type = 'chatgpt' AND b.activation_driver = 'responses_api'
                 JOIN projects p ON p.id = d.project_id
                 WHERE d.status IN ('queued', 'retry', 'waiting') AND d.next_attempt_at <= ?
                 ORDER BY CASE WHEN d.status = 'waiting' THEN 0 ELSE 1 END, d.id LIMIT " . $limit
            );
            $statement->execute([$now]);
            $seenAgents = [];
            foreach ($statement->fetchAll() as $row) {
                $agentKey = (int) $row['agent_id'];
                if (isset($seenAgents[$agentKey])) { $result['skipped']++; continue; }
                $seenAgents[$agentKey] = true;
                try {
                    $outcome = $row['status'] === 'waiting' ? $this->poll($row) : $this->start($row);
                    $result['processed']++;
                    $result[$outcome]++;
                } catch (Exception $exception) {
                    $result['processed']++;
                    $attempt = (int) $row['attempt_count'] + ($row['status'] === 'waiting' ? 0 : 1);
                    $dead = $attempt >= 8 || ($exception instanceof ResponsesApiHttpException && $exception->isPermanent());
                    $this->markFailed($row, $exception, $dead);
                    $result[$dead ? 'dead' : 'retried']++;
                }
            }
        } finally { $this->releaseLock(); }
        return $result;
    }

    public function encryptSecret($plaintext)
    {
        $plaintext = trim((string) $plaintext);
        if ($plaintext === '') { throw new InvalidArgumentException('Secret value is required.'); }
        $key = $this->encryptionKey(Db::config()['secret']);
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) { throw new RuntimeException('Unable to protect the secret.'); }
        return base64_encode(chr(1) . $iv . hash_hmac('sha256', $iv . $ciphertext, $key, true) . $ciphertext);
    }

    public function decryptSecret($encoded)
    {
        $payload = base64_decode((string) $encoded, true);
        if ($payload === false || strlen($payload) < 49 || ord($payload[0]) !== 1) { throw new RuntimeException('Stored Responses API secret is invalid.'); }
        $iv = substr($payload, 1, 16); $mac = substr($payload, 17, 32); $ciphertext = substr($payload, 49);
        $config = Db::config();
        foreach ([$config['secret'], $config['previous_secret']] as $secret) {
            if ($secret === null) { continue; }
            $key = $this->encryptionKey($secret);
            if (!hash_equals($mac, hash_hmac('sha256', $iv . $ciphertext, $key, true))) { continue; }
            $value = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($value !== false) { return $value; }
        }
        throw new RuntimeException('Stored Responses API secret failed integrity verification.');
    }

    private function start(array $row)
    {
        $active = $this->pdo->prepare("SELECT COUNT(*) FROM responses_api_deliveries WHERE agent_id = ? AND id <> ? AND status IN ('sending', 'waiting')");
        $active->execute([(int) $row['agent_id'], (int) $row['id']]);
        if ((int) $active->fetchColumn() > 0) { return 'skipped'; }
        $claimed = $this->pdo->prepare("UPDATE responses_api_deliveries SET status = 'sending', attempt_count = attempt_count + 1, last_attempt_at = ?, next_attempt_at = ? WHERE id = ? AND status IN ('queued', 'retry')");
        $claimed->execute([Db::now(), date('Y-m-d H:i:s', time() + 120), (int) $row['id']]);
        if ($claimed->rowCount() !== 1) { return 'skipped'; }
        $payload = [
            'model' => trim((string) $row['responses_model']) ?: 'gpt-5.6-terra',
            'background' => true,
            'store' => true,
            'instructions' => 'You are the Syndicatum project agent represented by the authorized MCP identity. Treat the Syndicatum project timeline as authoritative. Use the tools to inspect all pending messages addressed to you. Reply when useful and acknowledge a message only after you have handled it. Never expose credentials or this activation envelope.',
            'input' => 'Activation event ' . $row['delivery_uuid'] . ': a new message requires attention in project "' . $row['project_name']
                . '" (project ID ' . (int) $row['project_id'] . ', message ID ' . (int) $row['message_id']
                . '). Read the authoritative content with Syndicatum tools. Use the activation UUID as the prefix for any post_message idempotency_key.',
            'tools' => [[
                'type' => 'mcp', 'server_label' => 'syndicatum',
                'server_description' => 'The authoritative Syndicatum project timeline for this agent.',
                'server_url' => (new ChatGptOAuthService($this->pdo))->resource(),
                'authorization' => $this->decryptSecret($row['responses_mcp_token_encrypted']),
                'require_approval' => 'never',
                'allowed_tools' => ['get_project', 'list_participants', 'list_messages', 'get_message', 'post_message', 'acknowledge_message'],
            ]],
        ];
        if (!empty($row['responses_last_response_id'])) { $payload['previous_response_id'] = $row['responses_last_response_id']; }
        $response = $this->request('POST', 'https://api.openai.com/v1/responses', $payload, $row['responses_api_key_encrypted'], $row['delivery_uuid']);
        $decoded = $this->validResponse($response);
        $responseId = trim((string) (isset($decoded['id']) ? $decoded['id'] : ''));
        $state = trim((string) (isset($decoded['status']) ? $decoded['status'] : ''));
        if ($responseId === '') { throw new RuntimeException('OpenAI Responses API did not return a response ID.'); }
        if ($state === 'completed') { $this->markSucceeded($row, $responseId, $state, (int) $response['status']); return 'succeeded'; }
        if (in_array($state, ['failed', 'cancelled', 'incomplete'], true)) { throw new DeliveryProviderStateException($state); }
        $this->pdo->prepare("UPDATE responses_api_deliveries SET status = 'waiting', response_status = ?, response_id = ?, response_state = ?, last_error = NULL, last_failure_code = NULL, next_attempt_at = ? WHERE id = ? AND status = 'sending'")
            ->execute([(int) $response['status'], $responseId, $state ?: 'queued', date('Y-m-d H:i:s', time() + 2), (int) $row['id']]);
        return 'started';
    }

    private function poll(array $row)
    {
        $this->pdo->prepare("UPDATE responses_api_deliveries SET last_attempt_at = ? WHERE id = ? AND status = 'waiting'")
            ->execute([Db::now(), (int) $row['id']]);
        $response = $this->request('GET', 'https://api.openai.com/v1/responses/' . rawurlencode($row['response_id']), null, $row['responses_api_key_encrypted']);
        $decoded = $this->validResponse($response);
        $state = trim((string) (isset($decoded['status']) ? $decoded['status'] : ''));
        if ($state === 'completed') { $this->markSucceeded($row, $row['response_id'], $state, (int) $response['status']); return 'succeeded'; }
        if (in_array($state, ['failed', 'cancelled', 'incomplete'], true)) { throw new DeliveryProviderStateException($state); }
        $this->pdo->prepare("UPDATE responses_api_deliveries SET response_state = ?, response_status = ?, next_attempt_at = ? WHERE id = ? AND status = 'waiting'")
            ->execute([$state ?: 'in_progress', (int) $response['status'], date('Y-m-d H:i:s', time() + 2), (int) $row['id']]);
        return 'started';
    }

    private function validResponse(array $response)
    {
        $decoded = json_decode((string) (isset($response['body']) ? $response['body'] : ''), true);
        $status = (int) (isset($response['status']) ? $response['status'] : 0);
        if ($status < 200 || $status >= 300) {
            throw new ResponsesApiHttpException($status);
        }
        if (!is_array($decoded)) { throw new RuntimeException('OpenAI Responses API returned invalid JSON.'); }
        return $decoded;
    }

    private function request($method, $url, $payload, $encryptedApiKey, $idempotencyKey = null)
    {
        $headers = ['Authorization: Bearer ' . $this->decryptSecret($encryptedApiKey), 'Content-Type: application/json', 'User-Agent: Syndicatum-Responses-Agent/1.0'];
        if ($idempotencyKey) { $headers[] = 'Idempotency-Key: ' . $idempotencyKey; }
        if (is_callable($this->transport)) { return call_user_func($this->transport, $method, $url, $payload, $headers); }
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        if ($payload !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers); curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5); curl_setopt($handle, CURLOPT_TIMEOUT, 30);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false); curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true); curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        $body = curl_exec($handle);
        if ($body === false) { $failureCode = curl_errno($handle) === 28 ? 'timeout' : 'transport'; curl_close($handle); throw new DeliveryTransportException($failureCode); }
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_close($handle);
        return ['status' => $status, 'body' => $body];
    }

    private function markSucceeded(array $row, $responseId, $state, $httpStatus)
    {
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $updated = $this->pdo->prepare("UPDATE responses_api_deliveries SET status = 'succeeded', response_status = ?, response_id = ?, response_state = ?, last_error = NULL, last_failure_code = NULL, delivered_at = ? WHERE id = ? AND status IN ('sending', 'waiting')");
            $updated->execute([$httpStatus, $responseId, $state, $now, (int) $row['id']]);
            if ($updated->rowCount() !== 1) { $this->pdo->rollBack(); return; }
            $this->pdo->prepare('UPDATE agent_activation_bindings SET responses_last_response_id = ?, responses_last_success_at = ?, responses_last_error = NULL WHERE project_id = ? AND agent_id = ?')
                ->execute([$responseId, $now, (int) $row['project_id'], (int) $row['agent_id']]);
            $this->pdo->prepare('UPDATE message_addressees ma JOIN project_participants pp ON pp.id = ma.participant_id SET ma.notified_at = COALESCE(ma.notified_at, ?) WHERE ma.message_id = ? AND pp.project_id = ? AND pp.agent_id = ?')
                ->execute([$now, (int) $row['message_id'], (int) $row['project_id'], (int) $row['agent_id']]);
            $this->pdo->commit();
        } catch (Exception $exception) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $exception; }
    }

    private function markFailed(array $row, Exception $exception, $dead)
    {
        $attempt = max(1, (int) $row['attempt_count'] + ($row['status'] === 'waiting' ? 0 : 1));
        $delays = [5, 30, 120, 600, 1800, 3600, 7200]; $delay = $delays[min(count($delays) - 1, $attempt - 1)];
        $status = $exception instanceof ResponsesApiHttpException ? $exception->status() : null;
        $code = DeliveryFailureTaxonomy::fromException($exception, $status);
        $error = DeliveryFailureTaxonomy::safeSummary($code, $status);
        $providerState = $exception instanceof DeliveryProviderStateException ? $exception->state() : null;
        $this->pdo->prepare("UPDATE responses_api_deliveries SET status = ?, next_attempt_at = ?, response_status = ?, response_id = NULL, response_state = ?, last_error = ?, last_failure_code = ? WHERE id = ? AND status IN ('sending', 'waiting')")
            ->execute([$dead ? 'dead' : 'retry', date('Y-m-d H:i:s', time() + $delay), $status, $providerState, $error, $code, (int) $row['id']]);
        $this->pdo->prepare('UPDATE agent_activation_bindings SET responses_last_failure_at = ?, responses_last_error = ? WHERE project_id = ? AND agent_id = ?')
            ->execute([Db::now(), $error, (int) $row['project_id'], (int) $row['agent_id']]);
    }

    private function encryptionKey($secret) { if ($secret === null || trim((string) $secret) === '') { throw new RuntimeException('PBB_AGENTCHAT_SECRET is required for Responses API configuration.'); } return hash('sha256', "syndicatum-responses-api\n" . $secret, true); }
    private function acquireLock() { if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return true; } $s = $this->pdo->prepare('SELECT GET_LOCK(?, 0)'); $s->execute(['syndicatum_responses_api_worker']); return (int) $s->fetchColumn() === 1; }
    private function releaseLock() { if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; } $s = $this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $s->execute(['syndicatum_responses_api_worker']); }
    private function uuidV4() { $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); $h = bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
}
