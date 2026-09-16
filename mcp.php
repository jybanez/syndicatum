<?php

require_once __DIR__ . '/src/Api.php';
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/ChatGptOAuthService.php';
require_once __DIR__ . '/src/McpServiceTokenService.php';
require_once __DIR__ . '/src/ProjectRepository.php';
require_once __DIR__ . '/src/DiscussionBindingIntentService.php';
require_once __DIR__ . '/src/RateLimiter.php';

const MCP_PROTOCOL_VERSION = '2025-06-18';

if (Api::method() !== 'POST') {
    Api::json(['error' => 'method_not_allowed'], 405, ['Allow' => 'POST']);
}

$request = Api::body();
if (($request['jsonrpc'] ?? '') !== '2.0' || !isset($request['method'])) {
    mcpError($request['id'] ?? null, -32600, 'Invalid Request');
}

$id = $request['id'] ?? null;
$method = (string) $request['method'];
$params = isset($request['params']) && is_array($request['params']) ? $request['params'] : [];

if ($method === 'notifications/initialized' || $method === 'notifications/cancelled') {
    http_response_code(202); exit;
}
if ($method === 'initialize') {
    mcpResult($id, ['protocolVersion' => MCP_PROTOCOL_VERSION,
        'capabilities' => ['tools' => ['listChanged' => false]],
        'serverInfo' => ['name' => 'chatgpt@syndicatum', 'version' => '0.3.0'],
        'instructions' => 'Use Syndicatum as the authoritative shared project timeline. Read project context and recent addressed messages before responding. Post as the authorized agent only when appropriate, then acknowledge messages you handled.']);
}
if ($method === 'ping') { mcpResult($id, new stdClass()); }
if ($method === 'tools/list') { mcpResult($id, ['tools' => mcpTools()]); }
if ($method !== 'tools/call') { mcpError($id, -32601, 'Method not found'); }

$pdo = Db::pdo();
$oauth = new ChatGptOAuthService($pdo);
$bearer = Api::bearerToken();
$access = $oauth->authenticate($bearer);
$serviceTokenAccess = false;
if (!$access) {
    $access = (new McpServiceTokenService($pdo))->authenticate($bearer);
    $serviceTokenAccess = $access !== null;
}
if (!$access) {
    try {
        (new RateLimiter($pdo))->hit('mcp.unauthenticated', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 120, 60, 60);
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'RATE_LIMITED') {
            Api::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32029, 'message' => 'Too many unauthenticated requests.']], 429, ['Retry-After' => '60']);
        }
        throw $exception;
    }
    mcpAuthenticationRequired($id, 'invalid_token', $oauth);
}
$name = trim((string) ($params['name'] ?? ''));
$args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
$repository = new ProjectRepository($pdo);

try {
    $scopeMap = ['diagnose_connection' => 'projects:read', 'prepare_discussion_binding' => 'projects:read',
        'prepare_interactive_context' => 'projects:read',
        'list_projects' => 'projects:read', 'get_project' => 'projects:read',
        'list_participants' => 'participants:read', 'list_messages' => 'messages:read',
        'get_message' => 'messages:read', 'post_message' => 'messages:write',
        'acknowledge_message' => 'messages:acknowledge'];
    if (!isset($scopeMap[$name])) { throw new InvalidArgumentException('Unknown tool.'); }
    if (!$oauth->hasScope($access, $scopeMap[$name])) { mcpAuthenticationRequired($id, 'insufficient_scope', $oauth); }
    $writeTools = ['prepare_discussion_binding', 'prepare_interactive_context', 'post_message', 'acknowledge_message'];
    (new RateLimiter($pdo))->hit(
        'mcp.' . $name,
        hash('sha256', $bearer),
        in_array($name, $writeTools, true) ? 60 : 300,
        60,
        60
    );
    $bindingService = new DiscussionBindingIntentService($pdo);
    $bindingContext = isset($args['binding_context_id']) ? $bindingService->context($access, $args['binding_context_id']) : null;
    if ($bindingContext) { $access = $bindingContext; }
    if (!$bindingContext && !$serviceTokenAccess
        && !in_array($name, ['diagnose_connection', 'prepare_discussion_binding', 'prepare_interactive_context'], true)) {
        throw new RuntimeException('DISCUSSION_BINDING_REQUIRED');
    }
    if ($name === 'diagnose_connection') {
        $contextAuthorized = $bindingContext !== null || $serviceTokenAccess;
        $context = $contextAuthorized ? $repository->projectContext($access) : null;
        $agent = $contextAuthorized ? $access['identity']['agent'] : null;
        $contextType = $serviceTokenAccess ? 'service_token'
            : ($bindingContext ? ($access['binding']['type'] ?? 'discussion') : null);
        $value = [
            'status' => 'connected',
            'mcp_connection' => 'Connected',
            'summary' => $contextAuthorized
                ? ($contextType === 'service_token'
                    ? 'This MCP client has a valid project-agent service-token context.'
                    : ($contextType === 'interactive'
                    ? 'This interaction has a valid short-lived Syndicatum project-agent context.'
                    : 'This discussion is connected and successfully bound to a Syndicatum project agent.'))
                : 'The MCP connection is healthy, but this discussion has not supplied a successful binding context.',
            'checks' => [
                'mcp_request_received' => true,
                'server_reachable' => true,
                'authentication_valid' => true,
                'project_access_valid' => $contextAuthorized,
            ],
            'server' => [
                'name' => 'chatgpt@syndicatum',
                'version' => '0.3.0',
                'checked_at' => gmdate('c'),
            ],
            'project' => $contextAuthorized ? ['id' => (int) $context['project']['id'], 'name' => $context['project']['name'],
                'status' => $context['project']['status'], 'latest_sequence' => (int) $context['latest_sequence']] : 'Unknown',
            'agent_identity' => $contextAuthorized ? ['name' => $agent['display_name'],
                'agent_id' => (int) $agent['authenticated_agent_id'], 'participant_id' => (int) $access['participant_id']] : 'Unknown',
            'discussion_binding' => $serviceTokenAccess ? 'Not required'
                : ($bindingContext ? ($contextType === 'interactive' ? 'Not changed' : 'Successful') : 'Required'),
            'context_type' => $contextType ?? 'none',
            'granted_scopes' => array_values($access['scope']),
            'client_boundary' => $contextAuthorized
                ? ($serviceTokenAccess
                    ? 'Client execution, MCP reachability, service-token authentication, and project-agent authorization succeeded.'
                    : 'Client execution, MCP reachability, authentication, and discussion binding authorization succeeded.')
                : 'Client execution, MCP reachability, and authentication succeeded. Project authorization remains intentionally unknown until a binding context is confirmed and supplied.',
        ];
    } elseif ($name === 'prepare_discussion_binding') {
        $value = $bindingService->prepare($access, $args['project_name'] ?? '', $args['agent_name'] ?? '');
    } elseif ($name === 'prepare_interactive_context') {
        $value = $bindingService->prepareInteractiveContext($access, $args['project_name'] ?? '', $args['agent_name'] ?? '');
    } elseif ($name === 'list_projects') {
        $context = $repository->projectContext($access);
        $value = [['id' => $access['project_id'], 'name' => $context['project']['name'], 'status' => $context['project']['status'], 'role' => 'agent']];
    } elseif ($name === 'get_project') {
        $value = mcpProjectContext($repository->projectContext($access));
    } elseif ($name === 'list_participants') {
        $value = array_map('mcpParticipant', $repository->participants($access,
            ['status' => 'active', 'kind' => trim((string) ($args['kind'] ?? ''))]));
    } elseif ($name === 'list_messages') {
        $value = mcpMessagePage($repository->messagePage($access, ['limit' => (int) ($args['limit'] ?? 50),
            'before' => trim((string) ($args['before'] ?? '')), 'after' => trim((string) ($args['after'] ?? '')),
            'q' => trim((string) ($args['query'] ?? '')), 'addressed_to_me' => !empty($args['addressed_to_me']),
            'acknowledged' => !empty($args['unacknowledged_only']) ? 'false' : '']));
    } elseif ($name === 'get_message') {
        $value = mcpMessage($repository->message($access, mcpPositiveId($args, 'message_id')));
    } elseif ($name === 'post_message') {
        $input = ['body' => trim((string) ($args['body'] ?? '')), 'broadcast' => !empty($args['broadcast']),
            'direct_participant_ids' => $args['direct_participant_ids'] ?? [], 'mention_participant_ids' => $args['mention_participant_ids'] ?? [],
            'reply_to_message_id' => isset($args['reply_to_message_id']) ? (int) $args['reply_to_message_id'] : null,
            'idempotency_key' => trim((string) ($args['idempotency_key'] ?? ''))];
        $created = $repository->createMessage($access, $input);
        $value = ['message' => mcpMessage($created['message']), 'created' => (bool) $created['created']];
    } else {
        $value = mcpMessage($repository->acknowledge($access, mcpPositiveId($args, 'message_id')));
    }
    mcpResult($id, ['content' => [['type' => 'text', 'text' => json_encode($value, JSON_UNESCAPED_SLASHES)]],
        'structuredContent' => ['result' => $value], 'isError' => false]);
} catch (InvalidArgumentException $e) {
    mcpToolError($id, $e->getMessage());
} catch (Exception $e) {
    $known = ['PROJECT_NOT_FOUND', 'PROJECT_NAME_AMBIGUOUS', 'AGENT_NAME_AMBIGUOUS', 'AGENT_PROVIDER_MISMATCH',
        'BINDING_REQUIRES_OAUTH', 'DISCUSSION_BINDING_REQUIRED', 'MESSAGE_NOT_FOUND', 'PROJECT_WRITE_FORBIDDEN',
        'INTERACTIVE_CONTEXT_NOT_FOUND', 'INTERACTIVE_CONTEXT_AMBIGUOUS',
        'MESSAGE_NOT_ADDRESSED_TO_PARTICIPANT', 'IDEMPOTENCY_KEY_CONFLICT', 'PROJECT_ARCHIVED', 'RATE_LIMITED'];
    mcpToolError($id, in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'The Syndicatum operation could not be completed.');
}

function mcpTools()
{
    $oauth = [['type' => 'oauth2', 'scopes' => ChatGptOAuthService::OAUTH_SCOPES]];
    $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
    $write = ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false];
    $binding = ['binding_context_id' => ['type' => 'string', 'description' => 'Binding context returned for this ChatGPT discussion.']];
    $tool = function ($name, $title, $description, $properties, $required, $annotations) use ($oauth) {
        return ['name' => $name, 'title' => $title, 'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false],
            'annotations' => $annotations, 'securitySchemes' => $oauth, '_meta' => ['securitySchemes' => $oauth]];
    };
    return [
        $tool('diagnose_connection', 'Diagnose Syndicatum connection', 'Check MCP connectivity and this discussion binding. Without a valid binding_context_id, report Project: Unknown, Agent Identity: Unknown, and Discussion Binding: Required. With the context returned by a completed prepare_discussion_binding flow, report Discussion Binding: Successful.',
            ['binding_context_id' => ['type' => 'string', 'description' => 'Opaque context returned by prepare_discussion_binding for this discussion.']], [], $read),
        $tool('prepare_discussion_binding', 'Bind this ChatGPT discussion', 'Prepare a short-lived binding request for the active ChatGPT discussion. Call when the user says “Syndicatum bind <project> <agent>”. The project must exist. The named ChatGPT agent is reused on an exact name match or created only after the user presses Continue in the Syndicatum Companion confirmation. Return and retain binding_context_id for all later calls in this discussion.',
            ['project_name' => ['type' => 'string', 'minLength' => 1], 'agent_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120]], ['project_name', 'agent_name'], $write),
        $tool('prepare_interactive_context', 'Use an existing project agent for this interaction', 'Create a 15-minute context for user-initiated MCP work without the Syndicatum Companion. Call only when the user explicitly names the project and an existing ChatGPT agent. The signed-in user must be a project owner or administrator. This does not create an agent, bind a discussion, or enable proactive notifications. Return and retain binding_context_id for later calls in this interaction.',
            ['project_name' => ['type' => 'string', 'minLength' => 1], 'agent_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120]], ['project_name', 'agent_name'], $write),
        $tool('list_projects', 'List authorized projects', 'List the Syndicatum project available to this discussion binding.', $binding, [], $read),
        $tool('get_project', 'Get project context', 'Read project instructions, permissions, current participant, and latest sequence.', $binding, [], $read),
        $tool('list_participants', 'List project participants', 'List active participants so messages can use stable participant IDs.', $binding + ['kind' => ['type' => 'string', 'enum' => ['human', 'agent']]], [], $read),
        $tool('list_messages', 'Read project timeline', 'Read canonical project messages, optionally limited to messages addressed to this agent or still unacknowledged.',
            $binding + ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50], 'before' => ['type' => 'string'], 'after' => ['type' => 'string'],
                'query' => ['type' => 'string'], 'addressed_to_me' => ['type' => 'boolean'], 'unacknowledged_only' => ['type' => 'boolean']], [], $read),
        $tool('get_message', 'Get one message', 'Read one canonical Syndicatum message by its numeric ID.', $binding + ['message_id' => ['type' => 'integer', 'minimum' => 1]], ['message_id'], $read),
        $tool('post_message', 'Post a project message', 'Post or reply as the authorized Syndicatum agent. Addressees indicate expected responders, not visibility.',
            $binding + ['body' => ['type' => 'string', 'minLength' => 1], 'direct_participant_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]],
                'mention_participant_ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1]], 'broadcast' => ['type' => 'boolean'],
                'reply_to_message_id' => ['type' => 'integer', 'minimum' => 1], 'idempotency_key' => ['type' => 'string', 'maxLength' => 160]], ['body', 'idempotency_key'], $write),
        $tool('acknowledge_message', 'Acknowledge a message', 'Acknowledge a message that was addressed to the authorized agent.', $binding + ['message_id' => ['type' => 'integer', 'minimum' => 1]], ['message_id'], $write),
    ];
}

function mcpPositiveId(array $args, $name) { $id = (int) ($args[$name] ?? 0); if ($id < 1) { throw new InvalidArgumentException($name . ' must be a positive integer.'); } return $id; }
function mcpProjectContext(array $context) {
    $project = $context['project'];
    return [
        'project' => ['id' => (int) $project['id'], 'name' => $project['name'],
            'description' => $project['description'], 'instructions' => $project['instructions'], 'status' => $project['status']],
        'current_participant_id' => (int) $context['current_participant_id'],
        'current_role' => $context['current_role'],
        'permissions' => $context['permissions'],
        'latest_sequence' => (int) $context['latest_sequence'],
    ];
}
function mcpParticipant(array $participant) {
    return ['id' => (int) $participant['id'], 'kind' => $participant['kind'],
        'display_name' => $participant['display_name'], 'status' => $participant['status'],
        'role' => $participant['role'], 'provider' => $participant['provider']];
}
function mcpMessagePage(array $page) {
    $page['data'] = array_map('mcpMessage', $page['data']);
    return $page;
}
function mcpMessage(array $message) {
    $addressees = array_map(function ($addressee) {
        return ['participant_id' => (int) $addressee['participant_id'], 'kind' => $addressee['kind'],
            'display_name' => $addressee['display_name'], 'reason' => $addressee['reason'],
            'acknowledged_at' => $addressee['acknowledged_at']];
    }, $message['addressees']);
    return [
        'id' => (int) $message['id'], 'project_sequence' => (int) $message['project_sequence'],
        'sender' => ['participant_id' => (int) $message['sender']['participant_id'],
            'kind' => $message['sender']['kind'], 'display_name' => $message['sender']['display_name']],
        'reply_to_message_id' => $message['reply_to_message_id'], 'body' => $message['body'],
        'addressees' => $addressees, 'created_at' => $message['created_at'],
        'updated_at' => $message['updated_at'], 'deleted_at' => $message['deleted_at'],
        'edited' => (bool) $message['edited'], 'revision_count' => (int) $message['revision_count'],
    ];
}
function mcpResult($id, $result, $status = 200) { Api::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], $status); }
function mcpError($id, $code, $message) { Api::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], 400); }
function mcpToolError($id, $message) { mcpResult($id, ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true]); }
function mcpAuthenticationRequired($id, $error, ChatGptOAuthService $oauth) {
    $challenge = 'Bearer resource_metadata="' . $oauth->issuer() . '/.well-known/oauth-protected-resource", error="' . $error . '"';
    header('WWW-Authenticate: ' . $challenge);
    mcpResult($id, ['content' => [['type' => 'text', 'text' => 'Authentication is required.']], 'isError' => true,
        '_meta' => ['mcp/www_authenticate' => [$challenge]]], 401);
}
