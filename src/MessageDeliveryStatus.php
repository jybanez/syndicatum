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
            $outbox = $pdo->prepare('SELECT event_uuid, event_type, attempt_count, published_at, failed_at FROM message_events_outbox WHERE message_id = ? ORDER BY id');
            $outbox->execute([(int) $messageId]);
            $events = [];
            foreach ($outbox->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $events[] = [
                    'event_uuid' => $row['event_uuid'],
                    'event_type' => $row['event_type'],
                    'attempt_count' => (int) $row['attempt_count'],
                    'state' => self::deliveryState($row),
                ];
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
        foreach ($paths as $table => $name) {
            $available[$table] = Db::tableExists($pdo, $table);
            if (!$available[$table]) { $result['missing_delivery_tables'][] = $table; }
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
                    $deliveryQuery = $pdo->prepare('SELECT delivery_uuid, status, attempt_count FROM ' . $table . ' WHERE message_id = ? AND agent_id = ?');
                    $deliveryQuery->execute([(int) $messageId, (int) $row['agent_id']]);
                    $delivery = $deliveryQuery->fetch(PDO::FETCH_ASSOC);
                    $address['activation'][$name] = $delivery ? [
                        'delivery_uuid' => $delivery['delivery_uuid'],
                        'state' => self::deliveryState($delivery),
                        'queue_status' => $delivery['status'],
                        'attempt_count' => (int) $delivery['attempt_count'],
                    ] : ['state' => 'not_enqueued'];
                }
            }
            $result['addressees'][] = $address;
        }
        return $result;
    }
}
