<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';

class AccountIntegration
{
    const ATTEMPT_COOKIE = 'syndicatum_account_oauth';
    const ATTEMPT_TTL_SECONDS = 600;

    private $pdo;
    private $settings;
    private $auth;
    private $transport;

    public function __construct(PDO $pdo, $settings, AuthService $auth, $transport = null)
    {
        $this->pdo = $pdo;
        $this->settings = $settings;
        $this->auth = $auth;
        $this->transport = $transport;
    }

    public function isEnabled()
    {
        try {
            return $this->settings->get('account.enabled') === true;
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * Create a browser-bound one-time OAuth attempt.
     */
    public function beginAuthorization($returnPath = '/')
    {
        $this->requireEnabled();
        if (!Db::tableExists($this->pdo, 'account_oauth_attempts')) {
            throw new RuntimeException('Account OAuth storage is not installed.');
        }

        $attemptToken = self::randomToken(32);
        $state = bin2hex(self::secureRandomBytes(16));
        $nonce = bin2hex(self::secureRandomBytes(32));
        $returnPath = self::safeReturnPath($returnPath);
        $now = Db::now();
        $expiresAt = date('Y-m-d H:i:s', time() + self::ATTEMPT_TTL_SECONDS);

        $this->pdo->prepare('DELETE FROM account_oauth_attempts WHERE expires_at < ?')->execute([$now]);
        $statement = $this->pdo->prepare(
            'INSERT INTO account_oauth_attempts
             (attempt_hash, state_hash, nonce, return_path, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            hash('sha256', $attemptToken),
            hash('sha256', $state),
            $nonce,
            $returnPath,
            $now,
            $expiresAt,
        ]);

        $baseUrl = rtrim($this->requiredSetting('account.base_url'), '/');
        $authorizationUrl = $baseUrl . '/oauth/authorize?' . http_build_query([
            'client_id' => $this->requiredSetting('account.client_id'),
            'redirect_uri' => $this->requiredSetting('account.callback_url'),
            'response_type' => 'code',
            'scope' => trim((string) $this->settings->get('account.scopes')),
            'state' => $state,
            'nonce' => $nonce,
        ]);

        return [
            'authorization_url' => $authorizationUrl,
            'attempt_token' => $attemptToken,
            'attempt_expires_at' => $expiresAt,
        ];
    }

    /**
     * Validate the browser-bound callback, exchange the code, provision a
     * local ordinary user when necessary, and create the local session.
     */
    public function completeCallback(array $query, $attemptToken)
    {
        $this->requireEnabled();
        $state = isset($query['state']) ? trim((string) $query['state']) : '';
        $attemptToken = trim((string) $attemptToken);
        if ($state === '' || $attemptToken === '') {
            throw new RuntimeException('Account callback state is invalid or expired.');
        }

        $attempt = $this->consumeAttempt($attemptToken, $state);
        if (isset($query['error'])) {
            throw new RuntimeException('PBB Account did not authorize sign in.');
        }
        $code = isset($query['code']) ? trim((string) $query['code']) : '';
        if ($code === '') {
            throw new RuntimeException('Account callback is missing an authorization code.');
        }

        $token = $this->exchangeCode($code, $attempt['nonce']);
        if (!isset($token['nonce']) || !is_string($token['nonce'])
            || !hash_equals($attempt['nonce'], $token['nonce'])) {
            throw new RuntimeException('Account callback nonce is invalid.');
        }
        $identity = isset($token['user']) && is_array($token['user']) ? $token['user'] : $token;
        $pbbUserId = isset($identity['pbb_user_id']) ? trim((string) $identity['pbb_user_id']) : '';
        $accountStatus = isset($identity['status']) ? trim((string) $identity['status']) : '';
        $accountSessionId = isset($token['account_session_id']) ? trim((string) $token['account_session_id']) : '';
        if ($pbbUserId === '' || strlen($pbbUserId) > 120
            || $accountSessionId === '' || strlen($accountSessionId) > 255) {
            throw new RuntimeException('Account response is missing required session identity.');
        }
        if ($accountStatus !== 'active') {
            throw new RuntimeException('PBB Account is not active.');
        }

        $result = $this->provisionAndCreateSession($identity, $pbbUserId, $accountSessionId);
        $result['return_path'] = $attempt['return_path'];
        return $result;
    }

    public function logoutUrl()
    {
        $this->requireEnabled();
        return rtrim($this->requiredSetting('account.base_url'), '/') . '/oauth/logout?' . http_build_query([
            'client_id' => $this->requiredSetting('account.client_id'),
            'post_logout_redirect_uri' => $this->requiredSetting('account.post_logout_url'),
        ]);
    }

    public static function setAttemptCookie($token, $expiresAt)
    {
        // The database timestamp uses the configured application timezone.
        // Use the bounded duration instead of reparsing it under another zone.
        self::emitCookie(self::ATTEMPT_COOKIE, (string) $token, time() + self::ATTEMPT_TTL_SECONDS, true);
    }

    public static function clearAttemptCookie()
    {
        self::emitCookie(self::ATTEMPT_COOKIE, '', time() - 3600, true);
    }

    public static function safeReturnPath($value)
    {
        $path = trim((string) $value);
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '\\') !== false
            || strpos($path, "\r") !== false || strpos($path, "\n") !== false) {
            return '/';
        }
        return substr($path, 0, 500);
    }

    private function consumeAttempt($attemptToken, $state)
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM account_oauth_attempts
             WHERE attempt_hash = ? AND state_hash = ? AND consumed_at IS NULL AND expires_at > ?
             LIMIT 1'
        );
        $statement->execute([hash('sha256', $attemptToken), hash('sha256', $state), Db::now()]);
        $attempt = $statement->fetch();
        if (!$attempt) {
            throw new RuntimeException('Account callback state is invalid or expired.');
        }

        $consume = $this->pdo->prepare(
            'UPDATE account_oauth_attempts SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL'
        );
        $consume->execute([Db::now(), $attempt['id']]);
        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('Account callback state is invalid or expired.');
        }
        return $attempt;
    }

    private function exchangeCode($code, $nonce)
    {
        $request = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->requiredSetting('account.client_id'),
            'client_secret' => $this->requiredSetting('account.client_secret'),
            'redirect_uri' => $this->requiredSetting('account.callback_url'),
            'nonce' => $nonce,
        ];
        $url = rtrim($this->requiredSetting('account.base_url'), '/') . '/oauth/token';
        $response = $this->sendJson($url, $request);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $body = json_decode(isset($response['body']) ? (string) $response['body'] : '', true);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            throw new RuntimeException('Unable to exchange the PBB Account authorization code.');
        }
        return $body;
    }

    private function sendJson($url, array $payload)
    {
        $config = [
            'timeout_seconds' => max(1, (int) $this->settings->get('account.timeout_seconds')),
            'ca_bundle' => trim((string) $this->settings->get('account.ca_bundle')),
        ];
        if (is_callable($this->transport)) {
            return call_user_func($this->transport, $url, $payload, $config);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required for PBB Account sign in.');
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $curl = curl_init($url);
        $options = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $config['timeout_seconds']),
            CURLOPT_TIMEOUT => $config['timeout_seconds'],
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
        ];
        if ($config['ca_bundle'] !== '') {
            $options[CURLOPT_CAINFO] = $config['ca_bundle'];
        }
        curl_setopt_array($curl, $options);
        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            curl_close($curl);
            throw new RuntimeException('PBB Account is unavailable.');
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => (string) $responseBody];
    }

    private function provisionAndCreateSession(array $identity, $pbbUserId, $accountSessionId)
    {
        $email = isset($identity['email']) ? strtolower(trim((string) $identity['email'])) : '';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('PBB Account returned an invalid email address.');
        }
        if (strlen($email) > 191) {
            throw new RuntimeException('PBB Account returned an invalid email address.');
        }
        $displayName = isset($identity['name']) ? trim((string) $identity['name']) : '';
        if ($displayName === '') {
            $displayName = $email !== '' ? $email : 'PBB Account User';
        }
        $displayName = substr($displayName, 0, 120);
        $avatarUrl = isset($identity['avatar_url']) && trim((string) $identity['avatar_url']) !== ''
            ? substr(trim((string) $identity['avatar_url']), 0, 2048)
            : null;
        if ($avatarUrl !== null) {
            $avatarScheme = strtolower((string) parse_url($avatarUrl, PHP_URL_SCHEME));
            if (!filter_var($avatarUrl, FILTER_VALIDATE_URL) || !in_array($avatarScheme, ['http', 'https'], true)) {
                $avatarUrl = null;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare('SELECT * FROM users WHERE pbb_user_id = ? LIMIT 1 FOR UPDATE');
            $lookup->execute([$pbbUserId]);
            $user = $lookup->fetch();

            if (!$user && $email !== '') {
                $collision = $this->pdo->prepare('SELECT id FROM users WHERE normalized_email = ? LIMIT 1');
                $collision->execute([$email]);
                if ($collision->fetchColumn() !== false) {
                    throw new RuntimeException('This email belongs to an existing Syndicatum user and must be linked deliberately.');
                }
            }

            $now = Db::now();
            if (!$user) {
                $insert = $this->pdo->prepare(
                    "INSERT INTO users
                     (normalized_email, username, password_hash, display_name, avatar_url, pbb_user_id, status, created_at, updated_at)
                     VALUES (?, NULL, NULL, ?, ?, ?, 'active', ?, ?)"
                );
                $insert->execute([$email === '' ? null : $email, $displayName, $avatarUrl, $pbbUserId, $now, $now]);
                $userId = (int) $this->pdo->lastInsertId();
                $role = $this->pdo->prepare(
                    "INSERT INTO user_system_roles (user_id, role_id, granted_by_user_id, created_at)
                     SELECT ?, id, NULL, ? FROM system_roles WHERE code = 'user'"
                );
                $role->execute([$userId, $now]);
                $workspace = $this->pdo->prepare(
                    'INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)'
                );
                $workspace->execute([$userId, $displayName . "'s workspace", $now, $now]);
            } else {
                $userId = (int) $user['id'];
                if ($user['status'] !== 'active' || $user['deleted_at'] !== null) {
                    throw new RuntimeException('This Syndicatum user is not active.');
                }
                if ($email !== '' && $email !== (string) $user['normalized_email']) {
                    $collision = $this->pdo->prepare('SELECT id FROM users WHERE normalized_email = ? AND id <> ? LIMIT 1');
                    $collision->execute([$email, $userId]);
                    if ($collision->fetchColumn() !== false) {
                        throw new RuntimeException('The PBB Account email conflicts with another Syndicatum user.');
                    }
                }
                $update = $this->pdo->prepare(
                    'UPDATE users SET normalized_email = ?, display_name = ?, avatar_url = ?, updated_at = ? WHERE id = ?'
                );
                $update->execute([$email === '' ? $user['normalized_email'] : $email, $displayName, $avatarUrl, $now, $userId]);
            }

            $session = $this->auth->createSession($userId, $accountSessionId);
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $this->auth->audit($userId, 'auth.account_login_succeeded', 'user', (string) $userId);
        return ['user' => $this->auth->publicUser($userId), 'session' => $session];
    }

    private function requireEnabled()
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('PBB Account integration is disabled.');
        }
    }

    private function requiredSetting($key)
    {
        $value = trim((string) $this->settings->get($key));
        if ($value === '') {
            throw new RuntimeException('PBB Account integration is not configured.');
        }
        return $value;
    }

    private static function randomToken($bytes)
    {
        return rtrim(strtr(base64_encode(self::secureRandomBytes($bytes)), '+/', '-_'), '=');
    }

    private static function secureRandomBytes($bytes)
    {
        if (function_exists('random_bytes')) {
            return random_bytes($bytes);
        }
        $strong = false;
        $value = openssl_random_pseudo_bytes($bytes, $strong);
        if ($value === false || !$strong) {
            throw new RuntimeException('A cryptographically secure random source is required.');
        }
        return $value;
    }

    private static function emitCookie($name, $value, $expires, $httpOnly)
    {
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $cookie = rawurlencode($name) . '=' . rawurlencode($value)
            . '; Path=/; Expires=' . gmdate('D, d M Y H:i:s T', $expires) . '; SameSite=Lax';
        if ($secure) {
            $cookie .= '; Secure';
        }
        if ($httpOnly) {
            $cookie .= '; HttpOnly';
        }
        header('Set-Cookie: ' . $cookie, false);
    }
}
