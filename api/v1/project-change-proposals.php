<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectChangeProposalService.php';
require_once dirname(dirname(__DIR__)) . '/src/RateLimiter.php';

$method = Api::method();
if ($method === 'POST') {
    require_once __DIR__ . '/_bootstrap.php';
    try {
        list($pdo, $auth) = projectApiServices();
        $projectId = projectApiId('project_id');
        $access = $auth->projectAccess($projectId, 'messages:write');
        (new RateLimiter($pdo))->hit('project-change-proposals.create', $projectId . ':' . $access['participant_id'], 30, 60, 60);
        $body = Api::body();
        $type = trim((string) ($body['proposal_type'] ?? ''));
        $service = new ProjectChangeProposalService($pdo);
        if ($type === 'project_details') {
            $proposal = $service->proposeProjectDetails($access, $body);
        } elseif ($type === 'project_plan') {
            $proposal = $service->proposeProjectPlan($access, $body);
        } elseif ($type === 'agent_setup') {
            $proposal = $service->proposeAgentSetup($access, $body);
        } elseif ($type === 'agent_profile_update') {
            $proposal = $service->proposeAgentProfileUpdate($access, $body);
        } else {
            throw new InvalidArgumentException('A valid proposal_type is required.');
        }
        Api::json(['data' => $proposal], 201);
    } catch (Exception $exception) { projectApiError($exception); }
    return;
}

require_once __DIR__ . '/_human.php';

try {
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
