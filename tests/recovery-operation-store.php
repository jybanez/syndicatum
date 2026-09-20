<?php

require_once dirname(__DIR__) . '/src/RecoveryOperationStore.php';

function recoveryStoreAssert($condition, $message) { if (!$condition) { fwrite(STDERR, 'FAIL  ' . $message . PHP_EOL); exit(1); } }
function recoveryStoreThrows(callable $callback, $message, $expected = null) {
    try { $callback(); } catch (Throwable $exception) {
        if ($expected !== null) { recoveryStoreAssert($exception->getMessage() === $expected, $message . ' Unexpected error: ' . $exception->getMessage()); }
        return;
    }
    recoveryStoreAssert(false, $message);
}
function recoveryStoreRemove($path) {
    if (is_dir($path) && !is_link($path)) { foreach (scandir($path) ?: [] as $name) { if ($name !== '.' && $name !== '..') { recoveryStoreRemove($path . DIRECTORY_SEPARATOR . $name); } } @rmdir($path); }
    elseif (file_exists($path) || is_link($path)) { @unlink($path); }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-recovery-store-' . bin2hex(random_bytes(8));
mkdir($root, 0700); @chmod($root, 0700);
try {
    $store = new RecoveryOperationStore($root);
    $key = 'test-operation-key-00000001';
    $fingerprint = hash('sha256', 'request-a');
    recoveryStoreAssert($store->operationForKey(7, 11, 'backup', $key, $fingerprint) === null, 'A new idempotency key must be unused.');
    $operation = $store->createOperation(7, 11, 'backup', $key, $fingerprint);
    recoveryStoreAssert($operation['status'] === 'started', 'A new operation must begin in started state.');
    recoveryStoreAssert($store->operationForKey(7, 11, 'backup', $key, $fingerprint)['operation_id'] === $operation['operation_id'], 'Same-key recovery must return the original operation.');
    recoveryStoreAssert($store->operationForKey(7, 12, 'backup', $key, $fingerprint) === null, 'Idempotency receipts must be isolated by session.');
    recoveryStoreThrows(function () use ($store, $key) { $store->operationForKey(7, 11, 'backup', $key, hash('sha256', 'request-b')); }, 'Different payload under the same key must conflict.', 'IDEMPOTENCY_KEY_CONFLICT');
    $operation = $store->updateOperation($operation, [
        'status' => 'succeeded',
        'result' => ['envelope_sha256' => str_repeat('a', 64)],
        'private_result' => ['artifact_path' => $root . DIRECTORY_SEPARATOR . 'private-artifact'],
    ]);
    recoveryStoreAssert($store->operationById($operation['operation_id'], 7, 11)['status'] === 'succeeded', 'Bound actor/session must recover a terminal receipt.');
    recoveryStoreAssert($store->operationById($operation['operation_id'], 7, 12) === null, 'Another session must not recover the receipt.');

    $artifact = $root . DIRECTORY_SEPARATOR . 'artifact.syndicatum-backup';
    file_put_contents($artifact, 'encrypted-artifact'); @chmod($artifact, 0600);
    $ticket = $store->createTicket(7, 11, $artifact, 'backup.syndicatum-backup', hash_file('sha256', $artifact), 'application/octet-stream');
    recoveryStoreAssert(is_array($store->consumeTicket($ticket['token'], 7, 11)), 'Bound actor/session must consume a valid ticket.');
    recoveryStoreAssert($store->consumeTicket($ticket['token'], 7, 11) === null, 'A ticket must be single-use.');
    $otherTicket = $store->createTicket(7, 11, $artifact, 'backup.syndicatum-backup', hash_file('sha256', $artifact), 'application/octet-stream');
    recoveryStoreAssert($store->consumeTicket($otherTicket['token'], 8, 11) === null, 'A ticket must reject another actor.');

    $incoming = $root . DIRECTORY_SEPARATOR . 'incoming.syndicatum-backup';
    file_put_contents($incoming, 'verified-envelope'); @chmod($incoming, 0600);
    $inspection = $store->retainInspection(7, 11, $incoming, ['envelope_sha256' => hash('sha256', 'verified-envelope')]);
    recoveryStoreAssert(!file_exists($incoming), 'Retaining an inspection must move, not copy, the upload.');
    recoveryStoreAssert($store->inspection($inspection['inspection_id'], 7, 11)['metadata']['envelope_sha256'] === hash('sha256', 'verified-envelope'), 'Inspection metadata must be actor/session bound.');
    recoveryStoreAssert($store->inspection($inspection['inspection_id'], 7, 12) === null, 'Another session must not access an inspection.');
    $usage = $store->inspectionUsage();
    recoveryStoreAssert($usage['count'] === 1 && $usage['bytes'] === strlen('verified-envelope'), 'Inspection usage must include retained encrypted envelopes.');
    $freshPartial = $root . DIRECTORY_SEPARATOR . 'inspections' . DIRECTORY_SEPARATOR . 'upload-' . str_repeat('a', 32) . '.partial';
    file_put_contents($freshPartial, 'partial-upload'); @chmod($freshPartial, 0600);
    $usage = $store->inspectionUsage();
    recoveryStoreAssert($usage['count'] === 2 && $usage['bytes'] === strlen('verified-envelope') + strlen('partial-upload'), 'Inspection usage must reserve fresh partial-upload bytes.');
    touch($freshPartial, time() - 7200);
    $usage = $store->inspectionUsage();
    recoveryStoreAssert(!file_exists($freshPartial) && $usage['count'] === 1, 'Stale partial uploads must be removed before quota admission.');
    $claimed = $store->claimInspection($inspection['inspection_id'], 7, 11, $operation['operation_id']);
    recoveryStoreAssert($claimed['claimed_operation_id'] === $operation['operation_id'], 'Inspection claim must bind the retained package to one operation.');
    recoveryStoreThrows(function () use ($store, $inspection) {
        $store->claimInspection($inspection['inspection_id'], 7, 11, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    }, 'A second operation must not claim the same inspection.', 'RESTORE_INSPECTION_ALREADY_CLAIMED');

    recoveryStoreThrows(function () use ($store) {
        $store->withExclusiveLock(function () use ($store) { $store->withExclusiveLock(function () {}); });
    }, 'A concurrent recovery operation must fail closed.', 'RECOVERY_OPERATION_IN_PROGRESS');

    echo "Recovery operation store assertions passed.\n";
} finally { recoveryStoreRemove($root); }
