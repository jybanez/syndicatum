<?php

require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ChatGptOAuthService.php';

class McpServiceTokenService
{
    private $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function issue($projectId, $agentId, $createdByUserId)
    {
        $token = 'syndicatum_mcp_' . AuthService::randomToken(40);
        $this->pdo->prepare(
            'INSERT INTO mcp_service_tokens (token_hash, project_id, agent_id, created_by_user_id, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([hash('sha256', $token), (int) $projectId, (int) $agentId,
            $createdByUserId ? (int) $createdByUserId : null, Db::now()]);
        return $token;
    }

    public function authenticate($token)
    {
        if (!Db::tableExists($this->pdo, 'mcp_service_tokens')) { return null; }
        $statement = $this->pdo->prepare(
            "SELECT mst.id AS service_token_id, mst.project_id, a.id AS authenticated_agent_id,
                    a.project_name, a.description, a.role, a.is_active,
                    pa.status AS project_agent_status, pp.id AS participant_id,
                    pp.status AS participant_status, p.status AS project_status
             FROM mcp_service_tokens mst
             JOIN chat_agents a ON a.id = mst.agent_id
             JOIN project_agents pa ON pa.project_id = mst.project_id AND pa.agent_id = mst.agent_id
             JOIN project_participants pp ON pp.project_id = mst.project_id AND pp.agent_id = mst.agent_id AND pp.kind = 'agent'
             JOIN projects p ON p.id = mst.project_id
             WHERE mst.token_hash = ? AND mst.revoked_at IS NULL LIMIT 1"
        );
        $statement->execute([hash('sha256', trim((string) $token))]);
        $row = $statement->fetch();
        if (!$row || !$row['is_active'] || $row['project_agent_status'] !== 'active'
            || $row['participant_status'] !== 'active' || $row['project_status'] !== 'active') { return null; }
        $this->pdo->prepare('UPDATE mcp_service_tokens SET last_used_at = ? WHERE id = ?')
            ->execute([Db::now(), $row['service_token_id']]);
        return ['project_id' => (int) $row['project_id'], 'participant_id' => (int) $row['participant_id'],
            'role' => 'agent', 'project_status' => $row['project_status'], 'scope' => ChatGptOAuthService::SCOPES,
            'identity' => ['kind' => 'agent', 'agent' => $row]];
    }
}
