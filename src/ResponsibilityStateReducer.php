<?php

/**
 * Pure P1.1 transition core. Callers must obtain actor roles and membership
 * activity from the database, and serialize writes before calling apply().
 * This class does not persist events or authorize an HTTP request.
 */
class ResponsibilityStateReducer
{
    public static function initial($requestMessageId, $requesterId, $responderId)
    {
        return [
            'request_message_id' => (int) $requestMessageId,
            'requester_id' => (int) $requesterId,
            'responder_id' => (int) $responderId,
            'state' => 'open',
            'blocked' => false,
            'block_event_id' => null,
            'work_started' => false,
            'outcome' => null,
            'pending' => null,
            'last_event_id' => null,
        ];
    }

    public static function apply(array $state, array $event, array $actor)
    {
        $kind = isset($event['kind']) ? $event['kind'] : '';
        $eventId = isset($event['id']) ? (int) $event['id'] : 0;
        $expected = $state['last_event_id'] === null
            ? $state['request_message_id'] : $state['last_event_id'];
        if ($eventId <= 0 || !isset($event['expected_event_id'])
            || (int) $event['expected_event_id'] !== (int) $expected) {
            throw new RuntimeException('RESPONSIBILITY_CONFLICT');
        }
        if (empty($actor['active']) || empty($actor['id'])) {
            throw new RuntimeException('RESPONSIBILITY_FORBIDDEN');
        }
        $actorId = (int) $actor['id'];
        $requester = $actorId === (int) $state['requester_id'];
        $responder = $actorId === (int) $state['responder_id'];
        $moderator = !empty($actor['moderator']);
        $mayDecide = $requester || $moderator;
        $base = $state['state'];
        $pending = $state['pending'];

        switch ($kind) {
            case 'work_started':
                self::requireState($base, ['open', 'disputed']);
                self::requireActor($responder);
                $state['work_started'] = true;
                break;
            case 'blocked':
                self::requireState($base, ['open', 'disputed']);
                self::requireActor($responder);
                if ($state['blocked']) {
                    throw new RuntimeException('RESPONSIBILITY_CONFLICT');
                }
                $state['blocked'] = true;
                $state['block_event_id'] = $eventId;
                break;
            case 'unblocked':
                self::requireState($base, ['open', 'disputed']);
                self::requireActor($responder);
                if (!$state['blocked'] || !isset($event['ref_event_id'])
                    || (int) $event['ref_event_id'] !== (int) $state['block_event_id']) {
                    throw new RuntimeException('RESPONSIBILITY_CONFLICT');
                }
                $state['blocked'] = false;
                $state['block_event_id'] = null;
                break;
            case 'resolution_proposed':
                self::requireState($base, ['open', 'disputed']);
                self::requireActor($responder);
                $state['pending'] = ['kind' => 'resolution', 'id' => $eventId,
                    'prior_state' => $base, 'proposer_id' => $actorId];
                $state['state'] = 'resolution_pending';
                break;
            case 'resolution_accepted':
            case 'resolution_disputed':
                self::requirePending($base, $pending, 'resolution', $event);
                self::requireActor($mayDecide);
                $state['pending'] = null;
                $state['state'] = $kind === 'resolution_accepted' ? 'resolved' : 'disputed';
                $state['outcome'] = $kind === 'resolution_accepted' ? 'accepted' : null;
                break;
            case 'resolution_withdrawn':
                self::requirePending($base, $pending, 'resolution', $event);
                self::requireActor($actorId === (int) $pending['proposer_id']);
                $state['state'] = $pending['prior_state'];
                $state['pending'] = null;
                break;
            case 'request_withdrawn':
                self::requireState($base, ['open', 'disputed', 'orphaned',
                    'resolution_pending', 'transfer_pending']);
                self::requireActor($mayDecide);
                self::requireReason($event);
                $state['state'] = 'resolved';
                $state['pending'] = null;
                $state['outcome'] = 'withdrawn';
                break;
            case 'transfer_offered':
                self::requireState($base, ['open', 'disputed', 'orphaned']);
                self::requireActor($base === 'orphaned'
                    ? $mayDecide : ($responder || $mayDecide));
                if (empty($event['target_id']) || empty($actor['target_active'])
                    || (int) $event['target_id'] === (int) $state['responder_id']) {
                    throw new RuntimeException('RESPONSIBILITY_TARGET_INACTIVE');
                }
                $state['pending'] = ['kind' => 'transfer', 'id' => $eventId,
                    'prior_state' => $base, 'target_id' => (int) $event['target_id']];
                $state['state'] = 'transfer_pending';
                break;
            case 'transfer_accepted':
            case 'transfer_declined':
                self::requirePending($base, $pending, 'transfer', $event);
                self::requireActor($actorId === (int) $pending['target_id']);
                if ($kind === 'transfer_accepted') {
                    $state['responder_id'] = $actorId;
                    $state['state'] = 'open';
                    $state['blocked'] = false;
                    $state['block_event_id'] = null;
                    $state['work_started'] = false;
                } else {
                    $state['state'] = $pending['prior_state'];
                }
                $state['pending'] = null;
                break;
            case 'reopened':
                self::requireState($base, ['resolved']);
                self::requireActor($mayDecide);
                self::requireReason($event);
                $state['state'] = 'open';
                $state['outcome'] = null;
                $state['blocked'] = false;
                $state['block_event_id'] = null;
                $state['work_started'] = false;
                break;
            case 'responder_restored':
                self::requireState($base, ['orphaned']);
                self::requireActor($mayDecide);
                if (empty($actor['responder_active'])) {
                    throw new RuntimeException('RESPONSIBILITY_TARGET_INACTIVE');
                }
                $state['state'] = 'open';
                break;
            case 'corrected':
                self::requireActor($moderator);
                self::requireReason($event);
                if (empty($event['ref_event_id'])) {
                    throw new RuntimeException('RESPONSIBILITY_CONFLICT');
                }
                foreach (array_keys($event) as $field) {
                    if (!in_array($field, ['id', 'kind', 'expected_event_id',
                        'ref_event_id', 'reason', 'supporting_link'], true)) {
                        throw new InvalidArgumentException('Correction cannot change authority or state.');
                    }
                }
                break;
            default:
                throw new InvalidArgumentException('Unknown responsibility event kind.');
        }

        $state['last_event_id'] = $eventId;
        return $state;
    }

    private static function requireState($actual, array $allowed)
    {
        if (!in_array($actual, $allowed, true)) {
            throw new RuntimeException('RESPONSIBILITY_CONFLICT');
        }
    }

    private static function requireActor($allowed)
    {
        if (!$allowed) {
            throw new RuntimeException('RESPONSIBILITY_FORBIDDEN');
        }
    }

    private static function requirePending($state, $pending, $kind, array $event)
    {
        if ($state !== $kind . '_pending' || !is_array($pending)
            || $pending['kind'] !== $kind || !isset($event['ref_event_id'])
            || (int) $event['ref_event_id'] !== (int) $pending['id']) {
            throw new RuntimeException('RESPONSIBILITY_CONFLICT');
        }
    }

    private static function requireReason(array $event)
    {
        if (!isset($event['reason']) || trim((string) $event['reason']) === '') {
            throw new InvalidArgumentException('Reason required.');
        }
    }
}
