<?php

/** Offline-only trust fixture. Not shipped by the closed canonical release policy. */
require_once dirname(__DIR__) . '/src/LegacyUpgradePackage.php';

final class LegacyCandidateTrustPolicy
{
    const COMMIT = 'a006ce1c243cdc3a229d7b03f092921d5613f01b';
    const ARCHIVE = '84dd8c4feb9ff8abdf1f0f21a796ed5cbf6c77520c3eef494f5dcb8e6585302c';
    const MANIFEST = '3d53142b82e11cc2805c468067dd79e2f8d4cf849b24ab6b8c4e3bd95ea8fdda';
    const TREE = '7e53d334163f959d23c8c5ffd26ab56c5b4f66f8071eb64fe46a3f57c74ec4aa';
    const PROVENANCE = 'd8d4e5603533523c9b8afeea3f669f984cb25f498767f33fadf9a3a594aef766';
    const TAG = 'candidate-legacy-uplift-a006ce1';

    public function pins()
    {
        return [
            'archive_sha256' => self::ARCHIVE,
            'manifest_sha256' => self::MANIFEST,
            'content_tree_sha256' => self::TREE,
            'provenance_sha256' => self::PROVENANCE,
            'source_commit' => self::COMMIT,
            'source_tag' => self::TAG,
            'repository' => '.',
        ];
    }

    public function assertPinned(array $actual)
    {
        if ($actual !== $this->pins()) {
            throw new RuntimeException('Candidate package identity is not the frozen isolated-test fixture.');
        }
    }
}

function legacyCandidateOpen($archive, $manifest, $provenance, $stageRoot, $publicRoot, array $pins = null)
{
    $policy = new LegacyCandidateTrustPolicy();
    $method = new ReflectionMethod('LegacyUpgradePackage', 'openVerified');
    $method->setAccessible(true);
    return $method->invoke(null, $archive, $manifest, $provenance,
        $pins === null ? $policy->pins() : $pins, $stageRoot, $publicRoot, $policy);
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    if ($argc !== 4) {
        fwrite(STDERR, "Usage: legacy-candidate-package.php PACKAGE_DIR PRIVATE_STAGE_ROOT PUBLIC_ROOT\n");
        exit(2);
    }
    $directory = $argv[1];
    $archive = $directory . '/syndicatum-v1.0.0.zip';
    $manifest = $directory . '/syndicatum-v1.0.0.manifest.json';
    $provenance = $directory . '/syndicatum-v1.0.0.provenance.json';
    $policy = new LegacyCandidateTrustPolicy();
    try {
        LegacyUpgradePackage::open($archive, $manifest, $provenance,
            $policy->pins(), $argv[2], $argv[3]);
        throw new RuntimeException('Production trust unexpectedly accepted a candidate package.');
    } catch (RuntimeException $exception) {
        if (strpos($exception->getMessage(), 'Release provenance does not prove') === false) {
            throw $exception;
        }
    }
    foreach (['archive_sha256', 'manifest_sha256', 'content_tree_sha256',
        'provenance_sha256', 'source_commit'] as $field) {
        $tampered = $policy->pins();
        $tampered[$field] = str_repeat('0', strlen($tampered[$field]));
        try {
            legacyCandidateOpen($archive, $manifest, $provenance, $argv[2], $argv[3], $tampered);
            throw new RuntimeException('Tampered candidate pin was accepted: ' . $field);
        } catch (RuntimeException $exception) {
            if (strpos($exception->getMessage(), 'not the frozen isolated-test fixture') === false) {
                throw $exception;
            }
        }
    }
    $package = legacyCandidateOpen($archive, $manifest, $provenance, $argv[2], $argv[3]);
    echo json_encode([
        'production_rejected' => true,
        'candidate_pins_accepted' => true,
        'pin_mismatch_rejected' => true,
        'canonical' => false,
        'source_commit' => $package->sourceCommit(),
        'archive_sha256' => $package->archiveSha256(),
        'baseline_schema_path' => $package->baselineSchemaPath(),
    ], JSON_UNESCAPED_SLASHES) . "\n";
}
