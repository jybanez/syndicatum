<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/ProjectManagementService.php';
require_once __DIR__ . '/ProjectPlanService.php';
require_once __DIR__ . '/MessageOutbox.php';

class ProjectChangeProposalService
{
    private $pdo;
    private $auth;
    private $management;
    private $plan;
    private $outbox;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
        $this->management = new ProjectManagementService($pdo);
        $this->plan = new ProjectPlanService($pdo);
        $this->outbox = new MessageOutbox($pdo);
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

    public function proposeProjectPlan(array $access, array $input)
    {
        $this->assertNoSensitiveFields($input);
        $milestones = isset($input['milestones']) && is_array($input['milestones']) ? $input['milestones'] : [];
        $standalone = isset($input['standalone_deliverables']) && is_array($input['standalone_deliverables'])
            ? $input['standalone_deliverables'] : [];
        if (count($milestones) > 10) { throw new InvalidArgumentException('A project plan proposal may contain at most 10 milestones.'); }
        $payload = ['milestones' => []];
        $deliverableCount = 0;
        foreach ($milestones as $index => $milestone) {
            if (!is_array($milestone)) { throw new InvalidArgumentException('Each proposed milestone must be an object.'); }
            $deliverables = isset($milestone['deliverables']) && is_array($milestone['deliverables']) ? $milestone['deliverables'] : [];
            if (count($deliverables) > 20) { throw new InvalidArgumentException('A milestone may contain at most 20 proposed deliverables.'); }
            $normalized = $this->planMilestone($milestone, $index);
            $normalized['deliverables'] = [];
            foreach ($deliverables as $deliverableIndex => $deliverable) {
                if (!is_array($deliverable)) { throw new InvalidArgumentException('Each proposed deliverable must be an object.'); }
                $normalized['deliverables'][] = $this->planDeliverable($deliverable, $deliverableIndex);
                $deliverableCount++;
            }
            $payload['milestones'][] = $normalized;
        }
        if (count($standalone) > 20) { throw new InvalidArgumentException('A project plan may contain at most 20 standalone deliverables.'); }
        if ($standalone) {
            $payload['standalone_deliverables'] = [];
            foreach ($standalone as $index => $deliverable) {
                if (!is_array($deliverable)) { throw new InvalidArgumentException('Each proposed deliverable must be an object.'); }
                $payload['standalone_deliverables'][] = $this->planDeliverable($deliverable, $index);
                $deliverableCount++;
            }
        }
        if (!$payload['milestones'] && $deliverableCount === 0) {
            throw new InvalidArgumentException('Propose at least one milestone or deliverable.');
        }
        if ($deliverableCount > 50) { throw new InvalidArgumentException('A project plan proposal may contain at most 50 deliverables.'); }
        return $this->create($access, 'project_plan', null, $payload, $input['rationale'] ?? '', $this->planFingerprint((int) $access['project_id']));
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
                } elseif ($proposal['proposal_type'] === 'project_plan') {
                    if (!hash_equals((string) $proposal['base_plan_fingerprint'], $this->planFingerprint((int) $projectId))) {
                        throw new RuntimeException('PROPOSAL_PLAN_CHANGED');
                    }
                    $this->applyProjectPlan($projectId, $userId, $payload);
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
            $reviewed = $this->proposal($proposalId);
            $this->outbox->enqueueProjectProposalsChanged((int) $projectId, $proposalId,
                (int) $reviewed['version'], (string) $reviewed['status'], $status);
            $this->pdo->commit();
            return $reviewed;
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    private function create(array $access, $type, $targetAgentId, array $payload, $rationale, $basePlanFingerprint = null)
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
        $this->pdo->beginTransaction();
        try {
            $now = Db::now();
            $statement = $this->pdo->prepare("INSERT INTO project_change_proposals
                (public_id, project_id, proposal_type, target_agent_id, proposed_by_participant_id, payload_json, rationale, base_plan_fingerprint, status, version, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?)");
            $statement->execute([Db::uuidV4(), $projectId, $type, $targetAgentId, $participantId,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $rationale === '' ? null : $rationale,
                $basePlanFingerprint, $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
            $this->auth->audit(null, 'project.change_proposal_created', 'project_change_proposal', (string) $id,
                ['project_id' => $projectId, 'participant_id' => $participantId, 'proposal_type' => $type]);
            $proposal = $this->proposal($id);
            $this->outbox->enqueueProjectProposalsChanged($projectId, $id, (int) $proposal['version'],
                (string) $proposal['status'], 'created');
            $this->pdo->commit();
            return $proposal;
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
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

    private function planMilestone(array $input, $position)
    {
        $title = $this->planTitle($input, 'Milestone title');
        $description = $this->planOptionalText($input, 'description', 10000, 'Milestone description');
        $target = $this->planOptionalDay($input['target_date'] ?? ($input['target_at'] ?? null), 'Milestone target date');
        return array_filter(['title' => $title, 'description' => $description, 'target_date' => $target,
            'position' => (int) $position], function ($value) { return $value !== null; });
    }

    private function planDeliverable(array $input, $position)
    {
        $title = $this->planTitle($input, 'Deliverable title');
        $description = $this->planOptionalText($input, 'description', 10000, 'Deliverable description');
        $due = $this->planOptionalDay($input['due_date'] ?? ($input['due_at'] ?? null), 'Deliverable due date');
        $owner = null;
        if (array_key_exists('owner_participant_id', $input) && $input['owner_participant_id'] !== null && $input['owner_participant_id'] !== '') {
            if (!preg_match('/^[1-9][0-9]*$/', (string) $input['owner_participant_id'])) {
                throw new InvalidArgumentException('Deliverable owner participant must be a positive integer or null.');
            }
            $owner = (int) $input['owner_participant_id'];
        }
        return array_filter(['title' => $title, 'description' => $description, 'due_date' => $due,
            'owner_participant_id' => $owner, 'position' => (int) $position], function ($value) { return $value !== null; });
    }

    private function applyProjectPlan($projectId, $userId, array $payload)
    {
        $access = $this->reviewerPlanAccess($projectId, $userId);
        foreach (($payload['milestones'] ?? []) as $milestone) {
            $created = $this->plan->createMilestone($access, [
                'title' => $milestone['title'], 'description' => $milestone['description'] ?? null,
                'status' => 'planned', 'target_at' => $milestone['target_date'] ?? null,
                'position' => $milestone['position'] ?? 0,
            ]);
            foreach (($milestone['deliverables'] ?? []) as $deliverable) {
                $this->plan->createDeliverable($access, [
                    'milestone_id' => $created['id'], 'title' => $deliverable['title'],
                    'description' => $deliverable['description'] ?? null, 'status' => 'planned',
                    'owner_participant_id' => $deliverable['owner_participant_id'] ?? null,
                    'due_at' => $deliverable['due_date'] ?? null, 'position' => $deliverable['position'] ?? 0,
                ]);
            }
        }
        foreach (($payload['standalone_deliverables'] ?? []) as $deliverable) {
            $this->plan->createDeliverable($access, [
                'milestone_id' => null, 'title' => $deliverable['title'],
                'description' => $deliverable['description'] ?? null, 'status' => 'planned',
                'owner_participant_id' => $deliverable['owner_participant_id'] ?? null,
                'due_at' => $deliverable['due_date'] ?? null, 'position' => $deliverable['position'] ?? 0,
            ]);
        }
    }

    private function reviewerPlanAccess($projectId, $userId)
    {
        $statement = $this->pdo->prepare("SELECT pp.id AS participant_id, pm.role, p.status AS project_status
            FROM projects p JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ? AND pm.status = 'active'
            JOIN project_participants pp ON pp.project_id = p.id AND pp.user_id = ? AND pp.kind = 'human' AND pp.status = 'active'
            WHERE p.id = ? LIMIT 1");
        $statement->execute([(int) $userId, (int) $userId, (int) $projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('PROJECT_NOT_FOUND'); }
        return ['project_id' => (int) $projectId, 'participant_id' => (int) $row['participant_id'],
            'project_status' => $row['project_status'], 'role' => $row['role'], 'identity' => ['kind' => 'human']];
    }

    private function planFingerprint($projectId)
    {
        $parts = [];
        foreach ([
            ['project_milestones', 'SELECT id, version, position FROM project_milestones WHERE project_id = ? ORDER BY id'],
            ['project_deliverables', 'SELECT id, version, milestone_id, position FROM project_deliverables WHERE project_id = ? ORDER BY id'],
        ] as $definition) {
            $statement = $this->pdo->prepare($definition[1]);
            $statement->execute([(int) $projectId]);
            $parts[$definition[0]] = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
        return hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function planTitle(array $input, $label)
    {
        $value = trim((string) ($input['title'] ?? ''));
        if ($value === '' || mb_strlen($value) > 180) { throw new InvalidArgumentException($label . ' is required and must not exceed 180 characters.'); }
        return $value;
    }

    private function planOptionalText(array $input, $field, $limit, $label)
    {
        if (!array_key_exists($field, $input) || trim((string) $input[$field]) === '') { return null; }
        $value = trim((string) $input[$field]);
        if (mb_strlen($value) > $limit) { throw new InvalidArgumentException($label . ' must not exceed ' . number_format($limit) . ' characters.'); }
        return $value;
    }

    private function planOptionalDay($value, $label)
    {
        $value = trim((string) $value);
        if ($value === '') { return null; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) { throw new InvalidArgumentException($label . ' must use YYYY-MM-DD.'); }
        return $value;
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
