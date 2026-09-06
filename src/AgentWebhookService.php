<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';

class AgentWebhookService
{
    const EVENT_MESSAGE_CREATED = 'message.created';

    private $pdo;
    private $auth;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
    }

    public function configuration($projectId, $agentId, $actorUserId)
    {
        $this->requireProjectAgentAdmin($projectId, $agentId, $actorUserId);
        $statement = $this->pdo->prepare(
            'SELECT agent_id, project_id, endpoint_url, enabled, events_json, created_at, updated_at,
                    last_success_at, last_failure_at, last_error
             FROM agent_notification_webhooks WHERE project_id = ? AND agent_id = ?'
        );
        $statement->execute([(int) $projectId, (int) $agentId]);
        return $this->normalizeConfiguration($statement->fetch());
    }

    public function configure($projectId, $agentId, $actorUserId, array $input)
    {
        $this->requireProjectAgentAdmin($projectId, $agentId, $actorUserId);
        $existing = $this->pdo->prepare(
            'SELECT endpoint_url, enabled, events_json, signing_secret_encrypted
             FROM agent_notification_webhooks WHERE project_id = ? AND agent_id = ?'
        );
        $existing->execute([(int) $projectId, (int) $agentId]);
        $existing = $existing->fetch();
        $endpoint = array_key_exists('endpoint_url', $input)
            ? trim((string) $input['endpoint_url'])
            : ($existing ? $existing['endpoint_url'] : '');
        self::validateEndpointUrl($endpoint);
        if (array_key_exists('enabled', $input) && !is_bool($input['enabled'])) {
            throw new InvalidArgumentException('Webhook enabled must be boolean.');
        }
        $enabled = array_key_exists('enabled', $input) ? $input['enabled'] === true : ($existing ? (bool) $existing['enabled'] : true);
        $events = isset($input['events']) && is_array($input['events'])
            ? $input['events']
            : ($existing ? json_decode($existing['events_json'], true) : [self::EVENT_MESSAGE_CREATED]);
        $events = array_values(array_unique(array_intersect($events, [self::EVENT_MESSAGE_CREATED])));
        if (!$events) {
            throw new InvalidArgumentException('At least one supported webhook event is required.');
        }

        if (array_key_exists('replace_secret', $input) && !is_bool($input['replace_secret'])) {
            throw new InvalidArgumentException('replace_secret must be boolean.');
        }
        $encrypted = $existing ? $existing['signing_secret_encrypted'] : false;
        $replaceSecret = $encrypted === false || (isset($input['replace_secret']) && $input['replace_secret'] === true);
        $secret = null;
        if ($replaceSecret) {
            $secret = $this->randomToken(32);
            $encrypted = $this->encrypt($secret);
        }
        $now = Db::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO agent_notification_webhooks
             (agent_id, project_id, endpoint_url, enabled, events_json, signing_secret_encrypted, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE endpoint_url = VALUES(endpoint_url), enabled = VALUES(enabled),
               events_json = VALUES(events_json), signing_secret_encrypted = VALUES(signing_secret_encrypted), updated_at = VALUES(updated_at)'
        );
        $statement->execute([(int) $agentId, (int) $projectId, $endpoint, $enabled ? 1 : 0,
            json_encode($events), $encrypted, $now, $now]);
        $this->auth->audit((int) $actorUserId, 'project.agent_webhook_configured', 'agent', (string) ((int) $agentId), [
            'project_id' => (int) $projectId, 'enabled' => $enabled, 'events' => $events, 'secret_replaced' => $replaceSecret,
        ]);
        $result = $this->configuration($projectId, $agentId, $actorUserId);
        if ($secret !== null) {
            $result['signing_secret'] = $secret;
        }
        return $result;
    }

    public function enqueueMessageCreated($projectId, $messageId, array $message)
    {
        // Code may be deployed before this optional additive migration; messaging must remain available.
        if (!Db::tableExists($this->pdo, 'agent_notification_webhooks')
            || !Db::tableExists($this->pdo, 'agent_webhook_deliveries')) {
            return;
        }
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT pp.agent_id, pp.id AS participant_id, w.events_json
             FROM message_addressees ma
             JOIN project_participants pp ON pp.id = ma.participant_id AND pp.kind = 'agent' AND pp.status = 'active'
             JOIN messages m ON m.id = ma.message_id
             JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id AND pa.status = 'active'
             JOIN agent_notification_webhooks w ON w.project_id = pp.project_id AND w.agent_id = pp.agent_id AND w.enabled = 1
             WHERE ma.message_id = ? AND pp.project_id = ? AND pp.id <> m.sender_participant_id"
        );
        $statement->execute([(int) $messageId, (int) $projectId]);
        $now = Db::now();
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO agent_webhook_deliveries
             (delivery_uuid, project_id, message_id, agent_id, event_type, payload_json, status, next_attempt_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'queued', ?, ?)"
        );
        foreach ($statement->fetchAll() as $row) {
            $events = json_decode($row['events_json'], true);
            if (!is_array($events) || !in_array(self::EVENT_MESSAGE_CREATED, $events, true)) {
                continue;
            }
            $agentId = (int) $row['agent_id'];
            $deliveryUuid = $this->uuidV4();
            $payload = [
                'event_id' => $deliveryUuid,
                'type' => 'syndicatum.message.created',
                'occurred_at' => isset($message['created_at']) ? $message['created_at'] : $now,
                'project_id' => (int) $projectId,
                'sequence' => (int) $message['project_sequence'],
                'recipient' => ['agent_id' => $agentId, 'participant_id' => (int) $row['participant_id']],
                'message' => $message,
            ];
            $insert->execute([$deliveryUuid, (int) $projectId, (int) $messageId, (int) $agentId,
                self::EVENT_MESSAGE_CREATED, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now, $now]);
        }
    }

    public function decryptSigningSecret($encrypted)
    {
        return $this->decrypt($encrypted);
    }

    public static function validateEndpointUrl($endpoint)
    {
        self::resolveEndpoint($endpoint);
        return trim((string) $endpoint);
    }

    public static function resolveEndpoint($endpoint)
    {
        $endpoint = trim((string) $endpoint);
        $parts = parse_url($endpoint);
        if ($endpoint === '' || strlen($endpoint) > 2048 || !filter_var($endpoint, FILTER_VALIDATE_URL)
            || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Webhook endpoint must be an absolute HTTPS URL without credentials or a fragment.');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new InvalidArgumentException('Webhook endpoint host is invalid.');
        }
        if (in_array($host, self::privateHostAllowlist(), true)) {
            $allowPrivate = true;
        } else {
            $allowPrivate = false;
        }
        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $addresses[] = $host;
        } else {
            $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A) : [];
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) { $addresses[] = $record['ip']; }
                }
            }
            if (!$addresses) {
                $ipv4 = @gethostbynamel($host);
                if (is_array($ipv4)) { $addresses = array_merge($addresses, $ipv4); }
            }
        }
        if (!$addresses) {
            throw new InvalidArgumentException('Webhook endpoint host could not be resolved.');
        }
        foreach (array_unique($addresses) as $address) {
            if (!$allowPrivate && !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Webhook endpoint must not resolve to a private, loopback, link-local, or reserved address.');
            }
        }
        return [
            'url' => $endpoint,
            'host' => $host,
            'port' => isset($parts['port']) ? (int) $parts['port'] : 443,
            'addresses' => array_values(array_unique($addresses)),
        ];
    }

    private static function privateHostAllowlist()
    {
        $raw = getenv('SYNDICATUM_WEBHOOK_PRIVATE_HOST_ALLOWLIST');
        if ($raw === false || trim((string) $raw) === '') { return []; }
        $hosts = [];
        foreach (explode(',', strtolower((string) $raw)) as $host) {
            $host = rtrim(trim($host), '.');
            if ($host !== '') { $hosts[] = $host; }
        }
        return array_values(array_unique($hosts));
    }

    private function requireProjectAgentAdmin($projectId, $agentId, $actorUserId)
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM project_members pm
             JOIN project_agents pa ON pa.project_id = pm.project_id AND pa.agent_id = ?
             WHERE pm.project_id = ? AND pm.user_id = ? AND pm.status = 'active' AND pm.role IN ('owner', 'admin')"
        );
        $statement->execute([(int) $agentId, (int) $projectId, (int) $actorUserId]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('PROJECT_NOT_FOUND');
        }
    }

    private function normalizeConfiguration($row)
    {
        if (!$row) {
            return null;
        }
        return [
            'agent_id' => (int) $row['agent_id'], 'project_id' => (int) $row['project_id'],
            'endpoint_url' => $row['endpoint_url'], 'enabled' => (bool) $row['enabled'],
            'events' => json_decode($row['events_json'], true), 'secret_configured' => true,
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            'last_success_at' => $row['last_success_at'], 'last_failure_at' => $row['last_failure_at'],
            'last_error' => $row['last_error'],
        ];
    }

    private function encrypt($plaintext)
    {
        $config = Db::config();
        $key = $this->encryptionKey($config['secret']);
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to protect the webhook signing secret.');
        }
        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        return base64_encode(chr(1) . $iv . $mac . $ciphertext);
    }

    private function decrypt($encoded)
    {
        $payload = base64_decode((string) $encoded, true);
        if ($payload === false || strlen($payload) < 49 || ord($payload[0]) !== 1) {
            throw new RuntimeException('Stored webhook signing secret is invalid.');
        }
        $iv = substr($payload, 1, 16);
        $mac = substr($payload, 17, 32);
        $ciphertext = substr($payload, 49);
        $config = Db::config();
        foreach ([$config['secret'], $config['previous_secret']] as $serverSecret) {
            if ($serverSecret === null) {
                continue;
            }
            $key = $this->encryptionKey($serverSecret);
            if (!hash_equals($mac, hash_hmac('sha256', $iv . $ciphertext, $key, true))) {
                continue;
            }
            $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if ($plaintext !== false) {
                return $plaintext;
            }
        }
        throw new RuntimeException('Stored webhook signing secret failed integrity verification.');
    }

    private function encryptionKey($serverSecret)
    {
        if ($serverSecret === null || trim((string) $serverSecret) === '') {
            throw new RuntimeException('PBB_AGENTCHAT_SECRET is required for webhook configuration.');
        }
        return hash('sha256', "syndicatum-agent-webhook\n" . $serverSecret, true);
    }

    private function randomToken($bytes)
    {
        return rtrim(strtr(base64_encode($this->randomBytes($bytes)), '+/', '-_'), '=');
    }

    private function uuidV4()
    {
        $bytes = $this->randomBytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function randomBytes($length)
    {
        if (function_exists('random_bytes')) {
            return random_bytes($length);
        }
        $strong = false;
        $bytes = openssl_random_pseudo_bytes($length, $strong);
        if ($bytes === false || !$strong) {
            throw new RuntimeException('A cryptographically secure random source is required.');
        }
        return $bytes;
    }
}
