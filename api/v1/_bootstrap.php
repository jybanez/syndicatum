<?php

require_once dirname(dirname(__DIR__)) . '/src/Api.php';
require_once dirname(dirname(__DIR__)) . '/src/Db.php';
require_once dirname(dirname(__DIR__)) . '/src/RequestAuth.php';
require_once dirname(dirname(__DIR__)) . '/src/ProjectRepository.php';

function projectApiServices()
{
    $pdo = Db::pdo();
    if (!Db::tableExists($pdo, 'messages') || !Db::tableExists($pdo, 'project_participants')) {
        Api::json(['error' => true, 'code' => 'SCHEMA_NOT_INSTALLED', 'message' => 'Expanded Syndicatum schema is not installed.'], 503);
    }
    return [$pdo, new RequestAuth($pdo), new ProjectRepository($pdo)];
}

function projectApiId($name)
{
    $raw = isset($_GET[$name]) ? (string) $_GET[$name] : '';
    if (!preg_match('/^[1-9][0-9]*$/', $raw) || filter_var($raw, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('A valid ' . $name . ' is required.');
    }
    return (int) $raw;
}

function projectApiError(Exception $exception)
{
    if ($exception instanceof InvalidArgumentException) {
        Api::json(['error' => true, 'code' => 'VALIDATION_FAILED', 'message' => $exception->getMessage()], 422);
    }

    $code = $exception->getMessage();
    $errors = [
        'AUTHENTICATION_REQUIRED' => [401, 'Authentication is required.'],
        'CSRF_VALIDATION_FAILED' => [403, 'CSRF validation failed.'],
        'PROJECT_NOT_FOUND' => [404, 'Project not found.'],
        'MESSAGE_NOT_FOUND' => [404, 'Message not found.'],
        'TASK_NOT_FOUND' => [404, 'Task not found.'],
        'TASK_WRITE_FORBIDDEN' => [403, 'You cannot change this task.'],
        'TASK_VERSION_CONFLICT' => [409, 'This task changed. Reload it before trying again.'],
        'TASK_INVALID_TRANSITION' => [409, 'That task status change is not allowed.'],
        'TASK_ALREADY_LINKED' => [409, 'This action request already has a linked task.'],
        'PROJECT_WRITE_FORBIDDEN' => [403, 'This project role cannot post messages.'],
        'MESSAGE_WRITE_FORBIDDEN' => [403, 'Only the sender or a project administrator may change this message.'],
        'SYSTEM_MESSAGE_IMMUTABLE' => [403, 'System messages cannot be changed or removed.'],
        'MESSAGE_NOT_ADDRESSED_TO_PARTICIPANT' => [409, 'This participant is not an addressee of the message.'],
        'IDEMPOTENCY_KEY_CONFLICT' => [409, 'This idempotency key was already used for a different message request.'],
        'RESPONSIBILITY_CONFLICT' => [409, 'Responsibility state changed; reload the latest event and retry explicitly.'],
        'RESPONSIBILITY_ACTION_TYPE_MISMATCH' => [409, 'This action does not match the request type; reload the request and use its available actions.'],
        'RESPONSIBILITY_FORBIDDEN' => [403, 'This participant cannot perform that responsibility event.'],
        'RESPONSIBILITY_TARGET_INACTIVE' => [409, 'The responsibility target is not active.'],
        'RESPONSIBILITY_BASELINE_UNAVAILABLE' => [409, 'Historical request requires responsibility migration before state changes.'],
        'PROJECT_ARCHIVED' => [409, 'Archived projects are read-only.'],
        'FILE_SCHEMA_NOT_INSTALLED' => [503, 'Project file metadata is not installed yet.'],
        'FILE_STORAGE_NOT_CONFIGURED' => [503, 'Project file storage is not configured.'],
        'FILE_FOLDER_NOT_FOUND' => [404, 'Folder not found.'],
        'FILE_NOT_FOUND' => [404, 'File not found.'],
        'FILE_NAME_CONFLICT' => [409, 'That folder name is already in use here.'],
        'FILE_VERSION_CONFLICT' => [409, 'This file changed. Reload it before trying again.'],
        'FILE_IDEMPOTENCY_CONFLICT' => [409, 'This idempotency key was already used for a different file request.'],
        'FILE_OPERATION_IN_PROGRESS' => [409, 'This file operation is still in progress.'],
        'FILE_TOO_LARGE' => [413, 'The selected file exceeds the configured upload limit.'],
        'FILE_EMPTY' => [422, 'Empty files cannot be uploaded.'],
        'FILE_TYPE_NOT_ALLOWED' => [415, 'This file type is not allowed by the storage policy.'],
        'FILE_PROJECT_QUOTA_EXCEEDED' => [409, 'This project has reached its file-storage quota.'],
        'PROJECT_STATUS_FORBIDDEN' => [403, 'Only the project owner can view project status.'],
        'PROJECT_PLAN_FORBIDDEN' => [403, 'Only a project owner or administrator can change the project plan.'],
        'PROJECT_PLAN_PROGRESS_FORBIDDEN' => [403, 'This participant is not allowed to update project-plan progress.'],
        'MILESTONE_DELIVERABLES_INCOMPLETE' => [409, 'Complete or approve the milestone deliverables before marking the milestone completed.'],
        'DELIVERABLE_TASKS_INCOMPLETE' => [409, 'Complete the linked active tasks before approving or completing the deliverable.'],
        'DELIVERABLE_TASK_LINK_CONFLICT' => [409, 'Move the deliverable back to an active status before linking incomplete work.'],
        'PROPOSAL_AGENT_REQUIRED' => [403, 'Only an active project agent can submit a project proposal.'],
        'AGENT_NOT_FOUND' => [404, 'Project agent not found.'],
        'MILESTONE_NOT_FOUND' => [404, 'Milestone not found.'],
        'DELIVERABLE_NOT_FOUND' => [404, 'Deliverable not found.'],
        'MILESTONE_VERSION_CONFLICT' => [409, 'This milestone changed. Reload it before trying again.'],
        'DELIVERABLE_VERSION_CONFLICT' => [409, 'This deliverable changed. Reload it before trying again.'],
        'PROJECT_PLAN_REORDER_CONFLICT' => [409, 'The project plan changed. Reload it before trying the move again.'],
        'RATE_LIMITED' => [429, 'Too many requests. Try again later.'],
    ];
    if (isset($errors[$code])) {
        Api::json(['error' => true, 'code' => $code, 'message' => $errors[$code][1]], $errors[$code][0]);
    }

    error_log('Syndicatum project API error: ' . $exception->getMessage());
    Api::json(['error' => true, 'code' => 'INTERNAL_ERROR', 'message' => 'The request could not be completed.'], 500);
}

function projectApiRequireMethod(array $methods)
{
    if (!in_array(Api::method(), $methods, true)) {
        Api::json(['error' => true, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405, [
            'Allow' => implode(', ', $methods),
        ]);
    }
}
