<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/DeliveryFailureTaxonomy.php';

/**
 * Optional PBB Realtime transport for project timeline events.
 *
 * The database and HTTP API remain authoritative. This class only issues
 * short-lived room admission tokens and publishes already-committed outbox
 * events. It intentionally contains no project authorization policy.
 */
class RealtimeIntegration
{
    const BACKUP_ROOM = 'syndicatum.backups.global';
    const BACKUP_EVENT = 'syndicatum.backup.updated';
    const RESTORE_EVENT = 'syndicatum.restore.updated';

    private $settings;
    private $transport;
    private $publishCurl;

    public function __construct($settings, $transport = null)
    {
        $this->settings = $settings;
        $this->transport = $transport;
        $this->publishCurl = null;
    }

    public function __destruct()
    {
        if ($this->publishCurl !== null) {
            curl_close($this->publishCurl);
            $this->publishCurl = null;
        }
    }

    public function isEnabled()
    {
        try {
            return $this->setting('realtime.enabled', false) === true;
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * Build a least-privilege admission payload for one authorized project participant.
     */
    public function buildAdmission(array $participant, $projectIdentifier, array $additionalRooms = [])
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('Realtime integration is disabled.');
        }

        $config = $this->admissionConfig();
        $participantId = isset($participant['id']) ? trim((string) $participant['id']) : '';
        if ($participantId === '') {
            throw new InvalidArgumentException('A participant id is required for Realtime admission.');
        }

        $displayName = isset($participant['display_name'])
            ? trim((string) $participant['display_name'])
            : ('Participant ' . $participantId);
        $rawRoom = self::rawProjectRoom($projectIdentifier);
        $room = self::normalizeProjectRoom($projectIdentifier);
        $issuedAt = time();
        $expiresAt = $issuedAt + $config['token_ttl_seconds'];
        $tokenId = 'rt_' . bin2hex(self::secureRandomBytes(10));
        $userId = 'participant:' . $participantId;

        $rooms = [$room];
        foreach ($additionalRooms as $additionalRoom) {
            $additionalRoom = trim((string) $additionalRoom);
            if ($additionalRoom === '' || !preg_match('/\A[a-z0-9][a-z0-9._-]{0,179}\z/', $additionalRoom)) {
                throw new InvalidArgumentException('A Realtime room is invalid.');
            }
            $rooms[] = $additionalRoom;
        }
        $rooms = array_values(array_unique($rooms));

        $claims = [
            'iss' => $config['issuer'],
            'sub' => $userId,
            'aud' => $config['audience'],
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => $tokenId,
            'project_code' => $config['project_code'],
            'app_code' => $config['client_code'],
            'user_id' => $userId,
            'display_name' => $displayName,
            'roles' => [],
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_rooms' => $rooms,
            'allowed_room_prefixes' => [],
            'attachment_policy' => [],
        ];

        return [
            'enabled' => true,
            'token' => $this->signJwt($claims, $config['signing_secret']),
            'websocket_url' => $config['websocket_url'],
            'app_code' => $config['client_code'],
            'project_code' => $config['project_code'],
            'raw_room' => $rawRoom,
            'room' => $room,
            'rooms' => $rooms,
            'expires_at' => gmdate('c', $expiresAt),
            'session' => [
                'token_id' => $tokenId,
                'user_id' => $userId,
                'display_name' => $displayName,
                'capabilities' => $claims['capabilities'],
                'allowed_rooms' => $claims['allowed_rooms'],
                'allowed_room_prefixes' => [],
                'attachment_policy' => [],
            ],
        ];
    }

    /** Build an administrator-only admission for the application-wide Backup room. */
    public function buildBackupAdmission(array $user)
    {
        if (!$this->isEnabled()) { throw new RuntimeException('Realtime integration is disabled.'); }
        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        $displayName = isset($user['display_name']) ? trim((string) $user['display_name']) : '';
        if ($userId < 1 || $displayName === '') {
            throw new InvalidArgumentException('An administrator identity is required for Backup Realtime admission.');
        }
        $config = $this->admissionConfig();
        $issuedAt = time();
        $expiresAt = $issuedAt + $config['token_ttl_seconds'];
        $tokenId = 'rt_backup_' . bin2hex(self::secureRandomBytes(10));
        $subject = 'administrator:' . $userId;
        $rooms = [self::BACKUP_ROOM];
        $claims = [
            'iss' => $config['issuer'], 'sub' => $subject, 'aud' => $config['audience'],
            'iat' => $issuedAt, 'exp' => $expiresAt, 'jti' => $tokenId,
            'project_code' => $config['project_code'], 'app_code' => $config['client_code'],
            'user_id' => $subject, 'display_name' => $displayName, 'roles' => ['administrator'],
            'capabilities' => ['session.connect', 'room.join'],
            'allowed_rooms' => $rooms, 'allowed_room_prefixes' => [], 'attachment_policy' => [],
        ];
        return [
            'enabled' => true, 'token' => $this->signJwt($claims, $config['signing_secret']),
            'websocket_url' => $config['websocket_url'], 'app_code' => $config['client_code'],
            'project_code' => $config['project_code'], 'room' => self::BACKUP_ROOM, 'rooms' => $rooms,
            'expires_at' => gmdate('c', $expiresAt),
            'session' => [
                'token_id' => $tokenId, 'user_id' => $subject, 'display_name' => $displayName,
                'capabilities' => $claims['capabilities'], 'allowed_rooms' => $rooms,
                'allowed_room_prefixes' => [], 'attachment_policy' => [],
            ],
        ];
    }

    public function buildConnectorAuthorizationAdmission($authorizationId, $deviceName, $expiresAt)
    {
        if (!$this->isEnabled()) { throw new RuntimeException('Realtime integration is disabled.'); }
        $authorizationId = strtolower(trim((string) $authorizationId));
        if (!preg_match('/^[a-f0-9]{32}$/', $authorizationId)) { throw new InvalidArgumentException('A valid connector authorization id is required.'); }
        $config = $this->admissionConfig('realtime.connector_authorization_project_code');
        $room = self::connectorAuthorizationRoom($authorizationId);
        $issuedAt = time();
        $expiry = min($issuedAt + 600, max($issuedAt + 60, strtotime((string) $expiresAt)));
        $tokenId = 'rt_connector_' . bin2hex(self::secureRandomBytes(10));
        $userId = 'connector-authorization:' . $authorizationId;
        $claims = [
            'iss' => $config['issuer'], 'sub' => $userId, 'aud' => $config['audience'],
            'iat' => $issuedAt, 'exp' => $expiry, 'jti' => $tokenId,
            'project_code' => $config['project_code'], 'app_code' => $config['client_code'],
            'user_id' => $userId, 'display_name' => trim((string) $deviceName) ?: 'Codex connector',
            'roles' => [], 'capabilities' => ['session.connect', 'room.join'],
            'allowed_rooms' => [$room], 'allowed_room_prefixes' => [], 'attachment_policy' => [],
        ];
        return [
            'enabled' => true, 'token' => $this->signJwt($claims, $config['signing_secret']),
            'websocket_url' => $config['websocket_url'], 'room' => $room,
            'app_code' => $config['client_code'], 'project_code' => $config['project_code'],
            'expires_at' => gmdate('c', $expiry),
        ];
    }

    public function publishConnectorAuthorizationApproved($authorizationId)
    {
        $authorizationId = strtolower(trim((string) $authorizationId));
        if (!preg_match('/^[a-f0-9]{32}$/', $authorizationId)) { throw new InvalidArgumentException('A valid connector authorization id is required.'); }
        $config = $this->publishConfig('realtime.connector_authorization_project_code');
        $response = $this->sendPublishRequest($config, [
            'client_code' => $config['client_code'], 'project_code' => $config['project_code'],
            'room' => self::connectorAuthorizationRoom($authorizationId),
            'event_type' => 'connector.authorization.approved',
            'payload' => ['authorization_id' => $authorizationId, 'status' => 'approved'],
            'meta' => ['source_module' => 'syndicatum-connector-authorization'],
            'event_id' => 'connector-authorization-' . $authorizationId,
        ]);
        if ((int) (isset($response['status']) ? $response['status'] : 0) !== 202) { throw new RuntimeException($this->safeResponseError($response)); }
        return true;
    }

    /** Publish the complete canonical backup snapshot. Failure is terminal to the caller; there is no alternate transport. */
    public function publishBackupUpdated(array $backup)
    {
        if (!$this->isEnabled()) { throw new RuntimeException('Realtime integration is disabled.'); }
        $operationId = trim((string) (isset($backup['operation_id']) ? $backup['operation_id'] : ''));
        $revision = (int) (isset($backup['revision']) ? $backup['revision'] : 0);
        if (!preg_match('/\A[0-9a-f-]{36}\z/i', $operationId) || $revision < 1) {
            throw new InvalidArgumentException('Backup Realtime snapshot is invalid.');
        }
        $config = $this->publishConfig();
        $response = $this->sendPublishRequest($config, [
            'client_code' => $config['client_code'],
            'project_code' => $config['project_code'],
            'room' => self::BACKUP_ROOM,
            'event_type' => self::BACKUP_EVENT,
            'payload' => ['backup' => $backup],
            'meta' => ['source_module' => 'syndicatum-backup-service'],
            'event_id' => 'backup-' . strtolower($operationId) . '-revision-' . $revision,
        ]);
        if ((int) (isset($response['status']) ? $response['status'] : 0) !== 202) {
            throw new RuntimeException('Backup Realtime publish failed: ' . $this->safeResponseError($response));
        }
        return true;
    }

    /** Publish a complete in-app restore snapshot to the administrator Backup room. */
    public function publishRestoreUpdated(array $restore)
    {
        if (!$this->isEnabled()) { throw new RuntimeException('Realtime integration is disabled.'); }
        $operationId = trim((string) ($restore['operation_id'] ?? ''));
        $revision = (int) ($restore['revision'] ?? 0);
        if (!preg_match('/\A[0-9a-f-]{36}\z/i', $operationId) || $revision < 1) {
            throw new InvalidArgumentException('Restore Realtime snapshot is invalid.');
        }
        $config = $this->publishConfig();
        $response = $this->sendPublishRequest($config, [
            'client_code'=>$config['client_code'], 'project_code'=>$config['project_code'],
            'room'=>self::BACKUP_ROOM, 'event_type'=>self::RESTORE_EVENT,
            'payload'=>['restore'=>$restore], 'meta'=>['source_module'=>'syndicatum-restore-service'],
            'event_id'=>'restore-'.strtolower($operationId).'-revision-'.$revision,
        ]);
        if ((int) ($response['status'] ?? 0) !== 202) {
            throw new RuntimeException('Restore Realtime publish failed: ' . $this->safeResponseError($response));
        }
        return true;
    }

    public static function connectorAuthorizationRoom($authorizationId)
    {
        return 'syndicatum.connector.authorization.' . strtolower(trim((string) $authorizationId));
    }

    /**
     * Publish one durable outbox record to PBB Realtime backend ingress.
     *
     * @return array status, retryable, http_status and optional error
     */
    public function publishOutboxEvent(array $event)
    {
        if (!$this->isEnabled()) {
            return [
                'status' => 'disabled',
                'retryable' => true,
                'http_status' => null,
                'failure_code' => 'integration_disabled',
                'error' => 'Realtime integration is disabled.',
            ];
        }

        try {
            $config = $this->publishConfig();
            $payload = json_decode((string) $event['payload_json'], true);
            if (!is_array($payload)) {
                throw new InvalidArgumentException('Outbox payload is not valid JSON.');
            }

            $request = [
                'client_code' => $config['client_code'],
                'project_code' => $config['project_code'],
                'room' => self::normalizeProjectRoom($event['project_id']),
                'event_type' => (string) $event['event_type'],
                'payload' => $payload,
                'meta' => [
                    'source_module' => 'syndicatum-message-outbox',
                ],
                'event_id' => (string) $event['event_uuid'],
            ];

            $response = $this->sendPublishRequest($config, $request);
            $status = isset($response['status']) ? (int) $response['status'] : 0;
            if ($status === 202) {
                return [
                    'status' => 'accepted',
                    'retryable' => false,
                    'http_status' => 202,
                ];
            }

            $retryable = $status === 0 || $status === 408 || $status === 429 || $status >= 500;
            return [
                'status' => 'rejected',
                'retryable' => $retryable,
                'http_status' => $status ?: null,
                'failure_code' => self::publishFailureCode($status),
                'error' => $this->safeResponseError($response),
            ];
        } catch (InvalidArgumentException $exception) {
            return [
                'status' => 'rejected',
                'retryable' => false,
                'http_status' => null,
                'failure_code' => 'invalid_request',
                'error' => 'Realtime publish request was invalid.',
            ];
        } catch (Exception $exception) {
            return [
                'status' => 'failed',
                'retryable' => true,
                'http_status' => null,
                'failure_code' => 'internal_error',
                'error' => 'Realtime publish failed before a response was received.',
            ];
        }
    }

    public static function rawProjectRoom($projectIdentifier)
    {
        $identifier = trim((string) $projectIdentifier);
        $identifier = preg_replace('/[^A-Za-z0-9._-]+/', '-', $identifier);
        $identifier = trim((string) $identifier, '.-');
        if ($identifier === '') {
            throw new InvalidArgumentException('A project identifier is required for a Realtime room.');
        }
        return 'syndicatum.project.' . $identifier;
    }

    public static function normalizeProjectRoom($projectIdentifier)
    {
        return 'chat.thread.' . self::rawProjectRoom($projectIdentifier);
    }

    private function admissionConfig($projectCodeSetting = 'realtime.project_code')
    {
        return [
            'issuer' => $this->requiredSetting('realtime.issuer'),
            'audience' => $this->requiredSetting('realtime.audience'),
            'signing_secret' => $this->requiredSetting('realtime.signing_secret'),
            'websocket_url' => $this->requiredSetting('realtime.websocket_url'),
            'client_code' => $this->requiredSetting('realtime.client_code'),
            'project_code' => $this->requiredSetting($projectCodeSetting),
            'token_ttl_seconds' => max(60, (int) $this->setting('realtime.token_ttl_seconds', 300)),
        ];
    }

    private function publishConfig($projectCodeSetting = 'realtime.project_code')
    {
        $baseUrl = rtrim((string) $this->setting('realtime.base_url', ''), '/');
        $publishUrl = trim((string) $this->setting('realtime.publish_url', ''));
        if ($publishUrl === '' && $baseUrl !== '') {
            $publishUrl = $baseUrl . '/api/v1/events/publish';
        }
        if ($publishUrl === '') {
            throw new InvalidArgumentException('Missing required Realtime setting: realtime.publish_url.');
        }

        return [
            'publish_url' => $publishUrl,
            'backend_ingress_secret' => $this->requiredSetting('realtime.backend_ingress_secret'),
            'client_code' => $this->requiredSetting('realtime.client_code'),
            'project_code' => $this->requiredSetting($projectCodeSetting),
            'connect_timeout_seconds' => max(1, (int) $this->setting('realtime.connect_timeout_seconds', 3)),
            'timeout_seconds' => max(1, (int) $this->setting('realtime.timeout_seconds', 5)),
            'ca_bundle' => trim((string) $this->setting('realtime.ca_bundle', '')),
        ];
    }

    private function sendPublishRequest(array $config, array $request)
    {
        if (is_callable($this->transport)) {
            return call_user_func($this->transport, $config, $request);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required for Realtime publishing.');
        }

        $body = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Unable to encode the Realtime publish request.');
        }

        if ($this->publishCurl === null) {
            $this->publishCurl = curl_init();
            if ($this->publishCurl === false) {
                $this->publishCurl = null;
                throw new RuntimeException('Realtime publish connection could not be initialized.');
            }
        } else {
            curl_reset($this->publishCurl);
        }
        $curl = $this->publishCurl;
        $options = [
            CURLOPT_URL => $config['publish_url'],
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $config['connect_timeout_seconds'],
            CURLOPT_TIMEOUT => $config['timeout_seconds'],
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Realtime-Backend-Secret: ' . $config['backend_ingress_secret'],
            ],
            CURLOPT_POSTFIELDS => $body,
        ];
        if ($config['ca_bundle'] !== '') {
            $options[CURLOPT_CAINFO] = $config['ca_bundle'];
        }
        curl_setopt_array($curl, $options);
        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $error = curl_error($curl);
            return ['status' => 0, 'body' => '', 'transport_error' => $error];
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    private function safeResponseError(array $response)
    {
        if (!empty($response['transport_error'])) {
            return 'Realtime publish transport failed.';
        }
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        // Remote reason/message bodies and transport exceptions are untrusted.
        // The worker persists this string in outbox diagnostics, so only keep
        // a bounded numeric status that cannot echo credentials or message text.
        return $status > 0 ? ('Realtime publish returned HTTP ' . $status . '.') : 'Realtime publish failed.';
    }

    public static function publishFailureCode($status)
    {
        return DeliveryFailureTaxonomy::fromHttpStatus($status, 'realtime');
    }

    private function requiredSetting($key)
    {
        $value = trim((string) $this->setting($key, ''));
        if ($value === '') {
            throw new InvalidArgumentException('Missing required Realtime setting: ' . $key . '.');
        }
        return $value;
    }

    private function setting($key, $default = null)
    {
        try {
            $value = $this->settings->get($key);
        } catch (InvalidArgumentException $exception) {
            return $default;
        }
        return $value === null ? $default : $value;
    }

    private function signJwt(array $claims, $secret)
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
        $segments[] = self::base64UrlEncode(hash_hmac('sha256', implode('.', $segments), $secret, true));
        return implode('.', $segments);
    }

    private static function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    private static function secureRandomBytes($length)
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
