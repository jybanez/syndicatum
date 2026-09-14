<?php

require_once __DIR__ . '/src/Api.php';
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/ChatGptOAuthService.php';
require_once __DIR__ . '/src/McpServiceTokenService.php';
require_once __DIR__ . '/src/ProjectRepository.php';
require_once __DIR__ . '/src/DiscussionBindingIntentService.php';

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
if (!$access) { $access = (new McpServiceTokenService($pdo))->authenticate($bearer); }
if (!$access) { mcpAuthenticationRequired($id); }
$name = trim((string) ($params['name'] ?? ''));
$args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
$repository = new ProjectRepository($pdo);

try {
    $scopeMap = ['diagnose_connection' => 'projects:read', 'prepare_discussion_binding' => 'projects:read',
        'list_projects' => 'projects:read', 'get_project' => 'projects:read',
        'list_participants' => 'participants:read', 'list_messages' => 'messages:read',
        'get_message' => 'messages:read', 'post_message' => 'messages:write',
        'acknowledge_message' => 'messages:acknowledge'];
    if (!isset($scopeMap[$name])) { throw new InvalidArgumentException('Unknown tool.'); }
    if (!$oauth->hasScope($access, $scopeMap[$name])) { mcpAuthenticationRequired($id, 'insufficient_scope'); }
    $bindingService = new DiscussionBindingIntentService($pdo);
    $bindingContext = isset($args['binding_context_id']) ? $bindingService->context($access, $args['binding_context_id']) : null;
    if ($bindingContext) { $access = $bindingContext; }
    if ($name === 'diagnose_connection') {
        $context = $bindingContext ? $repository->projectContext($access) : null;
        $agent = $bindingContext ? $access['identity']['agent'] : null;
        $value = [
            'status' => 'connected',
            'mcp_connection' => 'Connected',
            'summary' => $bindingContext
                ? 'This discussion is connected and successfully bound to a Syndicatum project agent.'
                : 'The MCP connection is healthy, but this discussion has not supplied a successful binding context.',
            'checks' => [
                'mcp_request_received' => true,
                'server_reachable' => true,
                'authentication_valid' => true,
                'project_access_valid' => $bindingContext !== null,
            ],
            'server' => [
                'name' => 'chatgpt@syndicatum',
                'version' => '0.3.0',
                'checked_at' => gmdate('c'),
            ],
            'project' => $bindingContext ? ['id' => (int) $context['project']['id'], 'name' => $context['project']['name'],
                'status' => $context['project']['status'], 'latest_sequence' => (int) $context['latest_sequence']] : 'Unknown',
            'agent_identity' => $bindingContext ? ['name' => $agent['display_name'],
                'agent_id' => (int) $agent['authenticated_agent_id'], 'participant_id' => (int) $access['participant_id']] : 'Unknown',
            'discussion_binding' => $bindingContext ? 'Successful' : 'Required',
            'granted_scopes' => array_values($access['scope']),
            'client_boundary' => $bindingContext
                ? 'Client execution, MCP reachability, authentication, and discussion binding authorization succeeded.'
                : 'Client execution, MCP reachability, and authentication succeeded. Project authorization remains intentionally unknown until a binding context is confirmed and supplied.',
        ];
    } elseif ($name === 'prepare_discussion_binding') {
        $value = $bindingService->prepare($access, $args['project_name'] ?? '', $args['agent_name'] ?? '');
    } elseif ($name === 'list_projects') {
        $context = $repository->projectContext($access);
        $value = [['id' => $access['project_id'], 'name' => $context['project']['name'], 'status' => $context['project']['status'], 'role' => 'agent']];
    } elseif ($name === 'get_project') {
        $value = $repository->projectContext($access);
    } elseif ($name === 'list_participants') {
        $value = $repository->participants($access, ['status' => 'active', 'kind' => trim((string) ($args['kind'] ?? ''))]);
    } elseif ($name === 'list_messages') {
        $value = $repository->messagePage($access, ['limit' => (int) ($args['limit'] ?? 50),
            'before' => trim((string) ($args['before'] ?? '')), 'after' => trim((string) ($args['after'] ?? '')),
            'q' => trim((string) ($args['query'] ?? '')), 'addressed_to_me' => !empty($args['addressed_to_me']),
            'acknowledged' => !empty($args['unacknowledged_only']) ? 'false' : '']);
    } elseif ($name === 'get_message') {
        $value = $repository->message($access, mcpPositiveId($args, 'message_id'));
    } elseif ($name === 'post_message') {
        $input = ['body' => trim((string) ($args['body'] ?? '')), 'broadcast' => !empty($args['broadcast']),
            'direct_participant_ids' => $args['direct_participant_ids'] ?? [], 'mention_participant_ids' => $args['mention_participant_ids'] ?? [],
            'reply_to_message_id' => isset($args['reply_to_message_id']) ? (int) $args['reply_to_message_id'] : null,
            'idempotency_key' => trim((string) ($args['idempotency_key'] ?? ''))];
        $value = $repository->createMessage($access, $input);
    } else {
        $value = $repository->acknowledge($access, mcpPositiveId($args, 'message_id'));
    }
    mcpResult($id, ['content' => [['type' => 'text', 'text' => json_encode($value, JSON_UNESCAPED_SLASHES)]],
        'structuredContent' => ['result' => $value], 'isError' => false]);
} catch (InvalidArgumentException $e) {
    mcpToolError($id, $e->getMessage());
} catch (Exception $e) {
    $known = ['PROJECT_NOT_FOUND', 'PROJECT_NAME_AMBIGUOUS', 'AGENT_NAME_AMBIGUOUS', 'AGENT_PROVIDER_MISMATCH',
        'BINDING_REQUIRES_OAUTH', 'MESSAGE_NOT_FOUND', 'PROJECT_WRITE_FORBIDDEN',
        'MESSAGE_NOT_ADDRESSED_TO_PARTICIPANT', 'PROJECT_ARCHIVED', 'RATE_LIMITED'];
    mcpToolError($id, in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'The Syndicatum operation could not be completed.');
}

function mcpTools()
{
    $oauth = [['type' => 'oauth2', 'scopes' => ChatGptOAuthService::SCOPES]];
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
function mcpResult($id, $result) { Api::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]); }
function mcpError($id, $code, $message) { Api::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], 400); }
function mcpToolError($id, $message) { mcpResult($id, ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true]); }
function mcpAuthenticationRequired($id, $error = 'invalid_token') {
    $challenge = 'Bearer resource_metadata="' . ChatGptOAuthService::ISSUER . '/.well-known/oauth-protected-resource", error="' . $error . '"';
    header('WWW-Authenticate: ' . $challenge);
    mcpResult($id, ['content' => [['type' => 'text', 'text' => 'Authentication is required.']], 'isError' => true,
        '_meta' => ['mcp/www_authenticate' => [$challenge]]]);
}
