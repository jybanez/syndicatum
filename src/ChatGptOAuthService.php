<?php

require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/Db.php';

class ChatGptOAuthService
{
    const ISSUER = 'https://chatviewer.pbb.ph';
    const RESOURCE = 'https://chatviewer.pbb.ph/mcp';
    const SCOPES = ['projects:read', 'participants:read', 'messages:read', 'messages:write', 'messages:acknowledge'];

    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function registerClient(array $input)
    {
        $uris = isset($input['redirect_uris']) && is_array($input['redirect_uris']) ? $input['redirect_uris'] : [];
        $uris = array_values(array_unique(array_map('trim', $uris)));
        if (!$uris || count($uris) > 10) { throw new InvalidArgumentException('invalid_redirect_uris'); }
        foreach ($uris as $uri) { if (!$this->validRedirectUri($uri)) { throw new InvalidArgumentException('invalid_redirect_uri'); } }
        $method = isset($input['token_endpoint_auth_method']) ? (string) $input['token_endpoint_auth_method'] : 'none';
        if ($method !== 'none') { throw new InvalidArgumentException('unsupported_token_endpoint_auth_method'); }
        $clientId = 'syndicatum-' . AuthService::randomToken(24);
        $name = trim(isset($input['client_name']) ? (string) $input['client_name'] : 'ChatGPT');
        $name = $name === '' ? 'ChatGPT' : substr($name, 0, 160);
        $now = Db::now();
        $this->pdo->prepare('INSERT INTO oauth_clients (client_id, client_name, redirect_uris_json, token_endpoint_auth_method, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$clientId, $name, json_encode($uris), 'none', $now, $now]);
        return ['client_id' => $clientId, 'client_name' => $name, 'redirect_uris' => $uris,
            'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code']];
    }

    public function authorizationRequest(array $input)
    {
        $clientId = trim(isset($input['client_id']) ? (string) $input['client_id'] : '');
        $redirectUri = trim(isset($input['redirect_uri']) ? (string) $input['redirect_uri'] : '');
        $resource = trim(isset($input['resource']) ? (string) $input['resource'] : '');
        $challenge = trim(isset($input['code_challenge']) ? (string) $input['code_challenge'] : '');
        if ((isset($input['response_type']) ? $input['response_type'] : '') !== 'code' || (isset($input['code_challenge_method']) ? $input['code_challenge_method'] : '') !== 'S256'
            || $resource !== self::RESOURCE || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
            throw new InvalidArgumentException('invalid_request');
        }
        $client = $this->client($clientId);
        if (!in_array($redirectUri, json_decode($client['redirect_uris_json'], true) ?: [], true)) {
            throw new InvalidArgumentException('invalid_redirect_uri');
        }
        return ['client' => $client, 'client_id' => $clientId, 'redirect_uri' => $redirectUri,
            'resource' => $resource, 'scope' => $this->normalizeScope(isset($input['scope']) ? $input['scope'] : ''),
            'state' => trim((string) (isset($input['state']) ? $input['state'] : '')), 'code_challenge' => $challenge];
    }

    public function manageableChatGptAgents($userId)
    {
        $statement = $this->pdo->prepare("SELECT p.id AS project_id, p.name AS project_name, a.id AS agent_id, pa.display_name
            FROM project_members pm JOIN projects p ON p.id = pm.project_id AND p.status = 'active'
            JOIN project_agents pa ON pa.project_id = p.id AND pa.status = 'active'
            JOIN chat_agents a ON a.id = pa.agent_id AND a.is_active = 1
            WHERE pm.user_id = ? AND pm.status = 'active' AND pm.role IN ('owner','admin')
              AND LOWER(COALESCE(pa.provider, '')) = 'chatgpt'
            ORDER BY p.name, pa.display_name, a.id");
        $statement->execute([(int) $userId]);
        return $statement->fetchAll();
    }

    public function issueAuthorizationCode(array $request, $userId, $projectId, $agentId)
    {
        $allowed = false;
        foreach ($this->manageableChatGptAgents($userId) as $agent) {
            if ((int) $agent['project_id'] === (int) $projectId && (int) $agent['agent_id'] === (int) $agentId) { $allowed = true; break; }
        }
        if (!$allowed) { throw new RuntimeException('access_denied'); }
        $code = AuthService::randomToken(32);
        $this->pdo->prepare('INSERT INTO oauth_authorization_codes (code_hash, client_id, user_id, project_id, agent_id, redirect_uri, resource_uri, scope_text, code_challenge, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $code), $request['client_id'], (int) $userId, (int) $projectId, (int) $agentId,
                $request['redirect_uri'], self::RESOURCE, $request['scope'], $request['code_challenge'], Db::now(), date('Y-m-d H:i:s', time() + 300)]);
        return $code;
    }

    public function exchangeAuthorizationCode(array $input)
    {
        $code = trim((string) (isset($input['code']) ? $input['code'] : ''));
        $verifier = trim((string) (isset($input['code_verifier']) ? $input['code_verifier'] : ''));
        $statement = $this->pdo->prepare('SELECT * FROM oauth_authorization_codes WHERE code_hash = ? AND consumed_at IS NULL AND expires_at > ? LIMIT 1 FOR UPDATE');
        $this->pdo->beginTransaction();
        try {
            $statement->execute([hash('sha256', $code), Db::now()]);
            $row = $statement->fetch();
            if (!$row || !hash_equals($row['client_id'], (string) (isset($input['client_id']) ? $input['client_id'] : ''))
                || !hash_equals($row['redirect_uri'], (string) (isset($input['redirect_uri']) ? $input['redirect_uri'] : ''))
                || !hash_equals($row['resource_uri'], (string) (isset($input['resource']) ? $input['resource'] : ''))
                || !hash_equals($row['code_challenge'], $this->pkceChallenge($verifier))) {
                throw new RuntimeException('invalid_grant');
            }
            $this->pdo->prepare('UPDATE oauth_authorization_codes SET consumed_at = ? WHERE id = ?')->execute([Db::now(), $row['id']]);
            $tokens = $this->issueTokens($row);
            $this->pdo->commit();
            return $tokens;
        } catch (Exception $exception) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $exception; }
    }

    public function refresh(array $input)
    {
        $token = trim((string) (isset($input['refresh_token']) ? $input['refresh_token'] : ''));
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM oauth_refresh_tokens WHERE token_hash = ? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at > ? LIMIT 1 FOR UPDATE');
            $statement->execute([hash('sha256', $token), Db::now()]);
            $row = $statement->fetch();
            if (!$row || !hash_equals($row['client_id'], (string) (isset($input['client_id']) ? $input['client_id'] : ''))
                || !hash_equals($row['resource_uri'], (string) (isset($input['resource']) ? $input['resource'] : ''))) { throw new RuntimeException('invalid_grant'); }
            $this->pdo->prepare('UPDATE oauth_refresh_tokens SET consumed_at = ? WHERE id = ?')->execute([Db::now(), $row['id']]);
            $this->pdo->prepare('UPDATE oauth_access_tokens SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?')->execute([Db::now(), $row['access_token_id']]);
            $tokens = $this->issueTokens($row);
            $this->pdo->commit();
            return $tokens;
        } catch (Exception $exception) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $exception; }
    }

    public function authenticate($token)
    {
        $statement = $this->pdo->prepare("SELECT oat.*, oat.id AS access_token_id, a.id AS authenticated_agent_id, a.project_name,
                a.description, a.role, a.is_active, pa.status AS project_agent_status,
                pp.id AS participant_id, pp.status AS participant_status, p.status AS project_status
            FROM oauth_access_tokens oat JOIN chat_agents a ON a.id = oat.agent_id
            JOIN project_agents pa ON pa.project_id = oat.project_id AND pa.agent_id = oat.agent_id
            JOIN project_participants pp ON pp.project_id = oat.project_id AND pp.agent_id = oat.agent_id AND pp.kind = 'agent'
            JOIN projects p ON p.id = oat.project_id
            WHERE oat.token_hash = ? AND oat.revoked_at IS NULL AND oat.expires_at > ? LIMIT 1");
        $statement->execute([hash('sha256', trim((string) $token)), Db::now()]);
        $row = $statement->fetch();
        if (!$row || !$row['is_active'] || $row['project_agent_status'] !== 'active' || $row['participant_status'] !== 'active' || $row['project_status'] !== 'active'
            || !hash_equals(self::RESOURCE, $row['resource_uri'])) { return null; }
        $this->pdo->prepare('UPDATE oauth_access_tokens SET last_used_at = ? WHERE id = ?')->execute([Db::now(), $row['access_token_id']]);
        return ['project_id' => (int) $row['project_id'], 'participant_id' => (int) $row['participant_id'], 'role' => 'agent',
            'principal_user_id' => (int) $row['user_id'], 'access_token_id' => (int) $row['access_token_id'],
            'project_status' => $row['project_status'], 'scope' => preg_split('/\s+/', trim($row['scope_text'])),
            'identity' => ['kind' => 'agent', 'agent' => array_merge($row, ['id' => (int) $row['authenticated_agent_id']])]];
    }

    public function revoke(array $input)
    {
        $token = trim((string) (isset($input['token']) ? $input['token'] : ''));
        if ($token === '') { return; }
        $hash = hash('sha256', $token);
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT id, access_token_id, client_id FROM oauth_refresh_tokens WHERE token_hash = ? LIMIT 1 FOR UPDATE');
            $statement->execute([$hash]);
            $refresh = $statement->fetch();
            if ($refresh && (isset($input['client_id']) ? $input['client_id'] : $refresh['client_id']) === $refresh['client_id']) {
                $this->pdo->prepare('UPDATE oauth_refresh_tokens SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?')->execute([$now, $refresh['id']]);
                $this->pdo->prepare('UPDATE oauth_access_tokens SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?')->execute([$now, $refresh['access_token_id']]);
            } else {
                $statement = $this->pdo->prepare('SELECT id, client_id FROM oauth_access_tokens WHERE token_hash = ? LIMIT 1 FOR UPDATE');
                $statement->execute([$hash]);
                $access = $statement->fetch();
                if ($access && (isset($input['client_id']) ? $input['client_id'] : $access['client_id']) === $access['client_id']) {
                    $this->pdo->prepare('UPDATE oauth_access_tokens SET revoked_at = COALESCE(revoked_at, ?) WHERE id = ?')->execute([$now, $access['id']]);
                    $this->pdo->prepare('UPDATE oauth_refresh_tokens SET revoked_at = COALESCE(revoked_at, ?) WHERE access_token_id = ?')->execute([$now, $access['id']]);
                }
            }
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function hasScope(array $access, $scope) { return in_array($scope, $access['scope'], true); }

    private function issueTokens(array $row)
    {
        $access = AuthService::randomToken(32); $refresh = AuthService::randomToken(48); $now = Db::now();
        $accessExpiry = date('Y-m-d H:i:s', time() + 3600); $refreshExpiry = date('Y-m-d H:i:s', time() + 2592000);
        $this->pdo->prepare('INSERT INTO oauth_access_tokens (token_hash, client_id, user_id, project_id, agent_id, resource_uri, scope_text, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $access), $row['client_id'], $row['user_id'], $row['project_id'], $row['agent_id'], $row['resource_uri'], $row['scope_text'], $now, $accessExpiry]);
        $accessId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO oauth_refresh_tokens (token_hash, access_token_id, client_id, user_id, project_id, agent_id, resource_uri, scope_text, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $refresh), $accessId, $row['client_id'], $row['user_id'], $row['project_id'], $row['agent_id'], $row['resource_uri'], $row['scope_text'], $now, $refreshExpiry]);
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => $refresh, 'scope' => $row['scope_text']];
    }

    private function client($clientId)
    {
        $statement = $this->pdo->prepare('SELECT * FROM oauth_clients WHERE client_id = ? LIMIT 1'); $statement->execute([$clientId]);
        $client = $statement->fetch(); if (!$client) { throw new InvalidArgumentException('invalid_client'); } return $client;
    }

    private function normalizeScope($scope)
    {
        $requested = preg_split('/\s+/', trim((string) $scope), -1, PREG_SPLIT_NO_EMPTY);
        if (!$requested) { $requested = self::SCOPES; }
        $result = array_values(array_unique(array_intersect($requested, self::SCOPES)));
        if (!$result || count($result) !== count(array_unique($requested))) { throw new InvalidArgumentException('invalid_scope'); }
        return implode(' ', $result);
    }

    private function pkceChallenge($verifier) { return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='); }

    private function validRedirectUri($uri)
    {
        if (!filter_var($uri, FILTER_VALIDATE_URL) || strpos($uri, '#') !== false) { return false; }
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME)); $host = strtolower((string) parse_url($uri, PHP_URL_HOST));
        $chatGpt = $host === 'chatgpt.com' || substr($host, -12) === '.chatgpt.com';
        return ($scheme === 'https' && $chatGpt)
            || ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true));
    }
}
