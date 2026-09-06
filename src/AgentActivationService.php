<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/ProjectManagementService.php';

class AgentActivationService
{
    private $pdo;
    private $auth;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->auth = new AuthService($pdo);
    }

    public function configuration($projectId, $agentId, $actorUserId)
    {
        (new ProjectManagementService($this->pdo))->authorizeAgentManagement($projectId, $actorUserId);
        $this->requireProjectAgent($projectId, $agentId);
        return $this->binding($projectId, $agentId);
    }

    public function ownConfiguration($projectId, $agentId)
    {
        $this->requireProjectAgent($projectId, $agentId);
        return $this->binding($projectId, $agentId);
    }

    public function configure($projectId, $agentId, $actorUserId, array $input)
    {
        (new ProjectManagementService($this->pdo))->authorizeAgentManagement($projectId, $actorUserId);
        $this->requireProjectAgent($projectId, $agentId);
        $existing = $this->row($projectId, $agentId);
        $conversationId = array_key_exists('conversation_id', $input)
            ? trim((string) $input['conversation_id'])
            : ($existing ? $existing['conversation_id'] : '');
        $workingDirectory = array_key_exists('working_directory', $input)
            ? trim((string) $input['working_directory'])
            : ($existing ? $existing['working_directory'] : '');
        $normalized = $this->validateConfigurationInput([
            'enabled' => array_key_exists('enabled', $input) ? $input['enabled'] : ($existing ? (bool) $existing['enabled'] : false),
            'conversation_id' => $conversationId,
            'working_directory' => $workingDirectory,
        ]);
        $enabled = $normalized['enabled'];
        $conversationId = $normalized['conversation_id'];
        $workingDirectory = $normalized['working_directory'];

        $now = Db::now();
        $statement = $this->pdo->prepare(
            "INSERT INTO agent_activation_bindings
             (agent_id, project_id, runtime_type, conversation_id, working_directory, enabled, created_by_user_id, created_at, updated_at)
             VALUES (?, ?, 'codex', ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE conversation_id = VALUES(conversation_id), working_directory = VALUES(working_directory),
               enabled = VALUES(enabled), updated_at = VALUES(updated_at)"
        );
        $statement->execute([(int) $agentId, (int) $projectId, $conversationId, $workingDirectory,
            $enabled ? 1 : 0, (int) $actorUserId, $now, $now]);
        $this->auth->audit((int) $actorUserId, 'project.agent_activation_configured', 'agent', (string) ((int) $agentId), [
            'project_id' => (int) $projectId,
            'runtime_type' => 'codex',
            'enabled' => $enabled,
            'conversation_configured' => $conversationId !== '',
            'working_directory_configured' => $workingDirectory !== '',
        ]);
        return $this->binding($projectId, $agentId);
    }

    public function validateConfigurationInput(array $input)
    {
        $conversationId = trim(isset($input['conversation_id']) ? (string) $input['conversation_id'] : '');
        $workingDirectory = trim(isset($input['working_directory']) ? (string) $input['working_directory'] : '');
        $enabled = filter_var(isset($input['enabled']) ? $input['enabled'] : false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) { throw new InvalidArgumentException('Activation enabled must be boolean.'); }
        $this->validateConversationId($conversationId);
        $this->validateWorkingDirectory($workingDirectory);
        if ($enabled && ($conversationId === '' || $workingDirectory === '')) {
            throw new InvalidArgumentException('Conversation ID and working directory are required when activation is enabled.');
        }
        return ['enabled' => $enabled, 'conversation_id' => $conversationId, 'working_directory' => $workingDirectory];
    }

    private function binding($projectId, $agentId)
    {
        $row = $this->row($projectId, $agentId);
        return [
            'project_id' => (int) $projectId,
            'agent_id' => (int) $agentId,
            'runtime_type' => $row ? $row['runtime_type'] : 'codex',
            'conversation_id' => $row ? $row['conversation_id'] : '',
            'working_directory' => $row ? $row['working_directory'] : '',
            'enabled' => $row ? (bool) $row['enabled'] : false,
            'configured' => $row && $row['conversation_id'] !== '' && $row['working_directory'] !== '',
            'updated_at' => $row ? $row['updated_at'] : null,
        ];
    }

    private function row($projectId, $agentId)
    {
        if (!Db::tableExists($this->pdo, 'agent_activation_bindings')) { return false; }
        $statement = $this->pdo->prepare(
            'SELECT runtime_type, conversation_id, working_directory, enabled, updated_at
             FROM agent_activation_bindings WHERE project_id = ? AND agent_id = ? LIMIT 1'
        );
        $statement->execute([(int) $projectId, (int) $agentId]);
        return $statement->fetch();
    }

    private function requireProjectAgent($projectId, $agentId)
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM project_agents WHERE project_id = ? AND agent_id = ?');
        $statement->execute([(int) $projectId, (int) $agentId]);
        if ((int) $statement->fetchColumn() !== 1) { throw new RuntimeException('AGENT_NOT_FOUND'); }
    }

    private function validateConversationId($value)
    {
        if (strlen($value) > 255) { throw new InvalidArgumentException('Conversation ID is too long.'); }
        if ($value !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value)) {
            throw new InvalidArgumentException('Conversation ID contains unsupported characters.');
        }
    }

    private function validateWorkingDirectory($value)
    {
        if (strlen($value) > 1024) { throw new InvalidArgumentException('Working directory is too long.'); }
        if ($value !== '' && (preg_match('/[\x00-\x1F\x7F]/', $value)
            || !(preg_match('/^[A-Za-z]:[\\\\\/]/', $value) || strpos($value, '\\\\') === 0 || strpos($value, '/') === 0))) {
            throw new InvalidArgumentException('Working directory must be an absolute path.');
        }
    }
}
