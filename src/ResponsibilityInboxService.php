<?php

require_once __DIR__ . '/ResponsibilityStateProjector.php';

/** Read-only, project-scoped projection of direct requests and canonical events. */
class ResponsibilityInboxService
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function page(array $access, array $filters = [])
    {
        $projectId = (int) $access['project_id'];
        $viewerId = (int) $access['participant_id'];
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 50;
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Inbox limit must be between 1 and 50.');
        }
        $view = isset($filters['view']) ? trim((string) $filters['view']) : 'all';
        if (!in_array($view, ['all', 'mine', 'addressed_to_me', 'unacknowledged',
            'waiting_on_others', 'blocked', 'transfer_pending',
            'resolution_pending', 'disputed', 'orphaned', 'resolved', 'unknown'], true)) {
            throw new InvalidArgumentException('Unknown responsibility inbox view.');
        }
        $before = empty($filters['before']) ? null
            : $this->decodeCursor($filters['before'], $projectId);
        $where = ['m.project_id = ?', "ma.reason = 'direct'",
            'NOT EXISTS (SELECT 1 FROM responsibility_events source_event
                WHERE source_event.event_message_id = m.id)'];
        $parameters = [$projectId];
        if ($before !== null) {
            $where[] = '(m.project_sequence < ? OR
                (m.project_sequence = ? AND ma.participant_id < ?))';
            array_push($parameters, $before['sequence'], $before['sequence'],
                $before['participant_id']);
        }
        if ($view === 'unacknowledged') {
            $where[] = 'ma.participant_id = ? AND ma.acknowledged_at IS NULL';
            $parameters[] = $viewerId;
        } elseif ($view === 'addressed_to_me') {
            $where[] = 'ma.participant_id = ?';
            $parameters[] = $viewerId;
        } elseif ($view === 'waiting_on_others') {
            $where[] = 'm.sender_participant_id = ?';
            $parameters[] = $viewerId;
        }
        $sql = 'SELECT m.id AS request_message_id,
                m.project_sequence AS request_sequence, m.created_at,
                m.sender_participant_id, ma.participant_id AS initial_responder_id,
                ma.acknowledged_at, ma.responsibility_status_generation
            FROM messages m JOIN message_addressees ma ON ma.message_id = m.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY m.project_sequence DESC, ma.participant_id DESC LIMIT ' . ($limit + 1);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $events = $this->eventsForRows($projectId, $rows);
        $projected = [];
        $currentResponderIds = [];
        foreach ($rows as $row) {
            $key = $row['request_message_id'] . ':' . $row['initial_responder_id'];
            try {
                $result = ResponsibilityStateProjector::replay(
                    (int) $row['request_message_id'],
                    (int) $row['sender_participant_id'],
                    (int) $row['initial_responder_id'],
                    $row['responsibility_status_generation'],
                    isset($events[$key]) ? $events[$key] : []);
                $currentResponderIds[(int) $result['state']['responder_id']] = true;
                $projected[$key] = $result;
            } catch (Exception $error) {
                // An old direct message with no verified baseline, or corrupt
                // evidence, is explicitly unknown rather than silently resolved.
                $projected[$key] = ['error' => $error->getMessage()
                    === 'RESPONSIBILITY_BASELINE_UNAVAILABLE'
                    ? 'RESPONSIBILITY_BASELINE_UNAVAILABLE'
                    : 'RESPONSIBILITY_PROJECTION_FAILED'];
            }
        }
        $validity = $this->currentValidity($projectId,
            array_keys($currentResponderIds));
        $items = [];
        foreach ($rows as $row) {
            $key = $row['request_message_id'] . ':' . $row['initial_responder_id'];
            $result = $projected[$key];
            $error = isset($result['error']) ? $result['error'] : null;
            $state = $error === null ? $result['state'] : null;
            if ($state !== null) {
                $responderId = (int) $state['responder_id'];
                $status = isset($validity[$responderId])
                    ? $validity[$responderId] : ['active' => false, 'generation' => null];
                $state = ResponsibilityStateProjector::withCurrentValidity($state,
                    $result['responder_status_generation'], $status['active'],
                    $status['generation']);
            }
            $hasActiveOwner = $state !== null
                && $state['state'] !== 'orphaned'
                && !($state['state'] === 'transfer_pending'
                    && is_array($state['pending'])
                    && $state['pending']['prior_state'] === 'orphaned');
            $item = [
                'project_id' => $projectId,
                'request_message_id' => (int) $row['request_message_id'],
                'request_sequence' => (int) $row['request_sequence'],
                'request_created_at' => $row['created_at'],
                'requester_participant_id' => (int) $row['sender_participant_id'],
                'initial_responder_participant_id' => (int) $row['initial_responder_id'],
                'current_responder_participant_id' => !$hasActiveOwner
                    ? null : (int) $state['responder_id'],
                'state' => $state === null ? 'unknown' : $state['state'],
                'blocked' => $state === null ? null : (bool) $state['blocked'],
                'work_started' => $state === null ? null : (bool) $state['work_started'],
                'acknowledged' => $row['acknowledged_at'] !== null,
                'latest_evidence_message_id' => $state === null
                    ? null : ($state['last_event_id'] === null
                        ? (int) $row['request_message_id'] : (int) $state['last_event_id']),
                'latest_evidence_sequence' => $state === null ? null
                    : ($result['latest_event_sequence'] === null
                        ? (int) $row['request_sequence']
                        : (int) $result['latest_event_sequence']),
                'projection_error' => $error,
                'canonical_message_api_href' => '/api/v1/project-message.php?project_id='
                    . $projectId . '&id=' . (int) $row['request_message_id'],
            ];
            if ($this->matchesView($view, $item, $viewerId)) {
                $items[] = $item;
            }
        }
        $last = $rows ? $rows[count($rows) - 1] : null;
        return ['data' => $items, 'page' => [
            'limit' => $limit,
            'scanned' => count($rows),
            'has_more' => $hasMore,
            'older_cursor' => $last === null ? null
                : $this->encodeCursor($projectId, $last['request_sequence'],
                    $last['initial_responder_id']),
        ]];
    }

    private function eventsForRows($projectId, array $rows)
    {
        if (!$rows) {
            return [];
        }
        $ids = array_values(array_unique(array_map(function ($row) {
            return (int) $row['request_message_id'];
        }, $rows)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->pdo->prepare('SELECT re.*, em.project_sequence
            FROM responsibility_events re
            JOIN messages em ON em.id = re.event_message_id
                AND em.project_id = re.project_id
            WHERE re.project_id = ? AND re.request_message_id IN (' . $placeholders . ')
            ORDER BY em.project_sequence, re.event_message_id');
        $query->execute(array_merge([(int) $projectId], $ids));
        $events = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['request_message_id'] . ':'
                . $row['initial_responder_participant_id'];
            if (!isset($events[$key])) {
                $events[$key] = [];
            }
            $events[$key][] = $row;
        }
        return $events;
    }

    private function currentValidity($projectId, array $ids)
    {
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->pdo->prepare('SELECT pp.id, pp.kind, pp.status,
                pp.status_generation, pm.status AS member_status,
                pa.status AS agent_status, ca.is_active AS agent_active
            FROM project_participants pp
            LEFT JOIN project_members pm ON pm.project_id = pp.project_id
                AND pm.user_id = pp.user_id AND pp.kind = ?
            LEFT JOIN project_agents pa ON pa.project_id = pp.project_id
                AND pa.agent_id = pp.agent_id AND pp.kind = ?
            LEFT JOIN chat_agents ca ON ca.id = pp.agent_id
            WHERE pp.project_id = ? AND pp.id IN (' . $placeholders . ')');
        $query->execute(array_merge(['human', 'agent', (int) $projectId], $ids));
        $validity = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $active = $row['status'] === 'active' && (
                ($row['kind'] === 'human' && $row['member_status'] === 'active')
                || ($row['kind'] === 'agent' && $row['agent_status'] === 'active'
                    && (int) $row['agent_active'] === 1));
            $validity[(int) $row['id']] = [
                'active' => $active,
                'generation' => (int) $row['status_generation'],
            ];
        }
        return $validity;
    }

    private function matchesView($view, array $item, $viewerId)
    {
        if ($view === 'all') {
            return true;
        }
        if ($view === 'addressed_to_me') {
            return $item['initial_responder_participant_id'] === $viewerId;
        }
        if ($view === 'unacknowledged') {
            return $item['initial_responder_participant_id'] === $viewerId
                && !$item['acknowledged'];
        }
        if ($view === 'mine') {
            return $item['current_responder_participant_id'] === $viewerId
                && $item['state'] !== 'resolved';
        }
        if ($view === 'waiting_on_others') {
            return $item['requester_participant_id'] === $viewerId
                && $item['current_responder_participant_id'] !== $viewerId
                && !in_array($item['state'], ['resolved', 'unknown', 'orphaned'], true);
        }
        if ($view === 'blocked') {
            return $item['blocked'] === true;
        }
        return $item['state'] === $view;
    }

    private function encodeCursor($projectId, $sequence, $participantId)
    {
        return rtrim(strtr(base64_encode(json_encode([
            'p' => (int) $projectId, 's' => (int) $sequence,
            'a' => (int) $participantId], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    private function decodeCursor($cursor, $projectId)
    {
        if (!is_string($cursor) || !preg_match('/^[A-Za-z0-9_-]{1,256}$/', $cursor)) {
            throw new InvalidArgumentException('Invalid inbox cursor.');
        }
        $json = base64_decode(strtr((string) $cursor, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || !isset($data['p'], $data['s'], $data['a'])
            || !is_int($data['p']) || $data['p'] !== (int) $projectId
            || !is_int($data['s']) || $data['s'] < 1
            || !is_int($data['a']) || $data['a'] < 1) {
            throw new InvalidArgumentException('Invalid inbox cursor.');
        }
        return ['sequence' => $data['s'], 'participant_id' => $data['a']];
    }
}
