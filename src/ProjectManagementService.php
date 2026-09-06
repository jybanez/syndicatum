<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/AvatarService.php';
require_once __DIR__ . '/AgentWebhookService.php';

class ProjectManagementService
{
    private $pdo;
    private $auth;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
    }

    public function createProject($userId, array $input)
    {
        $name = trim(isset($input['name']) ? (string) $input['name'] : '');
        if ($name === '' || strlen($name) > 160) { throw new InvalidArgumentException('Project name must contain 1 to 160 characters.'); }
        $workspace = $this->pdo->prepare('SELECT id FROM workspaces WHERE owner_user_id = ?');
        $workspace->execute([(int) $userId]);
        $workspaceId = $workspace->fetchColumn();
        if ($workspaceId === false) { throw new RuntimeException('WORKSPACE_NOT_FOUND'); }
        $slug = $this->uniqueSlug((int) $workspaceId, isset($input['slug']) ? $input['slug'] : $name);
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
            "INSERT INTO projects (workspace_id, owner_user_id, name, slug, description, instructions, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)"
            );
            $insert->execute([(int) $workspaceId, (int) $userId, $name, $slug,
                isset($input['description']) ? trim((string) $input['description']) : null,
                isset($input['instructions']) ? trim((string) $input['instructions']) : null, $now, $now]);
            $projectId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, 'owner', 'active', ?, ?)")
                ->execute([$projectId, (int) $userId, $now, $now]);
            $this->pdo->prepare("INSERT INTO project_participants (project_id, kind, user_id, status, created_at, updated_at) VALUES (?, 'human', ?, 'active', ?, ?)")
                ->execute([$projectId, (int) $userId, $now, $now]);
            $this->pdo->prepare('INSERT INTO project_message_sequences (project_id, next_sequence) VALUES (?, 1)')->execute([$projectId]);
            $this->auth->audit((int) $userId, 'project.created', 'project', (string) $projectId);
            $this->pdo->commit();
            return $this->project($projectId);
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function updateWorkspace($userId, array $input)
    {
        $name = trim(isset($input['name']) ? (string) $input['name'] : '');
        if ($name === '' || strlen($name) > 120) { throw new InvalidArgumentException('Workspace name must contain 1 to 120 characters.'); }
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE workspaces SET name = ?, updated_at = ? WHERE owner_user_id = ?');
            $statement->execute([$name, Db::now(), (int) $userId]);
            if ($statement->rowCount() < 1) { throw new RuntimeException('WORKSPACE_NOT_FOUND'); }
            $this->auth->audit((int) $userId, 'workspace.updated', 'workspace', null);
            $select = $this->pdo->prepare('SELECT id, name FROM workspaces WHERE owner_user_id = ?');
            $select->execute([(int) $userId]);
            $row = $select->fetch(); $row['id'] = (int) $row['id'];
            $this->pdo->commit();
            return $row;
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function updateProject($projectId, $userId, array $input)
    {
        $access = $this->requireProjectAdmin($projectId, $userId);
        $status = isset($input['status']) ? (string) $input['status'] : $access['status'];
        if (!in_array($status, ['active', 'archived'], true)) { throw new InvalidArgumentException('Invalid project status.'); }
        $name = isset($input['name']) ? trim((string) $input['name']) : $access['name'];
        if ($name === '' || strlen($name) > 160) { throw new InvalidArgumentException('Invalid project name.'); }
        $description = array_key_exists('description', $input) ? trim((string) $input['description']) : $access['description'];
        $instructions = array_key_exists('instructions', $input) ? trim((string) $input['instructions']) : $access['instructions'];
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE projects SET name = ?, description = ?, instructions = ?, status = ?, archived_at = ?, updated_at = ? WHERE id = ?');
            $statement->execute([$name, $description, $instructions, $status, $status === 'archived' ? $now : null, $now, (int) $projectId]);
            $this->auth->audit((int) $userId, 'project.updated', 'project', (string) ((int) $projectId), ['status' => $status]);
            $this->pdo->commit();
            return $this->project($projectId);
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function transferProject($projectId, $ownerUserId, $newOwnerUserId)
    {
        if ((int) $ownerUserId === (int) $newOwnerUserId) { throw new InvalidArgumentException('The new owner must be a different user.'); }
        $access = $this->requireProjectAdmin($projectId, $ownerUserId, true);
        $workspace = $this->pdo->prepare("SELECT w.id FROM users u JOIN workspaces w ON w.owner_user_id = u.id WHERE u.id = ? AND u.status = 'active'");
        $workspace->execute([(int) $newOwnerUserId]);
        $newWorkspaceId = $workspace->fetchColumn();
        if ($newWorkspaceId === false) { throw new RuntimeException('NEW_OWNER_NOT_FOUND'); }
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT owner_user_id FROM projects WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $projectId]);
            if ((int) $lock->fetchColumn() !== (int) $ownerUserId) { throw new RuntimeException('PROJECT_NOT_FOUND'); }
            $this->ensureMembershipAndParticipant($projectId, $newOwnerUserId, 'owner');
            $this->pdo->prepare("UPDATE project_members SET role = 'admin', updated_at = ? WHERE project_id = ? AND user_id = ?")
                ->execute([$now, (int) $projectId, (int) $ownerUserId]);
            $slug = $this->uniqueSlug((int) $newWorkspaceId, $access['slug'], (int) $projectId);
            $this->pdo->prepare('UPDATE projects SET workspace_id = ?, owner_user_id = ?, slug = ?, updated_at = ? WHERE id = ?')
                ->execute([(int) $newWorkspaceId, (int) $newOwnerUserId, $slug, $now, (int) $projectId]);
            $this->auth->audit((int) $ownerUserId, 'project.ownership_transferred', 'project', (string) ((int) $projectId), ['new_owner_user_id' => (int) $newOwnerUserId]);
            $this->pdo->commit();
            return $this->project($projectId);
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function invite($projectId, $actorUserId, array $input)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $email = strtolower(trim(isset($input['email']) ? (string) $input['email'] : ''));
        $role = isset($input['role']) ? (string) $input['role'] : 'member';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['admin', 'member', 'viewer'], true)) {
            throw new InvalidArgumentException('A valid email and project role are required.');
        }
        $token = AuthService::randomToken(32);
        $now = Db::now();
        $expires = date('Y-m-d H:i:s', time() + 7 * 86400);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO project_invitations (project_id, invited_email, role, token_hash, invited_by_user_id, status, expires_at, created_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)"
            );
            $statement->execute([(int) $projectId, $email, $role, hash('sha256', $token), (int) $actorUserId, $expires, $now]);
            $id = (int) $this->pdo->lastInsertId();
            $this->auth->audit((int) $actorUserId, 'project.invitation_created', 'project_invitation', (string) $id, ['project_id' => (int) $projectId, 'role' => $role]);
            $this->pdo->commit();
            return ['id' => $id, 'project_id' => (int) $projectId, 'email' => $email, 'role' => $role, 'expires_at' => $expires, 'invitation_token' => $token];
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function acceptInvitation($userId, $token)
    {
        $token = trim((string) $token);
        $statement = $this->pdo->prepare(
            "SELECT i.*, u.normalized_email FROM project_invitations i JOIN users u ON u.id = ?
             WHERE i.token_hash = ? AND i.status = 'pending' AND i.expires_at > ? LIMIT 1"
        );
        $statement->execute([(int) $userId, hash('sha256', $token), Db::now()]);
        $invitation = $statement->fetch();
        if (!$invitation || strtolower((string) $invitation['normalized_email']) !== strtolower($invitation['invited_email'])) {
            throw new RuntimeException('INVITATION_NOT_FOUND');
        }
        $this->pdo->beginTransaction();
        try {
            $this->ensureMembershipAndParticipant((int) $invitation['project_id'], (int) $userId, $invitation['role']);
            $this->pdo->prepare("UPDATE project_invitations SET status = 'accepted', accepted_by_user_id = ?, responded_at = ? WHERE id = ? AND status = 'pending'")
                ->execute([(int) $userId, Db::now(), $invitation['id']]);
            $this->auth->audit((int) $userId, 'project.invitation_accepted', 'project_invitation', (string) $invitation['id']);
            $this->pdo->commit();
            return $this->project((int) $invitation['project_id']);
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function updateMember($projectId, $actorUserId, $memberUserId, $role, $remove = false)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        if ((int) $memberUserId === (int) $this->project($projectId)['owner_user_id']) { throw new RuntimeException('OWNER_MEMBERSHIP_LOCKED'); }
        if (!$remove && !in_array($role, ['admin', 'member', 'viewer'], true)) { throw new InvalidArgumentException('Invalid project role.'); }
        $now = Db::now();
        $status = $remove ? 'removed' : 'active';
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE project_members SET role = ?, status = ?, removed_at = ?, updated_at = ? WHERE project_id = ? AND user_id = ?');
            $statement->execute([$remove ? 'member' : $role, $status, $remove ? $now : null, $now, (int) $projectId, (int) $memberUserId]);
            if ($statement->rowCount() < 1) { throw new RuntimeException('MEMBER_NOT_FOUND'); }
            $this->pdo->prepare('UPDATE project_participants SET status = ?, updated_at = ? WHERE project_id = ? AND user_id = ?')
                ->execute([$status, $now, (int) $projectId, (int) $memberUserId]);
            $this->insertAudit((int) $actorUserId, $remove ? 'project.member_removed' : 'project.member_updated', 'project', (string) ((int) $projectId), ['member_user_id' => (int) $memberUserId, 'role' => $remove ? null : $role]);
            $this->pdo->commit();
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function createAgent($projectId, $actorUserId, array $input)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $displayName = trim(isset($input['display_name']) ? (string) $input['display_name'] : '');
        if ($displayName === '' || strlen($displayName) > 120) { throw new InvalidArgumentException('Agent display name must contain 1 to 120 characters.'); }
        $claimCode = AuthService::randomToken(24);
        $now = Db::now();
        $projectKey = 'project-' . (int) $projectId . '-agent-' . substr(hash('sha256', $displayName . $claimCode), 0, 16);
        $scopes = isset($input['scopes']) && is_array($input['scopes']) ? $input['scopes'] : ['messages:read', 'messages:write', 'messages:acknowledge', 'profile:read', 'profile:write'];
        $scopes = array_values(array_unique(array_intersect($scopes, ['messages:read', 'messages:write', 'messages:acknowledge', 'profile:read', 'profile:write'])));
        if (empty($scopes)) { throw new InvalidArgumentException('At least one valid agent scope is required.'); }
        $claimExpiresAt = date('Y-m-d H:i:s', time() + 900);
        $this->pdo->beginTransaction();
        try {
            $agent = $this->pdo->prepare(
                "INSERT INTO chat_agents (project_name, description, claim_prefix, claim_hash, claim_secret_version, claim_expires_at, role, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'primary', ?, 'agent', 1, ?, ?)"
            );
            $agent->execute([$projectKey, isset($input['description']) ? trim((string) $input['description']) : null,
                substr($claimCode, 0, 24), Db::hashToken($claimCode), $claimExpiresAt, $now, $now]);
            $agentId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                "INSERT INTO project_agents (project_id, agent_id, display_name, avatar_url, provider, runtime_name, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)"
            )->execute([(int) $projectId, $agentId, $displayName,
                $this->avatarUrl(isset($input['avatar_url']) ? $input['avatar_url'] : null),
                isset($input['provider']) ? trim((string) $input['provider']) : null,
                isset($input['runtime_name']) ? trim((string) $input['runtime_name']) : null, $now, $now]);
            $this->pdo->prepare("INSERT INTO project_participants (project_id, kind, agent_id, status, created_at, updated_at) VALUES (?, 'agent', ?, 'active', ?, ?)")
                ->execute([(int) $projectId, $agentId, $now, $now]);
            $scopeInsert = $this->pdo->prepare('INSERT INTO agent_credential_scopes (agent_id, scope, created_at) VALUES (?, ?, ?)');
            foreach ($scopes as $scope) { $scopeInsert->execute([$agentId, $scope, $now]); }
            $webhook = null;
            if (isset($input['webhook_url']) && trim((string) $input['webhook_url']) !== '') {
                $webhookInput = [
                    'endpoint_url' => $input['webhook_url'],
                    'events' => ['message.created'],
                ];
                if (array_key_exists('webhook_enabled', $input)) { $webhookInput['enabled'] = $input['webhook_enabled']; }
                $webhook = (new AgentWebhookService($this->pdo))->configure($projectId, $agentId, $actorUserId, $webhookInput);
            }
            $this->auth->audit((int) $actorUserId, 'project.agent_created', 'agent', (string) $agentId, ['project_id' => (int) $projectId, 'scopes' => $scopes]);
            $this->pdo->commit();
            $result = ['agent_id' => $agentId, 'project_id' => (int) $projectId, 'display_name' => $displayName,
                'avatar_url' => $this->avatarUrl(isset($input['avatar_url']) ? $input['avatar_url'] : null),
                'scopes' => $scopes, 'claim_code' => $claimCode, 'claim_expires_at' => $claimExpiresAt];
            if ($webhook && isset($webhook['signing_secret'])) { $result['webhook_signing_secret'] = $webhook['signing_secret']; }
            return $result;
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function authorizeAgentManagement($projectId, $actorUserId)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
    }

    public function updateAgentAvatar($projectId, $actorUserId, $agentId, $avatarUrl)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $agent = $this->requireProjectAgent($projectId, $agentId);
        $avatarUrl = $this->avatarUrl($avatarUrl);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE project_agents SET avatar_url = ?, updated_at = ? WHERE project_id = ? AND agent_id = ?')
                ->execute([$avatarUrl, Db::now(), (int) $projectId, (int) $agentId]);
            $this->auth->audit((int) $actorUserId, 'project.agent_avatar_updated', 'agent', (string) ((int) $agentId), ['project_id' => (int) $projectId]);
            $this->pdo->commit();
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
        if ($avatarUrl !== $agent['avatar_url']) { (new AvatarService())->deleteIfLocal($agent['avatar_url']); }
        return ['agent_id' => (int) $agentId, 'project_id' => (int) $projectId, 'avatar_url' => $avatarUrl];
    }

    public function updateAgentProfile($projectId, $actorUserId, $agentId, array $input)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $agent = $this->requireProjectAgent($projectId, $agentId);
        $displayName = array_key_exists('display_name', $input) ? trim((string) $input['display_name']) : $agent['project_display_name'];
        if ($displayName === '' || strlen($displayName) > 120) { throw new InvalidArgumentException('Agent display name must contain 1 to 120 characters.'); }
        $provider = array_key_exists('provider', $input) ? trim((string) $input['provider']) : $agent['provider'];
        $runtimeName = array_key_exists('runtime_name', $input) ? trim((string) $input['runtime_name']) : $agent['runtime_name'];
        if (strlen($provider) > 120 || strlen($runtimeName) > 160) { throw new InvalidArgumentException('Agent provider or runtime name is too long.'); }
        $avatarUrl = array_key_exists('avatar_url', $input) ? $this->avatarUrl($input['avatar_url']) : $agent['avatar_url'];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE project_agents SET display_name = ?, provider = ?, runtime_name = ?, avatar_url = ?, updated_at = ? WHERE project_id = ? AND agent_id = ?')
                ->execute([$displayName, $provider === '' ? null : $provider, $runtimeName === '' ? null : $runtimeName,
                    $avatarUrl, Db::now(), (int) $projectId, (int) $agentId]);
            $this->auth->audit((int) $actorUserId, 'project.agent_updated', 'agent', (string) ((int) $agentId), ['project_id' => (int) $projectId]);
            $this->pdo->commit();
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
        if ($avatarUrl !== $agent['avatar_url']) { (new AvatarService())->deleteIfLocal($agent['avatar_url']); }
        return ['project_id' => (int) $projectId, 'agent_id' => (int) $agentId, 'display_name' => $displayName,
            'provider' => $provider === '' ? null : $provider, 'runtime_name' => $runtimeName === '' ? null : $runtimeName,
            'avatar_url' => $avatarUrl, 'status' => $agent['status']];
    }

    public function issueAgentClaim($projectId, $actorUserId, $agentId)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $this->requireProjectAgent($projectId, $agentId);
        $code = AuthService::randomToken(24);
        $expiresAt = date('Y-m-d H:i:s', time() + 900);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "UPDATE chat_agents SET claim_prefix = ?, claim_hash = ?, claim_secret_version = 'primary', claim_expires_at = ?, claimed_at = NULL, updated_at = ? WHERE id = ?"
            );
            $statement->execute([substr($code, 0, 24), Db::hashToken($code), $expiresAt, Db::now(), (int) $agentId]);
            $this->auth->audit((int) $actorUserId, 'agent.credential_rotation_started', 'agent', (string) ((int) $agentId));
            $this->pdo->commit();
            return ['agent_id' => (int) $agentId, 'claim_code' => $code, 'claim_expires_at' => $expiresAt];
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function updateAgentStatus($projectId, $actorUserId, $agentId, $status, $revokeToken = false)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $agent = $this->requireProjectAgent($projectId, $agentId);
        if ($status === null) { $status = $agent['status']; }
        if (!in_array($status, ['active', 'suspended', 'retired'], true)) { throw new InvalidArgumentException('Invalid agent status.'); }
        $participantStatus = $status === 'active' ? 'active' : 'suspended';
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE project_agents SET status = ?, updated_at = ? WHERE project_id = ? AND agent_id = ?')
                ->execute([$status, $now, (int) $projectId, (int) $agentId]);
            $this->pdo->prepare('UPDATE project_participants SET status = ?, updated_at = ? WHERE project_id = ? AND agent_id = ?')
                ->execute([$participantStatus, $now, (int) $projectId, (int) $agentId]);
            $sql = 'UPDATE chat_agents SET is_active = ?, updated_at = ?';
            $params = [$status === 'active' ? 1 : 0, $now];
            if ($revokeToken) { $sql .= ', token_prefix = NULL, token_hash = NULL, token_secret_version = NULL'; }
            $sql .= ' WHERE id = ?'; $params[] = (int) $agentId;
            $this->pdo->prepare($sql)->execute($params);
            $this->auth->audit((int) $actorUserId, 'project.agent_status_changed', 'agent', (string) ((int) $agentId), ['project_id' => (int) $projectId, 'status' => $status, 'token_revoked' => (bool) $revokeToken]);
            $this->pdo->commit();
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    public function claimAgent($projectId, $agentId, $claimCode)
    {
        $token = AuthService::randomToken(32);
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT a.*, pa.status FROM project_agents pa JOIN chat_agents a ON a.id = pa.agent_id
                 WHERE pa.project_id = ? AND pa.agent_id = ? FOR UPDATE'
            );
            $statement->execute([(int) $projectId, (int) $agentId]);
            $agent = $statement->fetch();
            if (!$agent || $agent['status'] !== 'active' || empty($agent['claim_hash'])
                || (!empty($agent['claim_expires_at']) && strtotime($agent['claim_expires_at']) <= time())
                || !hash_equals($agent['claim_hash'], Db::hashToken((string) $claimCode))) {
                throw new RuntimeException('INVALID_CLAIM');
            }
            $update = $this->pdo->prepare(
                "UPDATE chat_agents SET token_prefix = ?, token_hash = ?, token_secret_version = 'primary', claim_prefix = NULL,
                        claim_hash = NULL, claim_secret_version = NULL, claim_expires_at = NULL, claimed_at = ?, updated_at = ? WHERE id = ?"
            );
            $update->execute([substr($token, 0, 24), Db::hashToken($token), $now, $now, (int) $agentId]);
            $this->insertAudit(null, 'agent.credential_claimed', 'agent', (string) ((int) $agentId), ['project_id' => (int) $projectId]);
            $this->pdo->commit();
            return ['agent_id' => (int) $agentId, 'project_id' => (int) $projectId, 'token' => $token];
        } catch (Exception $exception) { $this->rollback(); throw $exception; }
    }

    private function ensureMembershipAndParticipant($projectId, $userId, $role)
    {
        $now = Db::now();
        $this->pdo->prepare(
            "INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at) VALUES (?, ?, ?, 'active', ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), status = 'active', removed_at = NULL, updated_at = VALUES(updated_at)"
        )->execute([(int) $projectId, (int) $userId, $role, $now, $now]);
        $this->pdo->prepare(
            "INSERT INTO project_participants (project_id, kind, user_id, status, created_at, updated_at) VALUES (?, 'human', ?, 'active', ?, ?)
             ON DUPLICATE KEY UPDATE status = 'active', updated_at = VALUES(updated_at)"
        )->execute([(int) $projectId, (int) $userId, $now, $now]);
    }

    private function requireProjectAdmin($projectId, $userId, $ownerOnly = false)
    {
        $statement = $this->pdo->prepare(
            "SELECT p.*, pm.role FROM projects p JOIN project_members pm ON pm.project_id = p.id
             WHERE p.id = ? AND pm.user_id = ? AND pm.status = 'active' LIMIT 1"
        );
        $statement->execute([(int) $projectId, (int) $userId]);
        $row = $statement->fetch();
        $allowed = $row && ($ownerOnly ? $row['role'] === 'owner' : in_array($row['role'], ['owner', 'admin'], true));
        if (!$allowed) { throw new RuntimeException('PROJECT_NOT_FOUND'); }
        return $row;
    }

    private function requireProjectAgent($projectId, $agentId)
    {
        $statement = $this->pdo->prepare(
            'SELECT a.*, pa.status, pa.display_name AS project_display_name, pa.avatar_url, pa.provider, pa.runtime_name
             FROM project_agents pa JOIN chat_agents a ON a.id = pa.agent_id WHERE pa.project_id = ? AND pa.agent_id = ? LIMIT 1'
        );
        $statement->execute([(int) $projectId, (int) $agentId]);
        $row = $statement->fetch();
        if (!$row) { throw new RuntimeException('AGENT_NOT_FOUND'); }
        return $row;
    }

    private function project($projectId)
    {
        $statement = $this->pdo->prepare('SELECT * FROM projects WHERE id = ?');
        $statement->execute([(int) $projectId]);
        $row = $statement->fetch();
        if (!$row) { throw new RuntimeException('PROJECT_NOT_FOUND'); }
        $row['id'] = (int) $row['id']; $row['workspace_id'] = (int) $row['workspace_id']; $row['owner_user_id'] = (int) $row['owner_user_id'];
        return $row;
    }

    private function uniqueSlug($workspaceId, $value, $excludeProjectId = null)
    {
        $base = substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string) $value))), '-'), 0, 140);
        if ($base === '') { $base = 'project'; }
        for ($suffix = 0; $suffix < 1000; $suffix++) {
            $slug = $suffix === 0 ? $base : substr($base, 0, 150 - strlen((string) $suffix)) . '-' . $suffix;
            $sql = 'SELECT COUNT(*) FROM projects WHERE workspace_id = ? AND slug = ?'; $params = [(int) $workspaceId, $slug];
            if ($excludeProjectId !== null) { $sql .= ' AND id <> ?'; $params[] = (int) $excludeProjectId; }
            $statement = $this->pdo->prepare($sql); $statement->execute($params);
            if ((int) $statement->fetchColumn() === 0) { return $slug; }
        }
        throw new RuntimeException('Unable to allocate a unique project slug.');
    }

    private function rollback() { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }

    private function insertAudit($actorUserId, $action, $subjectType, $subjectId, array $metadata)
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO administrative_audit_events (actor_user_id, action, subject_type, subject_id, metadata_json, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$actorUserId === null ? null : (int) $actorUserId, $action, $subjectType, $subjectId, json_encode($metadata),
            isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 80) : null, Db::now()]);
    }

    private function avatarUrl($value)
    {
        $value = trim((string) $value);
        if ($value === '') { return null; }
        if (preg_match('/^api\/v1\/avatar\.php\?file=[a-f0-9]{40}\.(jpg|png|webp)$/', $value)) { return $value; }
        throw new InvalidArgumentException('Avatar URL must reference an uploaded Syndicatum image.');
    }
}
