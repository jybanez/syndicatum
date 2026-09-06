<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/DiscussionProviderRegistry.php';

try {
    if (Api::method() !== 'GET') { Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405); }
    humanApiServices(false);
    Api::json(['data' => (new DiscussionProviderRegistry())->definitions()]);
} catch (Exception $exception) {
    humanApiError($exception);
}
