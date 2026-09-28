<?php

require_once __DIR__ . '/_human.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectChangeProposalService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

try {
    $method = Api::method();
    if (!in_array($method, ['GET', 'PATCH'], true)) { Api::json(['error' => true, 'message' => 'Method not allowed.'], 405, ['Allow' => 'GET, PATCH']); }
    list($pdo, $auth, $user) = humanApiServices($method !== 'GET');
    $service = new ProjectChangeProposalService($pdo);
    if ($method === 'GET') {
        $projectId = (int) ($_GET['project_id'] ?? 0);
        if ($projectId < 1) { throw new InvalidArgumentException('Project is required.'); }
        (new RateLimiter($pdo))->hit('project-change-proposals.read', $user['id'] . ':' . $projectId, 120, 60, 60);
        Api::json(['data' => $service->listForReview($projectId, $user['id'], $_GET['status'] ?? '')]);
    }
    $body = Api::body();
    $projectId = (int) ($body['project_id'] ?? 0);
    if ($projectId < 1) { throw new InvalidArgumentException('Project is required.'); }
    (new RateLimiter($pdo))->hit('project-change-proposals.review', $user['id'] . ':' . $projectId, 60, 60, 60);
    Api::json(['data' => $service->review($projectId, $user['id'], $body['proposal_id'] ?? 0,
        $body['version'] ?? 0, $body['decision'] ?? '', $body['review_note'] ?? '')]);
} catch (Exception $exception) { humanApiError($exception); }
