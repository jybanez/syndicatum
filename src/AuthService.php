<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/AvatarService.php';

class AuthService
{
    const SESSION_COOKIE = 'syndicatum_session';
    const CSRF_COOKIE = 'syndicatum_csrf';

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function login($identity, $password)
    {
        $identity = strtolower(trim((string) $identity));
        if ($identity === '' || (string) $password === '') {
            throw new InvalidArgumentException('Identity and password are required.');
        }

        $statement = $this->pdo->prepare(
            "SELECT * FROM users
             WHERE status = 'active' AND deleted_at IS NULL
               AND (LOWER(normalized_email) = ? OR LOWER(username) = ?)
             LIMIT 1"
        );
        $statement->execute([$identity, $identity]);
        $user = $statement->fetch();

        if (!$user || empty($user['password_hash']) || !password_verify((string) $password, $user['password_hash'])) {
            $this->audit(null, 'auth.login_failed', 'identity', hash('sha256', $identity));
            throw new RuntimeException('Invalid sign-in credentials.');
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $rehash->execute([password_hash((string) $password, PASSWORD_DEFAULT), Db::now(), $user['id']]);
        }

        $session = $this->createSession((int) $user['id']);
        $this->audit((int) $user['id'], 'auth.login_succeeded', 'user', (string) $user['id']);
        return ['user' => $this->publicUser((int) $user['id']), 'session' => $session];
    }

    public function createSession($userId, $accountSessionId = null)
    {
        $token = self::randomToken(32);
        $csrf = self::randomToken(32);
        $now = Db::now();
        $sessionHours = Db::tableExists($this->pdo, 'system_settings') ? (int) (new SettingsService($this->pdo))->get('security.session_hours') : 12;
        $expires = date('Y-m-d H:i:s', time() + (max(1, $sessionHours) * 60 * 60));
        $statement = $this->pdo->prepare(
            'INSERT INTO syndicatum_sessions
             (user_id, token_hash, csrf_token_hash, account_session_id, ip_address, user_agent, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $userId,
            hash('sha256', $token),
            hash('sha256', $csrf),
            $accountSessionId,
            self::ipAddress(),
            self::userAgent(),
            $now,
            $now,
            $expires,
        ]);

        return ['token' => $token, 'csrf_token' => $csrf, 'expires_at' => $expires];
    }

    public function currentUser($token = null)
    {
        if ($token === null) {
            $token = isset($_COOKIE[self::SESSION_COOKIE]) ? $_COOKIE[self::SESSION_COOKIE] : '';
        }
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT s.id AS session_id, s.csrf_token_hash, s.expires_at, s.account_session_id, u.*
             FROM syndicatum_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > ?
               AND u.status = 'active' AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $statement->execute([hash('sha256', $token), Db::now()]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }

        $touch = $this->pdo->prepare('UPDATE syndicatum_sessions SET last_seen_at = ? WHERE id = ?');
        $touch->execute([Db::now(), $row['session_id']]);
        $user = $this->publicUser((int) $row['id']);
        $user['session_id'] = (int) $row['session_id'];
        $user['csrf_token_hash'] = $row['csrf_token_hash'];
        $user['auth_source'] = $row['account_session_id'] === null ? 'native' : 'account';
        $user['account_session_id'] = $row['account_session_id'];
        return $user;
    }

    public function requireUser()
    {
        $user = $this->currentUser();
        if (!$user) {
            throw new RuntimeException('AUTHENTICATION_REQUIRED');
        }
        return $user;
    }

    public function requireAdministrator()
    {
        $user = $this->requireUser();
        if (!in_array('administrator', $user['system_roles'], true)) {
            throw new RuntimeException('ADMINISTRATOR_REQUIRED');
        }
        return $user;
    }

    public function validateCsrf(array $user, $csrfToken)
    {
        $csrfToken = trim((string) $csrfToken);
        if ($csrfToken === '' || !hash_equals($user['csrf_token_hash'], hash('sha256', $csrfToken))) {
            throw new RuntimeException('CSRF_VALIDATION_FAILED');
        }
    }

    public function logout($token = null)
    {
        if ($token === null) {
            $token = isset($_COOKIE[self::SESSION_COOKIE]) ? $_COOKIE[self::SESSION_COOKIE] : '';
        }
        if (trim((string) $token) !== '') {
            $statement = $this->pdo->prepare('UPDATE syndicatum_sessions SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL');
            $statement->execute([Db::now(), hash('sha256', $token)]);
        }
    }

    public function updateProfile(array $user, array $input)
    {
        $displayName = isset($input['display_name']) ? trim((string) $input['display_name']) : '';
        if ($displayName === '' || strlen($displayName) > 120) {
            throw new InvalidArgumentException('Display name must contain 1 to 120 characters.');
        }
        $avatarUrl = isset($user['avatar_url']) ? $user['avatar_url'] : null;
        if (array_key_exists('avatar_url', $input) && trim((string) $input['avatar_url']) === '') {
            $avatarUrl = null;
        } elseif (isset($input['avatar_url']) && trim((string) $input['avatar_url']) !== '') {
            $avatarUrl = trim((string) $input['avatar_url']);
            $local = preg_match('#^api/v1/avatar\.php\?file=[a-f0-9]{40}\.(jpg|png|webp)$#', $avatarUrl) === 1;
            if (!$local) {
                throw new InvalidArgumentException('Avatar URL must reference an uploaded Syndicatum image.');
            }
        }

        $statement = $this->pdo->prepare('UPDATE users SET display_name = ?, avatar_url = ?, updated_at = ? WHERE id = ? AND status = \'active\' AND deleted_at IS NULL');
        $statement->execute([$displayName, $avatarUrl, Db::now(), (int) $user['id']]);
        if ($statement->rowCount() < 1 && !$this->publicUser((int) $user['id'])) {
            throw new RuntimeException('AUTHENTICATION_REQUIRED');
        }
        $this->audit((int) $user['id'], 'profile.updated', 'user', (string) ((int) $user['id']));
        if (isset($user['avatar_url']) && $avatarUrl !== $user['avatar_url']) {
            (new AvatarService())->deleteIfLocal($user['avatar_url']);
        }
        return $this->publicUser((int) $user['id']);
    }

    public function replaceAvatar(array $user, $avatarUrl)
    {
        return $this->updateProfile($user, [
            'display_name' => $user['display_name'],
            'avatar_url' => $avatarUrl,
        ]);
    }

    public function changePassword(array $user, $currentPassword, $newPassword, $confirmation)
    {
        $currentPassword = (string) $currentPassword;
        $newPassword = (string) $newPassword;
        $confirmation = (string) $confirmation;
        if ($newPassword !== $confirmation) {
            throw new InvalidArgumentException('New password and confirmation must match.');
        }
        if (strlen($newPassword) < 12) {
            throw new InvalidArgumentException('New password must be at least 12 characters.');
        }

        $newSessionToken = self::randomToken(32);
        $newCsrfToken = self::randomToken(32);
        $now = Db::now();
        $expires = date('Y-m-d H:i:s', time() + ($this->sessionHours() * 60 * 60));

        $this->pdo->beginTransaction();
        try {
            $session = $this->pdo->prepare(
                'SELECT s.id, s.user_id, u.password_hash
                 FROM syndicatum_sessions s JOIN users u ON u.id = s.user_id
                 WHERE s.id = ? AND s.user_id = ? AND s.revoked_at IS NULL AND s.expires_at > ?
                   AND u.status = \'active\' AND u.deleted_at IS NULL FOR UPDATE'
            );
            $session->execute([(int) $user['session_id'], (int) $user['id'], $now]);
            $row = $session->fetch();
            if (!$row) {
                throw new RuntimeException('AUTHENTICATION_REQUIRED');
            }
            if (empty($row['password_hash'])) {
                throw new RuntimeException('PASSWORD_MANAGED_BY_ACCOUNT');
            }
            if ($currentPassword === '' || !password_verify($currentPassword, $row['password_hash'])) {
                throw new RuntimeException('INVALID_CURRENT_PASSWORD');
            }

            $this->pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $now, (int) $user['id']]);
            $this->pdo->prepare(
                'UPDATE syndicatum_sessions
                 SET token_hash = ?, csrf_token_hash = ?, last_seen_at = ?, expires_at = ?
                 WHERE id = ? AND user_id = ?'
            )->execute([
                hash('sha256', $newSessionToken), hash('sha256', $newCsrfToken), $now, $expires,
                (int) $user['session_id'], (int) $user['id'],
            ]);
            $this->pdo->prepare(
                'UPDATE syndicatum_sessions SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL'
            )->execute([$now, (int) $user['id'], (int) $user['session_id']]);
            $this->audit((int) $user['id'], 'password.changed', 'user', (string) ((int) $user['id']), [
                'other_sessions_revoked' => true,
            ]);
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'user' => $this->publicUser((int) $user['id']),
            'session' => ['token' => $newSessionToken, 'csrf_token' => $newCsrfToken, 'expires_at' => $expires],
        ];
    }

    public function bootstrapAdministrator($identity, $displayName, $password)
    {
        $identity = strtolower(trim((string) $identity));
        $displayName = trim((string) $displayName);
        if (!filter_var($identity, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid administrator email is required.');
        }
        if ($displayName === '' || strlen((string) $password) < 12) {
            throw new InvalidArgumentException('Display name and a password of at least 12 characters are required.');
        }

        $lock = $this->pdo->query("SELECT GET_LOCK(CONCAT('syndicatum:bootstrap:', LEFT(SHA2(DATABASE(), 256), 32)), 10)")->fetchColumn();
        if ((int) $lock !== 1) { throw new RuntimeException('Unable to acquire the administrator bootstrap lock.'); }
        $this->pdo->beginTransaction();
        try {
            $administratorCount = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM user_system_roles ur JOIN system_roles r ON r.id = ur.role_id WHERE r.code = 'administrator'"
            )->fetchColumn();
            if ($administratorCount > 0) { throw new RuntimeException('An administrator has already been bootstrapped.'); }
            $now = Db::now();
            $insert = $this->pdo->prepare(
                "INSERT INTO users (normalized_email, username, password_hash, display_name, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'active', ?, ?)"
            );
            $username = preg_replace('/[^a-z0-9._-]+/', '-', strstr($identity, '@', true));
            $insert->execute([$identity, $username, password_hash((string) $password, PASSWORD_DEFAULT), $displayName, $now, $now]);
            $userId = (int) $this->pdo->lastInsertId();

            $roles = $this->pdo->prepare(
                "INSERT INTO user_system_roles (user_id, role_id, granted_by_user_id, created_at)
                 SELECT ?, id, NULL, ? FROM system_roles WHERE code IN ('user', 'administrator')"
            );
            $roles->execute([$userId, $now]);
            $workspace = $this->pdo->prepare('INSERT INTO workspaces (owner_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)');
            $workspace->execute([$userId, $displayName . "'s workspace", $now, $now]);
            $this->audit($userId, 'administrator.bootstrapped', 'user', (string) $userId);
            $this->pdo->commit();
            return $this->publicUser($userId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        } finally {
            try { $this->pdo->query("SELECT RELEASE_LOCK(CONCAT('syndicatum:bootstrap:', LEFT(SHA2(DATABASE(), 256), 32)))"); } catch (Exception $ignored) {}
        }
    }

    public function publicUser($userId)
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.normalized_email, u.username, u.display_name, u.avatar_url, u.pbb_user_id, u.status,
                    CASE WHEN u.password_hash IS NULL OR u.password_hash = \'\' THEN 0 ELSE 1 END AS has_native_password,
                    w.id AS workspace_id, w.name AS workspace_name
             FROM users u LEFT JOIN workspaces w ON w.owner_user_id = u.id WHERE u.id = ?'
        );
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if (!$user) {
            return null;
        }
        $roles = $this->pdo->prepare(
            'SELECT r.code FROM system_roles r JOIN user_system_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? ORDER BY r.code'
        );
        $roles->execute([$userId]);
        return [
            'id' => (int) $user['id'],
            'email' => $user['normalized_email'],
            'username' => $user['username'],
            'display_name' => $user['display_name'],
            'avatar_url' => $user['avatar_url'],
            'pbb_user_id' => $user['pbb_user_id'],
            'status' => $user['status'],
            'has_native_password' => (bool) $user['has_native_password'],
            'workspace' => $user['workspace_id'] ? ['id' => (int) $user['workspace_id'], 'name' => $user['workspace_name']] : null,
            'system_roles' => array_map(function ($row) { return $row['code']; }, $roles->fetchAll()),
        ];
    }

    public function audit($actorUserId, $action, $subjectType = null, $subjectId = null, array $metadata = [])
    {
        if (!Db::tableExists($this->pdo, 'administrative_audit_events')) {
            return;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO administrative_audit_events
             (actor_user_id, action, subject_type, subject_id, metadata_json, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $actorUserId,
            $action,
            $subjectType,
            $subjectId,
            empty($metadata) ? null : json_encode($metadata),
            self::ipAddress(),
            Db::now(),
        ]);
    }

    public static function setSessionCookies(array $session)
    {
        $secure = self::isSecureRequest();
        self::setCookieCompat(self::SESSION_COOKIE, $session['token'], strtotime($session['expires_at']), true, $secure);
        self::setCookieCompat(self::CSRF_COOKIE, $session['csrf_token'], strtotime($session['expires_at']), false, $secure);
    }

    public static function clearSessionCookies()
    {
        $secure = self::isSecureRequest();
        self::setCookieCompat(self::SESSION_COOKIE, '', time() - 3600, true, $secure);
        self::setCookieCompat(self::CSRF_COOKIE, '', time() - 3600, false, $secure);
    }

    public static function randomToken($bytes)
    {
        if (function_exists('random_bytes')) {
            return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        }
        $strong = false;
        $random = openssl_random_pseudo_bytes($bytes, $strong);
        if ($random === false || !$strong) {
            throw new RuntimeException('A cryptographically secure random source is required.');
        }
        return rtrim(strtr(base64_encode($random), '+/', '-_'), '=');
    }

    private function sessionHours()
    {
        $hours = Db::tableExists($this->pdo, 'system_settings')
            ? (int) (new SettingsService($this->pdo))->get('security.session_hours')
            : 12;
        return max(1, $hours);
    }

    private static function setCookieCompat($name, $value, $expires, $httpOnly, $secure)
    {
        $cookie = rawurlencode($name) . '=' . rawurlencode($value)
            . '; Path=/; Expires=' . gmdate('D, d M Y H:i:s T', $expires)
            . '; SameSite=Lax';
        if ($secure) {
            $cookie .= '; Secure';
        }
        if ($httpOnly) {
            $cookie .= '; HttpOnly';
        }
        header('Set-Cookie: ' . $cookie, false);
    }

    private static function isSecureRequest()
    {
        return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    }

    private static function ipAddress()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 80) : null;
    }

    private static function userAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
    }
}
