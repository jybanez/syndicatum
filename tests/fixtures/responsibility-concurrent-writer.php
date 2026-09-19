<?php

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/ProjectRepository.php';

if (count($argv) !== 8) {
    fwrite(STDERR, "Expected project, actor, request, responder, expected, key and kind.\n");
    exit(3);
}

$pdo = Db::pdo();
$access = [
    'project_id' => (int) $argv[1],
    'participant_id' => (int) $argv[2],
    'project_status' => 'active',
    'identity' => ['kind' => 'human'],
    'role' => 'member',
];
echo 'READY ' . $pdo->query('SELECT CONNECTION_ID()')->fetchColumn() . PHP_EOL;
flush();
try {
    $result = (new ProjectRepository($pdo))->createMessage($access, [
        'body' => $argv[7] . ' evidence',
        'idempotency_key' => $argv[6],
        'responsibility_event' => [
            'kind' => $argv[7],
            'request_message_id' => (int) $argv[3],
            'initial_responder_participant_id' => (int) $argv[4],
            'expected_event_id' => (int) $argv[5],
        ],
    ]);
    echo 'CREATED ' . $result['message']['id'] . ' ' . $argv[7]
        . ' ' . $argv[6] . PHP_EOL;
    exit(0);
} catch (Exception $error) {
    echo 'ERROR ' . $error->getMessage() . PHP_EOL;
    exit(2);
}
