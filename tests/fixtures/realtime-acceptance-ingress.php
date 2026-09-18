<?php

// Disposable, internal-only ingress for the Docker acceptance harness.
if ($_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: text/plain');
    echo 'ok';
    return;
}

if ($_SERVER['REQUEST_URI'] !== '/publish' || $_SERVER['REQUEST_METHOD'] !== 'POST'
    || (isset($_SERVER['HTTP_X_REALTIME_BACKEND_SECRET']) ? $_SERVER['HTTP_X_REALTIME_BACKEND_SECRET'] : '') !== 'acceptance-only-ingress-secret') {
    http_response_code(403);
    return;
}

$request = json_decode(file_get_contents('php://input'), true);
$eventId = is_array($request) && isset($request['event_id']) ? $request['event_id'] : '';
if (!is_string($eventId) || !preg_match('/^[a-f0-9-]{36}$/i', $eventId)) {
    http_response_code(400);
    return;
}

$receiptPath = '/receipts/' . strtolower($eventId);
$receipt = fopen($receiptPath, 'ab');
if (!$receipt || !flock($receipt, LOCK_EX)) {
    http_response_code(500);
    return;
}
fwrite($receipt, json_encode($request, JSON_UNESCAPED_SLASHES) . "\n");
fflush($receipt);
flock($receipt, LOCK_UN);
fclose($receipt);
if (isset($request['event_type']) && $request['event_type'] === 'acceptance.retry') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"acceptance-only retryable failure"}';
    return;
}
if (isset($request['event_type']) && $request['event_type'] === 'acceptance.uncertain') {
    // Simulate a destination that applied the effect before an ambiguous
    // response, then deduplicates a retry by the stable event identity.
    $effect = @fopen('/receipts/effect-' . strtolower($eventId), 'x');
    if ($effect) {
        fclose($effect);
        http_response_code(503);
        echo '{"error":"effect applied but acknowledgement unavailable"}';
        return;
    }
    if (!is_file('/receipts/effect-' . strtolower($eventId))) {
        http_response_code(500);
        return;
    }
}
http_response_code(202);
header('Content-Type: application/json');
echo '{"accepted":true}';
