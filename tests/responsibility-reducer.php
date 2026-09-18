<?php

require_once dirname(__DIR__) . '/src/ResponsibilityStateReducer.php';

$passed = 0;
$failed = 0;

function checkResponsibility($name, callable $test)
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo 'PASS  ' . $name . PHP_EOL;
    } catch (Exception $error) {
        $failed++;
        echo 'FAIL  ' . $name . ': ' . $error->getMessage() . PHP_EOL;
    }
}

function responsibilitySame($expected, $actual)
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function responsibilityFails(callable $test)
{
    try {
        $test();
    } catch (RuntimeException $expected) {
        return;
    }
    throw new RuntimeException('Expected transition to fail.');
}

function responsibilityEvent(array $state, $id, $kind, array $more = [])
{
    return array_merge(['id' => $id, 'kind' => $kind,
        'expected_event_id' => $state['last_event_id'] === null
            ? $state['request_message_id'] : $state['last_event_id']], $more);
}

function responsibilityActor($id, $moderator = false, array $context = [])
{
    return array_merge(['id' => $id, 'active' => true, 'moderator' => $moderator],
        $context);
}

checkResponsibility('direct request starts open without acceptance evidence', function () {
    $state = ResponsibilityStateReducer::initial(100, 10, 20);
    responsibilitySame('open', $state['state']);
    responsibilitySame(false, $state['work_started']);
    responsibilitySame(null, $state['outcome']);
});

checkResponsibility('responder starts, blocks, and unblocks explicitly', function () {
    $state = ResponsibilityStateReducer::initial(100, 10, 20);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 101, 'work_started'), responsibilityActor(20));
    responsibilitySame(true, $state['work_started']);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 102, 'blocked'), responsibilityActor(20));
    responsibilitySame(true, $state['blocked']);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 103, 'unblocked', ['ref_event_id' => 102]),
        responsibilityActor(20));
    responsibilitySame(false, $state['blocked']);
});

checkResponsibility('resolution requires responder proposal and requester decision', function () {
    $state = ResponsibilityStateReducer::initial(100, 10, 20);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 101, 'resolution_proposed'), responsibilityActor(20));
    responsibilitySame('resolution_pending', $state['state']);
    responsibilityFails(function () use ($state) {
        ResponsibilityStateReducer::apply($state,
            responsibilityEvent($state, 102, 'resolution_accepted', ['ref_event_id' => 101]),
            responsibilityActor(20));
    });
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 102, 'resolution_accepted', ['ref_event_id' => 101]),
        responsibilityActor(10));
    responsibilitySame('resolved', $state['state']);
    responsibilitySame('accepted', $state['outcome']);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 103, 'reopened', ['reason' => 'More work']),
        responsibilityActor(10));
    responsibilitySame('open', $state['state']);
    responsibilitySame(null, $state['outcome']);
});

checkResponsibility('dispute and proposer withdrawal restore the prior state', function () {
    $state = ResponsibilityStateReducer::initial(100, 10, 20);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 101, 'resolution_proposed'), responsibilityActor(20));
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 102, 'resolution_disputed', ['ref_event_id' => 101]),
        responsibilityActor(10));
    responsibilitySame('disputed', $state['state']);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 103, 'resolution_proposed'), responsibilityActor(20));
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 104, 'resolution_withdrawn', ['ref_event_id' => 103]),
        responsibilityActor(20));
    responsibilitySame('disputed', $state['state']);
});

checkResponsibility('offer, decline, and acceptance preserve two-step handoff', function () {
    $state = ResponsibilityStateReducer::initial(100, 10, 20);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 101, 'blocked'), responsibilityActor(20));
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 102, 'transfer_offered',
            ['target_id' => 30]), responsibilityActor(20, false, ['target_active' => true]));
    responsibilitySame('transfer_pending', $state['state']);
    responsibilitySame(20, $state['responder_id']);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 103, 'transfer_declined',
            ['ref_event_id' => 102]), responsibilityActor(30));
    responsibilitySame('open', $state['state']);
    responsibilitySame(true, $state['blocked']);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 104, 'transfer_offered',
            ['target_id' => 30]), responsibilityActor(10, false, ['target_active' => true]));
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 105, 'transfer_accepted',
            ['ref_event_id' => 104]), responsibilityActor(30));
    responsibilitySame('open', $state['state']);
    responsibilitySame(30, $state['responder_id']);
    responsibilitySame(false, $state['blocked']);
});

checkResponsibility('orphaned transfer is requester-only and decline stays orphaned', function () {
    $state = ResponsibilityStateReducer::initial(100, 10, 20);
    $state['state'] = 'orphaned'; // Membership projection supplies this state.
    responsibilityFails(function () use ($state) {
        ResponsibilityStateReducer::apply($state,
            responsibilityEvent($state, 101, 'transfer_offered',
                ['target_id' => 30]), responsibilityActor(20, false, ['target_active' => true]));
    });
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 101, 'transfer_offered',
            ['target_id' => 30]), responsibilityActor(10, false, ['target_active' => true]));
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 102, 'transfer_declined',
            ['ref_event_id' => 101]), responsibilityActor(30));
    responsibilitySame('orphaned', $state['state']);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 103, 'responder_restored',
            []), responsibilityActor(10, false, ['responder_active' => true]));
    responsibilitySame('open', $state['state']);
});

checkResponsibility('inactive target cannot accept and stale references fail', function () {
    $state = ResponsibilityStateReducer::initial(100, 10, 20);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 101, 'transfer_offered',
            ['target_id' => 30]), responsibilityActor(20, false, ['target_active' => true]));
    responsibilityFails(function () use ($state) {
        ResponsibilityStateReducer::apply($state,
            responsibilityEvent($state, 102, 'transfer_accepted',
                ['ref_event_id' => 101]), responsibilityActor(30, false, ['active' => false]));
    });
    responsibilityFails(function () use ($state) {
        ResponsibilityStateReducer::apply($state,
            responsibilityEvent($state, 102, 'transfer_accepted',
                ['ref_event_id' => 999]), responsibilityActor(30));
    });
    responsibilityFails(function () use ($state) {
        ResponsibilityStateReducer::apply($state,
            ['id' => 102, 'kind' => 'transfer_accepted', 'expected_event_id' => 100,
                'ref_event_id' => 101], responsibilityActor(30));
    });
});

checkResponsibility('withdrawn request is resolved without completed work', function () {
    $state = ResponsibilityStateReducer::initial(100, 10, 20);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 101, 'request_withdrawn', ['reason' => 'No longer needed']),
        responsibilityActor(10));
    responsibilitySame('resolved', $state['state']);
    responsibilitySame('withdrawn', $state['outcome']);
});

checkResponsibility('moderator correction is audit-only and cannot forge consent', function () {
    $state = ResponsibilityStateReducer::initial(100, 10, 20);
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 101, 'blocked'), responsibilityActor(20));
    responsibilityFails(function () use ($state) {
        ResponsibilityStateReducer::apply($state,
            responsibilityEvent($state, 102, 'corrected',
                ['ref_event_id' => 101, 'reason' => 'Typo', 'responder_id' => 30]),
            responsibilityActor(40, true));
    });
    $before = $state;
    $state = ResponsibilityStateReducer::apply($state,
        responsibilityEvent($state, 102, 'corrected',
            ['ref_event_id' => 101, 'reason' => 'Typo']), responsibilityActor(40, true));
    $before['last_event_id'] = 102;
    responsibilitySame($before, $state);
});

checkResponsibility('every event kind enforces its actor-role matrix', function () {
    $matrix = [
        'work_started' => [20], 'blocked' => [20], 'unblocked' => [20],
        'resolution_proposed' => [20], 'resolution_accepted' => [10, 40],
        'resolution_disputed' => [10, 40], 'resolution_withdrawn' => [20],
        'request_withdrawn' => [10, 40], 'transfer_offered' => [10, 20, 40],
        'transfer_accepted' => [30], 'transfer_declined' => [30],
        'reopened' => [10, 40], 'responder_restored' => [10, 40],
        'corrected' => [40],
    ];
    foreach ($matrix as $kind => $allowed) {
        $state = ResponsibilityStateReducer::initial(100, 10, 20);
        $extra = [];
        $context = [];
        if ($kind === 'unblocked' || $kind === 'corrected') {
            $state = ResponsibilityStateReducer::apply($state,
                responsibilityEvent($state, 101, 'blocked'), responsibilityActor(20));
            $extra['ref_event_id'] = 101;
        }
        if (in_array($kind, ['resolution_accepted', 'resolution_disputed',
            'resolution_withdrawn'], true)) {
            $state = ResponsibilityStateReducer::apply($state,
                responsibilityEvent($state, 101, 'resolution_proposed'),
                responsibilityActor(20));
            $extra['ref_event_id'] = 101;
        }
        if (in_array($kind, ['transfer_accepted', 'transfer_declined'], true)) {
            $state = ResponsibilityStateReducer::apply($state,
                responsibilityEvent($state, 101, 'transfer_offered', ['target_id' => 30]),
                responsibilityActor(20, false, ['target_active' => true]));
            $extra['ref_event_id'] = 101;
        }
        if ($kind === 'reopened') {
            $state = ResponsibilityStateReducer::apply($state,
                responsibilityEvent($state, 101, 'request_withdrawn',
                    ['reason' => 'Withdrawn']), responsibilityActor(10));
        }
        if ($kind === 'responder_restored') {
            $state['state'] = 'orphaned';
            $context['responder_active'] = true;
        }
        if ($kind === 'transfer_offered') {
            $extra['target_id'] = 30;
            $context['target_active'] = true;
        }
        if (in_array($kind, ['request_withdrawn', 'reopened', 'corrected'], true)) {
            $extra['reason'] = 'Recorded reason';
        }
        $event = responsibilityEvent($state, 102, $kind, $extra);
        foreach ([10, 20, 30, 40, 50] as $actorId) {
            $actor = responsibilityActor($actorId, $actorId === 40, $context);
            if (in_array($actorId, $allowed, true)) {
                try {
                    ResponsibilityStateReducer::apply($state, $event, $actor);
                } catch (RuntimeException $error) {
                    throw new RuntimeException($kind . ' should allow ' . $actorId
                        . ': ' . $error->getMessage());
                }
            } else {
                responsibilityFails(function () use ($state, $event, $actor) {
                    ResponsibilityStateReducer::apply($state, $event, $actor);
                });
            }
        }
    }
});

echo PHP_EOL . $passed . ' passed, ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
