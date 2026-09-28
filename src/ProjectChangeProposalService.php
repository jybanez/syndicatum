<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/ProjectManagementService.php';

class ProjectChangeProposalService
{
    private $pdo;
    private $auth;
    private $management;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
        $this->management = new ProjectManagementService($pdo);
    }

    public function proposeProjectDetails(array $access, array $input)
    {
        $this->assertNoSensitiveFields($input);
        $payload = $this->copyFields($input, ['name', 'description', 'instructions']);
        if (!$payload) { throw new InvalidArgumentException('At least one project detail must be proposed.'); }
        if (isset($payload['name'])) {
            $payload['name'] = trim((string) $payload['name']);
            if ($payload['name'] === '' || strlen($payload['name']) > 160) {
                throw new InvalidArgumentException('Project name must contain 1 to 160 characters.');
            }
        }
        foreach (['description' => 10000, 'instructions' => 50000] as $field => $limit) {
            if (isset($payload[$field])) {
                $payload[$field] = trim((string) $payload[$field]);
                if (strlen($payload[$field]) > $limit) { throw new InvalidArgumentException($field . ' is too long.'); }
            }
        }
        return $this->create($access, 'project_details', null, $payload, $input['rationale'] ?? '');
    }

    public function proposeAgentSetup(array $access, array $input)
    {
        $this->assertNoSensitiveFields($input);
        $payload = $this->agentPayload($input, true);
        return $this->create($access, 'agent_setup', null, $payload, $input['rationale'] ?? '');
    }

    public function proposeAgentProfileUpdate(array $access, array $input)
    {
        $this->assertNoSensitiveFields($input);
        $agentId = (int) ($input['target_agent_id'] ?? 0);
        if ($agentId < 1) { throw new InvalidArgumentException('target_agent_id must be a positive integer.'); }
        $payload = $this->agentPayload($input, false);
        if (!$payload) { throw new InvalidArgumentException('At least one agent profile field must be proposed.'); }
        $this->requireProjectAgent((int) $access['project_id'], $agentId);
        return $this->create($access, 'agent_profile_update', $agentId, $payload, $input['rationale'] ?? '');
    }

    public function listForReview($projectId, $userId, $status = '')
    {
        $this->management->authorizeAgentManagement($projectId, $userId);
        $status = trim((string) $status);
        if ($status !== '' && !in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid proposal status.');
        }
        $sql = "SELECT pcp.*, pp.kind AS proposer_kind,
                    COALESCE(u.display_name, pa.display_name, 'Project participant') AS proposer_name,
                    target.display_name AS target_agent_name,
                    reviewer.display_name AS reviewer_name
                FROM project_change_proposals pcp
                JOIN project_participants pp ON pp.id = pcp.proposed_by_participant_id AND pp.project_id = pcp.project_id
                LEFT JOIN users u ON u.id = pp.user_id
                LEFT JOIN project_agents pa ON pa.project_id = pcp.project_id AND pa.agent_id = pp.agent_id
                LEFT JOIN project_agents target ON target.project_id = pcp.project_id AND target.agent_id = pcp.target_agent_id
                LEFT JOIN users reviewer ON reviewer.id = pcp.reviewed_by_user_id
                WHERE pcp.project_id = ?";
        $params = [(int) $projectId];
        if ($status !== '') { $sql .= ' AND pcp.status = ?'; $params[] = $status; }
        $sql .= " ORDER BY (pcp.status = 'pending') DESC, pcp.created_at DESC, pcp.id DESC LIMIT 200";
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map([$this, 'present'], $statement->fetchAll());
    }

    public function review($projectId, $userId, $proposalId, $version, $decision, $note = '')
    {
        $this->management->authorizeAgentManagement($projectId, $userId);
        $proposalId = (int) $proposalId;
        $version = (int) $version;
        $decision = trim((string) $decision);
        $note = trim((string) $note);
        if ($proposalId < 1 || $version < 1) { throw new InvalidArgumentException('Proposal ID and version are required.'); }
        if (!in_array($decision, ['approve', 'reject'], true)) { throw new InvalidArgumentException('Decision must be approve or reject.'); }
        if (strlen($note) > 4000) { throw new InvalidArgumentException('Review note must not exceed 4,000 characters.'); }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('SELECT * FROM project_change_proposals WHERE id = ? AND project_id = ? FOR UPDATE');
            $statement->execute([$proposalId, (int) $projectId]);
            $proposal = $statement->fetch();
            if (!$proposal) { throw new RuntimeException('PROPOSAL_NOT_FOUND'); }
            if ($proposal['status'] !== 'pending' || (int) $proposal['version'] !== $version) {
                throw new RuntimeException('PROPOSAL_VERSION_CONFLICT');
            }

            $appliedAgentId = null;
            if ($decision === 'approve') {
                $payload = json_decode((string) $proposal['payload_json'], true);
                if (!is_array($payload)) { throw new RuntimeException('PROPOSAL_PAYLOAD_INVALID'); }
                if ($proposal['proposal_type'] === 'project_details') {
                    $this->management->updateProject($projectId, $userId, $payload);
                } elseif ($proposal['proposal_type'] === 'agent_setup') {
                    $created = $this->management->createAgent($projectId, $userId, $payload);
                    $appliedAgentId = (int) $created['agent_id'];
                } elseif ($proposal['proposal_type'] === 'agent_profile_update') {
                    $this->management->updateAgentProfile($projectId, $userId, (int) $proposal['target_agent_id'], $payload);
                    $appliedAgentId = (int) $proposal['target_agent_id'];
                } else {
                    throw new RuntimeException('PROPOSAL_PAYLOAD_INVALID');
                }
            }

            $status = $decision === 'approve' ? 'approved' : 'rejected';
            $now = Db::now();
            $update = $this->pdo->prepare('UPDATE project_change_proposals
                SET status = ?, version = version + 1, reviewed_by_user_id = ?, review_note = ?, applied_agent_id = ?, reviewed_at = ?, updated_at = ?
                WHERE id = ? AND project_id = ? AND status = \'pending\' AND version = ?');
            $update->execute([$status, (int) $userId, $note === '' ? null : $note, $appliedAgentId, $now, $now,
                $proposalId, (int) $projectId, $version]);
            if ($update->rowCount() !== 1) { throw new RuntimeException('PROPOSAL_VERSION_CONFLICT'); }
            $this->auth->audit((int) $userId, 'project.change_proposal_' . $status, 'project_change_proposal', (string) $proposalId,
                ['project_id' => (int) $projectId, 'proposal_type' => $proposal['proposal_type'], 'applied_agent_id' => $appliedAgentId]);
            $this->pdo->commit();
            return $this->proposal($proposalId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    private function create(array $access, $type, $targetAgentId, array $payload, $rationale)
    {
        $projectId = (int) ($access['project_id'] ?? 0);
        $participantId = (int) ($access['participant_id'] ?? 0);
        if (($access['identity']['kind'] ?? '') !== 'agent' || $projectId < 1 || $participantId < 1) {
            throw new RuntimeException('PROPOSAL_AGENT_REQUIRED');
        }
        $rationale = trim((string) $rationale);
        if (strlen($rationale) > 4000) { throw new InvalidArgumentException('Rationale must not exceed 4,000 characters.'); }
        $participant = $this->pdo->prepare("SELECT id FROM project_participants WHERE id = ? AND project_id = ? AND kind = 'agent' AND status = 'active'");
        $participant->execute([$participantId, $projectId]);
        if (!$participant->fetchColumn()) { throw new RuntimeException('PROPOSAL_AGENT_REQUIRED'); }
        $project = $this->pdo->prepare("SELECT status FROM projects WHERE id = ?");
        $project->execute([$projectId]);
        if ($project->fetchColumn() !== 'active') { throw new RuntimeException('PROJECT_ARCHIVED'); }
        $now = Db::now();
        $statement = $this->pdo->prepare("INSERT INTO project_change_proposals
            (public_id, project_id, proposal_type, target_agent_id, proposed_by_participant_id, payload_json, rationale, status, version, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?)");
        $statement->execute([Db::uuidV4(), $projectId, $type, $targetAgentId, $participantId,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $rationale === '' ? null : $rationale, $now, $now]);
        $id = (int) $this->pdo->lastInsertId();
        $this->auth->audit(null, 'project.change_proposal_created', 'project_change_proposal', (string) $id,
            ['project_id' => $projectId, 'participant_id' => $participantId, 'proposal_type' => $type]);
        return $this->proposal($id);
    }

    private function agentPayload(array $input, $requireName)
    {
        $fields = ['display_name', 'provider', 'runtime_name', 'role_title', 'role_summary', 'role_instructions', 'supervising_participant_id'];
        $payload = $this->copyFields($input, $fields);
        if ($requireName && !array_key_exists('display_name', $payload)) { throw new InvalidArgumentException('Agent display name is required.'); }
        if (isset($payload['display_name'])) {
            $payload['display_name'] = trim((string) $payload['display_name']);
            if ($payload['display_name'] === '' || strlen($payload['display_name']) > 120) { throw new InvalidArgumentException('Agent display name must contain 1 to 120 characters.'); }
        }
        foreach (['provider' => 120, 'runtime_name' => 160, 'role_title' => 120, 'role_summary' => 4000, 'role_instructions' => 20000] as $field => $limit) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = trim((string) $payload[$field]);
                if (strlen($payload[$field]) > $limit) { throw new InvalidArgumentException($field . ' is too long.'); }
            }
        }
        if (array_key_exists('supervising_participant_id', $payload)) {
            $value = $payload['supervising_participant_id'];
            $payload['supervising_participant_id'] = ($value === null || $value === '') ? null : (int) $value;
            if ($payload['supervising_participant_id'] !== null && $payload['supervising_participant_id'] < 1) {
                throw new InvalidArgumentException('supervising_participant_id must be a positive integer or null.');
            }
        }
        return $payload;
    }

    private function copyFields(array $input, array $allowed)
    {
        $payload = [];
        foreach ($allowed as $field) { if (array_key_exists($field, $input)) { $payload[$field] = $input[$field]; } }
        return $payload;
    }

    private function assertNoSensitiveFields(array $input)
    {
        $forbidden = ['api_key', 'token', 'secret', 'scopes', 'webhook_url', 'webhook_enabled', 'claim_code',
            'claim_expires_at', 'working_directory', 'activation', 'activation_mode', 'discussion_reference'];
        foreach ($forbidden as $field) {
            if (array_key_exists($field, $input)) {
                throw new InvalidArgumentException('Credentials, activation, scopes, webhooks, and runtime paths cannot be included in proposals.');
            }
        }
    }

    private function requireProjectAgent($projectId, $agentId)
    {
        $statement = $this->pdo->prepare("SELECT agent_id FROM project_agents WHERE project_id = ? AND agent_id = ? AND status = 'active'");
        $statement->execute([$projectId, $agentId]);
        if (!$statement->fetchColumn()) { throw new RuntimeException('AGENT_NOT_FOUND'); }
    }

    private function proposal($id)
    {
        $statement = $this->pdo->prepare("SELECT pcp.*, pp.kind AS proposer_kind,
                COALESCE(u.display_name, pa.display_name, 'Project participant') AS proposer_name,
                target.display_name AS target_agent_name, reviewer.display_name AS reviewer_name
            FROM project_change_proposals pcp
            JOIN project_participants pp ON pp.id = pcp.proposed_by_participant_id
            LEFT JOIN users u ON u.id = pp.user_id
            LEFT JOIN project_agents pa ON pa.project_id = pcp.project_id AND pa.agent_id = pp.agent_id
            LEFT JOIN project_agents target ON target.project_id = pcp.project_id AND target.agent_id = pcp.target_agent_id
            LEFT JOIN users reviewer ON reviewer.id = pcp.reviewed_by_user_id WHERE pcp.id = ? LIMIT 1");
        $statement->execute([(int) $id]);
        $row = $statement->fetch();
        if (!$row) { throw new RuntimeException('PROPOSAL_NOT_FOUND'); }
        return $this->present($row);
    }

    private function present(array $row)
    {
        return [
            'id' => (int) $row['id'], 'public_id' => $row['public_id'], 'project_id' => (int) $row['project_id'],
            'proposal_type' => $row['proposal_type'],
            'target_agent_id' => $row['target_agent_id'] === null ? null : (int) $row['target_agent_id'],
            'target_agent_name' => $row['target_agent_name'],
            'proposer' => ['participant_id' => (int) $row['proposed_by_participant_id'], 'kind' => $row['proposer_kind'], 'display_name' => $row['proposer_name']],
            'payload' => json_decode((string) $row['payload_json'], true), 'rationale' => $row['rationale'],
            'status' => $row['status'], 'version' => (int) $row['version'],
            'reviewer_name' => $row['reviewer_name'], 'review_note' => $row['review_note'],
            'applied_agent_id' => $row['applied_agent_id'] === null ? null : (int) $row['applied_agent_id'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], 'reviewed_at' => $row['reviewed_at'],
        ];
    }
}
