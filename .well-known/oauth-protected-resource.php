<?php
require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatGptOAuthService.php';
$oauth = new ChatGptOAuthService(Db::pdo());
Api::json(['resource' => $oauth->resource(), 'authorization_servers' => [$oauth->issuer()],
    'scopes_supported' => ChatGptOAuthService::OAUTH_SCOPES,
    'resource_documentation' => $oauth->issuer() . '/docs/chatgpt-plugin.md']);
