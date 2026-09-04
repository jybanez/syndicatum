<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';

$command = isset($argv[1]) ? $argv[1] : 'help';

try {
    $repository = new ChatRepository(Db::pdo());

    if ($command === 'install-schema') {
        $repository->installSchema();
        echo "Schema installed.\n";
        exit(0);
    }

    if ($command === 'generate-token') {
        $projectName = isset($argv[2]) ? $argv[2] : '';
        if ($projectName === '') {
            throw new RuntimeException('Usage: php scripts/chat-db.php generate-token "PBB Chatviewer"');
        }
        $token = $repository->generateToken($projectName);
        echo json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'generate-claim-code') {
        $projectName = isset($argv[2]) ? $argv[2] : '';
        if ($projectName === '') {
            throw new RuntimeException('Usage: php scripts/chat-db.php generate-claim-code "PBB Helper"');
        }
        $claim = $repository->generateClaimCode($projectName);
        echo json_encode($claim, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'generate-claim-codes') {
        $claims = $repository->generateClaimCodes();
        echo json_encode($claims, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'payload-summary') {
        $payload = $repository->payload();
        echo json_encode([
            'messages' => $payload['meta']['message_count'],
            'direct' => $payload['meta']['direct_count'],
            'participants' => $payload['meta']['participant_count'],
            'days' => $payload['meta']['day_count'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'credential-migration-summary') {
        echo json_encode($repository->credentialMigrationSummary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    echo "Usage:\n";
    echo "  php scripts/chat-db.php install-schema\n";
    echo "  php scripts/chat-db.php generate-claim-code \"PBB Helper\"\n";
    echo "  php scripts/chat-db.php generate-claim-codes\n";
    echo "  php scripts/chat-db.php generate-token \"PBB Chatviewer\"\n";
    echo "  php scripts/chat-db.php payload-summary\n";
    echo "  php scripts/chat-db.php credential-migration-summary\n";
    exit(0);
} catch (Exception $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
