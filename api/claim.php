<?php

require_once dirname(__DIR__) . '/src/Api.php';
require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/InstallationState.php';

try {
    if (Api::method() !== 'POST') {
        Api::json(['error' => true, 'message' => 'Method not allowed.'], 405, ['Allow' => 'POST']);
    }

    $pdo = Db::pdo(); Api::enforceLegacyPolicy($pdo); $repository = new ChatRepository($pdo);
    (new InstallationState($pdo))->assertReady();

    $body = Api::body();
    $claim = $repository->claimAgent(
        isset($body['project_name']) ? $body['project_name'] : '',
        isset($body['claim_code']) ? $body['claim_code'] : '',
        isset($body['project']) ? $body['project'] : (isset($body['syndicatum_project']) ? $body['syndicatum_project'] : null)
    );

    Api::json([
        'data' => $claim,
        'message' => 'Claim accepted. Store this token now; it will not be shown again.',
    ], 201);
} catch (InvalidArgumentException $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 400);
} catch (RuntimeException $exception) {
    if (strpos($exception->getMessage(), 'INSTALLATION_REQUIRED:') === 0) {
        Api::json(['error' => true, 'code' => 'INSTALLATION_REQUIRED', 'message' => 'Syndicatum installation is incomplete.'], 503);
    }
    $status = stripos($exception->getMessage(), 'already claimed') !== false ? 409 : 403;
    Api::json(['error' => true, 'message' => $exception->getMessage()], $status);
} catch (Exception $exception) {
    Api::json(['error' => true, 'message' => $exception->getMessage()], 500);
}
