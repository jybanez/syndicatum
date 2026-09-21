<?php

require_once dirname(__DIR__) . '/src/LegacyMigrationPlan.php';

function legacyPlanAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function legacyPlanReject(callable $operation, $message)
{
    try {
        $operation();
    } catch (RuntimeException $expected) {
        return;
    }
    throw new RuntimeException($message);
}

$plan = new LegacyMigrationPlan();
$plan->verifyFiles();
$historical = $plan->historicalMigrations();
$forward = $plan->forwardMigrations();
legacyPlanAssert(count($historical) === 25 && count($forward) === 5, 'Plan inventory is incomplete.');

$sourceRows = [];
foreach ($historical as $migration) {
    $sourceRows[] = ['version' => $migration['id'], 'checksum' => $migration['sha256']];
}
$targetRows = $sourceRows;
foreach ($forward as $migration) {
    $targetRows[] = ['version' => $migration['id'], 'checksum' => $migration['sha256']];
}
$plan->verifyLedgerRows($sourceRows, 'source');
$plan->verifyLedgerRows($targetRows, 'target');
legacyPlanReject(function () use ($plan, $sourceRows) {
    $plan->verifyLedgerRows($sourceRows, 'target');
}, 'Missing forward rows were accepted as target-ready.');
legacyPlanReject(function () use ($plan, $sourceRows) {
    array_pop($sourceRows);
    $plan->verifyLedgerRows($sourceRows, 'source');
}, 'Missing historical row was accepted.');
legacyPlanReject(function () use ($plan, $targetRows) {
    $targetRows[0]['checksum'] = str_repeat('0', 64);
    $plan->verifyLedgerRows($targetRows, 'target');
}, 'Edited historical checksum was accepted.');
legacyPlanReject(function () use ($plan, $targetRows) {
    $targetRows[0]['version'] = '202609190001_unknown';
    $plan->verifyLedgerRows($targetRows, 'target');
}, 'Unknown migration row was accepted.');

$sourceFile = dirname(__DIR__) . '/migrations/' . $historical[0]['id'] . '.php';
$raw = file_get_contents($sourceFile);
legacyPlanAssert(is_string($raw), 'Test migration could not be read.');
$lf = str_replace("\r\n", "\n", $raw);
$temp = tempnam(sys_get_temp_dir(), 'syndicatum-migration-');
legacyPlanAssert($temp !== false, 'Temporary migration file could not be created.');
try {
    file_put_contents($temp, str_replace("\n", "\r\n", $lf));
    legacyPlanAssert(LegacyMigrationPlan::canonicalSha256($temp) === $historical[0]['sha256'],
        'CRLF-only checkout variance changed the canonical digest.');
    file_put_contents($temp, $lf . "\n// true content edit\n");
    legacyPlanAssert(LegacyMigrationPlan::canonicalSha256($temp) !== $historical[0]['sha256'],
        'True migration content edit retained its canonical digest.');
    file_put_contents($temp, $lf . "\r");
    legacyPlanReject(function () use ($temp) { LegacyMigrationPlan::canonicalSha256($temp); },
        'Lone CR was accepted as canonical.');
} finally {
    unlink($temp);
}

echo "Legacy migration plan and checksum tests passed.\n";
