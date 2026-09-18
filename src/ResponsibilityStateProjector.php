<?php

require_once __DIR__ . '/ResponsibilityStateReducer.php';

/**
 * Rebuilds responsibility only from the direct request and persisted canonical
 * events. No inbox status is stored or mutated here.
 */
class ResponsibilityStateProjector
{
    public static function replay($requestId, $requesterId, $initialResponderId,
        $initialGeneration, array $rows)
    {
        if ($initialGeneration === null) {
            throw new RuntimeException('RESPONSIBILITY_BASELINE_UNAVAILABLE');
        }
        $state = ResponsibilityStateReducer::initial($requestId,
            $requesterId, $initialResponderId);
        $generation = (int) $initialGeneration;
        $knownEvents = [];
        $latestSequence = null;
        foreach ($rows as $row) {
            $eventId = (int) $row['event_message_id'];
            $knownEvents[$eventId] = true;
            if ($row['prior_state'] === 'orphaned' && $state['state'] !== 'orphaned') {
                $state['state'] = 'orphaned';
                $state['pending'] = null;
            }
            $event = [
                'id' => $eventId,
                'kind' => $row['kind'],
                'expected_event_id' => (int) $row['expected_event_message_id'],
                'ref_event_id' => $row['reference_event_message_id'] === null
                    ? null : (int) $row['reference_event_message_id'],
                'target_id' => $row['target_participant_id'] === null
                    ? null : (int) $row['target_participant_id'],
                'reason' => 'Previously validated canonical evidence',
            ];
            // Event-time authority is persisted. Later membership changes do
            // not retroactively invalidate canonical historical decisions.
            $actor = [
                'id' => (int) $row['actor_participant_id'],
                'active' => true,
                'moderator' => (bool) $row['actor_was_moderator'],
                'target_active' => true,
                'responder_active' => true,
            ];
            if ($row['kind'] === 'corrected') {
                unset($event['target_id']);
            }
            $state = ResponsibilityStateReducer::apply($state, $event, $actor);
            if (in_array($row['kind'], ['transfer_accepted', 'responder_restored'], true)) {
                if ($row['responder_status_generation'] === null) {
                    throw new RuntimeException('RESPONSIBILITY_BASELINE_UNAVAILABLE');
                }
                $generation = (int) $row['responder_status_generation'];
            }
            $latestSequence = isset($row['project_sequence'])
                ? (int) $row['project_sequence'] : $latestSequence;
        }
        return [
            'state' => $state,
            'responder_status_generation' => $generation,
            'known_event_ids' => $knownEvents,
            'latest_event_sequence' => $latestSequence,
        ];
    }

    public static function withCurrentValidity(array $state, $anchorGeneration,
        $responderActive, $currentGeneration)
    {
        if ((!$responderActive || (int) $currentGeneration !== (int) $anchorGeneration)
            && $state['state'] !== 'resolved') {
            if ($state['state'] === 'transfer_pending') {
                $state['pending']['prior_state'] = 'orphaned';
            } elseif ($state['state'] !== 'orphaned') {
                $state['state'] = 'orphaned';
                $state['pending'] = null;
            }
        }
        return $state;
    }
}
