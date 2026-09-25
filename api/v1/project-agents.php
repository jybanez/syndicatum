<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/AgentWebhookService.php';
require_once dirname(dirname(__DIR__)) . '/src/AgentActivationService.php';

try {
    $method = Api::method();
    if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    list($pdo, $auth, $user, $service) = humanApiServices($method !== 'GET');
    $body = $method === 'GET' ? $_GET : Api::body();
    $projectId = isset($body['project_id']) ? (int) $body['project_id'] : 0;
    if ($projectId < 1) { throw new InvalidArgumentException('project_id is required.'); }
    if ($method === 'GET') {
        $agentId = isset($body['agent_id']) ? (int) $body['agent_id'] : 0;
        if ($agentId < 1) { throw new InvalidArgumentException('agent_id is required.'); }
        Api::json(['data' => $service->agentCredentialStatus($projectId, $user['id'], $agentId)]);
    }
    if ($method === 'POST') {
        $activationService = new AgentActivationService($pdo);
        $activationInput = null;
        if (array_key_exists('activation_enabled', $body) || array_key_exists('provider', $body)
            || array_key_exists('discussion_reference', $body) || array_key_exists('conversation_id', $body)
            || array_key_exists('working_directory', $body)) {
            $activationCandidate = [
                'enabled' => isset($body['activation_enabled']) ? $body['activation_enabled'] : false,
                'provider' => isset($body['provider']) ? $body['provider'] : 'codex',
                'conversation_id' => isset($body['conversation_id']) ? $body['conversation_id'] : '',
                'working_directory' => isset($body['working_directory']) ? $body['working_directory'] : '',
            ];
            if (array_key_exists('discussion_reference', $body)) { $activationCandidate['discussion_reference'] = $body['discussion_reference']; }
            if (strtolower((string) $activationCandidate['provider']) === 'chatgpt') {
                $activationService->validateConfigurationInput($activationCandidate);
                $activationInput = $activationCandidate;
                $activationInput['activation_driver'] = 'responses_api';
                $activationInput['responses_api_key'] = $body['responses_api_key'] ?? '';
                $activationInput['responses_model'] = $body['responses_model'] ?? 'gpt-5.6-terra';
            } else {
                $activationInput = $activationService->validateConfigurationInput($activationCandidate);
            }
        }
        $result = $service->createAgent($projectId, $user['id'], $body);
        if (strtolower(trim((string) (isset($body['provider']) ? $body['provider'] : ''))) === 'chatgpt') {
            unset($result['claim_code'], $result['claim_expires_at']);
        }
        if ($activationInput !== null) {
            $result['activation'] = $activationService->configure(
                $projectId,
                $result['agent_id'],
                $user['id'],
                $activationInput
            );
        }
        Api::json(['data' => $result], 201);
    }
    $agentId = isset($body['agent_id']) ? (int) $body['agent_id'] : 0;
    if ($agentId < 1) { throw new InvalidArgumentException('agent_id is required.'); }
    if ($method === 'DELETE') {
        $service->updateAgentStatus($projectId, $user['id'], $agentId, 'retired', true);
        Api::json(['data' => ['project_id' => $projectId, 'agent_id' => $agentId, 'removed' => true]]);
    }
    if (!empty($body['rotate'])) { Api::json(['data' => $service->issueAgentClaim($projectId, $user['id'], $agentId)]); }
    $result = [];
    $changed = false;
    if (array_key_exists('display_name', $body) || array_key_exists('provider', $body)
        || array_key_exists('runtime_name', $body) || array_key_exists('avatar_url', $body)
        || array_key_exists('role_title', $body) || array_key_exists('role_summary', $body)
        || array_key_exists('role_instructions', $body)
        || array_key_exists('supervising_participant_id', $body)) {
        $result = $service->updateAgentProfile($projectId, $user['id'], $agentId, $body);
        $changed = true;
    }
    if (array_key_exists('webhook_url', $body) || array_key_exists('webhook_enabled', $body)) {
        $input = [];
        if (array_key_exists('webhook_url', $body)) { $input['endpoint_url'] = $body['webhook_url']; }
        if (array_key_exists('webhook_enabled', $body)) { $input['enabled'] = $body['webhook_enabled']; }
        $webhook = (new AgentWebhookService($pdo))->configure($projectId, $agentId, $user['id'], $input);
        if (isset($webhook['signing_secret'])) {
            $webhook['webhook_signing_secret'] = $webhook['signing_secret'];
        }
        $result['webhook'] = $webhook;
        if (isset($webhook['webhook_signing_secret'])) { $result['webhook_signing_secret'] = $webhook['webhook_signing_secret']; }
        $changed = true;
    }
    // `provider` is also part of the agent profile. A profile-only PATCH must not
    // be interpreted as an activation update because the activation binding is
    // saved independently (and may otherwise be overwritten with an empty
    // conversation ID before the dedicated activation request runs).
    if (array_key_exists('activation_enabled', $body)
        || array_key_exists('discussion_reference', $body) || array_key_exists('conversation_id', $body)
        || array_key_exists('working_directory', $body)) {
        $activationInput = [
            'enabled' => isset($body['activation_enabled']) ? $body['activation_enabled'] : false,
            'provider' => isset($body['provider']) ? $body['provider'] : 'codex',
            'conversation_id' => isset($body['conversation_id']) ? $body['conversation_id'] : '',
            'working_directory' => isset($body['working_directory']) ? $body['working_directory'] : '',
        ];
        if (array_key_exists('discussion_reference', $body)) { $activationInput['discussion_reference'] = $body['discussion_reference']; }
        if (strtolower((string) $activationInput['provider']) === 'chatgpt') {
            $activationInput['activation_driver'] = $body['activation_driver'] ?? 'responses_api';
            $activationInput['responses_api_key'] = $body['responses_api_key'] ?? '';
            $activationInput['responses_model'] = $body['responses_model'] ?? 'gpt-5.6-terra';
        }
        $result['activation'] = (new AgentActivationService($pdo))->configure(
            $projectId,
            $agentId,
            $user['id'],
            $activationInput
        );
        $changed = true;
    }
    if (array_key_exists('status', $body) || array_key_exists('revoke_token', $body)) {
        $status = array_key_exists('status', $body) ? $body['status'] : null;
        $service->updateAgentStatus($projectId, $user['id'], $agentId, $status, !empty($body['revoke_token']));
        $result['project_id'] = $projectId; $result['agent_id'] = $agentId;
        if ($status !== null) { $result['status'] = $status; }
        $changed = true;
    }
    if ($changed) { Api::json(['data' => $result]); }
    throw new InvalidArgumentException('No supported agent changes were provided.');
} catch (Exception $exception) { humanApiError($exception); }
