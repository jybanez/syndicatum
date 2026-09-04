<?php

require_once dirname(__DIR__) . '/src/Api.php';

Api::json([
    'error' => true,
    'message' => 'Web-based schema installation is disabled. Use the operator CLI command instead.',
], 410);
