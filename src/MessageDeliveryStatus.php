<?php

require_once __DIR__ . '/Db.php';

class MessageDeliveryStatus
{
    public static function handlingState(array $addressee)
    {
        if (!empty($addressee['acknowledged_at'])) { return 'acknowledged'; }
        if (!empty($addressee['seen_at'])) { return 'seen_not_acknowledged'; }
        if (!empty($addressee['notified_at'])) { return 'notified_not_seen'; }
        return 'unconfirmed';
    }

    public static function deliveryState(array $delivery)
    {
        if (!empty($delivery['failed_at']) || (isset($delivery['status']) && $delivery['status'] === 'dead')) {
            return 'failed';
        }
        if (!empty($delivery['published_at']) || (isset($delivery['status']) && $delivery['status'] === 'succeeded')) {
            return 'accepted';
        }
        return 'pending';
    }

    public static function realtimeEvent(array $row)
    {
        $state = self::deliveryState($row);
        $attemptCount = (int) $row['attempt_count'];
        return [
            'event_uuid' => $row['event_uuid'],
            'event_type' => $row['event_type'],
            'attempt_count' => $attemptCount,
            'state' => $state,
            'last_attempt_at' => self::databaseTime(isset($row['last_attempt_at']) ? $row['last_attempt_at'] : null),
            'next_retry_at' => $state === 'pending' && $attemptCount > 0
                ? self::databaseTime($row['available_at']) : null,
            'terminal_outcome' => $state === 'pending' ? null : $state,
            'failure_code' => $state === 'accepted' ? null
                : (isset($row['last_failure_code']) ? $row['last_failure_code'] : null),
        ];
    }

    public static function activationEvent(array $row)
    {
        $state = self::deliveryState($row);
        $queueStatus = $row['status'];
        return [
            'delivery_uuid' => $row['delivery_uuid'],
            'state' => $state,
            'queue_status' => $queueStatus,
            'attempt_count' => (int) $row['attempt_count'],
            'last_attempt_at' => self::databaseTime(isset($row['last_attempt_at']) ? $row['last_attempt_at'] : null),
            'next_retry_at' => in_array($queueStatus, ['retry', 'waiting'], true)
                ? self::databaseTime($row['next_attempt_at']) : null,
            'last_success_at' => self::databaseTime(isset($row['delivered_at']) ? $row['delivered_at'] : null),
            'terminal_outcome' => $state === 'pending' ? null : $state,
            'failure_code' => $state === 'accepted' ? null
                : (isset($row['last_failure_code']) ? $row['last_failure_code'] : null),
            'response_status' => isset($row['response_status']) ? (int) $row['response_status'] : null,
            'provider_state' => isset($row['response_state']) ? $row['response_state'] : null,
        ];
    }

    private static function databaseTime($value)
    {
        // The outbox stores DATETIME without an offset. Do not claim UTC here
        // unless the deployment timezone is independently established.
        return $value === null || $value === '' ? null : (string) $value;
    }

    public static function inspect(PDO $pdo, $messageId)
    {
        $query = $pdo->prepare('SELECT id, message_uuid, project_id, project_sequence, created_at, deleted_at FROM messages WHERE id = ?');
        $query->execute([(int) $messageId]);
        $message = $query->fetch(PDO::FETCH_ASSOC);
        if (!$message) {
            return ['found' => false, 'message_id' => (int) $messageId];
        }
        $result = [
            'found' => true,
            'canonical' => [
                'message_id' => (int) $message['id'],
                'message_uuid' => $message['message_uuid'],
                'project_id' => (int) $message['project_id'],
                'project_sequence' => (int) $message['project_sequence'],
                'state' => $message['deleted_at'] === null ? 'active' : 'deleted',
            ],
            'realtime' => ['state' => 'unknown', 'events' => null],
            'addressees' => [],
            'missing_delivery_tables' => [],
        ];
        if (Db::tableExists($pdo, 'message_events_outbox')) {
            $attemptColumn = Db::columnExists($pdo, 'message_events_outbox', 'last_attempt_at')
                ? 'last_attempt_at' : 'NULL AS last_attempt_at';
            $failureColumn = Db::columnExists($pdo, 'message_events_outbox', 'last_failure_code')
                ? 'last_failure_code' : 'NULL AS last_failure_code';
            $outbox = $pdo->prepare('SELECT event_uuid, event_type, attempt_count, available_at,
                    published_at, failed_at, ' . $attemptColumn . ', ' . $failureColumn . '
                FROM message_events_outbox WHERE message_id = ? ORDER BY id');
            $outbox->execute([(int) $messageId]);
            $events = [];
            foreach ($outbox->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $events[] = self::realtimeEvent($row);
            }
            $result['realtime'] = ['state' => count($events) ? 'recorded' : 'not_enqueued', 'events' => $events];
        } else {
            $result['missing_delivery_tables'][] = 'message_events_outbox';
        }
        $addressQuery = $pdo->prepare(
            'SELECT ma.participant_id, ma.reason, ma.notified_at, ma.seen_at, ma.acknowledged_at,
                    pp.kind, pp.agent_id
             FROM message_addressees ma JOIN project_participants pp ON pp.id = ma.participant_id
             WHERE ma.message_id = ? ORDER BY ma.participant_id'
        );
        $addressQuery->execute([(int) $messageId]);
        $paths = [
            'agent_webhook_deliveries' => 'webhook',
            'workspace_agent_trigger_deliveries' => 'workspace_agent',
            'responses_api_deliveries' => 'responses_api',
        ];
        $available = [];
        $diagnosticColumns = [];
        foreach ($paths as $table => $name) {
            $available[$table] = Db::tableExists($pdo, $table);
            if (!$available[$table]) { $result['missing_delivery_tables'][] = $table; }
            if ($available[$table]) {
                $diagnosticColumns[$table] = [
                    Db::columnExists($pdo, $table, 'last_attempt_at')
                        ? 'last_attempt_at' : 'NULL AS last_attempt_at',
                    Db::columnExists($pdo, $table, 'last_failure_code')
                        ? 'last_failure_code' : 'NULL AS last_failure_code',
                    Db::columnExists($pdo, $table, 'response_state')
                        ? 'response_state' : 'NULL AS response_state',
                ];
            }
        }
        foreach ($addressQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $address = [
                'participant_id' => (int) $row['participant_id'],
                'kind' => $row['kind'],
                'reason' => $row['reason'],
                'notification_recorded' => $row['notified_at'] !== null,
                'seen' => $row['seen_at'] !== null,
                'handling_state' => self::handlingState($row),
                'activation' => [],
            ];
            if ($row['kind'] === 'agent' && $row['agent_id'] !== null) {
                $address['agent_id'] = (int) $row['agent_id'];
                foreach ($paths as $table => $name) {
                    if (!$available[$table]) {
                        $address['activation'][$name] = ['state' => 'unknown'];
                        continue;
                    }
                    $deliveryQuery = $pdo->prepare('SELECT delivery_uuid, status, attempt_count, next_attempt_at,
                            response_status, delivered_at, ' . implode(', ', $diagnosticColumns[$table]) . '
                        FROM ' . $table . ' WHERE message_id = ? AND agent_id = ?');
                    $deliveryQuery->execute([(int) $messageId, (int) $row['agent_id']]);
                    $delivery = $deliveryQuery->fetch(PDO::FETCH_ASSOC);
                    $address['activation'][$name] = $delivery
                        ? self::activationEvent($delivery) : ['state' => 'not_enqueued'];
                }
            }
            $result['addressees'][] = $address;
        }
        return $result;
    }
}
