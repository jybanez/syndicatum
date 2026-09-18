<?php

require_once dirname(__DIR__) . '/src/McpConnectionHealth.php';

$checks = 0;
function assertConnectionHealth($condition, $description)
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($description); }
}
function diagnosis($binding, $access, $context)
{
    return ['result' => ['structuredContent' => ['result' => [
        'checks' => ['authentication_valid' => true, 'project_access_valid' => $access],
        'binding_health' => ['state' => $binding], 'context_type' => $context,
    ]]]];
}

foreach (['missing', 'invalid', 'pending', 'stale', 'unusable', 'revoked'] as $binding) {
    $health = mcpConnectionHealth(200, diagnosis($binding, false, 'none'));
    assertConnectionHealth($health['authentication'] === 'valid' && $health['binding'] === $binding
        && $health['project_access'] === false && $health['state'] === 'degraded',
        "Unbound state {$binding} was collapsed or authorized");
}
$healthy = mcpConnectionHealth(200, diagnosis('healthy', true, 'interactive'));
assertConnectionHealth($healthy['state'] === 'ok' && $healthy['binding'] === 'healthy', 'Healthy binding failed');
$service = mcpConnectionHealth(200, diagnosis('not_required', true, 'service_token'));
assertConnectionHealth($service['state'] === 'ok' && $service['binding'] === 'not_required', 'Service token failed');
$invalid = mcpConnectionHealth(401, null);
assertConnectionHealth($invalid['authentication'] === 'invalid' && $invalid['binding'] === 'unknown'
    && $invalid['state'] === 'degraded', 'Authentication failure was collapsed into binding failure');
$forbidden = mcpConnectionHealth(403, null);
assertConnectionHealth($forbidden['authentication'] === 'unknown' && $forbidden['state'] === 'degraded',
    'Forbidden response was misreported as invalid credentials');
$unknown = mcpConnectionHealth(503, null);
assertConnectionHealth($unknown['state'] === 'unknown' && $unknown['authentication'] === 'unknown',
    'Unreachable endpoint was misclassified');
$contradiction = mcpConnectionHealth(200, diagnosis('stale', true, 'interactive'));
assertConnectionHealth($contradiction['state'] === 'unknown', 'Contradictory response was accepted');
echo "MCP connection health: {$checks} passed\n";
