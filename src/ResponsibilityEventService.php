<?php

require_once __DIR__ . '/ResponsibilityStateReducer.php';

/**
 * Called only inside ProjectRepository::createMessage's transaction, after the
 * canonical message is inserted and before it is committed or dispatched.
 */
class ResponsibilityEventService
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function record(array $access, array $input, $eventMessageId, $idempotencyKey, $body)
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Responsibility event requires the message transaction.');
        }
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Responsibility event requires an idempotency key.');
        }
        $this->validateInput($input);
        $projectId = (int) $access['project_id'];
        $requestId = isset($input['request_message_id']) ? (int) $input['request_message_id'] : 0;
        $initialResponderId = isset($input['initial_responder_participant_id'])
            ? (int) $input['initial_responder_participant_id'] : 0;
        if ($requestId < 1 || $initialResponderId < 1) {
            throw new InvalidArgumentException('Request and initial responder are required.');
        }

        // This row lock also prevents a source-message edit/deletion race while
        // validating the item. The project sequence lock serializes event writes.
        $source = $this->pdo->prepare(
            'SELECT m.sender_participant_id, ma.responsibility_status_generation
             FROM messages m
             JOIN message_addressees ma ON ma.message_id = m.id
                AND ma.participant_id = ? AND ma.reason = ?
             WHERE m.id = ? AND m.project_id = ?
               AND NOT EXISTS (SELECT 1 FROM responsibility_events source_event
                   WHERE source_event.event_message_id = m.id)
             FOR UPDATE'
        );
        $source->execute([$initialResponderId, 'direct', $requestId, $projectId]);
        $sourceRow = $source->fetch(PDO::FETCH_ASSOC);
        if (!$sourceRow) {
            throw new RuntimeException('MESSAGE_NOT_FOUND');
        }
        if ($sourceRow['responsibility_status_generation'] === null) {
            throw new RuntimeException('RESPONSIBILITY_BASELINE_UNAVAILABLE');
        }
        $anchorGeneration = (int) $sourceRow['responsibility_status_generation'];

        $state = ResponsibilityStateReducer::initial($requestId,
            (int) $sourceRow['sender_participant_id'], $initialResponderId);
        $history = $this->pdo->prepare(
            'SELECT re.*, em.project_sequence
             FROM responsibility_events re
             JOIN messages em ON em.id = re.event_message_id AND em.project_id = re.project_id
             WHERE re.project_id = ? AND re.request_message_id = ?
               AND re.initial_responder_participant_id = ?
             ORDER BY em.project_sequence, re.event_message_id FOR UPDATE'
        );
        $history->execute([$projectId, $requestId, $initialResponderId]);
        $knownEvents = [];
        foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $eventId = (int) $row['event_message_id'];
            $knownEvents[$eventId] = true;
            if ($row['prior_state'] === 'orphaned' && $state['state'] !== 'orphaned') {
                $state['state'] = 'orphaned';
                $state['pending'] = null;
            }
            $historicalEvent = [
                'id' => $eventId,
                'kind' => $row['kind'],
                'expected_event_id' => (int) $row['expected_event_message_id'],
                'ref_event_id' => $row['reference_event_message_id'] === null
                    ? null : (int) $row['reference_event_message_id'],
                'target_id' => $row['target_participant_id'] === null
                    ? null : (int) $row['target_participant_id'],
                'reason' => 'Previously validated canonical evidence',
            ];
            // Event-time authority was checked before persistence. Current
            // membership cannot retroactively invalidate historical decisions.
            $historicalActor = [
                'id' => (int) $row['actor_participant_id'],
                'active' => true,
                'moderator' => (bool) $row['actor_was_moderator'],
                'target_active' => true,
                'responder_active' => true,
            ];
            if ($row['kind'] === 'corrected') {
                unset($historicalEvent['target_id']);
            }
            $state = ResponsibilityStateReducer::apply($state, $historicalEvent,
                $historicalActor);
            if (in_array($row['kind'], ['transfer_accepted', 'responder_restored'], true)) {
                if ($row['responder_status_generation'] === null) {
                    throw new RuntimeException('RESPONSIBILITY_BASELINE_UNAVAILABLE');
                }
                $anchorGeneration = (int) $row['responder_status_generation'];
            }
        }

        // Expected versions must name this request or one of its persisted
        // events. A foreign/unrelated ID is concealed as not found; a known
        // but older version proceeds to the reducer's 409 conflict check.
        $expectedId = isset($input['expected_event_id'])
            ? (int) $input['expected_event_id'] : 0;
        if ($expectedId !== $requestId && !isset($knownEvents[$expectedId])) {
            throw new RuntimeException('MESSAGE_NOT_FOUND');
        }

        $responderActive = $this->activeParticipant($projectId, $state['responder_id']);
        $responderGeneration = $this->participantGeneration($projectId,
            $state['responder_id']);
        if ((!$responderActive || $responderGeneration !== $anchorGeneration)
            && $state['state'] !== 'resolved') {
            if ($state['state'] === 'transfer_pending') {
                $state['pending']['prior_state'] = 'orphaned';
            } elseif ($state['state'] !== 'orphaned') {
                $state['state'] = 'orphaned';
                $state['pending'] = null;
            }
        }

        $kind = isset($input['kind']) ? trim((string) $input['kind']) : '';
        $ref = isset($input['reference_event_id']) ? (int) $input['reference_event_id'] : null;
        if ($ref !== null && !isset($knownEvents[$ref])) {
            throw new RuntimeException('MESSAGE_NOT_FOUND');
        }
        $targetId = isset($input['target_participant_id'])
            ? (int) $input['target_participant_id'] : null;
        if ($targetId !== null && !$this->participantInProject($projectId, $targetId)) {
            throw new RuntimeException('MESSAGE_NOT_FOUND');
        }
        $actor = [
            'id' => (int) $access['participant_id'],
            'active' => true,
            'moderator' => $access['identity']['kind'] === 'human'
                && in_array($access['role'], ['owner', 'admin'], true),
            'target_active' => $targetId === null ? false
                : $this->activeParticipant($projectId, $targetId),
            'responder_active' => $responderActive,
        ];
        $event = [
            'id' => (int) $eventMessageId,
            'kind' => $kind,
            'expected_event_id' => isset($input['expected_event_id'])
                ? (int) $input['expected_event_id'] : 0,
            'ref_event_id' => $ref,
            'target_id' => $targetId,
            'reason' => $body,
        ];
        if ($kind === 'corrected') {
            if ($targetId !== null) {
                throw new InvalidArgumentException('Correction cannot name a transfer target.');
            }
            unset($event['target_id']);
        }
        $priorState = $state['state'];
        ResponsibilityStateReducer::apply($state, $event, $actor);
        $newResponderGeneration = in_array($kind,
            ['transfer_accepted', 'responder_restored'], true)
            ? $this->participantGeneration($projectId, $state['responder_id']) : null;

        $insert = $this->pdo->prepare(
            'INSERT INTO responsibility_events
             (event_message_id, project_id, request_message_id,
              initial_responder_participant_id, actor_participant_id,
              actor_was_moderator, kind, prior_state, expected_event_message_id,
              reference_event_message_id, target_participant_id,
              responder_status_generation,
              idempotency_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([(int) $eventMessageId, $projectId, $requestId,
            $initialResponderId, (int) $access['participant_id'],
            $actor['moderator'] ? 1 : 0, $kind, $priorState,
            (int) $event['expected_event_id'],
            $ref, $targetId, $newResponderGeneration, $idempotencyKey, Db::now()]);
    }

    private function activeParticipant($projectId, $participantId)
    {
        $lookup = $this->pdo->prepare(
            'SELECT pp.kind, pp.status, pm.status AS member_status,
                    pa.status AS agent_status, ca.is_active AS agent_active
             FROM project_participants pp
             LEFT JOIN project_members pm ON pm.project_id = pp.project_id
                AND pm.user_id = pp.user_id AND pp.kind = ?
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id
                AND pa.agent_id = pp.agent_id AND pp.kind = ?
             LEFT JOIN chat_agents ca ON ca.id = pp.agent_id
             WHERE pp.project_id = ? AND pp.id = ? LIMIT 1'
        );
        $lookup->execute(['human', 'agent', (int) $projectId, (int) $participantId]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'active') {
            return false;
        }
        if ($row['kind'] === 'human') {
            return $row['member_status'] === 'active';
        }
        return $row['kind'] === 'agent' && $row['agent_status'] === 'active'
            && (int) $row['agent_active'] === 1;
    }

    private function participantInProject($projectId, $participantId)
    {
        $lookup = $this->pdo->prepare(
            'SELECT COUNT(*) FROM project_participants WHERE project_id = ? AND id = ?'
        );
        $lookup->execute([(int) $projectId, (int) $participantId]);
        return (int) $lookup->fetchColumn() === 1;
    }

    private function participantGeneration($projectId, $participantId)
    {
        $lookup = $this->pdo->prepare(
            'SELECT status_generation FROM project_participants WHERE project_id = ? AND id = ?'
        );
        $lookup->execute([(int) $projectId, (int) $participantId]);
        $generation = $lookup->fetchColumn();
        if ($generation === false) {
            throw new RuntimeException('MESSAGE_NOT_FOUND');
        }
        return (int) $generation;
    }

    private function validateInput(array $input)
    {
        $kind = isset($input['kind']) ? trim((string) $input['kind']) : '';
        $referenceKinds = ['unblocked', 'resolution_accepted', 'resolution_disputed',
            'resolution_withdrawn', 'transfer_accepted', 'transfer_declined', 'corrected'];
        foreach (array_keys($input) as $field) {
            if (!in_array($field, ['kind', 'request_message_id',
                'initial_responder_participant_id', 'expected_event_id',
                'reference_event_id', 'target_participant_id'], true)) {
                throw new InvalidArgumentException('Unknown responsibility event field.');
            }
        }
        if (empty($input['expected_event_id'])
            || (int) $input['expected_event_id'] < 1) {
            throw new InvalidArgumentException('Expected event ID is required.');
        }
        if (in_array($kind, $referenceKinds, true)
            !== (isset($input['reference_event_id'])
                && (int) $input['reference_event_id'] > 0)) {
            throw new InvalidArgumentException('Invalid event reference.');
        }
        if (($kind === 'transfer_offered')
            !== (isset($input['target_participant_id'])
                && (int) $input['target_participant_id'] > 0)) {
            throw new InvalidArgumentException('Invalid transfer target.');
        }
    }
}
