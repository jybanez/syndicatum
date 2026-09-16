<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/ProjectManagementService.php';
require_once __DIR__ . '/AgentActivationService.php';
require_once __DIR__ . '/DiscussionProviderRegistry.php';

class DiscussionBindingIntentService
{
    private $pdo;
    private $auth;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
    }

    public function prepare(array $oauthAccess, $projectName, $agentName)
    {
        $userId = (int) ($oauthAccess['principal_user_id'] ?? 0);
        $accessTokenId = (int) ($oauthAccess['access_token_id'] ?? 0);
        $projectName = trim((string) $projectName);
        $agentName = trim((string) $agentName);
        if ($userId < 1 || $accessTokenId < 1) { throw new RuntimeException('BINDING_REQUIRES_OAUTH'); }
        if ($projectName === '' || $agentName === '' || strlen($agentName) > 120) {
            throw new InvalidArgumentException('project_name and agent_name are required; agent_name may contain at most 120 characters.');
        }

        $project = $this->pdo->prepare("SELECT p.id, p.name FROM projects p JOIN project_members pm ON pm.project_id = p.id
            WHERE p.status = 'active' AND pm.user_id = ? AND pm.status = 'active' AND pm.role IN ('owner','admin')
              AND LOWER(p.name) = LOWER(?)");
        $project->execute([$userId, $projectName]);
        $projects = $project->fetchAll();
        if (count($projects) !== 1) { throw new RuntimeException(count($projects) ? 'PROJECT_NAME_AMBIGUOUS' : 'PROJECT_NOT_FOUND'); }
        $project = $projects[0];

        $agent = $this->pdo->prepare("SELECT pa.agent_id, pa.display_name FROM project_agents pa
            WHERE pa.project_id = ? AND pa.status = 'active' AND LOWER(pa.display_name) = LOWER(?)");
        $agent->execute([(int) $project['id'], $agentName]);
        $agents = $agent->fetchAll();
        if (count($agents) > 1) { throw new RuntimeException('AGENT_NAME_AMBIGUOUS'); }
        if ($agents && strtolower((string) $this->agentProvider((int) $project['id'], (int) $agents[0]['agent_id'])) !== 'chatgpt') {
            throw new RuntimeException('AGENT_PROVIDER_MISMATCH');
        }

        $intentId = $this->uuid();
        $contextToken = 'syndicatum_context_' . AuthService::randomToken(32);
        $now = Db::now();
        $expires = gmdate('Y-m-d H:i:s', time() + 900);
        $this->pdo->prepare("INSERT INTO connector_discussion_binding_intents
            (id, context_token_hash, oauth_access_token_id, created_by_user_id, project_id, requested_agent_id,
             requested_agent_name, provider, status, created_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'chatgpt', 'pending', ?, ?)")
            ->execute([$intentId, hash('sha256', $contextToken), $accessTokenId, $userId, (int) $project['id'],
                $agents ? (int) $agents[0]['agent_id'] : null, $agentName, $now, $expires]);
        $this->auth->audit($userId, 'connector.discussion_binding_requested', 'project', (string) $project['id'], [
            'intent_id' => $intentId, 'agent_name' => $agentName, 'agent_will_be_created' => !$agents,
        ]);
        return [
            'binding_context_id' => $contextToken,
            'intent_id' => $intentId,
            'status' => 'awaiting_confirmation',
            'project' => ['id' => (int) $project['id'], 'name' => $project['name']],
            'agent' => ['id' => $agents ? (int) $agents[0]['agent_id'] : null, 'name' => $agentName,
                'action' => $agents ? 'use_existing' : 'create_on_confirmation'],
            'expires_at' => gmdate('c', strtotime($expires)),
            'next_step' => 'The Syndicatum Companion will show Continue and Cancel in the active ChatGPT discussion. Retain binding_context_id and use it for subsequent Syndicatum tools in this discussion.',
        ];
    }

    public function prepareInteractiveContext(array $oauthAccess, $projectName, $agentName)
    {
        $userId = (int) ($oauthAccess['principal_user_id'] ?? 0);
        $accessTokenId = (int) ($oauthAccess['access_token_id'] ?? 0);
        $projectName = trim((string) $projectName);
        $agentName = trim((string) $agentName);
        if ($userId < 1 || $accessTokenId < 1) { throw new RuntimeException('BINDING_REQUIRES_OAUTH'); }
        if ($projectName === '' || $agentName === '' || strlen($agentName) > 120) {
            throw new InvalidArgumentException('project_name and agent_name are required; agent_name may contain at most 120 characters.');
        }

        $statement = $this->pdo->prepare("SELECT p.id AS project_id, p.name AS project_name, pa.agent_id, pa.display_name
            FROM projects p
            JOIN project_members pm ON pm.project_id = p.id
            JOIN project_agents pa ON pa.project_id = p.id
            WHERE p.status = 'active' AND pm.user_id = ? AND pm.status = 'active' AND pm.role IN ('owner','admin')
              AND pa.status = 'active' AND pa.provider = 'chatgpt'
              AND LOWER(p.name) = LOWER(?) AND LOWER(pa.display_name) = LOWER(?)");
        $statement->execute([$userId, $projectName, $agentName]);
        $matches = $statement->fetchAll();
        if (!$matches) { throw new RuntimeException('INTERACTIVE_CONTEXT_NOT_FOUND'); }
        if (count($matches) !== 1) { throw new RuntimeException('INTERACTIVE_CONTEXT_AMBIGUOUS'); }
        $match = $matches[0];

        $intentId = $this->uuid();
        $contextToken = 'syndicatum_context_' . AuthService::randomToken(32);
        $now = Db::now();
        $expires = gmdate('Y-m-d H:i:s', time() + 900);
        $this->pdo->prepare("INSERT INTO connector_discussion_binding_intents
            (id, context_token_hash, oauth_access_token_id, created_by_user_id, project_id, requested_agent_id,
             requested_agent_name, provider, status, confirmed_agent_id, created_at, expires_at, resolved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'chatgpt', 'confirmed', ?, ?, ?, ?)")
            ->execute([$intentId, hash('sha256', $contextToken), $accessTokenId, $userId,
                (int) $match['project_id'], (int) $match['agent_id'], $match['display_name'],
                (int) $match['agent_id'], $now, $expires, $now]);
        $this->auth->audit($userId, 'mcp.interactive_context_issued', 'agent', (string) $match['agent_id'], [
            'project_id' => (int) $match['project_id'], 'intent_id' => $intentId, 'expires_at' => $expires,
        ]);
        return [
            'binding_context_id' => $contextToken,
            'context_type' => 'interactive',
            'status' => 'active',
            'project' => ['id' => (int) $match['project_id'], 'name' => $match['project_name']],
            'agent' => ['id' => (int) $match['agent_id'], 'name' => $match['display_name']],
            'expires_at' => gmdate('c', strtotime($expires)),
            'next_step' => 'Retain binding_context_id for this interaction. This short-lived context does not create or modify a Companion discussion binding.',
        ];
    }

    public function pending(array $device)
    {
        $statement = $this->pdo->prepare("SELECT i.id, i.project_id, p.name AS project_name, i.requested_agent_id,
                i.requested_agent_name, i.provider, i.created_at, i.expires_at
            FROM connector_discussion_binding_intents i JOIN projects p ON p.id = i.project_id
            WHERE i.created_by_user_id = ? AND i.status = 'pending' AND i.expires_at > ?
            ORDER BY i.created_at, i.id LIMIT 20");
        $statement->execute([(int) $device['user_id'], Db::now()]);
        return array_map(function ($row) {
            return [
                'intent_id' => $row['id'], 'provider' => $row['provider'],
                'project_id' => (int) $row['project_id'], 'project_name' => $row['project_name'],
                'agent_id' => $row['requested_agent_id'] === null ? null : (int) $row['requested_agent_id'],
                'agent_name' => $row['requested_agent_name'],
                'agent_action' => $row['requested_agent_id'] === null ? 'create' : 'use_existing',
                'created_at' => gmdate('c', strtotime($row['created_at'])),
                'expires_at' => gmdate('c', strtotime($row['expires_at'])),
            ];
        }, $statement->fetchAll());
    }

    public function cancel(array $device, $intentId)
    {
        $statement = $this->pdo->prepare("UPDATE connector_discussion_binding_intents SET status = 'cancelled',
            resolved_by_device_id = ?, resolved_at = ? WHERE id = ? AND created_by_user_id = ?
            AND status = 'pending' AND expires_at > ?");
        $statement->execute([$device['id'], Db::now(), trim((string) $intentId), (int) $device['user_id'], Db::now()]);
        if ($statement->rowCount() !== 1) { throw new RuntimeException('INVALID_DISCUSSION_BINDING_INTENT'); }
        $this->auth->audit((int) $device['user_id'], 'connector.discussion_binding_cancelled', 'binding_intent', (string) $intentId);
        return ['status' => 'cancelled', 'intent_id' => (string) $intentId];
    }

    public function confirm(array $device, $intentId, $discussionReference)
    {
        $normalized = (new DiscussionProviderRegistry())->normalize('chatgpt', trim((string) $discussionReference));
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare("SELECT * FROM connector_discussion_binding_intents
                WHERE id = ? AND created_by_user_id = ? FOR UPDATE");
            $statement->execute([trim((string) $intentId), (int) $device['user_id']]);
            $intent = $statement->fetch();
            if (!$intent || $intent['status'] !== 'pending' || strtotime($intent['expires_at']) <= time()) {
                throw new RuntimeException('INVALID_DISCUSSION_BINDING_INTENT');
            }
            $conflict = $this->pdo->prepare("SELECT COUNT(*) FROM agent_activation_bindings
                WHERE runtime_type = 'chatgpt' AND activation_driver = 'browser_companion' AND conversation_id = ?
                  AND enabled = 1 AND created_by_user_id = ? AND (? IS NULL OR agent_id <> ?)");
            $conflict->execute([$normalized['discussion_id'], (int) $device['user_id'], $intent['requested_agent_id'], $intent['requested_agent_id']]);
            if ((int) $conflict->fetchColumn() > 0) { throw new RuntimeException('DISCUSSION_ALREADY_BOUND'); }

            $agentId = $intent['requested_agent_id'] === null ? null : (int) $intent['requested_agent_id'];
            if ($agentId === null) {
                $created = (new ProjectManagementService($this->pdo))->createAgent((int) $intent['project_id'], (int) $device['user_id'], [
                    'display_name' => $intent['requested_agent_name'], 'provider' => 'chatgpt',
                ]);
                $agentId = (int) $created['agent_id'];
                $this->pdo->prepare("UPDATE chat_agents SET claim_prefix = NULL, claim_hash = NULL,
                    claim_secret_version = NULL, claim_expires_at = NULL WHERE id = ?")->execute([$agentId]);
            }
            $configured = (new AgentActivationService($this->pdo))->configure((int) $intent['project_id'], $agentId,
                (int) $device['user_id'], ['provider' => 'chatgpt', 'activation_driver' => 'browser_companion',
                    'enabled' => true, 'discussion_reference' => $normalized['canonical_reference'], 'working_directory' => '']);
            $this->pdo->prepare("UPDATE connector_discussion_binding_intents SET status = 'confirmed', confirmed_agent_id = ?,
                discussion_id = ?, discussion_reference = ?, resolved_by_device_id = ?, resolved_at = ? WHERE id = ?")
                ->execute([$agentId, $normalized['discussion_id'], $normalized['canonical_reference'], $device['id'], Db::now(), $intent['id']]);
            $this->auth->audit((int) $device['user_id'], 'connector.discussion_binding_confirmed', 'agent', (string) $agentId, [
                'project_id' => (int) $intent['project_id'], 'intent_id' => $intent['id'], 'device_id' => $device['id'],
            ]);
            $this->pdo->commit();
            return $configured + ['intent_id' => $intent['id'], 'discussion_binding' => 'Successful', 'device_id' => $device['id']];
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function context(array $oauthAccess, $contextToken)
    {
        $token = trim((string) $contextToken);
        if ($token === '') { return null; }
        $statement = $this->pdo->prepare("SELECT i.*, p.name AS project_name, p.status AS project_status,
                pa.display_name AS agent_name, pa.status AS project_agent_status, a.is_active,
                pp.id AS participant_id, pp.status AS participant_status
            FROM connector_discussion_binding_intents i
            JOIN projects p ON p.id = i.project_id
            JOIN project_agents pa ON pa.project_id = i.project_id AND pa.agent_id = i.confirmed_agent_id
            JOIN chat_agents a ON a.id = i.confirmed_agent_id
            JOIN project_participants pp ON pp.project_id = i.project_id AND pp.agent_id = i.confirmed_agent_id AND pp.kind = 'agent'
            WHERE i.context_token_hash = ? AND i.created_by_user_id = ?
              AND i.status = 'confirmed'
              AND (i.discussion_reference IS NOT NULL OR i.expires_at > ?) LIMIT 1");
        $statement->execute([hash('sha256', $token), (int) ($oauthAccess['principal_user_id'] ?? 0), Db::now()]);
        $row = $statement->fetch();
        if (!$row) { return null; }
        return ['project_id' => (int) $row['project_id'], 'participant_id' => (int) $row['participant_id'],
            'role' => 'agent', 'project_status' => $row['project_status'], 'scope' => $oauthAccess['scope'],
            'identity' => ['kind' => 'agent', 'agent' => ['id' => (int) $row['confirmed_agent_id'],
                'authenticated_agent_id' => (int) $row['confirmed_agent_id'],
                'display_name' => $row['agent_name'], 'is_active' => $row['is_active'],
                'project_agent_status' => $row['project_agent_status'], 'participant_status' => $row['participant_status']]],
            'binding' => ['status' => 'Successful', 'type' => $row['discussion_reference'] === null ? 'interactive' : 'discussion',
                'intent_id' => $row['id'], 'discussion_reference' => $row['discussion_reference']],
        ];
    }

    private function agentProvider($projectId, $agentId)
    {
        $statement = $this->pdo->prepare('SELECT provider FROM project_agents WHERE project_id = ? AND agent_id = ?');
        $statement->execute([$projectId, $agentId]);
        return $statement->fetchColumn();
    }

    private function uuid()
    {
        $data = random_bytes(16); $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
