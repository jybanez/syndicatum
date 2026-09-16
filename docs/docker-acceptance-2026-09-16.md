# Docker acceptance evidence — 2026-09-16

## Result

**Passed** against the current source working tree on Docker Desktop for
Windows. This establishes local source-tree lifecycle evidence; it does not
promote the Docker path to a supported release artifact.

## Environment

- Docker Engine: `29.1.3`
- Docker Compose: `v2.40.3-desktop.1`
- Repository baseline: `0031cc719ab16459f8d20ddcab76fc1d56c285d2`
  plus the uncommitted working tree
- Application image:
  `sha256:521ded105436fd1e927f036f559118f8631fc36383caf098c253e0574200c070`
- Database image:
  `sha256:17168874454830c4f57364c973ebd4e93ce0a41cfdb499eee049bc97ac379e35`

## Verified by `scripts/docker-acceptance.ps1`

- rendered Compose configuration and project-scoped, non-external volumes;
- clean startup of MySQL, the PHP/Apache application, and the worker;
- all 24 ordered migrations applied with valid checksums;
- machine-readable core/database/schema health and application-root
  reachability;
- creation of an acceptance-only database probe;
- logical database backup;
- destructive removal of the probe from the isolated database;
- restore from the backup and exact probe-value verification; and
- guarded removal of the generated containers, networks, volumes, temporary
  environment file, and temporary backup.

The run exposed and fixed a MySQL 8.4 requirement for stored-function schema
installation by enabling `log_bin_trust_function_creators` in the isolated
database service. A second fresh run then exited successfully with code `0`.

## Still required before release promotion

- build and test an immutable release artifact rather than a dirty working
  tree;
- exercise documented upgrade and file-plus-database rollback procedures;
- record production proxy/TLS, off-host backup retention, monitoring, and
  supported integration acceptance separately.
