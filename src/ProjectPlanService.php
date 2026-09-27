<?php

require_once __DIR__ . '/Db.php';

/** Project milestones, deliverables, and task-backed progress. */
class ProjectPlanService
{
    private $pdo;
    private $milestoneStatuses = ['planned', 'in_progress', 'completed', 'at_risk', 'cancelled'];
    private $deliverableStatuses = ['planned', 'in_progress', 'in_review', 'approved', 'completed', 'blocked', 'cancelled'];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function plan(array $access)
    {
        $projectId = (int) $access['project_id'];
        $milestones = $this->pdo->prepare(
            "SELECT m.*,
                COUNT(DISTINCT CASE WHEN d.status <> 'cancelled' THEN d.id END) AS deliverable_count,
                SUM(d.status IN ('approved','completed')) AS ready_deliverable_count,
                SUM(d.status = 'blocked') AS blocked_deliverable_count
             FROM project_milestones m
             LEFT JOIN project_deliverables d ON d.milestone_id = m.id
             WHERE m.project_id = ?
             GROUP BY m.id
             ORDER BY m.position, m.target_at IS NULL, m.target_at, m.id"
        );
        $milestones->execute([$projectId]);
        $milestoneRows = array_map([$this, 'normalizeMilestone'], $milestones->fetchAll(PDO::FETCH_ASSOC));

        $deliverables = $this->pdo->prepare(
            "SELECT d.*,
                COALESCE(u.display_name, pa.display_name) AS owner_display_name,
                pp.kind AS owner_kind,
                COUNT(t.id) AS task_count,
                SUM(t.status = 'completed') AS completed_task_count,
                SUM(t.status = 'cancelled') AS cancelled_task_count,
                SUM(t.status = 'blocked') AS blocked_task_count
             FROM project_deliverables d
             LEFT JOIN project_participants pp ON pp.id = d.owner_participant_id
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             LEFT JOIN project_tasks t ON t.deliverable_id = d.id
             WHERE d.project_id = ?
             GROUP BY d.id
             ORDER BY d.milestone_id IS NULL, d.milestone_id, d.position, d.due_at IS NULL, d.due_at, d.id"
        );
        $deliverables->execute([$projectId]);
        $deliverableRows = array_map([$this, 'normalizeDeliverable'], $deliverables->fetchAll(PDO::FETCH_ASSOC));

        return [
            'project_id' => $projectId,
            'can_manage' => $this->isManager($access),
            'milestones' => $milestoneRows,
            'deliverables' => $deliverableRows,
            'generated_at' => Db::now(),
        ];
    }

    public function createMilestone(array $access, array $input)
    {
        $this->requireManager($access);
        $projectId = (int) $access['project_id'];
        $title = $this->title($input, 'Milestone title');
        $status = isset($input['status']) ? (string) $input['status'] : 'planned';
        $this->choice($status, $this->milestoneStatuses, 'milestone status');
        $now = Db::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO project_milestones
                (public_id, project_id, title, description, status, target_at, position, created_by_participant_id, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $statement->execute([
            $this->uuid(), $projectId, $title, $this->optionalText($input, 'description'), $status,
            $this->optionalDate(isset($input['target_at']) ? $input['target_at'] : null, 'Target date'),
            $this->position($input), (int) $access['participant_id'], $now, $now,
        ]);
        return $this->milestone($projectId, (int) $this->pdo->lastInsertId());
    }

    public function updateMilestone(array $access, $id, array $input)
    {
        $this->requireManager($access);
        $current = $this->milestone((int) $access['project_id'], (int) $id);
        $this->requireVersion($input, $current['version'], 'MILESTONE_VERSION_CONFLICT');
        $values = [
            $this->title($input, 'Milestone title'), $this->optionalText($input, 'description'),
            $this->requiredChoice($input, 'status', $this->milestoneStatuses, 'milestone status'),
            $this->optionalDate(isset($input['target_at']) ? $input['target_at'] : null, 'Target date'),
            $this->position($input), Db::now(), (int) $id, (int) $access['project_id'], (int) $current['version'],
        ];
        $statement = $this->pdo->prepare(
            'UPDATE project_milestones SET title = ?, description = ?, status = ?, target_at = ?, position = ?,
             version = version + 1, updated_at = ? WHERE id = ? AND project_id = ? AND version = ?'
        );
        $statement->execute($values);
        if ($statement->rowCount() !== 1) { throw new RuntimeException('MILESTONE_VERSION_CONFLICT'); }
        return $this->milestone((int) $access['project_id'], (int) $id);
    }

    public function createDeliverable(array $access, array $input)
    {
        $this->requireManager($access);
        $projectId = (int) $access['project_id'];
        $title = $this->title($input, 'Deliverable title');
        $status = isset($input['status']) ? (string) $input['status'] : 'planned';
        $this->choice($status, $this->deliverableStatuses, 'deliverable status');
        $now = Db::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO project_deliverables
                (public_id, project_id, milestone_id, title, description, status, owner_participant_id, due_at,
                 artifact_url, position, created_by_participant_id, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $statement->execute([
            $this->uuid(), $projectId, $this->optionalMilestone($projectId, isset($input['milestone_id']) ? $input['milestone_id'] : null),
            $title, $this->optionalText($input, 'description'), $status,
            $this->optionalParticipant($projectId, isset($input['owner_participant_id']) ? $input['owner_participant_id'] : null),
            $this->optionalDate(isset($input['due_at']) ? $input['due_at'] : null, 'Due date'),
            $this->optionalUrl(isset($input['artifact_url']) ? $input['artifact_url'] : null), $this->position($input),
            (int) $access['participant_id'], $now, $now,
        ]);
        return $this->deliverable($projectId, (int) $this->pdo->lastInsertId());
    }

    public function updateDeliverable(array $access, $id, array $input)
    {
        $this->requireManager($access);
        $projectId = (int) $access['project_id'];
        $current = $this->deliverable($projectId, (int) $id);
        $this->requireVersion($input, $current['version'], 'DELIVERABLE_VERSION_CONFLICT');
        $statement = $this->pdo->prepare(
            'UPDATE project_deliverables SET milestone_id = ?, title = ?, description = ?, status = ?,
             owner_participant_id = ?, due_at = ?, artifact_url = ?, position = ?, version = version + 1, updated_at = ?
             WHERE id = ? AND project_id = ? AND version = ?'
        );
        $statement->execute([
            $this->optionalMilestone($projectId, isset($input['milestone_id']) ? $input['milestone_id'] : null),
            $this->title($input, 'Deliverable title'), $this->optionalText($input, 'description'),
            $this->requiredChoice($input, 'status', $this->deliverableStatuses, 'deliverable status'),
            $this->optionalParticipant($projectId, isset($input['owner_participant_id']) ? $input['owner_participant_id'] : null),
            $this->optionalDate(isset($input['due_at']) ? $input['due_at'] : null, 'Due date'),
            $this->optionalUrl(isset($input['artifact_url']) ? $input['artifact_url'] : null), $this->position($input),
            Db::now(), (int) $id, $projectId, (int) $current['version'],
        ]);
        if ($statement->rowCount() !== 1) { throw new RuntimeException('DELIVERABLE_VERSION_CONFLICT'); }
        return $this->deliverable($projectId, (int) $id);
    }

    private function milestone($projectId, $id)
    {
        $statement = $this->pdo->prepare(
            "SELECT m.*, COUNT(DISTINCT CASE WHEN d.status <> 'cancelled' THEN d.id END) AS deliverable_count,
                SUM(d.status IN ('approved','completed')) AS ready_deliverable_count,
                SUM(d.status = 'blocked') AS blocked_deliverable_count
             FROM project_milestones m LEFT JOIN project_deliverables d ON d.milestone_id = m.id
             WHERE m.project_id = ? AND m.id = ? GROUP BY m.id"
        );
        $statement->execute([$projectId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('MILESTONE_NOT_FOUND'); }
        return $this->normalizeMilestone($row);
    }

    private function deliverable($projectId, $id)
    {
        $statement = $this->pdo->prepare(
            "SELECT d.*, COALESCE(u.display_name, pa.display_name) AS owner_display_name, pp.kind AS owner_kind,
                COUNT(t.id) AS task_count, SUM(t.status = 'completed') AS completed_task_count,
                SUM(t.status = 'cancelled') AS cancelled_task_count, SUM(t.status = 'blocked') AS blocked_task_count
             FROM project_deliverables d
             LEFT JOIN project_participants pp ON pp.id = d.owner_participant_id
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             LEFT JOIN project_tasks t ON t.deliverable_id = d.id
             WHERE d.project_id = ? AND d.id = ? GROUP BY d.id"
        );
        $statement->execute([$projectId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('DELIVERABLE_NOT_FOUND'); }
        return $this->normalizeDeliverable($row);
    }

    private function normalizeMilestone(array $row)
    {
        foreach (['id', 'project_id', 'position', 'created_by_participant_id', 'version', 'deliverable_count', 'ready_deliverable_count', 'blocked_deliverable_count'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['completion_percent'] = $row['deliverable_count'] === 0 ? 0
            : round(($row['ready_deliverable_count'] / $row['deliverable_count']) * 100, 1);
        return $row;
    }

    private function normalizeDeliverable(array $row)
    {
        foreach (['id', 'project_id', 'milestone_id', 'owner_participant_id', 'position', 'created_by_participant_id', 'version', 'task_count', 'completed_task_count', 'cancelled_task_count', 'blocked_task_count'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        $eligible = max(0, $row['task_count'] - $row['cancelled_task_count']);
        $row['eligible_task_count'] = $eligible;
        $row['completion_percent'] = $eligible === 0 ? 0 : round(($row['completed_task_count'] / $eligible) * 100, 1);
        return $row;
    }

    private function requireManager(array $access)
    {
        if (isset($access['project_status']) && $access['project_status'] !== 'active') { throw new RuntimeException('PROJECT_ARCHIVED'); }
        if (!$this->isManager($access)) { throw new RuntimeException('PROJECT_PLAN_FORBIDDEN'); }
    }
    private function isManager(array $access) { return $access['identity']['kind'] === 'human' && in_array($access['role'], ['owner', 'admin'], true); }
    private function requireVersion(array $input, $current, $error) { if (!isset($input['version']) || (int) $input['version'] !== (int) $current) { throw new RuntimeException($error); } }
    private function title(array $input, $label) { $value = trim(isset($input['title']) ? (string) $input['title'] : ''); if ($value === '' || mb_strlen($value) > 180) { throw new InvalidArgumentException($label . ' is required and must not exceed 180 characters.'); } return $value; }
    private function requiredChoice(array $input, $key, array $choices, $label) { $value = isset($input[$key]) ? (string) $input[$key] : ''; $this->choice($value, $choices, $label); return $value; }
    private function choice($value, array $choices, $label) { if (!in_array($value, $choices, true)) { throw new InvalidArgumentException('Select a valid ' . $label . '.'); } }
    private function optionalText(array $input, $key) { return !isset($input[$key]) || trim((string) $input[$key]) === '' ? null : trim((string) $input[$key]); }
    private function optionalDate($value, $label) { if ($value === null || trim((string) $value) === '') { return null; } $time = strtotime((string) $value); if ($time === false) { throw new InvalidArgumentException($label . ' must be a valid date and time.'); } return date('Y-m-d H:i:s', $time); }
    private function position(array $input) { $value = isset($input['position']) && $input['position'] !== '' ? (int) $input['position'] : 0; if ($value < 0) { throw new InvalidArgumentException('Position cannot be negative.'); } return $value; }
    private function optionalUrl($value) { $value = trim((string) $value); if ($value === '') { return null; } if (mb_strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) { throw new InvalidArgumentException('Artifact URL must be a valid HTTP or HTTPS URL.'); } return $value; }
    private function optionalMilestone($projectId, $value) { if ($value === null || $value === '') { return null; } $statement = $this->pdo->prepare('SELECT id FROM project_milestones WHERE project_id = ? AND id = ?'); $statement->execute([$projectId, (int) $value]); if (!$statement->fetchColumn()) { throw new InvalidArgumentException('Select a milestone from this project.'); } return (int) $value; }
    private function optionalParticipant($projectId, $value) { if ($value === null || $value === '') { return null; } $statement = $this->pdo->prepare("SELECT id FROM project_participants WHERE project_id = ? AND id = ? AND status = 'active' AND kind IN ('human','agent')"); $statement->execute([$projectId, (int) $value]); if (!$statement->fetchColumn()) { throw new InvalidArgumentException('Select an active person or AI agent from this project.'); } return (int) $value; }
    private function uuid() { $data = random_bytes(16); $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)); }
}
