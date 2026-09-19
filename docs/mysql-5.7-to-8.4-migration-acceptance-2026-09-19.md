# MySQL 5.7-to-8.4 migration acceptance — 2026-09-19

This record captures the accepted logical export/restore transition from the
declared MySQL 5.7.44 internal-RC baseline into a fresh MySQL 8.4 database.
It does not authorize production migration, automatic data movement, or an
in-place database upgrade. The V1.0.0 commercial support promise remains a
fresh Docker installation only.

## Candidate and pinned databases

- Protected-main base SHA: `5e9b4f4161c026cc663abd0b587ea474bf5150de`
- Test branch: `codex/mysql84-acceptance`
- Source image: `mysql:5.7.44@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb`
- Observed source version: `5.7.44`
- Target image: `mysql:8.4@sha256:85b9bf2e29cf836ecb8c2a15a935d4ba0c606631dff1dd79531a11983c638f2a`
- Observed target version: `8.4.11`
- Schema verified on both sides: all 30 ordered migrations and checksums

## Transition exercised

The isolated harness:

1. generated unique project-scoped secrets, names, volumes, and images;
2. started the pinned 5.7.44 database, application, and worker;
3. applied and verified the complete migration set;
4. seeded one linked user, workspace, project, owner membership, human and agent
   participants, canonical message, and direct addressee;
5. verified the source relational state as `1|1|1|1|2|1|1`;
6. created a single-transaction logical export including routines and triggers;
7. removed the source containers and volumes;
8. started a fresh pinned 8.4 database and restored the export;
9. started the application and worker, reverified all migrations, reproduced
   the exact relational state, and required healthy core/database/expanded-
   schema status; and
10. removed the generated target containers, networks, volumes, environment
    file, and database dump.

## Result

The local exercise and archived-candidate PR/protected-main CI passed. The
5.7.44 export restored into MySQL 8.4.11 with
the expected `1|1|1|1|2|1|1` state, all 30 migrations valid, and healthy
application behavior. Cleanup completed using only the generated project name.

## Remaining acceptance boundary

The dedicated CI job packages the candidate, verifies its archive checksum,
runs the transition from the unpacked bytes, and retains the acceptance log.
Commercial Assessor message 2877 closed the migration sub-gate for the tested
schema/data shape and exact merge candidate. An operator must still separately
review the exact source deployment, protected backups, downtime, credentials,
and cutover/recovery plan before any real migration.
