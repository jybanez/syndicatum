<?php

require_once __DIR__ . '/Db.php';

/** Owner-only, section-oriented project status queries. */
class ProjectStatusService
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function summary(array $access)
    {
        $projectId = $this->ownerProjectId($access);
        $tasks = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                SUM(status IN ('open','in_progress','in_review','blocked')) AS active,
                SUM(status = 'blocked') AS blocked,
                SUM(status = 'in_review') AS in_review,
                SUM(status = 'completed') AS completed,
                SUM(due_at IS NOT NULL AND due_at < UTC_TIMESTAMP()
                    AND status NOT IN ('completed','cancelled')) AS overdue,
                SUM(status IN ('blocked','in_review') OR
                    (due_at IS NOT NULL AND due_at < UTC_TIMESTAMP()
                     AND status NOT IN ('completed','cancelled'))) AS needs_attention
             FROM project_tasks WHERE project_id = ?"
        );
        $tasks->execute([$projectId]);
        $task = $tasks->fetch(PDO::FETCH_ASSOC) ?: [];

        $participants = $this->pdo->prepare(
            "SELECT COUNT(*) AS active_total,
                SUM(kind = 'human') AS humans,
                SUM(kind = 'agent') AS agents,
                SUM(kind = 'integration') AS integrations
             FROM project_participants WHERE project_id = ? AND status = 'active'"
        );
        $participants->execute([$projectId]);
        $team = $participants->fetch(PDO::FETCH_ASSOC) ?: [];

        $sequence = $this->pdo->prepare(
            'SELECT GREATEST(next_sequence - 1, 0) FROM project_message_sequences WHERE project_id = ?'
        );
        $sequence->execute([$projectId]);

        return [
            'project_id' => $projectId,
            'tasks' => [
                'total' => $this->integer($task, 'total'),
                'active' => $this->integer($task, 'active'),
                'blocked' => $this->integer($task, 'blocked'),
                'in_review' => $this->integer($task, 'in_review'),
                'completed' => $this->integer($task, 'completed'),
                'overdue' => $this->integer($task, 'overdue'),
                'needs_attention' => $this->integer($task, 'needs_attention'),
            ],
            'team' => [
                'active_total' => $this->integer($team, 'active_total'),
                'humans' => $this->integer($team, 'humans'),
                'agents' => $this->integer($team, 'agents'),
                'integrations' => $this->integer($team, 'integrations'),
            ],
            'message_sequence' => (int) ($sequence->fetchColumn() ?: 0),
            'generated_at' => Db::now(),
        ];
    }

    public function taskProgress(array $access)
    {
        $projectId = $this->ownerProjectId($access);
        $statement = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS task_count FROM project_tasks
             WHERE project_id = ? GROUP BY status'
        );
        $statement->execute([$projectId]);
        $counts = array_fill_keys(['open', 'in_progress', 'in_review', 'blocked', 'completed', 'cancelled'], 0);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = (int) $row['task_count'];
            }
        }
        $total = array_sum($counts);
        $eligible = max(0, $total - $counts['cancelled']);
        return [
            'project_id' => $projectId,
            'counts' => $counts,
            'total' => $total,
            'completion_percent' => $eligible === 0 ? 0 : round(($counts['completed'] / $eligible) * 100, 1),
            'generated_at' => Db::now(),
        ];
    }

    public function activity(array $access, $days)
    {
        $projectId = $this->ownerProjectId($access);
        $days = (int) $days;
        if (!in_array($days, [7, 14, 30], true)) {
            throw new InvalidArgumentException('Activity range must be 7, 14, or 30 days.');
        }
        $start = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $taskQuery = $this->pdo->prepare(
            "SELECT DATE(created_at) AS activity_date,
                SUM(event_type = 'created') AS opened,
                SUM(to_status = 'completed') AS completed
             FROM project_task_events
             WHERE project_id = ? AND created_at >= ?
             GROUP BY DATE(created_at)"
        );
        $taskQuery->execute([$projectId, $start]);
        $taskDays = [];
        foreach ($taskQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $taskDays[$row['activity_date']] = [
                'opened' => (int) $row['opened'], 'completed' => (int) $row['completed'],
            ];
        }
        $messageQuery = $this->pdo->prepare(
            'SELECT DATE(created_at) AS activity_date, COUNT(*) AS message_count
             FROM messages WHERE project_id = ? AND created_at >= ? AND deleted_at IS NULL
             GROUP BY DATE(created_at)'
        );
        $messageQuery->execute([$projectId, $start]);
        $messageDays = [];
        foreach ($messageQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $messageDays[$row['activity_date']] = (int) $row['message_count'];
        }
        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = gmdate('Y-m-d', strtotime($start . ' +' . $offset . ' days'));
            $tasks = isset($taskDays[$date]) ? $taskDays[$date] : ['opened' => 0, 'completed' => 0];
            $series[] = [
                'date' => $date,
                'tasks_opened' => $tasks['opened'],
                'tasks_completed' => $tasks['completed'],
                'messages' => isset($messageDays[$date]) ? $messageDays[$date] : 0,
            ];
        }
        return ['project_id' => $projectId, 'range_days' => $days, 'series' => $series, 'generated_at' => Db::now()];
    }

    public function attention(array $access, $limit, $beforeId = null)
    {
        $projectId = $this->ownerProjectId($access);
        $limit = (int) $limit;
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Attention limit must be between 1 and 20.');
        }
        $parameters = [$projectId];
        $before = '';
        if ($beforeId !== null && $beforeId !== '') {
            if (!preg_match('/^[1-9][0-9]*$/', (string) $beforeId)) {
                throw new InvalidArgumentException('Attention cursor is invalid.');
            }
            $before = ' AND t.id < ?';
            $parameters[] = (int) $beforeId;
        }
        $statement = $this->pdo->prepare(
            "SELECT t.id, t.public_id, t.title, t.status, t.priority, t.due_at,
                    t.blocked_reason, t.updated_at, pp.kind AS assignee_kind,
                    COALESCE(u.display_name, pa.display_name) AS assignee_name
             FROM project_tasks t
             LEFT JOIN project_participants pp ON pp.id = t.assignee_participant_id
             LEFT JOIN users u ON u.id = pp.user_id AND pp.kind = 'human'
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id
                AND pa.agent_id = pp.agent_id AND pp.kind = 'agent'
             WHERE t.project_id = ?{$before}
               AND (t.status IN ('blocked','in_review') OR
                    (t.due_at IS NOT NULL AND t.due_at < UTC_TIMESTAMP()
                     AND t.status NOT IN ('completed','cancelled')))
             ORDER BY t.id DESC LIMIT " . ($limit + 1)
        );
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(function ($row) {
            return [
                'id' => (int) $row['id'], 'public_id' => $row['public_id'],
                'title' => $row['title'], 'status' => $row['status'], 'priority' => $row['priority'],
                'due_at' => $row['due_at'], 'blocked_reason' => $row['blocked_reason'],
                'updated_at' => $row['updated_at'], 'assignee_kind' => $row['assignee_kind'],
                'assignee_name' => $row['assignee_name'],
                'overdue' => $row['due_at'] !== null && strtotime($row['due_at'] . ' UTC') < time()
                    && !in_array($row['status'], ['completed', 'cancelled'], true),
            ];
        }, $rows);
        $lastItem = $items ? $items[count($items) - 1] : null;
        return [
            'project_id' => $projectId, 'items' => $items, 'has_more' => $hasMore,
            'next_before' => $hasMore && $lastItem ? $lastItem['id'] : null,
            'generated_at' => Db::now(),
        ];
    }

    public function team(array $access)
    {
        $projectId = $this->ownerProjectId($access);
        $statement = $this->pdo->prepare(
            'SELECT kind, status, COUNT(*) AS participant_count FROM project_participants
             WHERE project_id = ? GROUP BY kind, status'
        );
        $statement->execute([$projectId]);
        $counts = [];
        foreach (['human', 'agent', 'integration'] as $kind) {
            $counts[$kind] = ['active' => 0, 'suspended' => 0, 'removed' => 0, 'total' => 0];
        }
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($counts[$row['kind']])) {
                continue;
            }
            $status = $row['status'];
            if (!isset($counts[$row['kind']][$status])) {
                $counts[$row['kind']][$status] = 0;
            }
            $value = (int) $row['participant_count'];
            $counts[$row['kind']][$status] = $value;
            $counts[$row['kind']]['total'] += $value;
        }
        return ['project_id' => $projectId, 'counts' => $counts, 'generated_at' => Db::now()];
    }

    public function integrations(array $access)
    {
        $projectId = $this->ownerProjectId($access);
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                SUM(ic.status = 'active') AS active,
                SUM(ic.status = 'disabled') AS disabled,
                SUM(ic.status = 'removed') AS removed,
                SUM(ic.status <> 'removed' AND NOT EXISTS (
                    SELECT 1 FROM integration_credentials credential
                    WHERE credential.integration_id = ic.id AND credential.status = 'active'
                )) AS without_active_credential,
                MAX((SELECT MAX(used.last_used_at) FROM integration_credentials used
                    WHERE used.integration_id = ic.id)) AS last_used_at
             FROM integration_connections ic WHERE ic.project_id = ?"
        );
        $statement->execute([$projectId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'project_id' => $projectId,
            'total' => $this->integer($row, 'total'),
            'active' => $this->integer($row, 'active'),
            'disabled' => $this->integer($row, 'disabled'),
            'removed' => $this->integer($row, 'removed'),
            'without_active_credential' => $this->integer($row, 'without_active_credential'),
            'last_used_at' => isset($row['last_used_at']) ? $row['last_used_at'] : null,
            'generated_at' => Db::now(),
        ];
    }

    private function ownerProjectId(array $access)
    {
        if (!isset($access['identity']['kind']) || $access['identity']['kind'] !== 'human'
            || !isset($access['role']) || $access['role'] !== 'owner') {
            throw new RuntimeException('PROJECT_STATUS_FORBIDDEN');
        }
        return (int) $access['project_id'];
    }

    private function integer(array $row, $key)
    {
        return isset($row[$key]) ? (int) $row[$key] : 0;
    }
}
