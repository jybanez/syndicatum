<?php

require_once dirname(__DIR__) . '/src/Db.php';
require_once dirname(__DIR__) . '/src/ResponsibilityMigrationAssessment.php';

if (PHP_SAPI !== 'cli' || (isset($argv[1])
    && !preg_match('/^[1-9][0-9]*$/', (string) $argv[1]))
    || isset($argv[2])) {
    fwrite(STDERR, "Usage: php scripts/responsibility-baseline-preflight.php [PROJECT_ID]\n");
    exit(2);
}

try {
    $pdo = Db::pdo();
    if (!Db::tableExists($pdo, 'responsibility_events')
        || !Db::columnExists($pdo, 'message_addressees',
            'responsibility_status_generation')) {
        throw new RuntimeException('Responsibility schema is not installed.');
    }
    $assessment = new ResponsibilityMigrationAssessment($pdo);
    $report = isset($argv[1])
        ? $assessment->report((int) $argv[1]) : $assessment->summary();
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} catch (Exception $error) {
    fwrite(STDERR, "Responsibility baseline preflight failed: "
        . $error->getMessage() . "\n");
    exit(1);
}
