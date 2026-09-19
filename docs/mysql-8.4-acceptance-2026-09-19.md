# MySQL 8.4 compatibility acceptance — 2026-09-19

This record captures a preliminary local clean-install compatibility run. It
does not change the internal RC1 MySQL 5.7.44 support declaration and is not
evidence of an in-place 5.7-to-8.4 migration.

## Candidate and environment

- Protected-main base SHA: `5e9b4f4161c026cc663abd0b587ea474bf5150de`
- Test branch: `codex/mysql84-acceptance`
- MySQL tag resolved during the run: `mysql:8.4`
- MySQL index digest: `sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a`
- Linux/amd64 manifest: `sha256:8c19b656bb381f163750b238852bd377ba5764e1ec30cdd3f02e55cf8e2f89b7`
- Observed server version: `8.4.11`
- Built database image ID: `sha256:cb9fc24e79576b63c9e25b596f1420af236ad909dd8c56465c0468712014f202`
- Built application image ID: `sha256:fa9c0c01eae2dff06f0ca9ad328c9956d9eea7a3ba19d6d857dcd5c79c99fc79`
- Local Docker Engine: `29.1.3`
- Local runtime: `runc` `1.3.4`

The local host is below the proposed external-host security floor of Docker
Engine 29.5.1 and runc 1.3.6. This run is therefore compatibility evidence,
not external-host baseline acceptance.

## Harness changes under test

The acceptance script now accepts an explicit MySQL image, expected version
pattern, and SQL-mode list. All defaults remain the MySQL 5.7.44 internal-RC
values. Compose still defaults to 5.7.44, but accepts the harness-provided SQL
mode so an 8.4 run omits the removed `NO_AUTO_CREATE_USER` mode.

## Result

The isolated run exited successfully and removed its generated containers,
networks, and volumes. It verified:

- MySQL `8.4.11` with strict SQL mode;
- all 30 ordered migrations and checksums;
- application, worker, and machine-readable health;
- missing-table, stalled-worker, retry exhaustion, dead-letter, uncertain-
  outcome replay, and idempotent-receiver behavior;
- anonymous, authenticated, revoked, member, removed-member, and foreign-owner
  authorization outcomes;
- OAuth and service-token MCP boundaries without identity creation;
- non-root Apache, worker, and MySQL processes with the expected capability
  posture;
- logical backup, destructive mutation, restore, and probe recovery; and
- guarded project-scoped cleanup.

## Remaining acceptance

Exact-head CI repeated this clean-install lifecycle from a checksummed archive
and retained the archive checksum and acceptance log. The separately recorded
[MySQL 5.7-to-8.4 migration acceptance](mysql-5.7-to-8.4-migration-acceptance-2026-09-19.md)
now exercises the logical export/restore transition. That migration record is
still preliminary until its exact-head CI job passes; neither result authorizes
production migration or changes the V1.0.0 clean-install-only support promise.
