<?php

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/ProjectManagementService.php';
require_once __DIR__ . '/DiscussionProviderRegistry.php';
require_once __DIR__ . '/WorkspaceAgentTriggerService.php';
require_once __DIR__ . '/ResponsesApiActivationService.php';
require_once __DIR__ . '/McpServiceTokenService.php';

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
        $provider = strtolower(trim((string) (array_key_exists('provider', $input)
            ? $input['provider'] : ($existing ? $existing['runtime_type'] : 'codex'))));
        $registry = new DiscussionProviderRegistry();
        $definition = $registry->definition($provider);
        $requestedEnabled = filter_var(array_key_exists('enabled', $input)
            ? $input['enabled'] : ($existing ? (bool) $existing['enabled'] : false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($requestedEnabled === null) { throw new InvalidArgumentException('Activation enabled must be boolean.'); }
        $legacyWorkspaceInput = array_key_exists('workspace_agent_access_token', $input)
            || preg_match('/^agtch_/', trim((string) (isset($input['discussion_reference']) ? $input['discussion_reference'] : '')));
        $existingChatGptDriver = $existing && in_array((isset($existing['activation_driver']) ? $existing['activation_driver'] : ''), ['responses_api', 'workspace_agent'], true)
            ? $existing['activation_driver'] : null;
        $activationDriver = $provider === 'gemini'
            ? 'browser_companion'
            : ($provider === 'chatgpt'
            ? strtolower(trim((string) (array_key_exists('activation_driver', $input)
                ? $input['activation_driver']
                : ($legacyWorkspaceInput ? 'workspace_agent' : ($existingChatGptDriver ?: 'browser_companion')))))
            : 'connector');
        if ($provider === 'chatgpt' && !in_array($activationDriver, ['browser_companion', 'responses_api', 'workspace_agent'], true)) {
            throw new InvalidArgumentException('The selected ChatGPT activation method is not supported.');
        }
        if ($provider === 'chatgpt' && $requestedEnabled && $activationDriver !== 'browser_companion') {
            throw new InvalidArgumentException('Responses API and Workspace Agent activation are disabled. Use the Syndicatum browser companion.');
        }
        if ($provider === 'chatgpt' && $activationDriver === 'responses_api') {
            $conversationId = 'responses_api';
        } elseif (array_key_exists('discussion_reference', $input)) {
            $reference = trim((string) $input['discussion_reference']);
            $conversationId = $reference === '' ? '' : $registry->normalize($provider, $reference)['discussion_id'];
        } else {
            $conversationId = array_key_exists('conversation_id', $input)
                ? trim((string) $input['conversation_id'])
                : ($existing ? $existing['conversation_id'] : '');
            if ($conversationId !== '') { $registry->fromStoredId($provider, $conversationId); }
        }
        $workingDirectory = array_key_exists('working_directory', $input)
            ? trim((string) $input['working_directory'])
            : ($existing ? $existing['working_directory'] : '');
        if (empty($definition['proactive_activation'])) {
            $conversationId = '';
            $workingDirectory = '';
        } elseif (empty($definition['working_directory_supported'])) {
            $workingDirectory = '';
        }
        if ($provider === 'chatgpt' && $activationDriver === 'responses_api') {
            $enabled = filter_var(array_key_exists('enabled', $input) ? $input['enabled'] : ($existing ? (bool) $existing['enabled'] : false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) { throw new InvalidArgumentException('Activation enabled must be boolean.'); }
            if (!$enabled && !array_key_exists('activation_driver', $input) && !array_key_exists('responses_api_key', $input)) { $conversationId = ''; }
            $workingDirectory = '';
        } else {
            $normalized = $this->validateConfigurationInput([
                'enabled' => array_key_exists('enabled', $input) ? $input['enabled'] : ($existing ? (bool) $existing['enabled'] : false),
                'provider' => $provider, 'conversation_id' => $conversationId, 'working_directory' => $workingDirectory,
            ]);
            $enabled = $normalized['enabled']; $conversationId = $normalized['conversation_id']; $workingDirectory = $normalized['working_directory'];
        }
        $workspaceAgentTriggerId = $provider === 'chatgpt' && $activationDriver === 'workspace_agent' ? $conversationId : null;
        $conversationKey = $provider === 'chatgpt' && $activationDriver === 'workspace_agent'
            ? trim((string) (array_key_exists('workspace_agent_conversation_key', $input)
                ? $input['workspace_agent_conversation_key']
                : ($existing && isset($existing['workspace_agent_conversation_key']) ? $existing['workspace_agent_conversation_key'] : '')))
            : null;
        if ($provider === 'chatgpt' && $activationDriver === 'workspace_agent' && $conversationKey === '') {
            $conversationKey = 'syndicatum:project:' . (int) $projectId . ':agent:' . (int) $agentId;
        }
        if ($conversationKey !== null && (strlen($conversationKey) > 255 || preg_match('/[\x00-\x1F\x7F]/', $conversationKey))) {
            throw new InvalidArgumentException('Workspace Agent conversation key is invalid.');
        }
        $tokenEncrypted = $existing && isset($existing['workspace_agent_token_encrypted'])
            ? $existing['workspace_agent_token_encrypted'] : null;
        if ($provider === 'chatgpt' && $activationDriver === 'workspace_agent' && array_key_exists('workspace_agent_access_token', $input)
            && trim((string) $input['workspace_agent_access_token']) !== '') {
            $tokenEncrypted = (new WorkspaceAgentTriggerService($this->pdo))->encryptAccessToken($input['workspace_agent_access_token']);
        }
        if ($provider !== 'chatgpt' || $activationDriver !== 'workspace_agent') { $tokenEncrypted = null; }
        if ($enabled && $provider === 'chatgpt' && $activationDriver === 'workspace_agent' && $tokenEncrypted === null) {
            throw new InvalidArgumentException('A Workspace Agent access token is required when activation is enabled.');
        }

        $responsesModel = $provider === 'chatgpt' && $activationDriver === 'responses_api'
            ? trim((string) (array_key_exists('responses_model', $input) ? $input['responses_model'] : (isset($existing['responses_model']) ? $existing['responses_model'] : 'gpt-5.6-terra'))) : null;
        if ($responsesModel !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/', $responsesModel)) {
            throw new InvalidArgumentException('The OpenAI model name is invalid.');
        }
        $responses = new ResponsesApiActivationService($this->pdo);
        $responsesApiKeyEncrypted = isset($existing['responses_api_key_encrypted']) ? $existing['responses_api_key_encrypted'] : null;
        if ($provider === 'chatgpt' && $activationDriver === 'responses_api' && !empty(trim((string) (isset($input['responses_api_key']) ? $input['responses_api_key'] : '')))) {
            $responsesApiKeyEncrypted = $responses->encryptSecret($input['responses_api_key']);
        }
        $responsesMcpTokenEncrypted = isset($existing['responses_mcp_token_encrypted']) ? $existing['responses_mcp_token_encrypted'] : null;
        if ($provider === 'chatgpt' && $activationDriver === 'responses_api' && $responsesMcpTokenEncrypted === null) {
            $serviceToken = (new McpServiceTokenService($this->pdo))->issue($projectId, $agentId, $actorUserId);
            $responsesMcpTokenEncrypted = $responses->encryptSecret($serviceToken);
        }
        if ($provider !== 'chatgpt' || $activationDriver !== 'responses_api') {
            $responsesModel = null; $responsesApiKeyEncrypted = null; $responsesMcpTokenEncrypted = null;
        }
        if ($enabled && $provider === 'chatgpt' && $activationDriver === 'responses_api' && $responsesApiKeyEncrypted === null) {
            throw new InvalidArgumentException('An OpenAI Platform API key is required when activation is enabled.');
        }

        $now = Db::now();
        $statement = $this->pdo->prepare(
            "INSERT INTO agent_activation_bindings
             (agent_id, project_id, runtime_type, activation_driver, conversation_id, working_directory,
              workspace_agent_trigger_id, workspace_agent_conversation_key, workspace_agent_token_encrypted,
              responses_model, responses_api_key_encrypted, responses_mcp_token_encrypted,
              enabled, created_by_user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE runtime_type = VALUES(runtime_type), activation_driver = VALUES(activation_driver), conversation_id = VALUES(conversation_id), working_directory = VALUES(working_directory),
               workspace_agent_trigger_id = VALUES(workspace_agent_trigger_id),
               workspace_agent_conversation_key = VALUES(workspace_agent_conversation_key),
               workspace_agent_token_encrypted = VALUES(workspace_agent_token_encrypted),
               responses_model = VALUES(responses_model), responses_api_key_encrypted = VALUES(responses_api_key_encrypted),
               responses_mcp_token_encrypted = VALUES(responses_mcp_token_encrypted),
               enabled = VALUES(enabled), created_by_user_id = VALUES(created_by_user_id), updated_at = VALUES(updated_at)"
        );
        $statement->execute([(int) $agentId, (int) $projectId, $provider, $activationDriver, $conversationId, $workingDirectory,
            $workspaceAgentTriggerId, $conversationKey, $tokenEncrypted,
            $responsesModel, $responsesApiKeyEncrypted, $responsesMcpTokenEncrypted,
            $enabled ? 1 : 0, (int) $actorUserId, $now, $now]);
        $this->auth->audit((int) $actorUserId, 'project.agent_activation_configured', 'agent', (string) ((int) $agentId), [
            'project_id' => (int) $projectId,
            'provider' => $provider,
            'enabled' => $enabled,
            'conversation_configured' => $conversationId !== '',
            'working_directory_configured' => $workingDirectory !== '',
            'workspace_agent_token_configured' => $tokenEncrypted !== null,
            'activation_driver' => $activationDriver,
            'responses_api_key_configured' => $responsesApiKeyEncrypted !== null,
        ]);
        return $this->binding($projectId, $agentId);
    }

    public function validateConfigurationInput(array $input)
    {
        $provider = strtolower(trim(isset($input['provider']) ? (string) $input['provider'] : 'codex'));
        $registry = new DiscussionProviderRegistry();
        $definition = $registry->definition($provider);
        $reference = array_key_exists('discussion_reference', $input) ? trim((string) $input['discussion_reference']) : null;
        $conversationId = $reference !== null
            ? ($reference === '' ? '' : $registry->normalize($provider, $reference)['discussion_id'])
            : trim(isset($input['conversation_id']) ? (string) $input['conversation_id'] : '');
        if ($conversationId !== '') { $registry->fromStoredId($provider, $conversationId); }
        $workingDirectory = trim(isset($input['working_directory']) ? (string) $input['working_directory'] : '');
        $enabled = filter_var(isset($input['enabled']) ? $input['enabled'] : false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) { throw new InvalidArgumentException('Activation enabled must be boolean.'); }
        if (empty($definition['proactive_activation'])) {
            if ($provider === 'chatgpt') {
                if ($enabled) { throw new InvalidArgumentException('ChatGPT proactive activation is currently unavailable. Use the ChatGPT MCP plugin interactively.'); }
                if ($conversationId === '') { throw new InvalidArgumentException('A ChatGPT discussion URL is required.'); }
            } else {
                $conversationId = '';
            }
            $enabled = false;
            $workingDirectory = '';
        } elseif (empty($definition['working_directory_supported'])) {
            $workingDirectory = '';
        }
        if ($provider === 'codex') { $this->validateConversationId($conversationId); }
        $this->validateWorkingDirectory($workingDirectory);
        if ($provider === 'chatgpt' && $conversationId === '') { throw new InvalidArgumentException('A ChatGPT discussion URL is required.'); }
        if ($provider === 'gemini' && $conversationId === '') { throw new InvalidArgumentException('A Gemini discussion URL is required.'); }
        if ($enabled && $conversationId === '') { throw new InvalidArgumentException('A provider discussion reference is required when activation is enabled.'); }
        return ['enabled' => $enabled, 'provider' => $provider, 'conversation_id' => $conversationId, 'working_directory' => $workingDirectory];
    }

    private function binding($projectId, $agentId)
    {
        $row = $this->row($projectId, $agentId);
        $provider = $row ? $row['runtime_type'] : 'codex';
        $reference = '';
        if ($row && $row['conversation_id'] !== '') {
            try { $reference = (new DiscussionProviderRegistry())->fromStoredId($provider, $row['conversation_id'])['canonical_reference']; }
            catch (Exception $ignored) { $reference = ''; }
        }
        return [
            'project_id' => (int) $projectId,
            'agent_id' => (int) $agentId,
            'runtime_type' => $provider,
            'provider' => $provider,
            'discussion_reference' => $reference,
            'conversation_id' => $row ? $row['conversation_id'] : '',
            'working_directory' => $row ? $row['working_directory'] : '',
            'enabled' => $row ? (bool) $row['enabled'] : false,
            'configured' => $row && (bool) $row['enabled'],
            'updated_at' => $row ? $row['updated_at'] : null,
            'workspace_agent_conversation_key' => $row && isset($row['workspace_agent_conversation_key']) ? $row['workspace_agent_conversation_key'] : '',
            'workspace_agent_token_configured' => $row && !empty($row['workspace_agent_token_encrypted']),
            'activation_driver' => $row && !empty($row['activation_driver']) ? $row['activation_driver'] : (in_array($provider, ['chatgpt', 'gemini'], true) ? 'browser_companion' : 'connector'),
            'responses_model' => $row && !empty($row['responses_model']) ? $row['responses_model'] : 'gpt-5.6-terra',
            'responses_api_key_configured' => $row && !empty($row['responses_api_key_encrypted']),
            'last_success_at' => $row && ((isset($row['activation_driver']) ? $row['activation_driver'] : '') === 'responses_api') ? (isset($row['responses_last_success_at']) ? $row['responses_last_success_at'] : null) : (isset($row['workspace_agent_last_success_at']) ? $row['workspace_agent_last_success_at'] : null),
            'last_failure_at' => $row && ((isset($row['activation_driver']) ? $row['activation_driver'] : '') === 'responses_api') ? (isset($row['responses_last_failure_at']) ? $row['responses_last_failure_at'] : null) : (isset($row['workspace_agent_last_failure_at']) ? $row['workspace_agent_last_failure_at'] : null),
            'last_error' => $row && ((isset($row['activation_driver']) ? $row['activation_driver'] : '') === 'responses_api') ? (isset($row['responses_last_error']) ? $row['responses_last_error'] : null) : (isset($row['workspace_agent_last_error']) ? $row['workspace_agent_last_error'] : null),
        ];
    }

    private function row($projectId, $agentId)
    {
        if (!Db::tableExists($this->pdo, 'agent_activation_bindings')) { return false; }
        $columns = 'runtime_type, conversation_id, working_directory, enabled, updated_at';
        if (Db::columnExists($this->pdo, 'agent_activation_bindings', 'workspace_agent_trigger_id')) {
            $columns .= ', workspace_agent_trigger_id, workspace_agent_conversation_key, workspace_agent_token_encrypted,
                workspace_agent_last_success_at, workspace_agent_last_failure_at, workspace_agent_last_error';
        }
        if (Db::columnExists($this->pdo, 'agent_activation_bindings', 'activation_driver')) {
            $columns .= ', activation_driver, responses_model, responses_api_key_encrypted, responses_mcp_token_encrypted,
                responses_last_response_id, responses_last_success_at, responses_last_failure_at, responses_last_error';
        }
        $statement = $this->pdo->prepare(
            'SELECT ' . $columns . '
             FROM agent_activation_bindings WHERE project_id = ? AND agent_id = ? LIMIT 1'
        );
        $statement->execute([(int) $projectId, (int) $agentId]);
        return $statement->fetch();
    }

    private function disableUnavailableChatGptBinding($projectId, $agentId, $actorUserId, $existing, $discussionUrl)
    {
        $now = Db::now();
        if ($existing && (isset($existing['runtime_type']) ? $existing['runtime_type'] : '') === 'chatgpt') {
            $statement = $this->pdo->prepare(
                'UPDATE agent_activation_bindings SET conversation_id = ?, enabled = 0, updated_at = ? WHERE project_id = ? AND agent_id = ?'
            );
            $statement->execute([$discussionUrl, $now, (int) $projectId, (int) $agentId]);
        } else {
            $statement = $this->pdo->prepare(
                "INSERT INTO agent_activation_bindings
                 (agent_id, project_id, runtime_type, activation_driver, conversation_id, working_directory,
                  workspace_agent_trigger_id, workspace_agent_conversation_key, workspace_agent_token_encrypted,
                  responses_model, responses_api_key_encrypted, responses_mcp_token_encrypted,
                  enabled, created_by_user_id, created_at, updated_at)
                 VALUES (?, ?, 'chatgpt', 'responses_api', ?, '', NULL, NULL, NULL, NULL, NULL, NULL, 0, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE runtime_type = 'chatgpt', activation_driver = 'responses_api',
                   conversation_id = '', working_directory = '', workspace_agent_trigger_id = NULL,
                   workspace_agent_conversation_key = NULL, workspace_agent_token_encrypted = NULL,
                   responses_model = NULL, responses_api_key_encrypted = NULL, responses_mcp_token_encrypted = NULL,
                   enabled = 0, updated_at = VALUES(updated_at)"
            );
            $statement->execute([(int) $agentId, (int) $projectId, $discussionUrl, (int) $actorUserId, $now, $now]);
        }
        $this->auth->audit((int) $actorUserId, 'project.agent_activation_configured', 'agent', (string) ((int) $agentId), [
            'project_id' => (int) $projectId,
            'provider' => 'chatgpt',
            'enabled' => false,
            'activation_available' => false,
        ]);
        return $this->binding($projectId, $agentId);
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
