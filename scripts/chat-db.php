<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/SchemaMigrator.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ExpansionMigrator.php';

$command = isset($argv[1]) ? $argv[1] : 'help';

try {
    $repository = new ChatRepository(Db::pdo());

    if ($command === 'install-schema') {
        $repository->installSchema();
        echo "Schema installed.\n";
        exit(0);
    }

    if ($command === 'migrate') {
        $migrations = (new SchemaMigrator(Db::pdo()))->migrate();
        echo json_encode([
            'applied' => $migrations,
            'count' => count($migrations),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'migration-status') {
        echo json_encode((new SchemaMigrator(Db::pdo()))->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'bootstrap-admin') {
        $email = isset($argv[2]) ? $argv[2] : '';
        $displayName = isset($argv[3]) ? $argv[3] : '';
        $password = getenv('SYNDICATUM_BOOTSTRAP_PASSWORD');
        if ($email === '' || $displayName === '' || $password === false) {
            throw new RuntimeException('Set SYNDICATUM_BOOTSTRAP_PASSWORD, then use: php scripts/chat-db.php bootstrap-admin admin@example.test "Display Name"');
        }
        $user = (new AuthService(Db::pdo()))->bootstrapAdministrator($email, $displayName, $password);
        echo json_encode($user, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'migrate-current-data') {
        $ownerUserId = isset($argv[2]) ? (int) $argv[2] : 0;
        $projectName = isset($argv[3]) ? $argv[3] : 'PBB Coordination';
        if ($ownerUserId < 1) {
            throw new RuntimeException('Usage: php scripts/chat-db.php migrate-current-data <administrator-user-id> "PBB Coordination"');
        }
        echo json_encode((new ExpansionMigrator(Db::pdo()))->migrateLegacyData($ownerUserId, $projectName), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'reconcile-expansion') {
        echo json_encode((new ExpansionMigrator(Db::pdo()))->reconciliation(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'expansion-preflight') {
        echo json_encode((new ExpansionMigrator(Db::pdo()))->preflight(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
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
    echo "  php scripts/chat-db.php migrate\n";
    echo "  php scripts/chat-db.php migration-status\n";
    echo "  SYNDICATUM_BOOTSTRAP_PASSWORD=... php scripts/chat-db.php bootstrap-admin admin@example.test \"Display Name\"\n";
    echo "  php scripts/chat-db.php migrate-current-data <administrator-user-id> \"PBB Coordination\"\n";
    echo "  php scripts/chat-db.php reconcile-expansion\n";
    echo "  php scripts/chat-db.php expansion-preflight\n";
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
