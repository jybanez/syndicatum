<?php

require_once __DIR__ . '/Db.php';

class ChatRepository
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function hasSchema()
    {
        return Db::tableExists($this->pdo, 'chat_agents')
            && Db::tableExists($this->pdo, 'chat_entries')
            && Db::tableExists($this->pdo, 'chat_entry_recipients')
            && Db::tableExists($this->pdo, 'chat_topics');
    }

    public function installSchema()
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS chat_agents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_name VARCHAR(120) NOT NULL UNIQUE,
                description TEXT NULL,
                source_order INT NULL,
                token_prefix VARCHAR(24) NULL UNIQUE,
                token_hash CHAR(64) NULL,
                token_secret_version VARCHAR(24) NULL,
                claim_prefix VARCHAR(24) NULL UNIQUE,
                claim_hash CHAR(64) NULL,
                claim_secret_version VARCHAR(24) NULL,
                role ENUM('agent', 'admin') NOT NULL DEFAULT 'agent',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                claimed_at DATETIME NULL,
                INDEX idx_chat_agents_active_order (is_active, source_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS chat_entries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entry_uuid CHAR(36) NULL UNIQUE,
                sender_agent_id BIGINT UNSIGNED NOT NULL,
                message_timestamp DATETIME NOT NULL,
                body MEDIUMTEXT NOT NULL,
                source_line INT NULL,
                source_order INT NULL,
                source_hash CHAR(64) NULL UNIQUE,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                INDEX idx_chat_entries_timestamp (message_timestamp, id),
                INDEX idx_chat_entries_sender (sender_agent_id, message_timestamp),
                INDEX idx_chat_entries_deleted (deleted_at),
                CONSTRAINT fk_chat_entries_sender FOREIGN KEY (sender_agent_id) REFERENCES chat_agents(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS chat_entry_recipients (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entry_id BIGINT UNSIGNED NOT NULL,
                target_agent_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_chat_entry_recipient (entry_id, target_agent_id),
                INDEX idx_chat_entry_recipients_target (target_agent_id, entry_id),
                CONSTRAINT fk_chat_entry_recipients_entry FOREIGN KEY (entry_id) REFERENCES chat_entries(id) ON DELETE CASCADE,
                CONSTRAINT fk_chat_entry_recipients_target FOREIGN KEY (target_agent_id) REFERENCES chat_agents(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS chat_entry_revisions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entry_id BIGINT UNSIGNED NOT NULL,
                edited_by_agent_id BIGINT UNSIGNED NOT NULL,
                previous_body MEDIUMTEXT NOT NULL,
                new_body MEDIUMTEXT NOT NULL,
                edited_at DATETIME NOT NULL,
                INDEX idx_chat_entry_revisions_entry (entry_id, edited_at),
                CONSTRAINT fk_chat_entry_revisions_entry FOREIGN KEY (entry_id) REFERENCES chat_entries(id) ON DELETE CASCADE,
                CONSTRAINT fk_chat_entry_revisions_editor FOREIGN KEY (edited_by_agent_id) REFERENCES chat_agents(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS chat_topics (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                body TEXT NOT NULL,
                source_hash CHAR(64) NULL UNIQUE,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_agent_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                INDEX idx_chat_topics_active (is_active, deleted_at),
                CONSTRAINT fk_chat_topics_creator FOREIGN KEY (created_by_agent_id) REFERENCES chat_agents(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS chat_write_audit (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                agent_id BIGINT UNSIGNED NULL,
                action VARCHAR(40) NOT NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                ip_address VARCHAR(80) NULL,
                user_agent VARCHAR(255) NULL,
                message TEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_chat_write_audit_created (created_at),
                CONSTRAINT fk_chat_write_audit_agent FOREIGN KEY (agent_id) REFERENCES chat_agents(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }

        $this->ensureClaimColumns();
        $this->ensureCredentialVersionColumns();
    }

    public function importPayload(array $payload)
    {
        $this->installSchema();
        $now = Db::now();
        $report = [
            'agents_created_or_updated' => 0,
            'inactive_senders_created' => 0,
            'topics_created_or_updated' => 0,
            'entries_created' => 0,
            'entries_skipped' => 0,
            'recipients_created' => 0,
            'warnings' => [],
        ];

        $this->pdo->beginTransaction();
        try {
            foreach ($payload['projects'] as $index => $project) {
                $this->upsertAgent($project['name'], isset($project['summary']) ? $project['summary'] : '', $index + 1, true);
                $report['agents_created_or_updated']++;
            }

            $this->pdo->exec('UPDATE chat_topics SET is_active = 0, updated_at = ' . $this->pdo->quote($now) . ' WHERE deleted_at IS NULL');
            foreach ($payload['active_topics'] as $topic) {
                $hash = hash('sha256', 'topic|' . $topic);
                $this->upsertTopic($topic, $hash, $now);
                $report['topics_created_or_updated']++;
            }

            foreach ($payload['messages'] as $message) {
                $senderId = $this->findAgentId($message['sender']);
                if ($senderId === null) {
                    $senderId = $this->upsertAgent($message['sender'], 'Imported sender not listed in #Projects.', null, false);
                    $report['inactive_senders_created']++;
                }

                $sourceHash = $this->sourceHash($message);
                $entryId = $this->findEntryIdByHash($sourceHash);
                if ($entryId !== null) {
                    $report['entries_skipped']++;
                } else {
                    $entryId = $this->insertEntry($senderId, $message, $sourceHash, $now);
                    $report['entries_created']++;
                }

                $targetNames = isset($message['targets']) && is_array($message['targets'])
                    ? $message['targets']
                    : $this->splitTargetNames(isset($message['target']) ? $message['target'] : '');

                foreach ($targetNames as $targetName) {
                    $targetId = $this->findAgentId($targetName);
                    if ($targetId === null) {
                        $report['warnings'][] = 'Unknown target skipped: ' . $targetName . ' for message source_order=' . $message['source_order'];
                        continue;
                    }

                    if ($this->insertRecipient($entryId, $targetId, $now)) {
                        $report['recipients_created']++;
                    }
                }
            }

            $this->pdo->commit();
        } catch (Exception $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $report;
    }

    public function payload()
    {
        $agents = $this->agents(true);
        $participants = $this->participants();
        $topics = array_map(function ($topic) {
            return $topic['body'];
        }, $this->topics());
        $messages = $this->messages(['order' => 'asc']);
        $messageCount = count($messages);
        $directCount = count(array_filter($messages, function ($message) {
            return !empty($message['is_direct']);
        }));
        $days = array_values(array_unique(array_map(function ($message) {
            return $message['day_key'];
        }, $messages)));
        $lastUpdated = $this->lastUpdated();
        $etag = '"' . sha1($lastUpdated . '|' . $messageCount . '|' . $directCount) . '"';

        return [
            'meta' => [
                'source_path' => 'mysql:pbb_agentchat',
                'source_name' => 'pbb_agentchat',
                'source_type' => 'database',
                'last_modified_unix' => strtotime($lastUpdated) ?: time(),
                'last_modified_iso' => gmdate(DATE_ATOM, strtotime($lastUpdated) ?: time()),
                'etag' => $etag,
                'message_count' => $messageCount,
                'direct_count' => $directCount,
                'participant_count' => count($participants),
                'day_count' => count($days),
            ],
            'projects' => array_map(function ($agent) {
                return [
                    'id' => (int) $agent['id'],
                    'name' => $agent['project_name'],
                    'summary' => $agent['description'] ?: '',
                ];
            }, $agents),
            'active_topics' => $topics,
            'participants' => $participants,
            'messages' => $messages,
        ];
    }

    public function agents($activeOnly = false)
    {
        $sql = 'SELECT * FROM chat_agents';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY is_active DESC, source_order IS NULL, source_order ASC, project_name ASC';

        return $this->pdo->query($sql)->fetchAll();
    }

    public function publicAgents($activeOnly = false)
    {
        $this->ensureClaimColumns();
        $this->ensureCredentialVersionColumns();

        return array_map(function ($agent) {
            return [
                'id' => (int) $agent['id'],
                'project_name' => $agent['project_name'],
                'description' => $agent['description'],
                'source_order' => $agent['source_order'] === null ? null : (int) $agent['source_order'],
                'role' => $agent['role'],
                'is_active' => (bool) $agent['is_active'],
                'is_claimed' => !empty($agent['token_hash']),
                'token_prefix' => $agent['token_prefix'],
                'claim_pending' => empty($agent['token_hash']) && !empty($agent['claim_hash']),
                'created_at' => $agent['created_at'],
                'updated_at' => $agent['updated_at'],
                'last_used_at' => $agent['last_used_at'],
                'claimed_at' => isset($agent['claimed_at']) ? $agent['claimed_at'] : null,
            ];
        }, $this->agents($activeOnly));
    }

    public function topics()
    {
        return $this->pdo->query('SELECT * FROM chat_topics WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id ASC')->fetchAll();
    }

    public function messages(array $filters = [])
    {
        $where = ['e.deleted_at IS NULL'];
        $params = [];
        if (!empty($filters['sender'])) {
            $where[] = 'sender.project_name = ?';
            $params[] = $filters['sender'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(e.body LIKE ? OR sender.project_name LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }

        $order = isset($filters['order']) && strtolower((string) $filters['order']) === 'asc'
            ? 'ASC'
            : 'DESC';

        $sql = "SELECT e.*, sender.project_name AS sender_name
            FROM chat_entries e
            JOIN chat_agents sender ON sender.id = e.sender_agent_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.message_timestamp " . $order . ", e.id " . $order;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $messages = [];

        foreach ($rows as $index => $row) {
            $targets = $this->targetsForEntry((int) $row['id']);
            if (!empty($filters['target']) && !in_array($filters['target'], $targets, true)) {
                continue;
            }

            if (isset($filters['direct']) && $filters['direct'] !== '' && ((int) $filters['direct']) !== (empty($targets) ? 0 : 1)) {
                continue;
            }

            $timestamp = $row['message_timestamp'];
            $target = implode('/', $targets);
            $body = $row['body'];
            $messages[] = [
                'id' => 'db-' . $row['id'],
                'db_id' => (int) $row['id'],
                'index' => $index + 1,
                'source_line' => $row['source_line'] === null ? null : (int) $row['source_line'],
                'source_order' => $row['source_order'] === null ? null : (int) $row['source_order'],
                'timestamp' => $timestamp,
                'timestamp_unix' => strtotime($timestamp) ?: null,
                'day_key' => substr($timestamp, 0, 10),
                'sender' => $row['sender_name'],
                'target' => $target === '' ? null : $target,
                'targets' => $targets,
                'is_direct' => !empty($targets),
                'body' => $body,
                'excerpt' => $this->makeExcerpt($body),
            ];
        }

        return $messages;
    }

    public function authenticate($token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        $hash = Db::hashToken($token);
        $statement = $this->pdo->prepare('SELECT * FROM chat_agents WHERE token_hash = ? AND is_active = 1');
        $statement->execute([$hash]);
        $agent = $statement->fetch();
        $migrated = false;
        if (!$agent) {
            $previousHash = Db::hashTokenWithPreviousSecret($token);
            if ($previousHash !== null) {
                $statement->execute([$previousHash]);
                $agent = $statement->fetch();
                $migrated = (bool) $agent;
            }
        }
        if (!$agent) {
            return null;
        }

        $now = Db::now();
        if ($migrated) {
            $update = $this->pdo->prepare(
                'UPDATE chat_agents SET token_hash = ?, token_secret_version = ?, last_used_at = ?, updated_at = ? WHERE id = ? AND token_hash = ?'
            );
            $update->execute([$hash, 'primary', $now, $now, $agent['id'], $previousHash]);
            if ($update->rowCount() !== 1) {
                return null;
            }
            $agent['token_hash'] = $hash;
            $agent['token_secret_version'] = 'primary';
        } else {
            $update = $this->pdo->prepare('UPDATE chat_agents SET token_secret_version = ?, last_used_at = ?, updated_at = ? WHERE id = ?');
            $update->execute(['primary', $now, $now, $agent['id']]);
        }

        return $agent;
    }

    public function createEntry(array $agent, array $input)
    {
        $body = trim((string) (isset($input['body']) ? $input['body'] : ''));
        if ($body === '') {
            throw new InvalidArgumentException('Message body is required.');
        }

        $targets = isset($input['targets']) && is_array($input['targets'])
            ? $input['targets']
            : $this->splitTargetNames(isset($input['target']) ? $input['target'] : '');
        $targets = array_values(array_unique(array_filter(array_map('trim', $targets))));
        $targetIds = [];
        foreach ($targets as $target) {
            $targetId = $this->findAgentId($target, true);
            if ($targetId === null) {
                throw new InvalidArgumentException('Unknown or inactive target: ' . $target);
            }
            if ((int) $targetId !== (int) $agent['id']) {
                $targetIds[] = (int) $targetId;
            }
        }
        $targetIds = array_values(array_unique($targetIds));

        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO chat_entries (entry_uuid, sender_agent_id, message_timestamp, body, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$this->uuid(), $agent['id'], $now, $body, $now, $now]);
            $entryId = (int) $this->pdo->lastInsertId();

            foreach ($targetIds as $targetId) {
                $this->insertRecipient($entryId, $targetId, $now);
            }

            $this->audit($agent, 'create_entry', true, 'Created entry ' . $entryId);
            $this->pdo->commit();
        } catch (Exception $exception) {
            $this->pdo->rollBack();
            $this->audit($agent, 'create_entry', false, $exception->getMessage());
            throw $exception;
        }

        return $this->entryById($entryId);
    }

    public function updateEntry($entryId, array $agent, array $input)
    {
        $entry = $this->rawEntry($entryId);
        if (!$entry) {
            throw new RuntimeException('Entry not found.');
        }
        if ((int) $entry['sender_agent_id'] !== (int) $agent['id'] && $agent['role'] !== 'admin') {
            throw new RuntimeException('Only the sender or an admin can edit this entry.');
        }

        $body = trim((string) (isset($input['body']) ? $input['body'] : $entry['body']));
        if ($body === '') {
            throw new InvalidArgumentException('Message body is required.');
        }

        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            if ($body !== $entry['body']) {
                $revision = $this->pdo->prepare(
                    'INSERT INTO chat_entry_revisions (entry_id, edited_by_agent_id, previous_body, new_body, edited_at) VALUES (?, ?, ?, ?, ?)'
                );
                $revision->execute([$entryId, $agent['id'], $entry['body'], $body, $now]);
            }
            $update = $this->pdo->prepare('UPDATE chat_entries SET body = ?, updated_at = ? WHERE id = ?');
            $update->execute([$body, $now, $entryId]);
            $this->audit($agent, 'update_entry', true, 'Updated entry ' . $entryId);
            $this->pdo->commit();
        } catch (Exception $exception) {
            $this->pdo->rollBack();
            $this->audit($agent, 'update_entry', false, $exception->getMessage());
            throw $exception;
        }

        return $this->entryById($entryId);
    }

    public function deleteEntry($entryId, array $agent)
    {
        $entry = $this->rawEntry($entryId);
        if (!$entry) {
            throw new RuntimeException('Entry not found.');
        }
        if ((int) $entry['sender_agent_id'] !== (int) $agent['id'] && $agent['role'] !== 'admin') {
            throw new RuntimeException('Only the sender or an admin can delete this entry.');
        }

        $now = Db::now();
        $statement = $this->pdo->prepare('UPDATE chat_entries SET deleted_at = ?, updated_at = ? WHERE id = ?');
        $statement->execute([$now, $now, $entryId]);
        $this->audit($agent, 'delete_entry', true, 'Deleted entry ' . $entryId);

        return true;
    }

    public function createTopic(array $agent, array $input)
    {
        $body = trim((string) (isset($input['body']) ? $input['body'] : ''));
        if ($body === '') {
            throw new InvalidArgumentException('Topic body is required.');
        }

        $now = Db::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO chat_topics (body, is_active, created_by_agent_id, created_at, updated_at) VALUES (?, 1, ?, ?, ?)'
        );
        $statement->execute([$body, $agent['id'], $now, $now]);
        $topicId = (int) $this->pdo->lastInsertId();
        $this->audit($agent, 'create_topic', true, 'Created topic ' . $topicId);

        return $this->topicById($topicId);
    }

    public function updateTopic($topicId, array $agent, array $input)
    {
        $topic = $this->rawTopic($topicId);
        if (!$topic) {
            throw new RuntimeException('Topic not found.');
        }
        if ($topic['created_by_agent_id'] !== null && (int) $topic['created_by_agent_id'] !== (int) $agent['id'] && $agent['role'] !== 'admin') {
            throw new RuntimeException('Only the creator or an admin can edit this topic.');
        }
        if ($topic['created_by_agent_id'] === null && $agent['role'] !== 'admin') {
            throw new RuntimeException('Only an admin can edit imported topics.');
        }

        $body = trim((string) (isset($input['body']) ? $input['body'] : $topic['body']));
        if ($body === '') {
            throw new InvalidArgumentException('Topic body is required.');
        }
        $isActive = isset($input['is_active']) ? ((bool) $input['is_active'] ? 1 : 0) : (int) $topic['is_active'];
        $now = Db::now();
        $statement = $this->pdo->prepare('UPDATE chat_topics SET body = ?, is_active = ?, updated_at = ? WHERE id = ?');
        $statement->execute([$body, $isActive, $now, $topicId]);
        $this->audit($agent, 'update_topic', true, 'Updated topic ' . $topicId);

        return $this->topicById($topicId);
    }

    public function deleteTopic($topicId, array $agent)
    {
        $topic = $this->rawTopic($topicId);
        if (!$topic) {
            throw new RuntimeException('Topic not found.');
        }
        if ($topic['created_by_agent_id'] !== null && (int) $topic['created_by_agent_id'] !== (int) $agent['id'] && $agent['role'] !== 'admin') {
            throw new RuntimeException('Only the creator or an admin can delete this topic.');
        }
        if ($topic['created_by_agent_id'] === null && $agent['role'] !== 'admin') {
            throw new RuntimeException('Only an admin can delete imported topics.');
        }

        $now = Db::now();
        $statement = $this->pdo->prepare('UPDATE chat_topics SET is_active = 0, deleted_at = ?, updated_at = ? WHERE id = ?');
        $statement->execute([$now, $now, $topicId]);
        $this->audit($agent, 'delete_topic', true, 'Deleted topic ' . $topicId);

        return true;
    }

    public function entryById($entryId)
    {
        $entry = $this->rawEntry($entryId);
        if (!$entry) {
            return null;
        }
        $targets = $this->targetsForEntry((int) $entry['id']);

        return [
            'id' => 'db-' . $entry['id'],
            'db_id' => (int) $entry['id'],
            'timestamp' => $entry['message_timestamp'],
            'sender' => $entry['sender_name'],
            'targets' => $targets,
            'target' => empty($targets) ? null : implode('/', $targets),
            'is_direct' => !empty($targets),
            'body' => $entry['body'],
        ];
    }

    public function topicById($topicId)
    {
        $topic = $this->rawTopic($topicId);
        if (!$topic) {
            return null;
        }

        return [
            'id' => (int) $topic['id'],
            'body' => $topic['body'],
            'is_active' => (bool) $topic['is_active'],
            'created_by_agent_id' => $topic['created_by_agent_id'] === null ? null : (int) $topic['created_by_agent_id'],
            'created_at' => $topic['created_at'],
            'updated_at' => $topic['updated_at'],
            'deleted_at' => $topic['deleted_at'],
        ];
    }

    public function generateToken($projectName)
    {
        $this->ensureClaimColumns();
        $this->ensureCredentialVersionColumns();

        $agentId = $this->findAgentId($projectName);
        if ($agentId === null) {
            throw new RuntimeException('Unknown agent: ' . $projectName);
        }

        $token = $this->makeSecret('pbbchat', $projectName);
        $prefix = substr($token, 0, 24);
        $now = Db::now();
        $statement = $this->pdo->prepare(
            'UPDATE chat_agents
             SET token_prefix = ?, token_hash = ?, token_secret_version = ?, claim_prefix = NULL, claim_hash = NULL,
                 claim_secret_version = NULL, claimed_at = ?, updated_at = ?
             WHERE id = ?'
        );
        $statement->execute([$prefix, Db::hashToken($token), 'primary', $now, $now, $agentId]);

        return [
            'project_name' => $projectName,
            'token_prefix' => $prefix,
            'token' => $token,
        ];
    }

    public function generateClaimCode($projectName)
    {
        $this->ensureClaimColumns();
        $this->ensureCredentialVersionColumns();

        $agent = $this->agentByProjectName($projectName, true);
        if (!$agent) {
            throw new RuntimeException('Unknown or inactive agent: ' . $projectName);
        }
        if (!empty($agent['token_hash'])) {
            throw new RuntimeException('Agent already claimed: ' . $projectName);
        }

        $claimCode = $this->makeSecret('pbbclaim', $projectName);
        $prefix = substr($claimCode, 0, 24);
        $now = Db::now();
        $statement = $this->pdo->prepare('UPDATE chat_agents SET claim_prefix = ?, claim_hash = ?, claim_secret_version = ?, updated_at = ? WHERE id = ?');
        $statement->execute([$prefix, Db::hashToken($claimCode), 'primary', $now, $agent['id']]);

        return [
            'project_name' => $projectName,
            'claim_prefix' => $prefix,
            'claim_code' => $claimCode,
        ];
    }

    public function generateClaimCodes()
    {
        $agents = $this->agents(true);
        $codes = [];
        foreach ($agents as $agent) {
            if (!empty($agent['token_hash'])) {
                continue;
            }
            $codes[] = $this->generateClaimCode($agent['project_name']);
        }

        return $codes;
    }

    public function claimAgent($projectName, $claimCode)
    {
        $this->ensureClaimColumns();
        $this->ensureCredentialVersionColumns();

        $projectName = trim((string) $projectName);
        $claimCode = trim((string) $claimCode);
        if ($projectName === '' || $claimCode === '') {
            throw new InvalidArgumentException('Project name and claim code are required.');
        }

        $agent = $this->agentByProjectName($projectName, true);
        if (!$agent) {
            throw new RuntimeException('Unknown or inactive agent.');
        }
        if (!empty($agent['token_hash'])) {
            throw new RuntimeException('Agent already claimed. Ask the operator for a token reset or provided token.');
        }
        if (empty($agent['claim_hash'])) {
            throw new RuntimeException('No claim code is active for this agent. Ask the operator for a claim code.');
        }
        $claimMatches = hash_equals($agent['claim_hash'], Db::hashToken($claimCode));
        if (!$claimMatches) {
            $previousHash = Db::hashTokenWithPreviousSecret($claimCode);
            $claimMatches = $previousHash !== null && hash_equals($agent['claim_hash'], $previousHash);
        }
        if (!$claimMatches) {
            throw new RuntimeException('Invalid claim code.');
        }

        $token = $this->makeSecret('pbbchat', $projectName);
        $prefix = substr($token, 0, 24);
        $now = Db::now();
        $statement = $this->pdo->prepare(
            'UPDATE chat_agents
             SET token_prefix = ?, token_hash = ?, token_secret_version = ?, claim_prefix = NULL, claim_hash = NULL,
                 claim_secret_version = NULL, claimed_at = ?, updated_at = ?
             WHERE id = ? AND token_hash IS NULL'
        );
        $statement->execute([$prefix, Db::hashToken($token), 'primary', $now, $now, $agent['id']]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Agent already claimed. Ask the operator for a token reset or provided token.');
        }

        $this->audit(['id' => $agent['id']], 'claim_agent', true, 'Claimed agent ' . $projectName);

        return [
            'project_name' => $projectName,
            'token_prefix' => $prefix,
            'token' => $token,
        ];
    }

    public function credentialMigrationSummary()
    {
        $this->ensureCredentialVersionColumns();
        $row = $this->pdo->query(
            "SELECT
                SUM(CASE WHEN is_active = 1 AND token_hash IS NOT NULL THEN 1 ELSE 0 END) AS active_claimed,
                SUM(CASE WHEN is_active = 1 AND token_hash IS NOT NULL AND token_secret_version = 'primary' THEN 1 ELSE 0 END) AS primary_tokens,
                SUM(CASE WHEN is_active = 1 AND token_hash IS NOT NULL AND token_secret_version = 'previous' THEN 1 ELSE 0 END) AS previous_tokens,
                SUM(CASE WHEN is_active = 1 AND token_hash IS NOT NULL AND token_secret_version IS NULL THEN 1 ELSE 0 END) AS unknown_tokens,
                SUM(CASE WHEN is_active = 1 AND token_hash IS NULL AND claim_hash IS NOT NULL THEN 1 ELSE 0 END) AS pending_claims,
                SUM(CASE WHEN is_active = 1 AND token_hash IS NULL AND claim_hash IS NOT NULL AND claim_secret_version = 'primary' THEN 1 ELSE 0 END) AS primary_claims,
                SUM(CASE WHEN is_active = 1 AND token_hash IS NULL AND claim_hash IS NOT NULL AND claim_secret_version = 'previous' THEN 1 ELSE 0 END) AS previous_claims
             FROM chat_agents"
        )->fetch();

        return [
            'active_claimed' => (int) $row['active_claimed'],
            'primary_tokens' => (int) $row['primary_tokens'],
            'previous_tokens' => (int) $row['previous_tokens'],
            'unknown_tokens' => (int) $row['unknown_tokens'],
            'pending_claims' => (int) $row['pending_claims'],
            'primary_claims' => (int) $row['primary_claims'],
            'previous_claims' => (int) $row['previous_claims'],
            'previous_secret_enabled' => Db::hasPreviousSecret(),
        ];
    }

    private function participants()
    {
        $agents = $this->agents(true);
        $participants = [];
        foreach ($agents as $agent) {
            $participants[$agent['id']] = [
                'name' => $agent['project_name'],
                'message_count' => 0,
                'sent_direct_count' => 0,
                'received_direct_count' => 0,
                'last_message_at' => null,
                'color_seed' => substr(sha1($agent['project_name']), 0, 6),
            ];
        }

        $sent = $this->pdo->query(
            'SELECT sender_agent_id, COUNT(*) AS message_count, MAX(message_timestamp) AS last_message_at
             FROM chat_entries WHERE deleted_at IS NULL GROUP BY sender_agent_id'
        )->fetchAll();
        foreach ($sent as $row) {
            if (!isset($participants[$row['sender_agent_id']])) {
                continue;
            }
            $participants[$row['sender_agent_id']]['message_count'] = (int) $row['message_count'];
            $participants[$row['sender_agent_id']]['last_message_at'] = $row['last_message_at'];
        }

        $directSent = $this->pdo->query(
            'SELECT e.sender_agent_id, COUNT(DISTINCT e.id) AS sent_direct_count
             FROM chat_entries e JOIN chat_entry_recipients r ON r.entry_id = e.id
             WHERE e.deleted_at IS NULL GROUP BY e.sender_agent_id'
        )->fetchAll();
        foreach ($directSent as $row) {
            if (isset($participants[$row['sender_agent_id']])) {
                $participants[$row['sender_agent_id']]['sent_direct_count'] = (int) $row['sent_direct_count'];
            }
        }

        $received = $this->pdo->query(
            'SELECT r.target_agent_id, COUNT(*) AS received_direct_count
             FROM chat_entry_recipients r JOIN chat_entries e ON e.id = r.entry_id
             WHERE e.deleted_at IS NULL GROUP BY r.target_agent_id'
        )->fetchAll();
        foreach ($received as $row) {
            if (isset($participants[$row['target_agent_id']])) {
                $participants[$row['target_agent_id']]['received_direct_count'] = (int) $row['received_direct_count'];
            }
        }

        usort($participants, function ($left, $right) {
            if ($left['message_count'] === $right['message_count']) {
                return strcmp($left['name'], $right['name']);
            }

            if ($right['message_count'] > $left['message_count']) {
                return 1;
            }

            return -1;
        });

        return array_values($participants);
    }

    private function lastUpdated()
    {
        $value = $this->pdo->query(
            "SELECT GREATEST(
                COALESCE((SELECT MAX(updated_at) FROM chat_entries), '1970-01-01 00:00:00'),
                COALESCE((SELECT MAX(updated_at) FROM chat_agents), '1970-01-01 00:00:00'),
                COALESCE((SELECT MAX(updated_at) FROM chat_topics), '1970-01-01 00:00:00')
            )"
        )->fetchColumn();

        return $value ?: Db::now();
    }

    private function upsertAgent($name, $description, $sourceOrder, $active)
    {
        $now = Db::now();
        $statement = $this->pdo->prepare(
            'INSERT INTO chat_agents (project_name, description, source_order, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), source_order = VALUES(source_order), is_active = VALUES(is_active), updated_at = VALUES(updated_at)'
        );
        $statement->execute([$name, $description, $sourceOrder, $active ? 1 : 0, $now, $now]);

        return $this->findAgentId($name);
    }

    private function findAgentId($name, $activeOnly = false)
    {
        $sql = 'SELECT id FROM chat_agents WHERE project_name = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$name]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function agentByProjectName($name, $activeOnly = false)
    {
        $sql = 'SELECT * FROM chat_agents WHERE project_name = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$name]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function ensureClaimColumns()
    {
        if (!Db::columnExists($this->pdo, 'chat_agents', 'claim_prefix')) {
            $this->pdo->exec('ALTER TABLE chat_agents ADD claim_prefix VARCHAR(24) NULL UNIQUE AFTER token_hash');
        }
        if (!Db::columnExists($this->pdo, 'chat_agents', 'claim_hash')) {
            $this->pdo->exec('ALTER TABLE chat_agents ADD claim_hash CHAR(64) NULL AFTER claim_prefix');
        }
        if (!Db::columnExists($this->pdo, 'chat_agents', 'claimed_at')) {
            $this->pdo->exec('ALTER TABLE chat_agents ADD claimed_at DATETIME NULL AFTER last_used_at');
        }
    }

    private function ensureCredentialVersionColumns()
    {
        if (!Db::columnExists($this->pdo, 'chat_agents', 'token_secret_version')) {
            $this->pdo->exec('ALTER TABLE chat_agents ADD token_secret_version VARCHAR(24) NULL AFTER token_hash');
            $this->pdo->exec("UPDATE chat_agents SET token_secret_version = 'previous' WHERE token_hash IS NOT NULL");
        }
        if (!Db::columnExists($this->pdo, 'chat_agents', 'claim_secret_version')) {
            $this->pdo->exec('ALTER TABLE chat_agents ADD claim_secret_version VARCHAR(24) NULL AFTER claim_hash');
            $this->pdo->exec("UPDATE chat_agents SET claim_secret_version = 'previous' WHERE claim_hash IS NOT NULL");
        }
    }

    private function makeSecret($prefix, $projectName)
    {
        $safeName = preg_replace('/[^A-Za-z0-9]+/', '', $projectName);
        if ($safeName === '') {
            $safeName = 'agent';
        }

        return $prefix . '_' . substr($safeName, 0, 20) . '_' . bin2hex($this->randomBytes(24));
    }

    private function upsertTopic($body, $hash, $now)
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO chat_topics (body, source_hash, is_active, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE body = VALUES(body), is_active = 1, updated_at = VALUES(updated_at), deleted_at = NULL'
        );
        $statement->execute([$body, $hash, $now, $now]);
    }

    private function sourceHash(array $message)
    {
        return hash('sha256', implode('|', [
            isset($message['timestamp']) ? $message['timestamp'] : '',
            isset($message['sender']) ? $message['sender'] : '',
            isset($message['target']) ? $message['target'] : '',
            isset($message['body']) ? $message['body'] : '',
        ]));
    }

    private function findEntryIdByHash($hash)
    {
        $statement = $this->pdo->prepare('SELECT id FROM chat_entries WHERE source_hash = ?');
        $statement->execute([$hash]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function insertEntry($senderId, array $message, $sourceHash, $now)
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO chat_entries (entry_uuid, sender_agent_id, message_timestamp, body, source_line, source_order, source_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $this->uuid(),
            $senderId,
            $message['timestamp'],
            $message['body'],
            isset($message['source_line']) ? $message['source_line'] : null,
            isset($message['source_order']) ? $message['source_order'] : null,
            $sourceHash,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertRecipient($entryId, $targetId, $now)
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO chat_entry_recipients (entry_id, target_agent_id, created_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$entryId, $targetId, $now]);

        return $statement->rowCount() > 0;
    }

    private function targetsForEntry($entryId)
    {
        $statement = $this->pdo->prepare(
            'SELECT a.project_name FROM chat_entry_recipients r JOIN chat_agents a ON a.id = r.target_agent_id WHERE r.entry_id = ? ORDER BY a.source_order IS NULL, a.source_order ASC, a.project_name ASC'
        );
        $statement->execute([$entryId]);

        return array_values(array_map(function ($row) {
            return $row['project_name'];
        }, $statement->fetchAll()));
    }

    private function rawEntry($entryId)
    {
        $statement = $this->pdo->prepare(
            'SELECT e.*, a.project_name AS sender_name FROM chat_entries e JOIN chat_agents a ON a.id = e.sender_agent_id WHERE e.id = ? AND e.deleted_at IS NULL'
        );
        $statement->execute([(int) $entryId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function rawTopic($topicId)
    {
        $statement = $this->pdo->prepare('SELECT * FROM chat_topics WHERE id = ? AND deleted_at IS NULL');
        $statement->execute([(int) $topicId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function splitTargetNames($target)
    {
        $parts = preg_split('/\s*(?:\/|,|;|\+|&|\band\b)\s*/i', trim((string) $target)) ?: [];
        $seen = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $seen[$part] = true;
            }
        }

        return array_keys($seen);
    }

    private function makeExcerpt($text, $maxLength = 180)
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text));
        if ($normalized === null) {
            $normalized = '';
        }
        if (mb_strlen($normalized) <= $maxLength) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $maxLength - 1)) . '…';
    }

    private function uuid()
    {
        $data = $this->randomBytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function randomBytes($length)
    {
        if (function_exists('random_bytes')) {
            return random_bytes($length);
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($bytes !== false && strlen($bytes) === $length) {
                return $bytes;
            }
        }

        throw new RuntimeException('Secure random byte generation is unavailable.');
    }

    private function audit($agent, $action, $success, $message)
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO chat_write_audit (agent_id, action, success, ip_address, user_agent, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                isset($agent['id']) ? $agent['id'] : null,
                $action,
                $success ? 1 : 0,
                isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
                isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                $message,
                Db::now(),
            ]);
        } catch (Exception $exception) {
            // Audit failure must not mask the original write result.
        }
    }
}
