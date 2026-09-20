<?php

require_once dirname(__DIR__) . '/src/BackupEnvelope.php';
require_once dirname(__DIR__) . '/src/BackupSecrets.php';

function backupEnvelopeFail($message)
{
    fwrite(STDERR, 'FAIL  ' . $message . PHP_EOL);
    exit(1);
}

function backupEnvelopeAssert($condition, $message)
{
    if (!$condition) { backupEnvelopeFail($message); }
}

function backupEnvelopeThrows(callable $callback, $message)
{
    try { $callback(); } catch (Throwable $exception) { return; }
    backupEnvelopeFail($message);
}

function backupEnvelopeRemoveTree($path)
{
    if (is_dir($path) && !is_link($path)) {
        $items = scandir($path);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') { backupEnvelopeRemoveTree($path . DIRECTORY_SEPARATOR . $item); }
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'syndicatum-envelope-test-' . bin2hex(random_bytes(8));
$staging = $root . DIRECTORY_SEPARATOR . 'staging';
if (!mkdir($root, 0700, true) || !mkdir($staging, 0700, true)) { backupEnvelopeFail('Unable to create private fixture roots.'); }
@chmod($root, 0700); @chmod($staging, 0700);

try {
    $secretDocument = BackupSecrets::create([
        'PBB_AGENTCHAT_SECRET' => str_repeat('a', 32),
        'SYNDICATUM_MASTER_KEY' => str_repeat('b', 32),
    ]);
    $secretDocument = BackupSecrets::parse(BackupSecrets::encode($secretDocument));
    $classifications = BackupSecrets::assertTargetMatches($secretDocument, [
        'PBB_AGENTCHAT_SECRET' => str_repeat('a', 32),
        'SYNDICATUM_MASTER_KEY' => str_repeat('b', 32),
    ]);
    backupEnvelopeAssert($classifications['PBB_AGENTCHAT_DB_PASS']['disposition'] === 'operator_supplied', 'Database password must not be exported.');
    backupEnvelopeAssert($classifications['PBB_AGENTCHAT_PREVIOUS_SECRET']['disposition'] === 'regenerated', 'Absent previous secret must be explicitly classified.');
    backupEnvelopeThrows(function () use ($secretDocument) {
        BackupSecrets::assertTargetMatches($secretDocument, [
            'PBB_AGENTCHAT_SECRET' => str_repeat('x', 32),
            'SYNDICATUM_MASTER_KEY' => str_repeat('b', 32),
        ]);
    }, 'Mismatched target recovery secrets must fail closed.');

    $archive = $root . DIRECTORY_SEPARATOR . 'fixture.zip';
    $archiveBytes = str_repeat("archive-payload\0", 9000) . random_bytes(3333);
    file_put_contents($archive, $archiveBytes);
    @chmod($archive, 0600);
    $manifest = '{"contract_name":"syndicatum-package","format_version":"1.0"}';
    $key = random_bytes(32);
    $identity = [
        'application_version' => '1.0.0',
        'schema_baseline' => 'syndicatum-mysql84-1.0.0-baseline.1',
        'schema_head' => '202609180004',
        'source_commit' => str_repeat('a', 40),
    ];
    $envelope = $root . DIRECTORY_SEPARATOR . 'fixture.syndicatum-backup';
    $created = BackupEnvelope::encrypt($archive, $manifest, $envelope, $key, $identity, 4096);
    backupEnvelopeAssert(is_file($envelope) && filesize($envelope) > strlen($archiveBytes), 'Encrypted envelope was not created.');
    backupEnvelopeAssert($created['header']['frame_count'] > 1, 'Fixture must exercise multiple authenticated frames.');
    backupEnvelopeAssert($created['header']['archive_sha256'] === hash('sha256', $archiveBytes), 'Envelope archive digest is wrong.');

    $originalEnvelopeSha256 = hash_file('sha256', $envelope);
    backupEnvelopeThrows(function () use ($archive, $manifest, $envelope, $key, $identity) {
        BackupEnvelope::encrypt($archive, $manifest, $envelope, $key, $identity, 4096);
    }, 'An existing backup destination must never be replaced.');
    backupEnvelopeAssert(hash_file('sha256', $envelope) === $originalEnvelopeSha256, 'Rejected replacement changed the original backup envelope.');
    $partialEnvelopes = glob($envelope . '.partial-*');
    backupEnvelopeAssert(is_array($partialEnvelopes) && count($partialEnvelopes) === 0, 'Rejected replacement left a partial envelope behind.');

    $opened = BackupEnvelope::decryptToPrivateStage($envelope, $staging, $key);
    backupEnvelopeAssert(hash_equals($manifest, file_get_contents($opened['manifest_path'])), 'Authenticated manifest did not round-trip.');
    backupEnvelopeAssert(hash_equals($archiveBytes, file_get_contents($opened['archive_path'])), 'Authenticated archive did not round-trip.');
    backupEnvelopeAssert($opened['archive_sha256'] === hash('sha256', $archiveBytes), 'Trusted archive identity was not returned after authentication.');
    backupEnvelopeAssert($opened['manifest_sha256'] === hash('sha256', $manifest), 'Trusted manifest identity was not returned after authentication.');
    backupEnvelopeAssert(!file_exists($opened['stage_path'] . DIRECTORY_SEPARATOR . 'authenticated.plaintext'), 'Combined plaintext staging file was not removed.');
    BackupEnvelope::removePrivateStage($opened['stage_path'], $staging);
    backupEnvelopeAssert(!file_exists($opened['stage_path']), 'Authenticated stage cleanup failed.');

    backupEnvelopeThrows(function () use ($envelope, $staging) {
        BackupEnvelope::decryptToPrivateStage($envelope, $staging, random_bytes(32));
    }, 'Wrong backup key must fail closed.');

    $tampered = $root . DIRECTORY_SEPARATOR . 'tampered.syndicatum-backup';
    $bytes = file_get_contents($envelope);
    $bytes[strlen($bytes) - 17] = chr(ord($bytes[strlen($bytes) - 17]) ^ 1);
    file_put_contents($tampered, $bytes); @chmod($tampered, 0600);
    backupEnvelopeThrows(function () use ($tampered, $staging, $key) {
        BackupEnvelope::decryptToPrivateStage($tampered, $staging, $key);
    }, 'Ciphertext tampering must fail authentication.');

    $truncated = $root . DIRECTORY_SEPARATOR . 'truncated.syndicatum-backup';
    file_put_contents($truncated, substr(file_get_contents($envelope), 0, -23)); @chmod($truncated, 0600);
    backupEnvelopeThrows(function () use ($truncated, $staging, $key) {
        BackupEnvelope::decryptToPrivateStage($truncated, $staging, $key);
    }, 'Truncated envelopes must fail closed.');

    $remainingStages = glob($staging . DIRECTORY_SEPARATOR . 'backup-stage-*');
    backupEnvelopeAssert(is_array($remainingStages) && count($remainingStages) === 0, 'Failed authentication left plaintext staging data behind.');
    backupEnvelopeThrows(function () use ($archive, $manifest, $root, $identity) {
        BackupEnvelope::encrypt($archive, $manifest, $root . DIRECTORY_SEPARATOR . 'bad-key.backup', 'short', $identity);
    }, 'Short encryption keys must be rejected.');
    backupEnvelopeThrows(function () use ($root) {
        BackupEnvelope::removePrivateStage($root, $root);
    }, 'Cleanup must reject deletion of the trusted root itself.');

    echo "Backup envelope assertions passed.\n";
} finally {
    backupEnvelopeRemoveTree($root);
}
