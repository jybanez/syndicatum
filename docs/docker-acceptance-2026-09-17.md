# Docker acceptance evidence — 2026-09-17

## Result

**Passed** on a clean Ubuntu 24.04 GitHub Actions runner against commit
`32882f4794fcf0c7b35ff14b55d2be0a418435f3`. This is source-tree lifecycle
evidence on the selected MySQL 5.7.44 baseline, not acceptance of a published
release artifact or approval for external production deployment.

[Workflow run 35228305400](https://github.com/jybanez/syndicatum/actions/runs/35228305400)
passed both `source-contract` and `docker-source-acceptance`. The latter retained
`docker-source-acceptance-32882f4794fcf0c7b35ff14b55d2be0a418435f3-1`
as a run artifact for 30 days.

## Environment

- Docker Engine: `28.0.4`
- Docker Compose: `v2.38.2`
- PowerShell: `7.6.5`
- Database: MySQL `5.7.44`; observed SQL mode included `STRICT_TRANS_TABLES`
- Acceptance project: `syndicatum-acceptance-88214e37ae36`

## Verified by `scripts/docker-acceptance.ps1`

- project-unique images, isolated startup, and database-version/strict-mode
  preflight before application startup;
- all 25 ordered migrations verified;
- application and database health and application-root reachability;
- logical database backup, deliberate mutation, restoration, and probe
  verification; and
- guarded cleanup of the isolated containers, volumes, and networks.

The job logged `Docker acceptance passed: clean start, migrations, health,
reachability, backup, and restore.` The project-scoped database and avatar
volumes were removed at the end of the run.

## Checksummed archive rehearsal

The later [workflow run 35230102059](https://github.com/jybanez/syndicatum/actions/runs/35230102059)
passed both required jobs at commit
`a0538b60ba583d9d48998c4b7f91eeb0322e32f8`. CI created a `git archive`
tarball for exactly that commit, wrote and checked its SHA-256 manifest,
unpacked it in a separate directory, and ran the Docker acceptance harness
from the unpacked copy. The Docker log again confirmed MySQL 5.7.44 with
`STRICT_TRANS_TABLES`, 25 migrations, health/reachability, logical backup,
deliberate mutation, restore/probe verification, and project-scoped cleanup.

- Candidate archive: `syndicatum-a0538b60ba583d9d48998c4b7f91eeb0322e32f8.tar.gz`
- SHA-256: `ccd9a6344981073b1a76e088fbe98c5ecb1163572476ce0fd9563c1970f85728`
- Retained Actions artifact: `docker-source-acceptance-a0538b60ba583d9d48998c4b7f91eeb0322e32f8-1`

The downloaded archive hash was independently checked against the retained
manifest. This improves packaging/install evidence but remains a time-limited
CI candidate artifact, not a tagged, published V1 release. Upgrade, rollback,
and external production acceptance are still unproven.

## Still required before release promotion

- publish and test a tagged immutable release artifact rather than only a
  time-limited CI candidate archive;
- exercise documented upgrade and file-plus-database rollback procedures;
- complete production proxy/TLS, off-host backup retention, monitoring, and
  supported integration acceptance; and
- review the security implications of the terminal MySQL 5.7.44 release before
  claiming external production readiness.
