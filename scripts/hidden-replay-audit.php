<?php

$root = dirname(__DIR__);

function auditFail($message)
{
    fwrite(STDERR, 'FAIL  ' . $message . PHP_EOL);
    exit(1);
}

function relativePath($root, $path)
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

function matchingFiles($root, $pattern)
{
    $matches = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = relativePath($root, $file->getPathname());
        if (strpos($relative, '.git/') === 0 || strpos($relative, 'docs/') === 0 || strpos($relative, 'schema/') === 0) {
            continue;
        }
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, ['php', 'sh', 'ps1', 'yaml', 'yml'], true)) {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (is_string($contents) && preg_match($pattern, $contents)) {
            $matches[] = $relative;
        }
    }
    sort($matches, SORT_STRING);
    return $matches;
}

function assertExactFiles($label, array $actual, array $expected)
{
    sort($expected, SORT_STRING);
    if ($actual !== $expected) {
        auditFail($label . ' call-site inventory changed. Expected [' . implode(', ', $expected)
            . '], observed [' . implode(', ', $actual) . '].');
    }
}

$installSchemaCallers = matchingFiles($root, '/(?:->|::)installSchema\s*\(/');
assertExactFiles('Legacy installSchema', $installSchemaCallers, [
    'scripts/chat-db.php',
    'tests/account-profile.php',
    'tests/account-sso.php',
    'tests/agent-activation.php',
    'tests/avatar-webhooks.php',
    'tests/chatgpt-oauth.php',
    'tests/expansion.php',
    'tests/google-sso.php',
    'tests/migrations.php',
    'tests/project-api.php',
    'tests/registration.php',
    'tests/responses-api-activation.php',
    'tests/responsibility-events.php',
    'tests/run.php',
    'tests/surfaces.php',
    'tests/workspace-agent-triggers.php',
]);

$migratorCallers = matchingFiles($root, '/new\s+SchemaMigrator\s*\(/');
assertExactFiles('SchemaMigrator construction', $migratorCallers, [
    'scripts/chat-db.php',
    'src/ChatRepository.php',
    'tests/migrations.php',
]);

$entrypoint = file_get_contents($root . '/docker/entrypoint.sh');
if (!is_string($entrypoint) || strpos($entrypoint, 'chat-db.php startup-schema') === false
    || strpos($entrypoint, 'chat-db.php install-schema') !== false) {
    auditFail('Docker entrypoint must use only the state-aware startup-schema route.');
}
$claim = file_get_contents($root . '/api/claim.php');
if (!is_string($claim) || strpos($claim, 'InstallationState') === false
    || preg_match('/installSchema\s*\(/', $claim)) {
    auditFail('Legacy claim endpoint must be read-only with respect to installation state.');
}
$dockerAcceptance = file_get_contents($root . '/scripts/docker-acceptance.ps1');
if (!is_string($dockerAcceptance)
    || preg_match('/chat-db\.php[\'\",\s]+[\'\"](?:install-schema|migrate)[\'\"]/', $dockerAcceptance)) {
    auditFail('Fresh Docker acceptance must not invoke legacy installation or migration replay.');
}
$legacyAcceptance = file_get_contents($root . '/scripts/mysql57-to-84-migration-acceptance.ps1');
if (!is_string($legacyAcceptance) || strpos($legacyAcceptance, 'SYNDICATUM_ALLOW_LEGACY_UPGRADE=1') === false) {
    auditFail('MySQL 5.7 upgrade acceptance must retain explicit legacy authorization.');
}

echo 'Hidden-replay audit passed.' . PHP_EOL;
echo 'Legacy installSchema callers (definition adapter and test fixtures): ' . count($installSchemaCallers) . PHP_EOL;
echo 'SchemaMigrator constructors (legacy implementation/CLI/test): ' . count($migratorCallers) . PHP_EOL;
echo 'Fresh runtime and Docker acceptance callers: 0' . PHP_EOL;
