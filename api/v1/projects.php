<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    projectApiRequireMethod(['GET']);
    list($pdo, $auth) = projectApiServices();
    Api::json(['data' => $auth->projects()]);
} catch (Exception $exception) {
    projectApiError($exception);
}
