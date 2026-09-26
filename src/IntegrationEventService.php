<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/SettingsService.php';
require_once __DIR__ . '/SystemMessageService.php';
require_once __DIR__ . '/MessageSeverity.php';

class IntegrationEventService
{
    const MAX_PAYLOAD_BYTES = 65536;
    const RATE_LIMIT_PER_MINUTE = 60;

    private $pdo;
    private $auth;
    private $settings;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
        $this->settings = new SettingsService($pdo);
    }

    public function issueCredential($projectId, $actorUserId, $integrationId, $rotate = false)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $origin = rtrim((string) $this->settings->get('general.public_origin'), '/');
        if ($origin === '') {
            throw new InvalidArgumentException('Configure the public Syndicatum URL before issuing an integration callback URL.');
        }
        $secret = $this->randomSecret();
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $connection = $this->lockedConnection($projectId, $integrationId);
            if ($connection['status'] === 'removed') { throw new RuntimeException('INTEGRATION_REMOVED'); }
            $active = $this->pdo->prepare(
                "SELECT id FROM integration_credentials WHERE integration_id = ? AND status = 'active' ORDER BY id DESC FOR UPDATE"
            );
            $active->execute([(int) $integrationId]);
            $activeIds = array_map('intval', $active->fetchAll(PDO::FETCH_COLUMN));
            if ($activeIds && !$rotate) { throw new RuntimeException('INTEGRATION_CREDENTIAL_EXISTS'); }
            if ($activeIds) {
                $this->pdo->prepare(
                    "UPDATE integration_credentials SET status = 'revoked', revoked_at = ?
                     WHERE integration_id = ? AND status = 'active'"
                )->execute([$now, (int) $integrationId]);
            }
            $insert = $this->pdo->prepare(
                "INSERT INTO integration_credentials
                 (integration_id, secret_hash, secret_prefix, status, created_by_user_id, created_at)
                 VALUES (?, ?, ?, 'active', ?, ?)"
            );
            $insert->execute([(int) $integrationId, hash('sha256', $secret), substr($secret, 0, 10),
                (int) $actorUserId, $now]);
            $credentialId = (int) $this->pdo->lastInsertId();
            $this->auth->audit((int) $actorUserId,
                $activeIds ? 'project.integration_credential_rotated' : 'project.integration_credential_issued',
                'integration_connection', (string) ((int) $integrationId),
                ['project_id' => (int) $projectId, 'credential_id' => $credentialId]);
            $this->pdo->commit();
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
        return [
            'integration_id' => (int) $integrationId,
            'credential_id' => $credentialId,
            'credential_prefix' => substr($secret, 0, 10),
            'callback_url' => $origin . '/api/v1/integration-events/'
                . rawurlencode($connection['public_id']) . '/' . rawurlencode($secret),
            'shown_once' => true,
            'created_at' => $now,
        ];
    }

    public function revokeCredential($projectId, $actorUserId, $integrationId)
    {
        $this->requireProjectAdmin($projectId, $actorUserId);
        $now = Db::now();
        $this->pdo->beginTransaction();
        try {
            $this->lockedConnection($projectId, $integrationId);
            $statement = $this->pdo->prepare(
                "UPDATE integration_credentials SET status = 'revoked', revoked_at = ?
                 WHERE integration_id = ? AND status = 'active'"
            );
            $statement->execute([$now, (int) $integrationId]);
            $revoked = $statement->rowCount() > 0;
            if ($revoked) {
                $this->auth->audit((int) $actorUserId, 'project.integration_credential_revoked',
                    'integration_connection', (string) ((int) $integrationId),
                    ['project_id' => (int) $projectId]);
            }
            $this->pdo->commit();
            return ['integration_id' => (int) $integrationId, 'revoked' => $revoked, 'revoked_at' => $revoked ? $now : null];
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $exception;
        }
    }

    public function ingest($integrationPublicId, $secret, $rawBody, $idempotencyKey = '')
    {
        $rawBody = (string) $rawBody;
        $payloadBytes = strlen($rawBody);
        $identity = $this->authenticate($integrationPublicId, $secret);
        try {
            if ($payloadBytes < 2 || $payloadBytes > self::MAX_PAYLOAD_BYTES) {
                throw new RuntimeException('INTEGRATION_PAYLOAD_REJECTED');
            }
            $payload = json_decode($rawBody, true);
            if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE || array_values($payload) === $payload) {
                throw new RuntimeException('INTEGRATION_PAYLOAD_REJECTED');
            }
            $payloadHash = hash('sha256', $rawBody);
            $idempotencyKey = trim((string) $idempotencyKey);
            if (strlen($idempotencyKey) > 160) { throw new RuntimeException('INTEGRATION_IDEMPOTENCY_INVALID'); }
            $idempotencyHash = hash('sha256', $idempotencyKey === '' ? 'payload:' . $payloadHash : 'key:' . $idempotencyKey);
            $event = $this->normalizeEvent($payload, $identity);
        } catch (Exception $exception) {
            $this->auditFailure($identity, $exception->getMessage(), $payloadBytes);
            throw $exception;
        }

        $this->pdo->beginTransaction();
        try {
            $locked = $this->pdo->prepare(
                "SELECT ic.status AS integration_status, pp.status AS participant_status, cr.status AS credential_status
                 FROM integration_connections ic
                 JOIN project_participants pp ON pp.project_id = ic.project_id AND pp.integration_id = ic.id
                 JOIN integration_credentials cr ON cr.integration_id = ic.id
                 WHERE ic.id = ? AND cr.id = ? FOR UPDATE"
            );
            $locked->execute([$identity['integration_id'], $identity['credential_id']]);
            $state = $locked->fetch(PDO::FETCH_ASSOC);
            if (!$state || $state['integration_status'] !== 'active' || $state['participant_status'] !== 'active'
                || $state['credential_status'] !== 'active') {
                throw new RuntimeException('INTEGRATION_AUTHENTICATION_FAILED');
            }
            $existing = $this->pdo->prepare(
                'SELECT id, public_id, payload_sha256, message_id, created_at
                 FROM integration_event_receipts WHERE integration_id = ? AND idempotency_key_hash = ? LIMIT 1'
            );
            $existing->execute([$identity['integration_id'], $idempotencyHash]);
            $receipt = $existing->fetch(PDO::FETCH_ASSOC);
            if ($receipt) {
                if (!hash_equals($receipt['payload_sha256'], $payloadHash)) {
                    throw new RuntimeException('INTEGRATION_IDEMPOTENCY_CONFLICT');
                }
                $this->pdo->commit();
                return $this->receiptResult($receipt, true);
            }
            $rate = $this->pdo->prepare(
                'SELECT COUNT(*) FROM integration_event_receipts
                 WHERE integration_id = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)'
            );
            $rate->execute([$identity['integration_id']]);
            if ((int) $rate->fetchColumn() >= self::RATE_LIMIT_PER_MINUTE) {
                throw new RuntimeException('INTEGRATION_RATE_LIMITED');
            }
            $receiptPublicId = $this->uuidV4();
            $now = Db::now();
            $sourceJson = json_encode($event['source'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $insert = $this->pdo->prepare(
                'INSERT INTO integration_event_receipts
                 (public_id, project_id, integration_id, credential_id, idempotency_key_hash,
                  payload_sha256, payload_bytes, event_type, severity, source_metadata_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$receiptPublicId, $identity['project_id'], $identity['integration_id'],
                $identity['credential_id'], $idempotencyHash, $payloadHash, $payloadBytes,
                $event['type'], $event['severity'], $sourceJson, $now]);
            $receiptId = (int) $this->pdo->lastInsertId();
            $eventData = [
                'subject_type' => 'integration',
                'integration_id' => $identity['integration_id'],
                'integration_public_id' => $identity['integration_public_id'],
                'integration_provider' => $identity['provider'],
                'receipt_id' => $receiptPublicId,
                'event_type' => $event['type'],
                'source' => $event['source'],
                'payload_sha256' => $payloadHash,
                'payload_bytes' => $payloadBytes,
            ];
            $access = ['project_id' => $identity['project_id'], 'participant_id' => $identity['participant_id']];
            $notificationParticipantIds = $this->notificationParticipantIds(
                $identity['project_id'], $identity['integration_id']);
            $message = (new SystemMessageService($this->pdo))->externalEvent(
                $access, $event['type'], $event['severity'], $event['body'], $eventData,
                $notificationParticipantIds
            );
            $this->pdo->prepare('UPDATE integration_event_receipts SET message_id = ? WHERE id = ?')
                ->execute([(int) $message['id'], $receiptId]);
            $this->pdo->prepare('UPDATE integration_credentials SET last_used_at = ? WHERE id = ?')
                ->execute([$now, $identity['credential_id']]);
            $this->auth->audit(null, 'integration.event_ingested', 'integration_connection',
                (string) $identity['integration_id'], [
                    'project_id' => $identity['project_id'], 'receipt_id' => $receiptPublicId,
                    'event_type' => $event['type'], 'severity' => $event['severity'],
                    'payload_bytes' => $payloadBytes,
                ]);
            $this->pdo->commit();
            return ['receipt_id' => $receiptPublicId, 'message_id' => (int) $message['id'],
                'duplicate' => false, 'created_at' => $now];
        } catch (Exception $exception) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $this->auditFailure($identity, $exception->getMessage(), $payloadBytes);
            throw $exception;
        }
    }

    private function authenticate($publicId, $secret)
    {
        $publicId = strtolower(trim((string) $publicId));
        $secret = trim((string) $secret);
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $publicId)
            || !preg_match('/^[A-Za-z0-9_-]{43}$/', $secret)) {
            throw new RuntimeException('INTEGRATION_AUTHENTICATION_FAILED');
        }
        $statement = $this->pdo->prepare(
            "SELECT ic.id AS integration_id, ic.public_id AS integration_public_id, ic.project_id,
                    ic.provider, ic.display_name, ic.status AS integration_status,
                    pp.id AS participant_id, pp.status AS participant_status,
                    cr.id AS credential_id, cr.secret_hash, cr.status AS credential_status
             FROM integration_connections ic
             JOIN project_participants pp ON pp.project_id = ic.project_id AND pp.integration_id = ic.id
             JOIN integration_credentials cr ON cr.integration_id = ic.id AND cr.status = 'active'
             WHERE ic.public_id = ? ORDER BY cr.id DESC"
        );
        $statement->execute([$publicId]);
        $candidate = null;
        $known = null;
        $presentedHash = hash('sha256', $secret);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($known === null) { $known = $row; }
            if (hash_equals($row['secret_hash'], $presentedHash)) { $candidate = $row; }
        }
        if (!$candidate || $candidate['integration_status'] !== 'active'
            || $candidate['participant_status'] !== 'active' || $candidate['credential_status'] !== 'active') {
            $auditIdentity = $candidate ?: $known;
            if ($auditIdentity) { $this->auditFailure($auditIdentity, 'INTEGRATION_AUTHENTICATION_FAILED', 0); }
            throw new RuntimeException('INTEGRATION_AUTHENTICATION_FAILED');
        }
        return [
            'integration_id' => (int) $candidate['integration_id'],
            'integration_public_id' => $candidate['integration_public_id'],
            'project_id' => (int) $candidate['project_id'],
            'participant_id' => (int) $candidate['participant_id'],
            'credential_id' => (int) $candidate['credential_id'],
            'provider' => $candidate['provider'],
            'display_name' => $candidate['display_name'],
        ];
    }

    private function normalizeEvent(array $payload, array $identity)
    {
        $allowedTypes = ['event', 'alert', 'status', 'build', 'deployment', 'incident', 'security', 'monitoring', 'source_control'];
        $type = isset($payload['event_type']) && is_string($payload['event_type'])
            ? strtolower(trim($payload['event_type'])) : 'event';
        if (!in_array($type, $allowedTypes, true)) { throw new RuntimeException('INTEGRATION_EVENT_TYPE_REJECTED'); }
        $severity = isset($payload['severity']) && is_string($payload['severity'])
            ? strtolower(trim($payload['severity'])) : 'info';
        if (!in_array($severity, MessageSeverity::VALUES, true)) {
            throw new RuntimeException('INTEGRATION_SEVERITY_REJECTED');
        }
        $title = isset($payload['title']) && is_string($payload['title']) ? trim($payload['title']) : '';
        $message = isset($payload['message']) && is_string($payload['message']) ? trim($payload['message']) : '';
        if ($title !== '' && strlen($title) > 200) { throw new RuntimeException('INTEGRATION_PAYLOAD_REJECTED'); }
        if ($message !== '' && strlen($message) > 4000) { throw new RuntimeException('INTEGRATION_PAYLOAD_REJECTED'); }
        $body = $title !== '' ? $title : $identity['display_name'] . ' sent an external event.';
        if ($message !== '') { $body .= "\n\n" . $message; }
        $source = [];
        if (isset($payload['source']) && is_array($payload['source'])) {
            foreach (['event_id', 'resource_type', 'resource_id', 'name', 'url'] as $key) {
                if (!isset($payload['source'][$key]) || !is_scalar($payload['source'][$key])) { continue; }
                $value = trim((string) $payload['source'][$key]);
                if ($value !== '') { $source[$key] = substr($value, 0, $key === 'url' ? 1000 : 255); }
            }
        }
        return ['type' => $type, 'severity' => $severity, 'body' => $body, 'source' => $source];
    }

    private function notificationParticipantIds($projectId, $integrationId)
    {
        $statement = $this->pdo->prepare(
            "SELECT recipient.participant_id
             FROM integration_notification_recipients recipient
             JOIN project_participants participant ON participant.id = recipient.participant_id
                 AND participant.project_id = recipient.project_id
             WHERE recipient.project_id = ? AND recipient.integration_id = ?
               AND participant.status = 'active' AND participant.kind IN ('human', 'agent')
             ORDER BY recipient.participant_id"
        );
        $statement->execute([(int) $projectId, (int) $integrationId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function receiptResult(array $receipt, $duplicate)
    {
        return ['receipt_id' => $receipt['public_id'], 'message_id' => (int) $receipt['message_id'],
            'duplicate' => (bool) $duplicate, 'created_at' => $receipt['created_at']];
    }

    private function lockedConnection($projectId, $integrationId)
    {
        $statement = $this->pdo->prepare(
            'SELECT id, public_id, status FROM integration_connections WHERE project_id = ? AND id = ? FOR UPDATE'
        );
        $statement->execute([(int) $projectId, (int) $integrationId]);
        $connection = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$connection) { throw new RuntimeException('INTEGRATION_NOT_FOUND'); }
        return $connection;
    }

    private function requireProjectAdmin($projectId, $userId)
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM project_members WHERE project_id = ? AND user_id = ?
             AND status = 'active' AND role IN ('owner', 'admin')"
        );
        $statement->execute([(int) $projectId, (int) $userId]);
        if ((int) $statement->fetchColumn() !== 1) { throw new RuntimeException('PROJECT_NOT_FOUND'); }
    }

    private function auditFailure(array $identity, $reason, $payloadBytes)
    {
        try {
            $this->auth->audit(null, 'integration.event_rejected', 'integration_connection',
                isset($identity['integration_id']) ? (string) $identity['integration_id'] : null, [
                    'project_id' => isset($identity['project_id']) ? (int) $identity['project_id'] : null,
                    'reason' => substr((string) $reason, 0, 80),
                    'payload_bytes' => (int) $payloadBytes,
                ]);
        } catch (Exception $ignored) {
            // Rejection auditing must not change the original fail-closed result.
        }
    }

    private function randomSecret()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function uuidV4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
