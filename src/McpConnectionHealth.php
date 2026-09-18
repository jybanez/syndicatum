<?php

/** Project the authenticated MCP diagnosis into a non-secret operator status. */
function mcpConnectionHealth($httpStatus, $response)
{
    $base = [
        'state' => 'unknown',
        'authentication' => 'unknown',
        'binding' => 'unknown',
        'project_access' => null,
        'context_type' => 'unknown',
        'attention' => true,
    ];
    if ($httpStatus === 401) {
        $base['state'] = 'degraded';
        $base['authentication'] = 'invalid';
        return $base;
    }
    if ($httpStatus === 403) {
        $base['state'] = 'degraded';
        return $base;
    }
    if ($httpStatus !== 200 || !is_array($response)) {
        return $base;
    }
    $result = $response['result']['structuredContent']['result'] ?? null;
    if (!is_array($result) || !empty($response['result']['isError'])) {
        return $base;
    }
    $checks = $result['checks'] ?? null;
    $binding = $result['binding_health']['state'] ?? null;
    $contextType = $result['context_type'] ?? null;
    $allowedBinding = ['missing', 'invalid', 'pending', 'healthy', 'stale', 'unusable', 'revoked', 'not_required'];
    $allowedContext = ['none', 'interactive', 'discussion', 'service_token'];
    if (!is_array($checks) || ($checks['authentication_valid'] ?? null) !== true
        || !is_bool($checks['project_access_valid'] ?? null)
        || !in_array($binding, $allowedBinding, true)
        || !in_array($contextType, $allowedContext, true)) {
        return $base;
    }
    $authorized = $checks['project_access_valid'];
    if (($binding === 'healthy' && (!$authorized || !in_array($contextType, ['interactive', 'discussion'], true)))
        || ($binding === 'not_required' && (!$authorized || $contextType !== 'service_token'))
        || (!in_array($binding, ['healthy', 'not_required'], true) && $authorized)) {
        return $base;
    }
    $base['authentication'] = 'valid';
    $base['binding'] = $binding;
    $base['project_access'] = $authorized;
    $base['context_type'] = $contextType;
    $base['state'] = $authorized ? 'ok' : 'degraded';
    $base['attention'] = !$authorized;
    return $base;
}
