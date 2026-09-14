<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/AccountIntegration.php';

class GoogleIntegration
{
    const ATTEMPT_COOKIE = 'syndicatum_google_oauth';
    const ATTEMPT_TTL_SECONDS = 600;
    const AUTHORIZATION_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const CERTIFICATES_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    private $pdo;
    private $settings;
    private $auth;
    private $transport;
    private $idTokenVerifier;

    public function __construct(PDO $pdo, $settings, AuthService $auth, $transport = null, $idTokenVerifier = null)
    {
        $this->pdo = $pdo;
        $this->settings = $settings;
        $this->auth = $auth;
        $this->transport = $transport;
        $this->idTokenVerifier = $idTokenVerifier;
    }

    public function isEnabled()
    {
        try { return $this->settings->get('google.enabled') === true; }
        catch (Exception $exception) { return false; }
    }

    public function beginAuthorization($returnPath = '/')
    {
        return $this->beginAttempt('login', null, $returnPath);
    }

    public function beginLinkAuthorization($userId, $returnPath = '/')
    {
        $userId = (int) $userId;
        if ($userId < 1) { throw new RuntimeException('AUTHENTICATION_REQUIRED'); }
        $statement = $this->pdo->prepare("SELECT google_subject FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1");
        $statement->execute([$userId]);
        $subject = $statement->fetchColumn();
        if ($subject === false) { throw new RuntimeException('AUTHENTICATION_REQUIRED'); }
        return $this->beginAttempt('link', $userId, $returnPath);
    }

    private function beginAttempt($flowType, $linkUserId, $returnPath)
    {
        $this->requireEnabled();
        if (!Db::tableExists($this->pdo, 'google_oauth_attempts')) {
            throw new RuntimeException('Google OAuth storage is not installed.');
        }
        $attemptToken = self::randomToken(32);
        $state = self::randomToken(32);
        $nonce = self::randomToken(32);
        $codeVerifier = self::randomToken(64);
        $now = Db::now();
        $expiresAt = date('Y-m-d H:i:s', time() + self::ATTEMPT_TTL_SECONDS);
        $this->pdo->prepare('DELETE FROM google_oauth_attempts WHERE expires_at < ?')->execute([$now]);
        $statement = $this->pdo->prepare(
            'INSERT INTO google_oauth_attempts
             (attempt_hash, state_hash, nonce, code_verifier, flow_type, link_user_id, return_path, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            hash('sha256', $attemptToken), hash('sha256', $state), $nonce, $codeVerifier,
            $flowType, $linkUserId, AccountIntegration::safeReturnPath($returnPath), $now, $expiresAt,
        ]);
        $authorizationUrl = self::AUTHORIZATION_URL . '?' . http_build_query([
            'client_id' => $this->requiredSetting('google.client_id'),
            'redirect_uri' => $this->requiredSetting('google.callback_url'),
            'response_type' => 'code',
            'scope' => trim((string) $this->settings->get('google.scopes')),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64Url(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ]);
        return ['authorization_url' => $authorizationUrl, 'attempt_token' => $attemptToken, 'attempt_expires_at' => $expiresAt];
    }

    public function completeCallback(array $query, $attemptToken)
    {
        $this->requireEnabled();
        $state = isset($query['state']) ? trim((string) $query['state']) : '';
        if ($state === '' || trim((string) $attemptToken) === '') {
            throw new RuntimeException('Google callback state is invalid or expired.');
        }
        $attempt = $this->consumeAttempt($attemptToken, $state);
        if (isset($query['error'])) { throw new RuntimeException('Google did not authorize sign in.'); }
        $code = isset($query['code']) ? trim((string) $query['code']) : '';
        if ($code === '') { throw new RuntimeException('Google callback is missing an authorization code.'); }
        $token = $this->exchangeCode($code, $attempt['code_verifier']);
        $idToken = isset($token['id_token']) ? trim((string) $token['id_token']) : '';
        if ($idToken === '') { throw new RuntimeException('Google did not return an identity token.'); }
        $claims = $this->verifyIdToken($idToken, $attempt['nonce']);
        $flowType = isset($attempt['flow_type']) ? (string) $attempt['flow_type'] : 'login';
        if ($flowType === 'link') {
            $currentUser = $this->auth->currentUser();
            if (!$currentUser || (int) $currentUser['id'] !== (int) $attempt['link_user_id']) {
                throw new RuntimeException('GOOGLE_LINK_SESSION_EXPIRED');
            }
            $result = $this->linkIdentity((int) $attempt['link_user_id'], $claims);
        } else {
            $result = $this->provisionAndCreateSession($claims);
        }
        $result['return_path'] = $attempt['return_path'];
        return $result;
    }

    public static function setAttemptCookie($token, $expiresAt)
    {
        self::emitCookie(self::ATTEMPT_COOKIE, (string) $token, time() + self::ATTEMPT_TTL_SECONDS);
    }

    public static function clearAttemptCookie()
    {
        self::emitCookie(self::ATTEMPT_COOKIE, '', time() - 3600);
    }

    private function consumeAttempt($attemptToken, $state)
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM google_oauth_attempts WHERE attempt_hash = ? AND state_hash = ?
             AND consumed_at IS NULL AND expires_at > ? LIMIT 1'
        );
        $statement->execute([hash('sha256', trim((string) $attemptToken)), hash('sha256', $state), Db::now()]);
        $attempt = $statement->fetch();
        if (!$attempt) { throw new RuntimeException('Google callback state is invalid or expired.'); }
        $consume = $this->pdo->prepare('UPDATE google_oauth_attempts SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL');
        $consume->execute([Db::now(), $attempt['id']]);
        if ($consume->rowCount() !== 1) { throw new RuntimeException('Google callback state is invalid or expired.'); }
        return $attempt;
    }

    private function exchangeCode($code, $codeVerifier)
    {
        $response = $this->send('POST', self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->requiredSetting('google.client_id'),
            'client_secret' => $this->requiredSetting('google.client_secret'),
            'redirect_uri' => $this->requiredSetting('google.callback_url'),
            'code_verifier' => $codeVerifier,
        ]);
        $body = json_decode(isset($response['body']) ? (string) $response['body'] : '', true);
        $status = (int) (isset($response['status']) ? $response['status'] : 0);
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            throw new RuntimeException('Unable to exchange the Google authorization code.');
        }
        return $body;
    }

    private function verifyIdToken($idToken, $expectedNonce)
    {
        if (is_callable($this->idTokenVerifier)) {
            $claims = call_user_func($this->idTokenVerifier, $idToken);
        } else {
            $parts = explode('.', $idToken);
            if (count($parts) !== 3) { throw new RuntimeException('Google identity token is malformed.'); }
            $header = json_decode(self::base64UrlDecode($parts[0]), true);
            $claims = json_decode(self::base64UrlDecode($parts[1]), true);
            $signature = self::base64UrlDecode($parts[2]);
            if (!is_array($header) || !is_array($claims) || (isset($header['alg']) ? $header['alg'] : '') !== 'RS256' || empty($header['kid'])) {
                throw new RuntimeException('Google identity token is invalid.');
            }
            $certResponse = $this->send('GET', self::CERTIFICATES_URL, []);
            $certificates = json_decode(isset($certResponse['body']) ? (string) $certResponse['body'] : '', true);
            $certificate = is_array($certificates) && isset($certificates[$header['kid']]) ? $certificates[$header['kid']] : null;
            if ((int) (isset($certResponse['status']) ? $certResponse['status'] : 0) !== 200 || !$certificate
                || openssl_verify($parts[0] . '.' . $parts[1], $signature, $certificate, OPENSSL_ALGO_SHA256) !== 1) {
                throw new RuntimeException('Google identity token signature is invalid.');
            }
        }
        if (!is_array($claims)) { throw new RuntimeException('Google identity token is invalid.'); }
        $issuer = isset($claims['iss']) ? (string) $claims['iss'] : '';
        $audience = isset($claims['aud']) ? $claims['aud'] : '';
        $clientId = $this->requiredSetting('google.client_id');
        $audienceMatches = is_array($audience) ? in_array($clientId, $audience, true) : hash_equals($clientId, (string) $audience);
        $now = time();
        $issuedAt = (int) (isset($claims['iat']) ? $claims['iat'] : 0);
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
            || !$audienceMatches || (int) (isset($claims['exp']) ? $claims['exp'] : 0) < $now - 60
            || $issuedAt <= 0 || $issuedAt > $now + 300
            || (isset($claims['nbf']) && (int) $claims['nbf'] > $now + 60)
            || !isset($claims['nonce']) || !hash_equals($expectedNonce, (string) $claims['nonce'])) {
            throw new RuntimeException('Google identity token claims are invalid.');
        }
        if (is_array($audience) && count($audience) > 1 && (string) (isset($claims['azp']) ? $claims['azp'] : '') !== $clientId) {
            throw new RuntimeException('Google identity token presenter is invalid.');
        }
        return $claims;
    }

    private function provisionAndCreateSession(array $claims)
    {
        $identity = $this->identityFromClaims($claims);
        $subject = $identity['subject'];
        $email = $identity['email'];
        $displayName = $identity['display_name'];
        $avatarUrl = $identity['avatar_url'];
        $this->pdo->beginTransaction();
        try {
            $lookup = $this->pdo->prepare('SELECT * FROM users WHERE google_subject = ? LIMIT 1 FOR UPDATE');
            $lookup->execute([$subject]);
            $user = $lookup->fetch();
            if (!$user) {
                $collision = $this->pdo->prepare('SELECT id FROM users WHERE normalized_email = ? LIMIT 1');
                $collision->execute([$email]);
                if ($collision->fetchColumn() !== false) {
                    throw new RuntimeException('This email belongs to an existing Syndicatum user and must be linked deliberately.');
                }
                $now = Db::now();
                $insert = $this->pdo->prepare(
                    "INSERT INTO users (normalized_email, username, password_hash, display_name, avatar_url, google_subject, status, created_at, updated_at)
                     VALUES (?, NULL, NULL, ?, ?, ?, 'active', ?, ?)"
                );
                $insert->execute([$email, $displayName, $avatarUrl, $subject, $now, $now]);
                $userId = (int) $this->pdo->lastInsertId();
                $this->pdo->prepare("INSERT INTO user_system_roles (user_id, role_id, granted_by_user_id, created_at)
                    SELECT ?, id, NULL, ? FROM system_roles WHERE code = 'user'")->execute([$userId, $now]);
                $this->pdo->prepare('INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, $displayName . "'s workspace", $now, $now]);
            } else {
                $userId = (int) $user['id'];
                if ($user['status'] !== 'active' || $user['deleted_at'] !== null) { throw new RuntimeException('This Syndicatum user is not active.'); }
                if ($email !== (string) $user['normalized_email']) {
                    $collision = $this->pdo->prepare('SELECT id FROM users WHERE normalized_email = ? AND id <> ? LIMIT 1');
                    $collision->execute([$email, $userId]);
                    if ($collision->fetchColumn() !== false) { throw new RuntimeException('The Google email conflicts with another Syndicatum user.'); }
                }
                $now = Db::now();
                $this->pdo->prepare('UPDATE users SET normalized_email = ?, display_name = ?, avatar_url = ?, updated_at = ? WHERE id = ?')
                    ->execute([$email, $displayName, $avatarUrl, $now, $userId]);
            }
            $session = $this->auth->createSession($userId, null, 'google');
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
        $this->auth->audit($userId, 'auth.google_login_succeeded', 'user', (string) $userId);
        return ['user' => $this->auth->publicUser($userId), 'session' => $session];
    }

    private function linkIdentity($userId, array $claims)
    {
        $identity = $this->identityFromClaims($claims);
        $this->pdo->beginTransaction();
        try {
            $target = $this->pdo->prepare("SELECT id, google_subject, avatar_url FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1 FOR UPDATE");
            $target->execute([(int) $userId]);
            $user = $target->fetch();
            if (!$user) { throw new RuntimeException('GOOGLE_LINK_SESSION_EXPIRED'); }
            $existingSubject = trim((string) $user['google_subject']);
            if ($existingSubject !== '' && !hash_equals($existingSubject, $identity['subject'])) {
                throw new RuntimeException('GOOGLE_ACCOUNT_ALREADY_LINKED');
            }
            $owner = $this->pdo->prepare('SELECT id FROM users WHERE google_subject = ? AND id <> ? LIMIT 1 FOR UPDATE');
            $owner->execute([$identity['subject'], (int) $userId]);
            if ($owner->fetchColumn() !== false) { throw new RuntimeException('GOOGLE_IDENTITY_ALREADY_LINKED'); }
            $existingAvatar = trim((string) $user['avatar_url']);
            $googleAvatar = $identity['avatar_url'];
            $syncAvatar = $googleAvatar !== null && ($existingAvatar === '' || $this->isGoogleAvatarUrl($existingAvatar));
            if ($existingSubject === '' || $syncAvatar) {
                $nextAvatar = $syncAvatar ? $googleAvatar : ($existingAvatar === '' ? null : $existingAvatar);
                $this->pdo->prepare('UPDATE users SET google_subject = ?, avatar_url = ?, updated_at = ? WHERE id = ?')
                    ->execute([$identity['subject'], $nextAvatar, Db::now(), (int) $userId]);
            }
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
        $this->auth->audit((int) $userId, 'auth.google_link_succeeded', 'user', (string) ((int) $userId), ['avatar_synced' => $syncAvatar]);
        return ['user' => $this->auth->publicUser((int) $userId), 'linked' => true, 'avatar_synced' => $syncAvatar];
    }

    private function identityFromClaims(array $claims)
    {
        $subject = trim((string) (isset($claims['sub']) ? $claims['sub'] : ''));
        $email = strtolower(trim((string) (isset($claims['email']) ? $claims['email'] : '')));
        $verified = filter_var(isset($claims['email_verified']) ? $claims['email_verified'] : false, FILTER_VALIDATE_BOOLEAN);
        if ($subject === '' || strlen($subject) > 255) { throw new RuntimeException('Google returned an invalid subject identifier.'); }
        if (!$verified || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            throw new RuntimeException('Google must return a verified email address.');
        }
        $displayName = trim((string) (isset($claims['name']) ? $claims['name'] : ''));
        if ($displayName === '') { $displayName = $email; }
        return [
            'subject' => $subject,
            'email' => $email,
            'display_name' => substr($displayName, 0, 120),
            'avatar_url' => $this->safeAvatarUrl(isset($claims['picture']) ? $claims['picture'] : null),
        ];
    }

    private function safeAvatarUrl($value)
    {
        $url = trim((string) $value);
        if ($url === '' || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') { return null; }
        return $url;
    }

    private function isGoogleAvatarUrl($value)
    {
        $host = strtolower(trim((string) parse_url((string) $value, PHP_URL_HOST)));
        return $host === 'googleusercontent.com' || substr($host, -22) === '.googleusercontent.com';
    }

    private function send($method, $url, array $payload)
    {
        $config = ['timeout_seconds' => max(1, (int) $this->settings->get('google.timeout_seconds')),
            'ca_bundle' => trim((string) $this->settings->get('google.ca_bundle'))];
        if (is_callable($this->transport)) { return call_user_func($this->transport, $method, $url, $payload, $config); }
        if (!function_exists('curl_init')) { throw new RuntimeException('The cURL extension is required for Google sign in.'); }
        $curl = curl_init($url);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(5, $config['timeout_seconds']),
            CURLOPT_TIMEOUT => $config['timeout_seconds'], CURLOPT_HTTPHEADER => ['Accept: application/json']];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_POSTFIELDS] = http_build_query($payload);
        }
        if ($config['ca_bundle'] !== '') { $options[CURLOPT_CAINFO] = $config['ca_bundle']; }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        if ($body === false) { curl_close($curl); throw new RuntimeException('Google sign in is unavailable.'); }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => (string) $body];
    }

    private function requireEnabled()
    {
        if (!$this->isEnabled()) { throw new RuntimeException('Google sign in is disabled.'); }
    }

    private function requiredSetting($key)
    {
        $value = trim((string) $this->settings->get($key));
        if ($value === '') { throw new RuntimeException('Google sign in is not configured.'); }
        return $value;
    }

    private static function randomToken($bytes)
    {
        if (function_exists('random_bytes')) { return self::base64Url(random_bytes($bytes)); }
        $strong = false;
        $value = openssl_random_pseudo_bytes($bytes, $strong);
        if ($value === false || !$strong) { throw new RuntimeException('A cryptographically secure random source is required.'); }
        return self::base64Url($value);
    }
    private static function base64Url($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private static function base64UrlDecode($value)
    {
        $value = strtr((string) $value, '-_', '+/');
        $decoded = base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) { throw new RuntimeException('Google identity token is malformed.'); }
        return $decoded;
    }
    private static function emitCookie($name, $value, $expires)
    {
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        $cookie = rawurlencode($name) . '=' . rawurlencode($value) . '; Path=/; Expires=' . gmdate('D, d M Y H:i:s T', $expires) . '; SameSite=Lax; HttpOnly';
        if ($secure) { $cookie .= '; Secure'; }
        header('Set-Cookie: ' . $cookie, false);
    }
}
