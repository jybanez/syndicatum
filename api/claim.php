<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

try {
    if (Api::method() !== 'POST') {
        Api::json(['error' => true, 'message' => 'Method not allowed.'], 405, ['Allow' => 'POST']);
    }

    $pdo = Db::pdo(); Api::enforceLegacyPolicy($pdo); $repository = new ChatRepository($pdo);
    if (!$repository->hasSchema()) {
        Api::json(['error' => true, 'message' => 'Chat database schema is not installed.'], 503);
    }
    $repository->installSchema();

    $body = Api::body();
    $claim = $repository->claimAgent(
        isset($body['project_name']) ? $body['project_name'] : '',
        isset($body['claim_code']) ? $body['claim_code'] : ''
    );

    Api::json([
        'data' => $claim,
        'message' => 'Claim accepted. Store this token now; it will not be shown again.',
    ], 201);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 400);
} catch (RuntimeException $exception) {
    $status = stripos($exception->getMessage(), 'already claimed') !== false ? 409 : 403;
    Api::json(['error' => true, 'message' => $exception->getMessage()], $status);
} catch (Exception $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 500);
}
