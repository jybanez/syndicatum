<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/ProjectRepository.php';
require_once dirname(__DIR__) . '/src/ProjectManagementService.php';
require_once dirname(__DIR__) . '/src/ResponsibilityInboxService.php';

function responsibilityAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function responsibilityExpectFailure(callable $callback, $text)
{
    try {
        $callback();
    } catch (Exception $error) {
        responsibilityAssert(strpos($error->getMessage(), $text) !== false,
            'Unexpected error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure containing ' . $text);
}

function responsibilityUser(PDO $pdo, $email)
{
    $now = Db::now();
    $pdo->prepare('INSERT INTO users
        (normalized_email, display_name, created_at, updated_at) VALUES (?, ?, ?, ?)')
        ->execute([$email, $email, $now, $now]);
    return (int) $pdo->lastInsertId();
}

function responsibilityProject(PDO $pdo, $ownerId, $suffix)
{
    $now = Db::now();
    $pdo->prepare('INSERT INTO workspaces
        (owner_user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)')
        ->execute([$ownerId, 'Workspace ' . $suffix, $now, $now]);
    $workspaceId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO projects
        (public_id, workspace_id, owner_user_id, name, slug, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'active', ?, ?)")
        ->execute([Db::uuidV4(), $workspaceId, $ownerId,
            'Responsibility ' . $suffix, 'responsibility-' . $suffix, $now, $now]);
    return (int) $pdo->lastInsertId();
}

function responsibilityMember(PDO $pdo, $projectId, $userId, $role)
{
    $now = Db::now();
    $pdo->prepare("INSERT INTO project_members
        (project_id, user_id, role, status, created_at, updated_at)
        VALUES (?, ?, ?, 'active', ?, ?)")
        ->execute([$projectId, $userId, $role, $now, $now]);
    $pdo->prepare("INSERT INTO project_participants
        (project_id, kind, user_id, status, created_at, updated_at)
        VALUES (?, 'human', ?, 'active', ?, ?)")
        ->execute([$projectId, $userId, $now, $now]);
    return (int) $pdo->lastInsertId();
}

function responsibilityAccess($projectId, $participantId, $role)
{
    return ['project_id' => $projectId, 'participant_id' => $participantId,
        'project_status' => 'active', 'identity' => ['kind' => 'human'],
        'role' => $role];
}

function responsibilityWrite(ProjectRepository $repository, array $access,
    $requestId, $initialResponderId, $expected, $kind, $key, array $extra = [])
{
    return $repository->createMessage($access, [
        'body' => $kind . ' evidence',
        'idempotency_key' => $key,
        'responsibility_event' => array_merge([
            'kind' => $kind,
            'request_message_id' => $requestId,
            'initial_responder_participant_id' => $initialResponderId,
            'expected_event_id' => $expected,
        ], $extra),
    ]);
}

$database = 'syndicatum_resp_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^syndicatum_resp_test_[a-f0-9]{12}$/', $database)) {
    throw new RuntimeException('Unsafe temporary database name.');
}
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('PBB_AGENTCHAT_DB_HOST=127.0.0.1');
putenv('PBB_AGENTCHAT_DB_NAME=' . $database);
putenv('PBB_AGENTCHAT_DB_USER=root');
putenv('PBB_AGENTCHAT_DB_PASS=');
putenv('PBB_AGENTCHAT_SECRET=' . bin2hex(random_bytes(32)));

try {
    $pdo = Db::pdo();
    (new ChatRepository($pdo))->installSchema();
    $ownerId = responsibilityUser($pdo, 'owner@responsibility.test');
    $responderId = responsibilityUser($pdo, 'responder@responsibility.test');
    $targetId = responsibilityUser($pdo, 'target@responsibility.test');
    $otherOwnerId = responsibilityUser($pdo, 'foreign@responsibility.test');
    $projectId = responsibilityProject($pdo, $ownerId, 'one');
    $foreignProjectId = responsibilityProject($pdo, $otherOwnerId, 'two');
    $ownerParticipant = responsibilityMember($pdo, $projectId, $ownerId, 'owner');
    $responderParticipant = responsibilityMember($pdo, $projectId, $responderId, 'member');
    $targetParticipant = responsibilityMember($pdo, $projectId, $targetId, 'member');
    $foreignParticipant = responsibilityMember($pdo, $foreignProjectId, $otherOwnerId, 'owner');
    $owner = responsibilityAccess($projectId, $ownerParticipant, 'owner');
    $responder = responsibilityAccess($projectId, $responderParticipant, 'member');
    $target = responsibilityAccess($projectId, $targetParticipant, 'member');
    $foreign = responsibilityAccess($foreignProjectId, $foreignParticipant, 'owner');
    $repository = new ProjectRepository($pdo);

    $request = $repository->createMessage($owner,
        ['body' => 'Please investigate', 'direct_participant_ids' => [$responderParticipant]]);
    $requestId = $request['message']['id'];
    $foreignRequest = $repository->createMessage($foreign,
        ['body' => 'Foreign request', 'direct_participant_ids' => []]);
    $foreignRequestId = $foreignRequest['message']['id'];
    $event = responsibilityWrite($repository, $responder, $requestId,
        $responderParticipant, $requestId, 'work_started', 'responsibility-start');
    $eventId = $event['message']['id'];
    responsibilityAssert($event['created'], 'Initial event was not created.');
    responsibilityAssert((int) $pdo->query('SELECT COUNT(*) FROM responsibility_events')->fetchColumn() === 1,
        'Event and message were not committed together.');
    $same = responsibilityWrite($repository, $responder, $requestId,
        $responderParticipant, $requestId, 'work_started', 'responsibility-start');
    responsibilityAssert(!$same['created'] && $same['message']['id'] === $eventId,
        'Unchanged idempotency retry did not return the original event message.');
    responsibilityExpectFailure(function () use ($repository, $responder, $requestId,
        $responderParticipant) {
        responsibilityWrite($repository, $responder, $requestId,
            $responderParticipant, $requestId, 'blocked', 'responsibility-start');
    }, 'IDEMPOTENCY_KEY_CONFLICT');

    $before = (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    responsibilityExpectFailure(function () use ($repository, $responder, $requestId,
        $responderParticipant) {
        responsibilityWrite($repository, $responder, $requestId,
            $responderParticipant, $requestId, 'blocked', 'responsibility-stale');
    }, 'RESPONSIBILITY_CONFLICT');
    responsibilityAssert((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === $before,
        'Stale event left an orphan canonical message.');
    responsibilityExpectFailure(function () use ($repository, $owner, $requestId,
        $responderParticipant, $eventId) {
        responsibilityWrite($repository, $owner, $requestId,
            $responderParticipant, $eventId, 'blocked', 'responsibility-forbidden');
    }, 'RESPONSIBILITY_FORBIDDEN');
    responsibilityExpectFailure(function () use ($repository, $responder, $foreignRequestId,
        $responderParticipant) {
        responsibilityWrite($repository, $responder, $foreignRequestId,
            $responderParticipant, $foreignRequestId, 'blocked', 'responsibility-foreign');
    }, 'MESSAGE_NOT_FOUND');

    $blocked = responsibilityWrite($repository, $responder, $requestId,
        $responderParticipant, $eventId, 'blocked', 'responsibility-block');
    $blockedId = $blocked['message']['id'];
    responsibilityExpectFailure(function () use ($repository, $owner, $requestId,
        $responderParticipant, $blockedId, $foreignParticipant) {
        responsibilityWrite($repository, $owner, $requestId,
            $responderParticipant, $blockedId, 'transfer_offered',
            'responsibility-foreign-target',
            ['target_participant_id' => $foreignParticipant]);
    }, 'MESSAGE_NOT_FOUND');
    $correction = responsibilityWrite($repository, $owner, $requestId,
        $responderParticipant, $blockedId, 'corrected', 'responsibility-correction',
        ['reference_event_id' => $blockedId]);
    $correctionId = $correction['message']['id'];
    $offered = responsibilityWrite($repository, $owner, $requestId,
        $responderParticipant, $correctionId, 'transfer_offered', 'responsibility-offer',
        ['target_participant_id' => $targetParticipant]);
    $offerId = $offered['message']['id'];
    $accepted = responsibilityWrite($repository, $target, $requestId,
        $responderParticipant, $offerId, 'transfer_accepted', 'responsibility-accept',
        ['reference_event_id' => $offerId]);
    responsibilityAssert($accepted['created'], 'Transfer acceptance was not committed.');
    $acceptedId = $accepted['message']['id'];
    $management = new ProjectManagementService($pdo);
    $management->updateMember($projectId, $ownerId, $targetId, 'member', true);
    $management->updateMember($projectId, $ownerId, $targetId, 'member', false);
    responsibilityExpectFailure(function () use ($repository, $target, $requestId,
        $responderParticipant, $acceptedId) {
        responsibilityWrite($repository, $target, $requestId,
            $responderParticipant, $acceptedId, 'work_started',
            'responsibility-no-silent-restore');
    }, 'RESPONSIBILITY_CONFLICT');
    $restored = responsibilityWrite($repository, $owner, $requestId,
        $responderParticipant, $acceptedId, 'responder_restored',
        'responsibility-explicit-restore');
    $afterRestore = responsibilityWrite($repository, $target, $requestId,
        $responderParticipant, $restored['message']['id'], 'work_started',
        'responsibility-after-restore');
    responsibilityAssert($afterRestore['created'],
        'Explicit restoration did not reenable the responder.');
    $addressedEvent = $repository->createMessage($target, [
        'body' => 'Blocking this request; owner notified directly',
        'direct_participant_ids' => [$ownerParticipant],
        'idempotency_key' => 'responsibility-addressed-event',
        'responsibility_event' => [
            'kind' => 'blocked', 'request_message_id' => $requestId,
            'initial_responder_participant_id' => $responderParticipant,
            'expected_event_id' => $afterRestore['message']['id'],
        ],
    ]);
    responsibilityExpectFailure(function () use ($repository, $owner, $addressedEvent,
        $ownerParticipant) {
        responsibilityWrite($repository, $owner, $addressedEvent['message']['id'],
            $ownerParticipant, $addressedEvent['message']['id'], 'work_started',
            'responsibility-event-not-request');
    }, 'MESSAGE_NOT_FOUND');
    $repository->updateMessage($owner, $blockedId, ['body' => 'Edited explanation']);
    $repository->deleteMessage($owner, $blockedId);
    $check = $pdo->prepare('SELECT COUNT(*) FROM responsibility_events WHERE event_message_id = ?');
    $check->execute([$blockedId]);
    responsibilityAssert((int) $check->fetchColumn() === 1,
        'Edit or soft deletion destroyed structured responsibility evidence.');

    // Hold the project sequence row while two separate PHP processes enter
    // createMessage. Both must be waiting on the real InnoDB lock together.
    $raceRequest = $repository->createMessage($owner, [
        'body' => 'Competing writer request',
        'direct_participant_ids' => [$responderParticipant],
    ]);
    $raceRequestId = $raceRequest['message']['id'];
    $messageCountBeforeRace = (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    $children = [];
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT next_sequence FROM project_message_sequences
            WHERE project_id = ? FOR UPDATE');
        $lock->execute([$projectId]);
        responsibilityAssert($lock->fetchColumn() !== false, 'Project sequence lock missing.');
        foreach (['race-a' => 'work_started', 'race-b' => 'blocked'] as $key => $kind) {
            $pipes = [];
            $process = proc_open([PHP_BINARY,
                __DIR__ . '/fixtures/responsibility-concurrent-writer.php',
                (string) $projectId, (string) $responderParticipant,
                (string) $raceRequestId, (string) $responderParticipant,
                (string) $raceRequestId, $key, $kind],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes, dirname(__DIR__));
            responsibilityAssert(is_resource($process), 'Concurrent writer did not start.');
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 10);
            $ready = fgets($pipes[1]);
            responsibilityAssert(is_string($ready)
                && preg_match('/^READY ([0-9]+)\s*$/', $ready, $match) === 1,
                'Concurrent writer did not become ready.');
            $children[] = ['process' => $process, 'pipes' => $pipes,
                'connection_id' => (int) $match[1]];
        }
        $waiters = 0;
        for ($attempt = 0; $attempt < 30 && $waiters !== 2; $attempt++) {
            usleep(100000);
            $probe = $pdo->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST
                WHERE ID IN (?, ?) AND INFO LIKE '%project_message_sequences%'");
            $probe->execute([$children[0]['connection_id'], $children[1]['connection_id']]);
            $waiters = (int) $probe->fetchColumn();
        }
        responsibilityAssert($waiters === 2,
            'Two writers were not simultaneously waiting on the sequence lock.');
        $pdo->commit();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    $raceResults = [];
    foreach ($children as $child) {
        $raceResults[] = trim(stream_get_contents($child['pipes'][1]));
        fclose($child['pipes'][1]);
        $stderr = stream_get_contents($child['pipes'][2]);
        fclose($child['pipes'][2]);
        $exitCode = proc_close($child['process']);
        responsibilityAssert($stderr === '', 'Concurrent writer stderr: ' . $stderr);
        responsibilityAssert(in_array($exitCode, [0, 2], true),
            'Concurrent writer exited unexpectedly.');
    }
    sort($raceResults);
    responsibilityAssert(count($raceResults) === 2
        && preg_match('/^CREATED ([0-9]+) (work_started|blocked) (race-a|race-b)$/',
            $raceResults[0], $winner) === 1
        && $raceResults[1] === 'ERROR RESPONSIBILITY_CONFLICT',
        'Competing writes did not produce exactly one winner and one conflict: '
            . implode(' | ', $raceResults));
    responsibilityAssert((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn()
        === $messageCountBeforeRace + 1,
        'Losing concurrent write left a canonical message behind.');
    $raceCount = $pdo->prepare('SELECT COUNT(*) FROM responsibility_events
        WHERE request_message_id = ?');
    $raceCount->execute([$raceRequestId]);
    responsibilityAssert((int) $raceCount->fetchColumn() === 1,
        'Competing writes did not leave exactly one responsibility event.');
    $replay = responsibilityWrite($repository, $responder, $raceRequestId,
        $responderParticipant, $raceRequestId, $winner[2], $winner[3]);
    responsibilityAssert(!$replay['created']
        && $replay['message']['id'] === (int) $winner[1],
        'Winner idempotency replay was not stable after the race.');
    $winningEvent = $pdo->prepare('SELECT re.kind, re.event_message_id,
        em.project_sequence FROM responsibility_events re
        JOIN messages em ON em.id = re.event_message_id
        WHERE re.project_id = ? AND re.request_message_id = ?
        ORDER BY em.project_sequence, re.event_message_id');
    $winningEvent->execute([$projectId, $raceRequestId]);
    $committed = $winningEvent->fetchAll(PDO::FETCH_ASSOC);
    responsibilityAssert(count($committed) === 1
        && (int) $committed[0]['event_message_id'] === (int) $winner[1]
        && $committed[0]['kind'] === $winner[2],
        'Canonical sequence does not identify exactly the winning event.');
    $finalState = ResponsibilityStateReducer::apply(
        ResponsibilityStateReducer::initial($raceRequestId,
            $ownerParticipant, $responderParticipant),
        ['id' => (int) $committed[0]['event_message_id'],
            'kind' => $committed[0]['kind'],
            'expected_event_id' => $raceRequestId],
        ['id' => $responderParticipant, 'active' => true, 'moderator' => false]);
    responsibilityAssert($finalState['state'] === 'open'
        && $finalState['last_event_id'] === (int) $winner[1]
        && $finalState['work_started'] === ($winner[2] === 'work_started')
        && $finalState['blocked'] === ($winner[2] === 'blocked'),
        'Final reducer state includes anything beyond the committed event.');
    echo "PASS two live writers, one event, one stale conflict, no losing message.\n";

    $inbox = new ResponsibilityInboxService($pdo);
    $ownerView = $inbox->page($owner, ['view' => 'waiting_on_others']);
    $ownedRequests = array_column($ownerView['data'], 'request_message_id');
    responsibilityAssert(in_array($requestId, $ownedRequests, true)
        && in_array($raceRequestId, $ownedRequests, true),
        'Requester inbox omitted canonical work waiting on responders.');
    $targetView = $inbox->page($target, ['view' => 'mine']);
    $targetItems = array_values(array_filter($targetView['data'], function ($item) use ($requestId) {
        return $item['request_message_id'] === $requestId;
    }));
    responsibilityAssert(count($targetItems) === 1
        && $targetItems[0]['current_responder_participant_id'] === $targetParticipant
        && $targetItems[0]['state'] === 'open'
        && $targetItems[0]['latest_evidence_message_id'] === $addressedEvent['message']['id'],
        'Inbox did not rebuild transferred responsibility from canonical evidence.');

    $management->updateMember($projectId, $ownerId, $targetId, 'member', true);
    $management->updateMember($projectId, $ownerId, $targetId, 'member', false);
    $orphanView = $inbox->page($owner, ['view' => 'orphaned']);
    $orphanItems = array_values(array_filter($orphanView['data'],
        function ($item) use ($requestId) {
            return $item['request_message_id'] === $requestId;
        }));
    responsibilityAssert(count($orphanItems) === 1
        && $orphanItems[0]['current_responder_participant_id'] === null,
        'Inbox silently restored responsibility after membership reactivation.');
    $orphanOffer = responsibilityWrite($repository, $owner, $requestId,
        $responderParticipant, $addressedEvent['message']['id'],
        'transfer_offered', 'responsibility-inbox-orphan-offer',
        ['target_participant_id' => $responderParticipant]);
    $pendingView = $inbox->page($owner, ['view' => 'transfer_pending']);
    $pendingItems = array_values(array_filter($pendingView['data'],
        function ($item) use ($requestId) {
            return $item['request_message_id'] === $requestId;
        }));
    responsibilityAssert(count($pendingItems) === 1
        && $pendingItems[0]['current_responder_participant_id'] === null,
        'Orphaned transfer offer invented a current owner before acceptance.');
    $orphanDecline = responsibilityWrite($repository, $responder, $requestId,
        $responderParticipant, $orphanOffer['message']['id'],
        'transfer_declined', 'responsibility-inbox-orphan-decline',
        ['reference_event_id' => $orphanOffer['message']['id']]);
    $restoredAgain = responsibilityWrite($repository, $owner, $requestId,
        $responderParticipant, $orphanDecline['message']['id'],
        'responder_restored', 'responsibility-inbox-restore');
    $repository->updateMessage($owner, $requestId,
        ['body' => 'Edited source request text']);
    $repository->deleteMessage($owner, $requestId);
    $restoredView = $inbox->page($target, ['view' => 'mine']);
    $restoredItems = array_values(array_filter($restoredView['data'],
        function ($item) use ($requestId) {
            return $item['request_message_id'] === $requestId;
        }));
    responsibilityAssert(count($restoredItems) === 1
        && $restoredItems[0]['latest_evidence_message_id']
            === $restoredAgain['message']['id']
        && $restoredItems[0]['state'] === 'open',
        'Inbox lost the canonical item after source edit/soft deletion or restoration.');

    $dual = $repository->createMessage($owner, [
        'body' => 'Two independent direct responsibilities',
        'direct_participant_ids' => [$responderParticipant, $targetParticipant],
    ]);
    $beforeRead = $pdo->prepare('SELECT participant_id, seen_at, acknowledged_at
        FROM message_addressees WHERE message_id = ? ORDER BY participant_id');
    $beforeRead->execute([$dual['message']['id']]);
    $untouchedAddressees = $beforeRead->fetchAll(PDO::FETCH_ASSOC);
    $inbox->page($responder, ['view' => 'unacknowledged']);
    $beforeRead->execute([$dual['message']['id']]);
    responsibilityAssert($beforeRead->fetchAll(PDO::FETCH_ASSOC) === $untouchedAddressees,
        'Reading the derived inbox mutated message seen/acknowledgement state.');
    $old = $repository->createMessage($owner, [
        'body' => 'Historical request without verified membership generation',
        'direct_participant_ids' => [$responderParticipant],
    ]);
    $pdo->prepare('UPDATE message_addressees
        SET responsibility_status_generation = NULL
        WHERE message_id = ? AND participant_id = ?')
        ->execute([$old['message']['id'], $responderParticipant]);
    $legacyPage = $inbox->page($responder, ['view' => 'unknown']);
    responsibilityAssert(count($legacyPage['data']) === 1
        && $legacyPage['data'][0]['request_message_id'] === $old['message']['id']
        && $legacyPage['data'][0]['projection_error'] === 'RESPONSIBILITY_BASELINE_UNAVAILABLE',
        'Historical direct message was silently assigned a responsibility state.');

    $cursor = null;
    $keys = [];
    do {
        $filters = ['limit' => 1];
        if ($cursor !== null) {
            $filters['before'] = $cursor;
        }
        $page = $inbox->page($owner, $filters);
        foreach ($page['data'] as $item) {
            $keys[] = $item['request_message_id'] . ':'
                . $item['initial_responder_participant_id'];
        }
        $cursor = $page['page']['older_cursor'];
    } while ($page['page']['has_more']);
    responsibilityAssert(count($keys) === count(array_unique($keys))
        && in_array($dual['message']['id'] . ':' . $responderParticipant, $keys, true)
        && in_array($dual['message']['id'] . ':' . $targetParticipant, $keys, true),
        'Inbox cursor duplicated or omitted one direct addressee of a message.');
    responsibilityExpectFailure(function () use ($inbox, $foreign, $cursor) {
        $inbox->page($foreign, ['before' => $cursor]);
    }, 'Invalid inbox cursor.');
    echo "PASS canonical inbox rebuild, unknown baseline, and multi-addressee pagination.\n";

    echo "Responsibility persistence and conflict tests passed.\n";
} finally {
    if (!preg_match('/^syndicatum_resp_test_[a-f0-9]{12}$/', $database)) {
        throw new RuntimeException('Refusing unsafe database cleanup.');
    }
    $admin->exec('DROP DATABASE `' . $database . '`');
}
