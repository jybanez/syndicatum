<?php
require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/ChatGptOAuthService.php';
Api::json(['resource' => ChatGptOAuthService::RESOURCE, 'authorization_servers' => [ChatGptOAuthService::ISSUER],
    'scopes_supported' => ChatGptOAuthService::SCOPES,
    'resource_documentation' => ChatGptOAuthService::ISSUER . '/docs/chatgpt-plugin.md']);
