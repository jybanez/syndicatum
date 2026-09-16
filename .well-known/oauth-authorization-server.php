<?php
require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatGptOAuthService.php';
$issuer = (new ChatGptOAuthService(Db::pdo()))->issuer();
Api::json(['issuer' => $issuer, 'authorization_endpoint' => $issuer . '/oauth/authorize',
    'token_endpoint' => $issuer . '/oauth/token', 'registration_endpoint' => $issuer . '/oauth/register',
    'revocation_endpoint' => $issuer . '/oauth/revoke',
    'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
    'token_endpoint_auth_methods_supported' => ['none'], 'code_challenge_methods_supported' => ['S256'],
    'scopes_supported' => ChatGptOAuthService::OAUTH_SCOPES]);
