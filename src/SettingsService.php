<?php

require_once __DIR__ . '/Db.php';

class SettingsService
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function registry()
    {
        return [
            'general.installation_name' => ['section' => 'general', 'type' => 'string', 'default' => 'Syndicatum', 'max' => 120],
            'general.support_url' => ['section' => 'general', 'type' => 'url', 'default' => ''],
            'messaging.max_message_bytes' => ['section' => 'messaging', 'type' => 'integer', 'default' => 24000, 'min' => 1024, 'max' => 1048576],
            'messaging.max_reply_depth' => ['section' => 'messaging', 'type' => 'integer', 'default' => 12, 'min' => 1, 'max' => 100],
            'realtime.enabled' => ['section' => 'integrations', 'type' => 'boolean', 'default' => false],
            'realtime.base_url' => ['section' => 'integrations', 'type' => 'url', 'default' => ''],
            'realtime.publish_url' => ['section' => 'integrations', 'type' => 'url', 'default' => ''],
            'realtime.admission_url' => ['section' => 'integrations', 'type' => 'url', 'default' => ''],
            'realtime.websocket_url' => ['section' => 'integrations', 'type' => 'url', 'default' => '', 'schemes' => ['ws', 'wss']],
            'realtime.issuer' => ['section' => 'integrations', 'type' => 'string', 'default' => 'syndicatum@pbb.ph', 'max' => 120],
            'realtime.audience' => ['section' => 'integrations', 'type' => 'string', 'default' => 'pbb-realtime', 'max' => 120],
            'realtime.client_code' => ['section' => 'integrations', 'type' => 'string', 'default' => '', 'max' => 120],
            'realtime.project_code' => ['section' => 'integrations', 'type' => 'string', 'default' => '', 'max' => 120],
            'realtime.connector_authorization_project_code' => ['section' => 'integrations', 'type' => 'string', 'default' => '', 'max' => 120],
            'realtime.token_ttl_seconds' => ['section' => 'integrations', 'type' => 'integer', 'default' => 300, 'min' => 30, 'max' => 900],
            'realtime.signing_secret' => ['section' => 'integrations', 'type' => 'secret', 'default' => null],
            'realtime.backend_ingress_secret' => ['section' => 'integrations', 'type' => 'secret', 'default' => null],
            'realtime.connect_timeout_seconds' => ['section' => 'integrations', 'type' => 'integer', 'default' => 3, 'min' => 1, 'max' => 30],
            'realtime.timeout_seconds' => ['section' => 'integrations', 'type' => 'integer', 'default' => 10, 'min' => 1, 'max' => 60],
            'realtime.ca_bundle' => ['section' => 'integrations', 'type' => 'string', 'default' => '', 'max' => 1024],
            'account.enabled' => ['section' => 'integrations', 'type' => 'boolean', 'default' => false],
            'account.base_url' => ['section' => 'integrations', 'type' => 'url', 'default' => ''],
            'account.client_id' => ['section' => 'integrations', 'type' => 'string', 'default' => '', 'max' => 255],
            'account.client_secret' => ['section' => 'integrations', 'type' => 'secret', 'default' => null],
            'account.callback_url' => ['section' => 'integrations', 'type' => 'url', 'default' => ''],
            'account.post_logout_url' => ['section' => 'integrations', 'type' => 'url', 'default' => ''],
            'account.profile_url' => ['section' => 'integrations', 'type' => 'url', 'default' => ''],
            'account.scopes' => ['section' => 'integrations', 'type' => 'string', 'default' => 'openid profile', 'max' => 500],
            'account.timeout_seconds' => ['section' => 'integrations', 'type' => 'integer', 'default' => 10, 'min' => 1, 'max' => 30],
            'account.ca_bundle' => ['section' => 'integrations', 'type' => 'string', 'default' => '', 'max' => 1024],
            'account.native_login_enabled' => ['section' => 'security', 'type' => 'boolean', 'default' => true],
            'security.session_hours' => ['section' => 'security', 'type' => 'integer', 'default' => 12, 'min' => 1, 'max' => 720],
            'operations.legacy_api_enabled' => ['section' => 'operations', 'type' => 'boolean', 'default' => true],
            'operations.poll_interval_seconds' => ['section' => 'operations', 'type' => 'integer', 'default' => 15, 'min' => 3, 'max' => 300],
        ];
    }

    public function publicSettings($section = null)
    {
        $stored = $this->storedRows();
        $result = [];
        foreach (self::registry() as $key => $definition) {
            if ($section !== null && $definition['section'] !== $section) {
                continue;
            }
            $override = $this->environmentOverride($key, $definition);
            $row = isset($stored[$key]) ? $stored[$key] : null;
            $item = [
                'key' => $key,
                'section' => $definition['section'],
                'type' => $definition['type'],
                'locked' => $override['exists'],
                'source' => $override['exists'] ? 'environment' : ($row ? 'database' : 'default'),
            ];
            if ($definition['type'] === 'secret') {
                $configured = $override['exists'] || ($row && trim((string) $row['encrypted_value']) !== '');
                $item['configured'] = $configured;
                $item['masked'] = $configured ? '••••••••' : '';
                $item['value'] = null;
            } else {
                $item['value'] = $override['exists']
                    ? $override['value']
                    : ($row ? json_decode($row['value_json'], true) : $definition['default']);
            }
            $result[$key] = $item;
        }
        return $result;
    }

    public function get($key)
    {
        $registry = self::registry();
        if (!isset($registry[$key])) {
            throw new InvalidArgumentException('Unknown setting key.');
        }
        $definition = $registry[$key];
        $override = $this->environmentOverride($key, $definition);
        if ($override['exists']) {
            return $override['value'];
        }
        $statement = $this->pdo->prepare('SELECT value_json, encrypted_value FROM system_settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $row = $statement->fetch();
        if (!$row) {
            return $definition['default'];
        }
        if ($definition['type'] === 'secret') {
            return $this->decrypt($row['encrypted_value']);
        }
        return json_decode($row['value_json'], true);
    }

    public function update(array $changes, $actorUserId)
    {
        $changes = $this->withDerivedRealtimeEndpoints($changes);
        $this->validateRealtimeEndpointCompatibility($changes);
        $registry = self::registry();
        $this->pdo->beginTransaction();
        try {
            foreach ($changes as $key => $input) {
                if (!isset($registry[$key])) {
                    throw new InvalidArgumentException('Unknown setting key: ' . $key);
                }
                $definition = $registry[$key];
                if ($key === 'account.native_login_enabled' && $input !== true) {
                    throw new InvalidArgumentException('Native administrator recovery must remain enabled.');
                }
                if ($this->environmentOverride($key, $definition)['exists']) {
                    throw new InvalidArgumentException('Setting is locked by the environment: ' . $key);
                }

                $valueJson = null;
                $encryptedValue = null;
                if ($definition['type'] === 'secret') {
                    $operation = is_array($input) && isset($input['operation']) ? $input['operation'] : 'keep';
                    if ($operation === 'keep') {
                        continue;
                    }
                    if ($operation === 'replace') {
                        $secret = is_array($input) && isset($input['value']) ? (string) $input['value'] : '';
                        if ($secret === '') {
                            throw new InvalidArgumentException('Replacement secret cannot be empty: ' . $key);
                        }
                        $encryptedValue = $this->encrypt($secret);
                    } elseif ($operation !== 'clear') {
                        throw new InvalidArgumentException('Invalid secret operation for ' . $key);
                    }
                } else {
                    $valueJson = json_encode($this->validateValue($key, $input, $definition));
                }

                $statement = $this->pdo->prepare(
                    'INSERT INTO system_settings (setting_key, value_json, encrypted_value, updated_by_user_id, updated_at)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), encrypted_value = VALUES(encrypted_value),
                       updated_by_user_id = VALUES(updated_by_user_id), updated_at = VALUES(updated_at)'
                );
                $statement->execute([$key, $valueJson, $encryptedValue, $actorUserId, Db::now()]);
            }
            $audit = $this->pdo->prepare(
                'INSERT INTO administrative_audit_events (actor_user_id, action, subject_type, subject_id, metadata_json, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $audit->execute([(int) $actorUserId, 'settings.updated', 'system_settings', null,
                json_encode(['keys' => array_keys($changes)]),
                isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 80) : null, Db::now()]);
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $this->publicSettings();
    }

    private function withDerivedRealtimeEndpoints(array $changes)
    {
        if (!array_key_exists('realtime.base_url', $changes)) {
            return $changes;
        }

        $baseUrl = rtrim(trim((string) $changes['realtime.base_url']), '/');
        $changes['realtime.publish_url'] = $baseUrl === '' ? '' : $baseUrl . '/api/v1/events/publish';
        if ($baseUrl === '') {
            $changes['realtime.websocket_url'] = '';
        } else {
            $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
            $socketBase = ($scheme === 'https' ? 'wss://' : 'ws://') . substr($baseUrl, strlen($scheme . '://'));
            $changes['realtime.websocket_url'] = rtrim($socketBase, '/') . '/realtime';
        }

        return $changes;
    }

    private function validateRealtimeEndpointCompatibility(array $changes)
    {
        if (!array_key_exists('realtime.websocket_url', $changes)) {
            return;
        }

        $baseUrl = array_key_exists('realtime.base_url', $changes)
            ? trim((string) $changes['realtime.base_url'])
            : trim((string) $this->get('realtime.base_url'));
        $websocketUrl = trim((string) $changes['realtime.websocket_url']);
        if (strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) parse_url($websocketUrl, PHP_URL_SCHEME)) === 'ws') {
            throw new InvalidArgumentException('realtime.websocket_url must use wss when realtime.base_url uses https.');
        }
    }

    private function validateValue($key, $value, array $definition)
    {
        if ($definition['type'] === 'boolean') {
            if (!is_bool($value)) {
                throw new InvalidArgumentException($key . ' must be boolean.');
            }
            return $value;
        }
        if ($definition['type'] === 'integer') {
            if (!is_int($value) && !ctype_digit((string) $value)) {
                throw new InvalidArgumentException($key . ' must be an integer.');
            }
            $value = (int) $value;
            if ($value < $definition['min'] || $value > $definition['max']) {
                throw new InvalidArgumentException($key . ' is outside its allowed range.');
            }
            return $value;
        }
        $value = trim((string) $value);
        $scheme = $value === '' ? '' : strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $allowedSchemes = isset($definition['schemes']) ? $definition['schemes'] : ['http', 'https'];
        if ($definition['type'] === 'url' && $value !== '' && (!filter_var($value, FILTER_VALIDATE_URL) || !in_array($scheme, $allowedSchemes, true))) {
            throw new InvalidArgumentException($key . ' must be an absolute URL.');
        }
        if (isset($definition['max']) && strlen($value) > $definition['max']) {
            throw new InvalidArgumentException($key . ' is too long.');
        }
        return $value;
    }

    private function storedRows()
    {
        $rows = $this->pdo->query('SELECT setting_key, value_json, encrypted_value FROM system_settings')->fetchAll();
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['setting_key']] = $row;
        }
        return $indexed;
    }

    private function environmentOverride($key, array $definition)
    {
        $name = 'SYNDICATUM_SETTING_' . strtoupper(str_replace('.', '_', $key));
        $value = getenv($name);
        if ($value === false) {
            return ['exists' => false, 'value' => null];
        }
        if ($definition['type'] === 'secret') {
            return ['exists' => true, 'value' => (string) $value];
        }
        if ($definition['type'] === 'boolean') {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null) {
                throw new RuntimeException($name . ' must be a boolean.');
            }
            return ['exists' => true, 'value' => $parsed];
        }
        return ['exists' => true, 'value' => $this->validateValue($key, $value, $definition)];
    }

    private function encrypt($plaintext)
    {
        $key = $this->masterKey();
        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt((string) $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to protect the setting secret.');
        }
        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        return base64_encode(chr(1) . $iv . $mac . $ciphertext);
    }

    private function decrypt($encoded)
    {
        if ($encoded === null || trim((string) $encoded) === '') {
            return null;
        }
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) < 49 || ord($payload[0]) !== 1) {
            throw new RuntimeException('Stored setting secret is invalid.');
        }
        $iv = substr($payload, 1, 16);
        $mac = substr($payload, 17, 32);
        $ciphertext = substr($payload, 49);
        $key = $this->masterKey();
        if (!hash_equals($mac, hash_hmac('sha256', $iv . $ciphertext, $key, true))) {
            throw new RuntimeException('Stored setting secret failed integrity verification.');
        }
        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt the setting secret.');
        }
        return $plaintext;
    }

    private function masterKey()
    {
        $value = Db::secretValue('SYNDICATUM_MASTER_KEY');
        if ($value === null || strlen(trim((string) $value)) < 32) {
            throw new RuntimeException('SYNDICATUM_MASTER_KEY of at least 32 characters is required for stored integration secrets.');
        }
        return hash('sha256', (string) $value, true);
    }
}
