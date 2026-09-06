<?php

require_once __DIR__ . '/Db.php';

class ExpansionMigrator
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function migrateLegacyData($ownerUserId, $projectName = 'PBB Coordination')
    {
        $ownerUserId = (int) $ownerUserId;
        $owner = $this->owner($ownerUserId);
        $this->pdo->beginTransaction();
        try {
            $projectId = $this->ensureProject($owner, trim((string) $projectName));
            $this->ensureHumanParticipant($projectId, $ownerUserId);
            $agentParticipants = $this->ensureAgentParticipants($projectId);
            $messageMap = $this->ensureMessages($projectId, $agentParticipants);
            $this->ensureAddressees($projectId, $messageMap, $agentParticipants);
            $this->ensureRevisions($messageMap, $agentParticipants);
            $this->pdo->prepare(
                "INSERT INTO system_settings (setting_key, value_json, encrypted_value, updated_by_user_id, updated_at)
                 VALUES ('migration.default_project_id', ?, NULL, ?, ?)
                 ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_by_user_id = VALUES(updated_by_user_id), updated_at = VALUES(updated_at)"
            )->execute([json_encode($projectId), $ownerUserId, Db::now()]);
            $this->pdo->commit();
            return $this->reconciliation($projectId);
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function preflight()
    {
        $count = function ($table) {
            if (!Db::tableExists($this->pdo, $table)) { return null; }
            return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        };
        $tokenVersions = [];
        if (Db::tableExists($this->pdo, 'chat_agents') && Db::columnExists($this->pdo, 'chat_agents', 'token_secret_version')) {
            foreach ($this->pdo->query("SELECT COALESCE(token_secret_version, 'none') AS version, COUNT(*) AS count FROM chat_agents GROUP BY token_secret_version")->fetchAll() as $row) {
                $tokenVersions[$row['version']] = (int) $row['count'];
            }
        }
        $foreignKeyFailures = [];
        if (Db::tableExists($this->pdo, 'chat_entries')) {
            $missingSenders = (int) $this->pdo->query('SELECT COUNT(*) FROM chat_entries e LEFT JOIN chat_agents a ON a.id = e.sender_agent_id WHERE a.id IS NULL')->fetchColumn();
            if ($missingSenders > 0) { $foreignKeyFailures['messages_without_sender'] = $missingSenders; }
        }
        return [
            'database' => $this->pdo->query('SELECT DATABASE()')->fetchColumn(),
            'server_version' => $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'legacy_counts' => [
                'agents' => $count('chat_agents'), 'messages' => $count('chat_entries'),
                'recipients' => $count('chat_entry_recipients'), 'revisions' => $count('chat_entry_revisions'),
                'topics' => $count('chat_topics'),
            ],
            'token_secret_versions' => $tokenVersions,
            'integrity_failures' => $foreignKeyFailures,
            'ready' => empty($foreignKeyFailures),
            'captured_at' => date(DATE_ATOM),
        ];
    }

    public function reconciliation($projectId = null)
    {
        if ($projectId === null) {
            $projectId = $this->defaultProjectId();
        }
        $projectId = (int) $projectId;
        if ($projectId < 1) {
            throw new RuntimeException('The default migrated project has not been created.');
        }
        $count = function ($sql, array $params = []) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            return (int) $statement->fetchColumn();
        };
        $legacyMessages = $count('SELECT COUNT(*) FROM chat_entries');
        $canonicalMessages = $count('SELECT COUNT(*) FROM messages WHERE project_id = ? AND legacy_entry_id IS NOT NULL', [$projectId]);
        $legacyRevisions = $count('SELECT COUNT(*) FROM chat_entry_revisions');
        $canonicalRevisions = $count('SELECT COUNT(*) FROM message_revisions mr JOIN messages m ON m.id = mr.message_id WHERE m.project_id = ?', [$projectId]);
        $legacyRecipients = $count('SELECT COUNT(*) FROM chat_entry_recipients');
        $directAddressees = $count("SELECT COUNT(*) FROM message_addressees ma JOIN messages m ON m.id = ma.message_id WHERE m.project_id = ? AND ma.reason = 'direct'", [$projectId]);
        $legacyAgents = $count('SELECT COUNT(*) FROM chat_agents');
        $projectAgents = $count('SELECT COUNT(*) FROM project_agents WHERE project_id = ?', [$projectId]);
        return [
            'project_id' => $projectId,
            'legacy' => ['agents' => $legacyAgents, 'messages' => $legacyMessages, 'revisions' => $legacyRevisions, 'recipients' => $legacyRecipients],
            'canonical' => ['agents' => $projectAgents, 'messages' => $canonicalMessages, 'revisions' => $canonicalRevisions, 'direct_addressees' => $directAddressees,
                'broadcast_addressees' => $count("SELECT COUNT(*) FROM message_addressees ma JOIN messages m ON m.id = ma.message_id WHERE m.project_id = ? AND ma.reason = 'broadcast'", [$projectId])],
            'matches' => [
                'agents' => $legacyAgents === $projectAgents,
                'messages' => $legacyMessages === $canonicalMessages,
                'revisions' => $legacyRevisions === $canonicalRevisions,
                'direct_recipients' => $legacyRecipients === $directAddressees,
            ],
        ];
    }

    public function defaultProjectId()
    {
        $statement = $this->pdo->prepare("SELECT value_json FROM system_settings WHERE setting_key = 'migration.default_project_id'");
        $statement->execute();
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) json_decode($value, true);
    }

    private function owner($ownerUserId)
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.display_name, w.id AS workspace_id
             FROM users u JOIN workspaces w ON w.owner_user_id = u.id
             JOIN user_system_roles ur ON ur.user_id = u.id
             JOIN system_roles r ON r.id = ur.role_id AND r.code = 'administrator'
             WHERE u.id = ? AND u.status = 'active' AND u.deleted_at IS NULL LIMIT 1"
        );
        $statement->execute([$ownerUserId]);
        $owner = $statement->fetch();
        if (!$owner) {
            throw new RuntimeException('Migration owner must be an active administrator with a personal workspace.');
        }
        return $owner;
    }

    private function ensureProject(array $owner, $projectName)
    {
        if ($projectName === '') {
            throw new InvalidArgumentException('Project name is required.');
        }
        $existing = $this->defaultProjectId();
        if ($existing) {
            return $existing;
        }
        $slugBase = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($projectName)), '-');
        $slug = $slugBase === '' ? 'pbb-coordination' : substr($slugBase, 0, 140);
        $now = Db::now();
        $statement = $this->pdo->prepare(
            "INSERT INTO projects (workspace_id, owner_user_id, name, slug, description, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'active', ?, ?)"
        );
        $statement->execute([(int) $owner['workspace_id'], (int) $owner['id'], $projectName, $slug,
            'Migrated transparent coordination timeline for existing Syndicatum agents.', $now, $now]);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO project_members (project_id, user_id, role, status, created_at, updated_at)
             VALUES (?, ?, 'owner', 'active', ?, ?)"
        )->execute([$projectId, (int) $owner['id'], $now, $now]);
        $this->pdo->prepare('INSERT INTO project_message_sequences (project_id, next_sequence) VALUES (?, 1)')->execute([$projectId]);
        return $projectId;
    }

    private function ensureHumanParticipant($projectId, $userId)
    {
        $statement = $this->pdo->prepare(
            "INSERT IGNORE INTO project_participants (project_id, kind, user_id, status, created_at, updated_at)
             VALUES (?, 'human', ?, 'active', ?, ?)"
        );
        $statement->execute([$projectId, $userId, Db::now(), Db::now()]);
    }

    private function ensureAgentParticipants($projectId)
    {
        $agents = $this->pdo->query('SELECT id, project_name, is_active, created_at, updated_at FROM chat_agents ORDER BY id')->fetchAll();
        $projectAgent = $this->pdo->prepare(
            "INSERT IGNORE INTO project_agents (project_id, agent_id, display_name, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $participant = $this->pdo->prepare(
            "INSERT IGNORE INTO project_participants (project_id, kind, agent_id, status, created_at, updated_at)
             VALUES (?, 'agent', ?, ?, ?, ?)"
        );
        foreach ($agents as $agent) {
            $status = (int) $agent['is_active'] === 1 ? 'active' : 'suspended';
            $projectAgent->execute([$projectId, $agent['id'], $agent['project_name'], $status, $agent['created_at'], $agent['updated_at']]);
            $participant->execute([$projectId, $agent['id'], $status, $agent['created_at'], $agent['updated_at']]);
        }
        $statement = $this->pdo->prepare("SELECT id, agent_id FROM project_participants WHERE project_id = ? AND kind = 'agent'");
        $statement->execute([$projectId]);
        $map = [];
        foreach ($statement->fetchAll() as $row) {
            $map[(int) $row['agent_id']] = (int) $row['id'];
        }
        return $map;
    }

    private function ensureMessages($projectId, array $agentParticipants)
    {
        $entries = $this->pdo->query('SELECT * FROM chat_entries ORDER BY message_timestamp, id')->fetchAll();
        $existingRows = $this->pdo->prepare('SELECT id, legacy_entry_id, project_sequence FROM messages WHERE project_id = ? AND legacy_entry_id IS NOT NULL');
        $existingRows->execute([$projectId]);
        $map = [];
        $maxSequence = 0;
        foreach ($existingRows->fetchAll() as $row) {
            $map[(int) $row['legacy_entry_id']] = (int) $row['id'];
            $maxSequence = max($maxSequence, (int) $row['project_sequence']);
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO messages
             (message_uuid, legacy_entry_id, project_id, project_sequence, sender_participant_id, body, created_at, updated_at, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($entries as $entry) {
            $legacyId = (int) $entry['id'];
            if (isset($map[$legacyId])) {
                continue;
            }
            if (!isset($agentParticipants[(int) $entry['sender_agent_id']])) {
                throw new RuntimeException('Legacy message sender has no migrated participant: ' . $legacyId);
            }
            $maxSequence++;
            $uuid = !empty($entry['entry_uuid']) ? $entry['entry_uuid'] : self::uuidV4();
            $insert->execute([$uuid, $legacyId, $projectId, $maxSequence, $agentParticipants[(int) $entry['sender_agent_id']],
                $entry['body'], $entry['message_timestamp'], $entry['updated_at'], $entry['deleted_at']]);
            $map[$legacyId] = (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare(
            'INSERT INTO project_message_sequences (project_id, next_sequence) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE next_sequence = GREATEST(next_sequence, VALUES(next_sequence))'
        )->execute([$projectId, $maxSequence + 1]);
        return $map;
    }

    private function ensureAddressees($projectId, array $messageMap, array $agentParticipants)
    {
        $recipientRows = $this->pdo->query('SELECT entry_id, target_agent_id, created_at FROM chat_entry_recipients ORDER BY id')->fetchAll();
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO message_addressees (message_id, participant_id, reason, created_at) VALUES (?, ?, ?, ?)"
        );
        $directEntries = [];
        foreach ($recipientRows as $row) {
            $entryId = (int) $row['entry_id'];
            if (isset($messageMap[$entryId], $agentParticipants[(int) $row['target_agent_id']])) {
                $insert->execute([$messageMap[$entryId], $agentParticipants[(int) $row['target_agent_id']], 'direct', $row['created_at']]);
                $directEntries[$entryId] = true;
            }
        }
        $participants = $this->pdo->prepare("SELECT id FROM project_participants WHERE project_id = ? AND status = 'active'");
        $participants->execute([$projectId]);
        $activeIds = array_map('intval', $participants->fetchAll(PDO::FETCH_COLUMN));
        $messages = $this->pdo->prepare('SELECT id, legacy_entry_id, sender_participant_id, created_at FROM messages WHERE project_id = ? AND legacy_entry_id IS NOT NULL');
        $messages->execute([$projectId]);
        foreach ($messages->fetchAll() as $message) {
            if (isset($directEntries[(int) $message['legacy_entry_id']])) {
                continue;
            }
            foreach ($activeIds as $participantId) {
                if ($participantId !== (int) $message['sender_participant_id']) {
                    $insert->execute([$message['id'], $participantId, 'broadcast', $message['created_at']]);
                }
            }
        }
    }

    private function ensureRevisions(array $messageMap, array $agentParticipants)
    {
        $rows = $this->pdo->query('SELECT * FROM chat_entry_revisions ORDER BY id')->fetchAll();
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO message_revisions (legacy_revision_id, message_id, editor_participant_id, previous_body, new_body, edited_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            if (!isset($messageMap[(int) $row['entry_id']], $agentParticipants[(int) $row['edited_by_agent_id']])) {
                continue;
            }
            $messageId = $messageMap[(int) $row['entry_id']];
            $insert->execute([(int) $row['id'], $messageId, $agentParticipants[(int) $row['edited_by_agent_id']], $row['previous_body'], $row['new_body'], $row['edited_at']]);
        }
    }

    private static function uuidV4()
    {
        $bytes = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
