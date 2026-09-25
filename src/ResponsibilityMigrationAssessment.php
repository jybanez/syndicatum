<?php

/** Content-free, read-only preflight for historical direct-request baselines. */
class ResponsibilityMigrationAssessment
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function report($projectId)
    {
        $projectId = (int) $projectId;
        if ($projectId < 1) {
            throw new InvalidArgumentException('A project ID is required.');
        }
        $query = $this->pdo->prepare(
            "SELECT COUNT(*) AS direct_items,
                COALESCE(SUM(CASE WHEN ma.responsibility_status_generation IS NULL
                    THEN 1 ELSE 0 END), 0) AS unverified_baselines,
                COALESCE(SUM(CASE WHEN ma.responsibility_status_generation IS NOT NULL
                    THEN 1 ELSE 0 END), 0) AS verified_baselines,
                COALESCE(SUM(CASE WHEN m.legacy_entry_id IS NOT NULL
                    THEN 1 ELSE 0 END), 0) AS legacy_direct_items,
                COALESCE(SUM(CASE WHEN m.legacy_entry_id IS NOT NULL
                    AND ma.responsibility_status_generation IS NULL
                    THEN 1 ELSE 0 END), 0) AS legacy_unverified_baselines
             FROM messages m
             JOIN message_addressees ma ON ma.message_id = m.id
             WHERE m.project_id = ? AND m.action_requested = 1 AND ma.reason = 'direct'
               AND NOT EXISTS (SELECT 1 FROM responsibility_events source_event
                   WHERE source_event.event_message_id = m.id)"
        );
        $query->execute([$projectId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $invalid = $this->pdo->prepare(
            'SELECT COUNT(*) FROM responsibility_events re
             JOIN message_addressees ma ON ma.message_id = re.request_message_id
                 AND ma.participant_id = re.initial_responder_participant_id
             WHERE re.project_id = ?
                 AND ma.responsibility_status_generation IS NULL'
        );
        $invalid->execute([$projectId]);
        $affected = $this->pdo->prepare(
            "SELECT COUNT(*) AS affected_items,
                MIN(m.created_at) AS oldest_created_at,
                MAX(m.created_at) AS newest_created_at,
                MIN(m.project_sequence) AS oldest_project_sequence,
                MAX(m.project_sequence) AS newest_project_sequence,
                COALESCE(SUM(CASE WHEN pp.id IS NULL THEN 1 ELSE 0 END), 0)
                    AS current_addressee_missing,
                COALESCE(SUM(CASE WHEN pp.status = 'active' AND
                    ((pp.kind = 'human' AND pm.status = 'active') OR
                     (pp.kind = 'agent' AND pa.status = 'active'
                        AND ca.is_active = 1)) THEN 1 ELSE 0 END), 0)
                    AS current_addressee_active
             FROM messages m
             JOIN message_addressees ma ON ma.message_id = m.id
             LEFT JOIN project_participants pp ON pp.id = ma.participant_id
                 AND pp.project_id = m.project_id
             LEFT JOIN project_members pm ON pm.project_id = pp.project_id
                 AND pm.user_id = pp.user_id AND pp.kind = 'human'
             LEFT JOIN project_agents pa ON pa.project_id = pp.project_id
                 AND pa.agent_id = pp.agent_id AND pp.kind = 'agent'
             LEFT JOIN chat_agents ca ON ca.id = pp.agent_id
             WHERE m.project_id = ? AND m.action_requested = 1 AND ma.reason = 'direct'
                 AND ma.responsibility_status_generation IS NULL
                 AND NOT EXISTS (SELECT 1 FROM responsibility_events source_event
                     WHERE source_event.event_message_id = m.id)"
        );
        $affected->execute([$projectId]);
        $affectedRow = $affected->fetch(PDO::FETCH_ASSOC);
        $affectedCount = (int) $affectedRow['affected_items'];
        $activeCount = (int) $affectedRow['current_addressee_active'];
        $missingCount = (int) $affectedRow['current_addressee_missing'];
        return [
            'project_id' => $projectId,
            'direct_items' => (int) $row['direct_items'],
            'verified_baselines' => (int) $row['verified_baselines'],
            'unverified_baselines' => (int) $row['unverified_baselines'],
            'legacy_direct_items' => (int) $row['legacy_direct_items'],
            'legacy_unverified_baselines' => (int) $row['legacy_unverified_baselines'],
            'events_on_unverified_baselines' => (int) $invalid->fetchColumn(),
            'current_addressee_validity' => [
                'active' => $activeCount,
                'inactive' => $affectedCount - $activeCount - $missingCount,
                'missing' => $missingCount,
            ],
            'unverified_range' => [
                'oldest_created_at' => $affectedRow['oldest_created_at'],
                'newest_created_at' => $affectedRow['newest_created_at'],
                'oldest_project_sequence' => $affectedCount
                    ? (int) $affectedRow['oldest_project_sequence'] : null,
                'newest_project_sequence' => $affectedCount
                    ? (int) $affectedRow['newest_project_sequence'] : null,
            ],
        ];
    }

    public function summary()
    {
        $row = $this->pdo->query(
            "SELECT COUNT(DISTINCT m.project_id) AS affected_projects,
                COUNT(*) AS unverified_direct_items,
                COALESCE(SUM(CASE WHEN m.legacy_entry_id IS NOT NULL
                    THEN 1 ELSE 0 END), 0) AS legacy_unverified_direct_items
             FROM messages m
             JOIN message_addressees ma ON ma.message_id = m.id
             WHERE m.action_requested = 1 AND ma.reason = 'direct'
                 AND ma.responsibility_status_generation IS NULL
                 AND NOT EXISTS (SELECT 1 FROM responsibility_events source_event
                     WHERE source_event.event_message_id = m.id)"
        )->fetch(PDO::FETCH_ASSOC);
        return [
            'affected_projects' => (int) $row['affected_projects'],
            'unverified_direct_items' => (int) $row['unverified_direct_items'],
            'legacy_unverified_direct_items' => (int) $row['legacy_unverified_direct_items'],
        ];
    }
}
