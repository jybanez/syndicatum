<?php

require_once __DIR__ . '/ArchiveSafetyReader.php';
require_once __DIR__ . '/BaselineMetadata.php';
require_once __DIR__ . '/LegacyMigrationPlan.php';
require_once __DIR__ . '/PackageManifest.php';

/**
 * A legacy upgrade may read migration PHP only from a validated release stage.
 * The caller supplies independently pinned publication identities, never hashes
 * computed from the archive being accepted.
 */
final class LegacyUpgradePackage
{
    private $stage;
    private $plan;
    private $baseline;
    private $archiveSha256;
    private $sourceCommit;
    private $formatVersion;

    private function __construct(ArchiveExtractionStage $stage, LegacyMigrationPlan $plan, array $baseline, $archiveSha256, $sourceCommit, $formatVersion)
    {
        $this->stage = $stage;
        $this->plan = $plan;
        $this->baseline = $baseline;
        $this->archiveSha256 = $archiveSha256;
        $this->sourceCommit = $sourceCommit;
        $this->formatVersion = $formatVersion;
    }

    public static function open($archivePath, $manifestPath, $provenancePath, array $trust, $stagingRoot, $publicRoot)
    {
        return self::openVerified($archivePath, $manifestPath, $provenancePath, $trust,
            $stagingRoot, $publicRoot, null);
    }

    /** Candidate policy is reachable only through the isolated test harness via reflection. */
    private static function openVerified($archivePath, $manifestPath, $provenancePath, array $trust,
        $stagingRoot, $publicRoot, $candidatePolicy)
    {
        $candidate = $candidatePolicy !== null;
        if ($candidate && (!is_object($candidatePolicy)
            || get_class($candidatePolicy) !== 'LegacyCandidateTrustPolicy'
            || !method_exists($candidatePolicy, 'assertPinned'))) {
            throw new InvalidArgumentException('Candidate package trust is restricted to the isolated test fixture.');
        }
        $required = ['archive_sha256', 'manifest_sha256', 'content_tree_sha256',
            'provenance_sha256', 'source_commit', 'source_tag', 'repository'];
        if (count($trust) !== count($required) || array_diff($required, array_keys($trust))
            || !preg_match('/\A[a-f0-9]{64}\z/', $trust['archive_sha256'])
            || !preg_match('/\A[a-f0-9]{64}\z/', $trust['manifest_sha256'])
            || !preg_match('/\A[a-f0-9]{64}\z/', $trust['content_tree_sha256'])
            || !preg_match('/\A[a-f0-9]{64}\z/', $trust['provenance_sha256'])
            || !preg_match('/\A[a-f0-9]{40}\z/', $trust['source_commit'])
            || !is_string($trust['source_tag']) || $trust['source_tag'] === ''
            || !is_string($trust['repository']) || $trust['repository'] === '') {
            throw new InvalidArgumentException('Externally pinned release publication identity is incomplete.');
        }
        if ($candidate) {
            $candidatePolicy->assertPinned($trust);
        }
        $manifestJson = @file_get_contents($manifestPath);
        $provenanceJson = @file_get_contents($provenancePath);
        if (!is_string($manifestJson) || !is_string($provenanceJson)
            || !hash_equals($trust['provenance_sha256'], hash('sha256', $provenanceJson))) {
            throw new RuntimeException('Release sidecars do not match the pinned provenance digest.');
        }
        $provenance = json_decode($provenanceJson, true);
        if (!is_array($provenance) || json_last_error() !== JSON_ERROR_NONE
            || !isset($provenance['contract_name'], $provenance['format_version'], $provenance['canonical'],
                $provenance['archive_sha256'], $provenance['manifest_sha256'], $provenance['content_tree_sha256'],
                $provenance['source_commit'], $provenance['source_tag'], $provenance['repository'],
                $provenance['workflow_ref'], $provenance['workflow_sha'], $provenance['run_id'],
                $provenance['run_attempt'], $provenance['toolchain'], $provenance['producer_version'],
                $provenance['baseline_id'], $provenance['schema_head'], $provenance['baseline_schema_sha256'],
                $provenance['baseline_source_commit'])
            || $provenance['contract_name'] !== 'syndicatum-release-provenance'
            || $provenance['format_version'] !== '1.0'
            || $provenance['canonical'] !== !$candidate
            || $provenance['producer_version'] !== '1.0.0'
            || !hash_equals($trust['archive_sha256'], (string) $provenance['archive_sha256'])
            || !hash_equals($trust['manifest_sha256'], hash('sha256', $manifestJson))
            || !hash_equals($trust['manifest_sha256'], (string) $provenance['manifest_sha256'])
            || !hash_equals($trust['content_tree_sha256'], (string) $provenance['content_tree_sha256'])
            || !hash_equals($trust['source_commit'], (string) $provenance['source_commit'])
            || !hash_equals($trust['source_tag'], (string) $provenance['source_tag'])
            || !hash_equals($trust['repository'], (string) $provenance['repository'])
            || (!$candidate && !hash_equals($trust['repository'] . '/.github/workflows/contract-ci.yml@refs/tags/' . $trust['source_tag'],
                (string) $provenance['workflow_ref']))
            || ($candidate && ((string) $provenance['workflow_ref'] !== 'candidate'
                || (string) $provenance['run_id'] !== 'candidate'))
            || !hash_equals($trust['source_commit'], (string) $provenance['workflow_sha'])
            || (!$candidate && !preg_match('/\A[1-9][0-9]*\z/', (string) $provenance['run_id']))
            || !preg_match('/\A[1-9][0-9]*\z/', (string) $provenance['run_attempt'])
            || !preg_match('/\Apython-[0-9]+\.[0-9]+\.[0-9]+-zip-stored\z/', (string) $provenance['toolchain'])) {
            throw new RuntimeException('Release provenance does not prove the pinned canonical publication.');
        }
        $manifest = PackageManifest::parse($manifestJson);
        $context = ArchiveValidationContext::forRelease(
            $trust['archive_sha256'], hash('sha256', $manifestJson), [
                'source_commit' => $trust['source_commit'],
                'source_tag' => $trust['source_tag'],
                'schema_baseline' => $manifest['schema_baseline'],
                'schema_head' => $manifest['schema_head'],
            ]
        );
        $stage = (new ArchiveSafetyReader())->extractToNewStage(
            $archivePath, $manifestJson, $context, $stagingRoot, $publicRoot
        );
        $verifiedManifest = $stage->validationReport()->manifest();
        if (!hash_equals((string) $provenance['content_tree_sha256'], (string) $verifiedManifest['content_tree_sha256'])) {
            throw new RuntimeException('Release content-tree identity differs from pinned provenance.');
        }
        $root = $stage->path();
        $baselineJson = @file_get_contents($root . '/schema/baselines/mysql84/baseline.json');
        $baseline = is_string($baselineJson) ? json_decode($baselineJson, true) : null;
        if (!is_array($baseline) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Authenticated release baseline is unavailable.');
        }
        BaselineMetadata::fromArray($baseline);
        $legacyRoot = $root . '/schema/legacy-upgrade';
        $plan = new LegacyMigrationPlan($legacyRoot . '/plan.json', $legacyRoot . '/migrations');
        $plan->verifyFiles();
        $planMetadata = $plan->metadata();
        if (!hash_equals($baseline['baseline_id'], $planMetadata['target_baseline_id'])
            || !hash_equals($baseline['migration_cutover'], $planMetadata['target_schema_head'])
            || !hash_equals($baseline['baseline_id'], $verifiedManifest['schema_baseline'])
            || !hash_equals($baseline['schema_head'], $verifiedManifest['schema_head'])
            || !hash_equals($baseline['baseline_id'], (string) $provenance['baseline_id'])
            || !hash_equals($baseline['schema_head'], (string) $provenance['schema_head'])
            || !hash_equals($baseline['schema_sha256'], (string) $provenance['baseline_schema_sha256'])
            || !hash_equals($baseline['source_commit'], (string) $provenance['baseline_source_commit'])) {
            throw new RuntimeException('Authenticated release baseline and legacy plan disagree.');
        }
        return new self($stage, $plan, $baseline, $trust['archive_sha256'],
            $trust['source_commit'], $verifiedManifest['format_version']);
    }

    public function plan() { return $this->plan; }
    public function baseline() { return $this->baseline; }
    public function archiveSha256() { return $this->archiveSha256; }
    public function sourceCommit() { return $this->sourceCommit; }
    public function formatVersion() { return $this->formatVersion; }

    public function baselineSchemaPath()
    {
        return $this->stage->path() . '/schema/baselines/mysql84/schema.sql';
    }

    public function baselineMetadataPath()
    {
        return $this->stage->path() . '/schema/baselines/mysql84/baseline.json';
    }

    public function postMigrationDirectory()
    {
        return $this->stage->path() . '/schema/baselines/mysql84/migrations';
    }

    public function forwardMigrationPath($id)
    {
        foreach ($this->plan->forwardMigrations() as $migration) {
            if ($migration['id'] === $id) {
                return $this->stage->path() . '/schema/legacy-upgrade/migrations/' . $id . '.php';
            }
        }
        throw new InvalidArgumentException('Migration is absent from the authenticated forward plan.');
    }
}
