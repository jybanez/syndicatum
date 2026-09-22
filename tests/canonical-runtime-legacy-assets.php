<?php

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php canonical-runtime-legacy-assets.php PACKAGE_STAGE INSTALL_ROOT\n");
    exit(2);
}

$stage = rtrim($argv[1], '/');
$install = rtrim($argv[2], '/');
$source = $stage . '/schema/legacy-upgrade';
$planSource = $source . '/plan.json';
$planRuntime = $install . '/schema/mysql84/legacy-upgrade-plan.json';
if (!is_file($planSource) || !is_file($planRuntime)
    || !hash_equals(hash_file('sha256', $planSource), hash_file('sha256', $planRuntime))) {
    throw new RuntimeException('Installed legacy plan differs from the authenticated package.');
}

$sourceFiles = glob($source . '/migrations/*.php');
$runtimeFiles = glob($install . '/migrations/*.php');
if ($sourceFiles === false || $runtimeFiles === false
    || count($sourceFiles) !== 30 || count($runtimeFiles) !== 30) {
    throw new RuntimeException('Installed legacy migration inventory is incomplete.');
}
foreach ($sourceFiles as $file) {
    $runtime = $install . '/migrations/' . basename($file);
    if (!is_file($runtime) || !hash_equals(hash_file('sha256', $file), hash_file('sha256', $runtime))) {
        throw new RuntimeException('Installed legacy migration differs from the authenticated package.');
    }
}

// Loading the class from the assembled install root exercises its unchanged
// default paths, not explicit paths supplied by this test.
require_once $install . '/src/LegacyMigrationPlan.php';
$plan = new LegacyMigrationPlan();
$plan->verifyFiles();
if (count($plan->historicalMigrations()) !== 25 || count($plan->forwardMigrations()) !== 5) {
    throw new RuntimeException('Installed legacy plan has an unexpected lineage.');
}

function rejectsRuntimeAssets(callable $operation, $message)
{
    try {
        $operation();
    } catch (RuntimeException $expected) {
        return;
    }
    throw new RuntimeException($message);
}

$scratch = sys_get_temp_dir() . '/syndicatum-runtime-assets-' . bin2hex(random_bytes(8));
$scratchMigrations = $scratch . '/migrations';
if (!mkdir($scratchMigrations, 0700, true)) {
    throw new RuntimeException('Could not create isolated runtime-asset test directory.');
}
$scratchPlan = $scratch . '/plan.json';
try {
    if (!copy($planRuntime, $scratchPlan)) {
        throw new RuntimeException('Could not stage isolated legacy plan.');
    }
    foreach ($runtimeFiles as $file) {
        if (!copy($file, $scratchMigrations . '/' . basename($file))) {
            throw new RuntimeException('Could not stage isolated legacy migration.');
        }
    }
    $scratchVerifier = new LegacyMigrationPlan($scratchPlan, $scratchMigrations);
    $scratchVerifier->verifyFiles();
    $firstFile = $scratchMigrations . '/' . basename($runtimeFiles[0]);
    unlink($firstFile);
    rejectsRuntimeAssets(function () use ($scratchVerifier) { $scratchVerifier->verifyFiles(); },
        'Missing installed migration was accepted.');
    copy($runtimeFiles[0], $firstFile);
    file_put_contents($firstFile, "\n// tampered\n", FILE_APPEND);
    rejectsRuntimeAssets(function () use ($scratchVerifier) { $scratchVerifier->verifyFiles(); },
        'Tampered installed migration was accepted.');
    file_put_contents($scratchPlan, '{');
    rejectsRuntimeAssets(function () use ($scratchPlan, $scratchMigrations) {
        new LegacyMigrationPlan($scratchPlan, $scratchMigrations);
    }, 'Tampered installed plan was accepted.');
    unlink($scratchPlan);
    set_error_handler(function () { return true; });
    try {
        rejectsRuntimeAssets(function () use ($scratchPlan, $scratchMigrations) {
            new LegacyMigrationPlan($scratchPlan, $scratchMigrations);
        }, 'Missing installed plan was accepted.');
    } finally {
        restore_error_handler();
    }
} finally {
    foreach (glob($scratchMigrations . '/*.php') ?: [] as $file) {
        unlink($file);
    }
    if (is_file($scratchPlan)) {
        unlink($scratchPlan);
    }
    rmdir($scratchMigrations);
    rmdir($scratch);
}

echo "Canonical runtime legacy assets: 1 plan and 30 byte-identical migrations verified; missing/tampered assets rejected.\n";
