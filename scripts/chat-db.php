<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ChatRepository.php';
require_once dirname(__DIR__) . '/src/SchemaMigrator.php';
require_once dirname(__DIR__) . '/src/AuthService.php';
require_once dirname(__DIR__) . '/src/ExpansionMigrator.php';
require_once dirname(__DIR__) . '/src/BaselineInstaller.php';

$command = isset($argv[1]) ? $argv[1] : 'help';

try {
    $repository = new ChatRepository(Db::pdo());

    if ($command === 'baseline-install') {
        $packageSha256 = getenv('SYNDICATUM_PACKAGE_SHA256');
        $releaseSourceCommit = getenv('SYNDICATUM_RELEASE_SOURCE_COMMIT');
        if ($packageSha256 === false || $releaseSourceCommit === false) {
            throw new RuntimeException('SYNDICATUM_PACKAGE_SHA256 and SYNDICATUM_RELEASE_SOURCE_COMMIT are required.');
        }
        $installationId = getenv('SYNDICATUM_INSTALLATION_ID');
        if ($installationId === false || trim($installationId) === '') {
            $installationId = Db::uuidV4();
        }
        $installedAt = getenv('SYNDICATUM_INSTALLED_AT');
        if ($installedAt === false || trim($installedAt) === '') {
            $installedAt = gmdate('Y-m-d\TH:i:s\Z');
        }
        $root = dirname(__DIR__);
        $installer = new BaselineInstaller(
            Db::pdo(),
            $root . '/schema/mysql84/schema.sql',
            $root . '/schema/mysql84/baseline.json'
        );
        echo json_encode($installer->install([
            'application_version' => '1.0.0',
            'schema_baseline' => 'syndicatum-mysql84-1.0.0-baseline.1',
            'schema_head' => '202609180004',
            'baseline_source_commit' => '8d8cfb12aff96ac1a7ce7ce1a8ad05c6c5e5ec9d',
            'release_source_commit' => $releaseSourceCommit,
            'package_sha256' => $packageSha256,
            'package_format_version' => '1.0',
            'installation_id' => $installationId,
            'installed_at' => $installedAt,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

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

    if ($command === 'legacy-retirement-status') {
        $days = isset($argv[2]) ? max(1, (int) $argv[2]) : 30;
        echo json_encode((new ExpansionMigrator(Db::pdo()))->legacyRetirementStatus($days), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'expansion-preflight') {
        echo json_encode((new ExpansionMigrator(Db::pdo()))->preflight(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'generate-token') {
        $projectName = isset($argv[2]) ? $argv[2] : '';
        if ($projectName === '') {
            throw new RuntimeException('Usage: php scripts/chat-db.php generate-token "PBB Chatviewer" ["PBB Coordination"]');
        }
        $projectRef = isset($argv[3]) ? $argv[3] : null;
        $token = $repository->generateToken($projectName, $projectRef);
        echo json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'generate-claim-code') {
        $projectName = isset($argv[2]) ? $argv[2] : '';
        if ($projectName === '') {
            throw new RuntimeException('Usage: php scripts/chat-db.php generate-claim-code "PBB Helper" ["PBB Coordination"]');
        }
        $projectRef = isset($argv[3]) ? $argv[3] : null;
        $claim = $repository->generateClaimCode($projectName, $projectRef);
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
    echo "  SYNDICATUM_PACKAGE_SHA256=... SYNDICATUM_RELEASE_SOURCE_COMMIT=... php scripts/chat-db.php baseline-install\n";
    echo "  php scripts/chat-db.php install-schema\n";
    echo "  php scripts/chat-db.php migrate\n";
    echo "  php scripts/chat-db.php migration-status\n";
    echo "  SYNDICATUM_BOOTSTRAP_PASSWORD=... php scripts/chat-db.php bootstrap-admin admin@example.test \"Display Name\"\n";
    echo "  php scripts/chat-db.php migrate-current-data <administrator-user-id> \"PBB Coordination\"\n";
    echo "  php scripts/chat-db.php reconcile-expansion\n";
    echo "  php scripts/chat-db.php legacy-retirement-status [observation-days]\n";
    echo "  php scripts/chat-db.php expansion-preflight\n";
    echo "  php scripts/chat-db.php generate-claim-code \"PBB Helper\" [\"PBB Coordination\"]\n";
    echo "  php scripts/chat-db.php generate-claim-codes\n";
    echo "  php scripts/chat-db.php generate-token \"PBB Chatviewer\" [\"PBB Coordination\"]\n";
    echo "  php scripts/chat-db.php payload-summary\n";
    echo "  php scripts/chat-db.php credential-migration-summary\n";
    exit(0);
} catch (Exception $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
