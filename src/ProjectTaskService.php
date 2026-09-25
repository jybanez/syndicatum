<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/MessageOutbox.php';

class ProjectTaskService
{
    private $pdo;
    private $outbox;
    private $statuses = ['open', 'in_progress', 'in_review', 'blocked', 'completed', 'cancelled'];
    private $priorities = ['low', 'normal', 'high', 'urgent'];

    public function __construct(PDO $pdo) { $this->pdo = $pdo; $this->outbox = new MessageOutbox($pdo); }

    public function listTasks(array $access, array $filters = [])
    {
        $where = ['t.project_id = ?'];
        $parameters = [(int) $access['project_id']];
        if (!empty($filters['status'])) {
            $statuses = array_values(array_filter(explode(',', (string) $filters['status'])));
            foreach ($statuses as $status) { $this->requireChoice($status, $this->statuses, 'status'); }
            if ($statuses) {
                $where[] = 't.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
                $parameters = array_merge($parameters, $statuses);
            }
        }
        if (!empty($filters['assignee'])) {
            $assignee = $filters['assignee'] === 'me' ? (int) $access['participant_id'] : (int) $filters['assignee'];
            $where[] = 't.assignee_participant_id = ?';
            $parameters[] = $assignee;
        }
        if (!empty($filters['q'])) {
            $where[] = '(t.title LIKE ? OR t.description LIKE ?)';
            $term = '%' . trim((string) $filters['q']) . '%';
            $parameters[] = $term; $parameters[] = $term;
        }
        $statement = $this->pdo->prepare($this->taskSelect()
            . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY FIELD(t.status, 'blocked','in_review','in_progress','open','completed','cancelled'),"
            . " FIELD(t.priority, 'urgent','high','normal','low'), t.due_at IS NULL, t.due_at, t.updated_at DESC LIMIT 500");
        $statement->execute($parameters);
        return array_map([$this, 'normalize'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function task(array $access, $taskId, $includeEvents = false)
    {
        $statement = $this->pdo->prepare($this->taskSelect() . ' WHERE t.project_id = ? AND t.id = ?');
        $statement->execute([(int) $access['project_id'], (int) $taskId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('TASK_NOT_FOUND'); }
        $task = $this->normalize($row);
        if ($includeEvents) { $task['events'] = $this->events((int) $taskId); }
        return $task;
    }

    public function create(array $access, array $input)
    {
        $this->requireWritable($access);
        $this->requireTaskCreator($access);
        $projectId = (int) $access['project_id'];
        $creator = (int) $access['participant_id'];
        $title = trim(isset($input['title']) ? (string) $input['title'] : '');
        if ($title === '' || mb_strlen($title) > 180) { throw new InvalidArgumentException('Task title is required and must not exceed 180 characters.'); }
        $priority = isset($input['priority']) ? (string) $input['priority'] : 'normal';
        $this->requireChoice($priority, $this->priorities, 'priority');
        $assignee = $this->optionalParticipant($projectId, isset($input['assignee_participant_id']) ? $input['assignee_participant_id'] : null);
        // The authenticated participant is the immutable task giver. Keep the
        // internal supervision link aligned with that identity; callers cannot
        // create work under another participant's authority.
        $supervisor = $creator;
        $source = $this->optionalMessage($projectId, isset($input['source_message_id']) ? $input['source_message_id'] : null);
        if (array_key_exists('convert_action_request', $input)
            && !is_bool($input['convert_action_request'])) {
            throw new InvalidArgumentException('convert_action_request must be a boolean.');
        }
        $convertActionRequest = !empty($input['convert_action_request']);
        if ($convertActionRequest && $source === null) {
            throw new InvalidArgumentException('Choose an action request to convert.');
        }
        $dueAt = $this->optionalDate(isset($input['due_at']) ? $input['due_at'] : null);
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            if ($source !== null) {
                $this->requireSourceLink($access, $source, $convertActionRequest);
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO project_tasks (public_id, project_id, title, description, acceptance_criteria, status, priority,
                 assignee_participant_id, supervising_participant_id, created_by_participant_id, source_message_id, due_at,
                 version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $insert->execute([$this->uuid(), $projectId, $title, $this->optionalText($input, 'description'),
                $this->optionalText($input, 'acceptance_criteria'), 'open', $priority, $assignee, $supervisor,
                $creator, $source, $dueAt, $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
            $this->event($id, $projectId, $creator, 'created', null, 'open', null,
                ['assignee_participant_id' => $assignee, 'supervising_participant_id' => $supervisor]);
            $task = $this->task($access, $id, true);
            $this->outbox->enqueueTaskUpdated($projectId, $id, $task, 'created');
            $this->pdo->commit();
            return $task;
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function update(array $access, $taskId, array $input)
    {
        $this->requireWritable($access);
        $current = $this->task($access, $taskId, false);
        if (!isset($input['version']) || (int) $input['version'] !== (int) $current['version']) {
            throw new RuntimeException('TASK_VERSION_CONFLICT');
        }
        $manager = $this->isManager($access);
        $actor = (int) $access['participant_id'];
        $taskGiver = $actor === (int) $current['created_by_participant_id'];
        $responsible = $actor === (int) $current['assignee_participant_id']
            || $actor === (int) $current['supervising_participant_id'];
        if (!$manager && !$responsible) { throw new RuntimeException('TASK_WRITE_FORBIDDEN'); }

        $sets = []; $values = []; $eventType = 'updated';
        foreach (['title', 'description', 'acceptance_criteria', 'priority', 'due_at', 'assignee_participant_id'] as $field) {
            if (!array_key_exists($field, $input)) { continue; }
            if (!$taskGiver) { throw new RuntimeException('TASK_WRITE_FORBIDDEN'); }
            $value = $input[$field];
            if ($field === 'title') {
                $value = trim((string) $value);
                if ($value === '' || mb_strlen($value) > 180) { throw new InvalidArgumentException('Task title is required and must not exceed 180 characters.'); }
            } elseif ($field === 'priority') { $value = (string) $value; $this->requireChoice($value, $this->priorities, 'priority'); }
            elseif ($field === 'due_at') { $value = $this->optionalDate($value); }
            elseif (strpos($field, 'participant_id') !== false) { $value = $this->optionalParticipant((int) $access['project_id'], $value); $eventType = 'assigned'; }
            else { $value = trim((string) $value) === '' ? null : trim((string) $value); }
            $sets[] = $field . ' = ?'; $values[] = $value;
        }
        $fromStatus = $current['status']; $toStatus = $fromStatus;
        if (isset($input['status'])) {
            $toStatus = (string) $input['status'];
            $this->requireChoice($toStatus, $this->statuses, 'status');
            $this->authorizeTransition($fromStatus, $toStatus, $manager, $actor === (int) $current['assignee_participant_id'], $actor === (int) $current['supervising_participant_id']);
            $sets[] = 'status = ?'; $values[] = $toStatus; $eventType = 'status_changed';
            if ($toStatus === 'in_progress' && !$current['started_at']) { $sets[] = 'started_at = ?'; $values[] = Db::now(); }
            if ($toStatus === 'in_review') { $sets[] = 'submitted_at = ?'; $values[] = Db::now(); }
            if ($toStatus === 'completed') { $sets[] = 'completed_at = ?'; $values[] = Db::now(); }
            if ($toStatus === 'cancelled') { $sets[] = 'cancelled_at = ?'; $values[] = Db::now(); }
            if (!in_array($toStatus, ['completed'], true)) { $sets[] = 'completed_at = NULL'; }
            if ($toStatus !== 'cancelled') { $sets[] = 'cancelled_at = NULL'; }
        }
        foreach (['blocked_reason', 'completion_summary'] as $field) {
            if (array_key_exists($field, $input)) { $sets[] = $field . ' = ?'; $values[] = trim((string) $input[$field]) ?: null; }
        }
        if (!$sets) { return $current; }
        $sets[] = 'version = version + 1'; $sets[] = 'updated_at = ?'; $values[] = Db::now();
        $values[] = (int) $taskId; $values[] = (int) $access['project_id']; $values[] = (int) $current['version'];
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare('UPDATE project_tasks SET ' . implode(', ', $sets) . ' WHERE id = ? AND project_id = ? AND version = ?');
            $update->execute($values);
            if ($update->rowCount() !== 1) { throw new RuntimeException('TASK_VERSION_CONFLICT'); }
            $this->event((int) $taskId, (int) $access['project_id'], $actor, $eventType,
                $fromStatus, $toStatus, isset($input['note']) ? trim((string) $input['note']) : null, null);
            $task = $this->task($access, $taskId, true);
            $this->outbox->enqueueTaskUpdated((int) $access['project_id'], (int) $taskId, $task, $eventType);
            $this->pdo->commit();
            return $task;
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    private function taskSelect()
    {
        return "SELECT t.*,
            COALESCE(au.display_name, apa.display_name) AS assignee_display_name,
            ap.kind AS assignee_kind,
            COALESCE(su.display_name, spa.display_name) AS supervisor_display_name,
            sp.kind AS supervisor_kind,
            COALESCE(cu.display_name, cpa.display_name) AS creator_display_name
          FROM project_tasks t
          LEFT JOIN project_participants ap ON ap.id = t.assignee_participant_id
          LEFT JOIN users au ON au.id = ap.user_id
          LEFT JOIN project_agents apa ON apa.project_id = ap.project_id AND apa.agent_id = ap.agent_id
          LEFT JOIN project_participants sp ON sp.id = t.supervising_participant_id
          LEFT JOIN users su ON su.id = sp.user_id
          LEFT JOIN project_agents spa ON spa.project_id = sp.project_id AND spa.agent_id = sp.agent_id
          JOIN project_participants cp ON cp.id = t.created_by_participant_id
          LEFT JOIN users cu ON cu.id = cp.user_id
          LEFT JOIN project_agents cpa ON cpa.project_id = cp.project_id AND cpa.agent_id = cp.agent_id";
    }

    private function normalize(array $row)
    {
        $integerFields = ['id','project_id','assignee_participant_id','supervising_participant_id','created_by_participant_id','source_message_id','version'];
        foreach ($integerFields as $field) { $row[$field] = $row[$field] === null ? null : (int) $row[$field]; }
        return $row;
    }

    private function events($taskId)
    {
        $statement = $this->pdo->prepare(
            "SELECT e.*, pp.kind, COALESCE(u.display_name, pa.display_name) AS actor_display_name
             FROM project_task_events e JOIN project_participants pp ON pp.id = e.actor_participant_id
             LEFT JOIN users u ON u.id = pp.user_id
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id AND pa.agent_id = pp.agent_id
             WHERE e.task_id = ? ORDER BY e.id DESC"
        );
        $statement->execute([$taskId]);
        return array_map(function ($row) {
            $row['id'] = (int) $row['id']; $row['task_id'] = (int) $row['task_id'];
            $row['project_id'] = (int) $row['project_id']; $row['actor_participant_id'] = (int) $row['actor_participant_id'];
            $row['metadata'] = $row['metadata_json'] ? json_decode($row['metadata_json'], true) : null;
            unset($row['metadata_json']); return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function event($taskId, $projectId, $actor, $type, $from, $to, $body, $metadata)
    {
        $statement = $this->pdo->prepare('INSERT INTO project_task_events
            (task_id, project_id, actor_participant_id, event_type, from_status, to_status, body, metadata_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$taskId, $projectId, $actor, $type, $from, $to, $body,
            $metadata === null ? null : json_encode($metadata), Db::now()]);
    }

    private function authorizeTransition($from, $to, $manager, $assignee, $supervisor)
    {
        if ($from === $to) { return; }
        $allowed = [
            'open' => ['in_progress','cancelled'], 'in_progress' => ['blocked','in_review','completed','cancelled'],
            'blocked' => ['in_progress','cancelled'], 'in_review' => ['in_progress','completed','cancelled'],
            'completed' => ['open'], 'cancelled' => ['open'],
        ];
        if (!in_array($to, isset($allowed[$from]) ? $allowed[$from] : [], true)) { throw new RuntimeException('TASK_INVALID_TRANSITION'); }
        if ($manager) { return; }
        if ($assignee && in_array($to, ['in_progress','blocked','in_review'], true)) { return; }
        if ($supervisor && in_array($to, ['in_progress','completed'], true)) { return; }
        throw new RuntimeException('TASK_WRITE_FORBIDDEN');
    }

    private function isManager(array $access) { return $access['identity']['kind'] === 'human' && in_array($access['role'], ['owner','admin'], true); }
    private function requireWritable(array $access) { if (isset($access['project_status']) && $access['project_status'] !== 'active') { throw new RuntimeException('PROJECT_ARCHIVED'); } }
    private function requireTaskCreator(array $access) {
        $kind = isset($access['identity']['kind']) ? (string) $access['identity']['kind'] : '';
        if ($kind === 'agent') { return; }
        if ($kind === 'human' && in_array($access['role'], ['owner','admin','member'], true)) { return; }
        throw new RuntimeException('TASK_WRITE_FORBIDDEN');
    }
    private function requireChoice($value, array $choices, $label) { if (!in_array($value, $choices, true)) { throw new InvalidArgumentException('Invalid task ' . $label . '.'); } }
    private function optionalText(array $input, $key) { return !isset($input[$key]) || trim((string) $input[$key]) === '' ? null : trim((string) $input[$key]); }
    private function optionalDate($value) {
        if ($value === null || trim((string) $value) === '') { return null; }
        $time = strtotime((string) $value); if ($time === false) { throw new InvalidArgumentException('Due date must be a valid date and time.'); }
        return date('Y-m-d H:i:s', $time);
    }
    private function optionalParticipant($projectId, $value) {
        if ($value === null || $value === '') { return null; }
        if (!preg_match('/^[1-9][0-9]*$/', (string) $value)) { throw new InvalidArgumentException('Select a valid active project participant.'); }
        $statement = $this->pdo->prepare("SELECT id FROM project_participants WHERE project_id = ? AND id = ? AND status = 'active'");
        $statement->execute([$projectId, (int) $value]); if (!$statement->fetchColumn()) { throw new InvalidArgumentException('Select an active participant in this project.'); }
        return (int) $value;
    }
    private function optionalMessage($projectId, $value) {
        if ($value === null || $value === '') { return null; }
        $statement = $this->pdo->prepare('SELECT id FROM messages WHERE project_id = ? AND id = ? AND deleted_at IS NULL');
        $statement->execute([$projectId, (int) $value]); if (!$statement->fetchColumn()) { throw new InvalidArgumentException('Source message must belong to this project.'); }
        return (int) $value;
    }
    private function requireSourceLink(array $access, $sourceMessageId, $conversionRequested) {
        $projectId = (int) $access['project_id'];
        $actor = (int) $access['participant_id'];
        $statement = $this->pdo->prepare(
            "SELECT m.sender_participant_id, m.action_requested,
                EXISTS(SELECT 1 FROM message_addressees ma
                    WHERE ma.message_id = m.id AND ma.participant_id = ?
                      AND ma.reason = 'direct') AS directly_addressed
             FROM messages m
             WHERE m.project_id = ? AND m.id = ? AND m.deleted_at IS NULL
             FOR UPDATE"
        );
        $statement->execute([$actor, $projectId, (int) $sourceMessageId]);
        $message = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$message) { throw new RuntimeException('MESSAGE_NOT_FOUND'); }
        $isActionRequest = (bool) $message['action_requested'];
        if ($conversionRequested && !$isActionRequest) {
            throw new InvalidArgumentException('Only an action request can be converted to a task.');
        }
        if (!$isActionRequest) { return; }
        $authorized = $this->isManager($access)
            || (int) $message['sender_participant_id'] === $actor
            || (bool) $message['directly_addressed'];
        if (!$authorized) { throw new RuntimeException('TASK_WRITE_FORBIDDEN'); }
        $existing = $this->pdo->prepare(
            'SELECT id FROM project_tasks WHERE project_id = ? AND source_message_id = ? LIMIT 1'
        );
        $existing->execute([$projectId, (int) $sourceMessageId]);
        if ($existing->fetchColumn() !== false) {
            throw new RuntimeException('TASK_ALREADY_LINKED');
        }
    }
    private function uuid() {
        $data = random_bytes(16); $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
