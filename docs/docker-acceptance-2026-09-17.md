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

## Still required before release promotion

- build and test an immutable release artifact rather than source checkout;
- exercise documented upgrade and file-plus-database rollback procedures;
- complete production proxy/TLS, off-host backup retention, monitoring, and
  supported integration acceptance; and
- review the security implications of the terminal MySQL 5.7.44 release before
  claiming external production readiness.
