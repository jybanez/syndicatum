# Docker deployment

**Status:** Candidate supported path. It becomes the supported V1 Docker path
only after the clean-environment acceptance harness passes against the release
artifact and the result is recorded.

An earlier MySQL 8.4 source-tree lifecycle passed locally on 2026-09-16; see
[`docker-acceptance-2026-09-16.md`](docker-acceptance-2026-09-16.md). Release
promotion remains pending because that run used a different database baseline
and built the working tree, not an immutable release artifact.

The selected MySQL 5.7.44 baseline passed an isolated source-tree lifecycle on
a clean GitHub Actions runner on 2026-09-17; see
[`docker-acceptance-2026-09-17.md`](docker-acceptance-2026-09-17.md). Release
promotion remains pending because the run did not install a published,
immutable release artifact or resolve the production security review.
The same record includes a later checksummed archive rehearsal: CI built an
archive from one exact commit, verified its hash, unpacked it separately, and
repeated the Docker lifecycle from the unpacked files. This is still a
candidate artifact, not a tagged, published release.

This runbook describes the candidate self-hosted Docker deployment for
Syndicatum. It is an operator procedure, not a substitute for tested backups,
TLS termination, host hardening, monitoring, or an organization-specific
disaster-recovery plan.

## Runtime contract

The candidate stack uses:

- Docker Engine with the Compose v2 plugin;
- PHP 8.2 with Apache on Debian Bookworm;
- MySQL 5.7.44 with strict SQL mode, matching the owner-selected V1 version
  baseline;
- the Compose services `app`, `db`, and `worker`;
- the named volume `syndicatum_db` for MySQL data; and
- the named volume `syndicatum_avatars` for uploaded avatars shared by the web
  application and worker.

`compose.yaml` pins the PHP major/minor and MySQL 5.7.44. Treat movement to
a new PHP minor, Debian release, or MySQL version as an upgrade requiring
the full test suite and a backup/restore rehearsal. Oracle identifies
[5.7.44 as the final MySQL 5.7 release](https://dev.mysql.com/doc/relnotes/mysql/5.7/en/news-5-7-44.html);
this is a stability baseline, not a claim of ongoing
upstream security maintenance. Security and release review must address that
risk before promoting this candidate path for external production use.

The Docker acceptance harness builds project-unique images and starts the
database first. It verifies the running server reports MySQL 5.7.44 with
strict SQL mode before allowing the application to start or run migrations.
The 5.7.44 source-tree lifecycle acceptance passed, including the preflight,
install, health, backup, and restore checks. Repeat acceptance against the
published release artifact before declaring this a supported path.

The `worker` service runs both existing background processors in a bounded
loop: agent webhook delivery and the optional Realtime message outbox. The
Realtime processor exits successfully without work when Realtime is disabled.
Do not run an additional scheduler for these processors unless the Compose
worker is disabled deliberately; their database locks prevent concurrent work,
but duplicate supervisors make operations harder to diagnose.

## Environment and secrets

Copy [`.env.example`](../.env.example) to `.env`. `.env` is deployment state:
restrict its permissions, exclude it from backups intended for source code,
and never commit, paste, or post its values.

The stack refuses to start without:

- `MYSQL_ROOT_PASSWORD`, used by the database container.
- `PBB_AGENTCHAT_DB_PASS`, used by the least-privileged application database
  account.
- `PBB_AGENTCHAT_SECRET`, used to HMAC agent tokens and claim codes.
- `SYNDICATUM_MASTER_KEY`, at least 32 characters, used to encrypt integration
  and webhook secrets stored in the database.

The two database passwords must be distinct, contain at least 16 characters,
and must not use a common placeholder such as `password`, `secret`, `changeme`,
or `change-me`. Startup validation rejects credentials that violate this
minimum contract.

Generate each secret independently, for example:

```console
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

`SYNDICATUM_MASTER_KEY` must remain stable across restarts, upgrades, and
restores. A restored database containing encrypted settings is unusable without
the matching key.

`PBB_AGENTCHAT_PREVIOUS_SECRET` is not a second permanent secret. Use it only
for the documented credential-rotation overlap, inspect
`credential-migration-summary`, and remove it when no previous or unknown
credentials remain.

The Docker deployment stores avatars at
`/var/lib/syndicatum/avatars`. Do not point `SYNDICATUM_AVATAR_DIR` elsewhere
inside the containers unless an equivalent persistent, shared mount is added.
Leave `SYNDICATUM_WEBHOOK_PRIVATE_HOST_ALLOWLIST` unset unless exact private
webhook hostnames have been security-reviewed.

Any setting in `SettingsService::registry()` can be locked with an environment
variable named `SYNDICATUM_SETTING_` followed by its uppercase key with dots
replaced by underscores. For example,
`SYNDICATUM_SETTING_GENERAL_PUBLIC_ORIGIN=https://syndicatum.example.com`.
Only add such variables to `compose.yaml` deliberately: even an empty variable
counts as an override and may fail validation. Prefer the administrator UI for
ordinary mutable settings.

## Network and TLS boundary

The default bind is `127.0.0.1:8080`; it is intentionally not exposed to other
hosts. For production, place a maintained reverse proxy or load balancer in
front of Syndicatum, terminate HTTPS there, and forward to the loopback port.
Set the public origin to the externally reachable HTTPS origin before OAuth,
MCP, or discussion binding is enabled.

Do not bind directly to `0.0.0.0` unless host firewalling, TLS, proxy headers,
request limits, and access logging have been reviewed. MySQL is internal to the
Compose network and must not be published on the host.

## First installation

1. Check out a reviewed release tag, not a moving development branch.
2. Create and protect `.env` as described above.
3. Validate interpolation before creating containers:

   ```console
   docker compose config --quiet
   ```

4. Build and start the stack:

   ```console
   docker compose build --pull
   docker compose up -d
   docker compose ps
   ```

5. Follow initialization without printing environment values:

   ```console
   docker compose logs --tail=100 app db worker
   ```

   The application entrypoint waits for MySQL and runs the idempotent schema
   installer. That installer creates the base tables and applies ordered,
   checksummed migrations under a MySQL migration lock. A container becoming
   ready does not authorize destructive or reverse migrations.

6. Verify schema state:

   ```console
   docker compose exec app php scripts/chat-db.php migration-status
   ```

   Every applied migration must have a valid checksum and no pending migration
   may be ignored.

7. Bootstrap the first administrator without placing the password in shell
   history or a command argument. Open a container shell, read the value
   silently, run the command, and immediately unset it:

   ```console
   docker compose exec app sh
   read -rsp "Bootstrap password: " SYNDICATUM_BOOTSTRAP_PASSWORD; export SYNDICATUM_BOOTSTRAP_PASSWORD; echo
   php scripts/chat-db.php bootstrap-admin admin@example.com "Administrator Name"
   unset SYNDICATUM_BOOTSTRAP_PASSWORD
   exit
   ```

8. Sign in, change the bootstrap password, configure the public HTTPS origin,
   and keep optional integrations disabled until tested independently.

For migration of a pre-expansion installation, do not substitute this section
for [the expansion migration runbook](expansion-migration-runbook.md). That
workflow requires preflight, backfill, reconciliation, and a compatibility
observation period.

## Clean-environment acceptance

Before treating a release as deployable, run the PowerShell 7 acceptance
harness on a host with a running Docker engine:

```powershell
pwsh ./scripts/docker-acceptance.ps1
```

The harness generates temporary secrets, a unique Compose project name, an
isolated database, and project-scoped volumes. It validates rendered
configuration, clean startup, ordered migrations and checksums, the health
endpoint and application root, then performs a byte-preserving logical backup
and restore probe. Its guarded cleanup removes only the generated acceptance
project and its volumes. If cleanup fails, follow the exact project-scoped
command printed by the harness; never substitute the production project name.

Passing this harness proves the tested local lifecycle, not production TLS,
off-host backup retention, monitoring, external integrations, or high
availability. Record the Docker/Compose versions, Git revision, image IDs, and
acceptance result as release evidence.

## Health and operational status

The unauthenticated health endpoint is:

```text
/api/v1/health.php
```

With the default local bind:

```console
curl --fail http://127.0.0.1:8080/api/v1/health.php
```

Healthy core output reports `core.status` as `ok`, `database` as `true`, and
`expanded_schema` as `true`. Optional integrations are reported separately;
disabled is not a core failure. The endpoint proves database reachability and
required table presence, not that every worker or external integration is
healthy.

Use these additional checks:

```console
docker compose ps
docker compose logs --tail=200 app worker db
docker compose exec app php scripts/chat-db.php migration-status
docker compose exec app php scripts/plugin-operational-status.php
```

`plugin-operational-status.php` exits `0` when healthy, `2` when backlog or dead
deliveries need attention, and `3` when database status cannot be read. Alert
on repeated HTTP health failures, restarting/unhealthy containers, worker
absence, an oldest pending delivery above five minutes, dead-letter growth,
low disk space, old backups, and TLS expiry.

## Database backup

Backups contain message content, credential hashes, configuration, and possibly
encrypted integration secrets. Store them encrypted, outside the repository
and container volumes, with access limited to operators.

Create a consistent logical dump from the running database. The command reads
credentials from the database container environment and writes only the dump
to the host file:

```console
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --user="$MYSQL_USER" --single-transaction --routines --triggers --default-character-set=utf8mb4 "$MYSQL_DATABASE"' > syndicatum-$(date +%Y%m%d-%H%M%S).sql
```

PowerShell operators should supply the filename explicitly instead of the
POSIX `$(date ...)` expression:

```powershell
$backup = "syndicatum-$((Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')).sql"
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --user="$MYSQL_USER" --single-transaction --routines --triggers --default-character-set=utf8mb4 "$MYSQL_DATABASE"' > $backup
```

Confirm the command succeeded, the file is non-empty, and a SHA-256 digest has
been recorded. A dump is not verified merely because it exists.

Also back up the `syndicatum_avatars` volume and the protected deployment
configuration needed to decrypt and use restored data. Store the database,
avatars, `.env`/secret material, release identifier, and checksums as separate
protected artifacts with the same recovery-point label.

## Restore rehearsal

Test restores regularly and before every release containing a migration. Never
restore over the only surviving database or volume.

The required rehearsal is:

1. Create an isolated MySQL 5.7.44 instance or an isolated Compose project with a
   new database volume and no production ingress.
2. Import the dump with the MySQL client using credentials supplied through the
   isolated environment, not shell history.
3. Restore avatars into a new avatar volume and provide the matching application
   HMAC/master keys through protected configuration.
4. Start the exact application release associated with the backup.
5. Run `migration-status`, `expansion-preflight`,
   `credential-migration-summary`, and `reconcile-expansion` as applicable.
6. Compare expected project, participant, message, revision, addressee, and
   avatar counts; exercise login and a non-sensitive read/write cycle.
7. Record artifact hashes, release, test results, operator, and UTC time, then
   destroy the isolated rehearsal environment.

Do not call a backup recoverable until this rehearsal passes. Tokens issued
after the recovery point must be revoked after a real restore unless they can
be reconciled reliably.

## Upgrade

1. Read the target release notes and migration notes. Confirm the previous
   release can be recovered and that the target runtime/database versions are
   supported.
2. Stop or otherwise quiesce writes and both workers.
3. Create and verify a database backup and avatar/configuration recovery set.
4. Record the current release tag and image IDs.
5. Check out the reviewed target tag and validate configuration:

   ```console
   docker compose config --quiet
   docker compose build --pull
   ```

6. Start the database and application. The application entrypoint applies only
   ordered forward migrations under the migration lock:

   ```console
   docker compose up -d
   docker compose logs --tail=100 app worker
   ```

7. Require healthy core output, valid migration status, normal operational
   status, login, project discovery, timeline read/write, and one bounded
   connector cycle before reopening normal traffic.

Never modify an applied migration file. `SchemaMigrator` records SHA-256
checksums and rejects modified applied migrations.

## Rollback

Database migrations are forward-only. MySQL DDL may auto-commit, so this
project intentionally does not promise atomic down migrations.

An application-only rollback is allowed only when the previous release has
been verified against the current schema and no incompatible canonical writes
have occurred. Otherwise:

1. stop writes and workers;
2. preserve the failed database and logs for diagnosis;
3. create a new database volume rather than overwriting the failed one;
4. restore the verified pre-upgrade database, avatar, configuration, and secret
   set into the new environment;
5. start the previous application release;
6. validate health, migration status, counts, authentication, and a bounded
   read/write cycle; and
7. switch ingress only after validation.

Export post-backup human-authored data separately if it must be retained. Do
not attempt a partial reverse transform of the schema.

## Diagnostics

Start with:

```console
docker compose ps
docker compose logs --since=30m app worker db
docker compose exec app php scripts/chat-db.php migration-status
docker compose exec app php scripts/plugin-operational-status.php
docker compose config --quiet
```

Common boundaries:

- `CORE_UNAVAILABLE` or a failing app health check: verify `db` health, database
  name/user/password agreement, volume capacity, and MySQL logs.
- `expanded_schema: false`: initialization/migration is incomplete; inspect app
  startup logs and migration status. Do not expose traffic.
- migration checksum failure: restore the original released migration file;
  never edit the database checksum to conceal drift.
- worker healthy but no Realtime work: expected when Realtime is disabled.
- webhook backlog/dead letters: inspect sanitized operational status and the
  destination configuration; never print signing secrets or message bodies in
  shared logs.
- encrypted setting failures after restore: supply the exact master key used
  at backup time. Generating a replacement does not decrypt existing values.
- agent authentication failures after secret rotation: restore the correct
  primary/previous-secret overlap and inspect `credential-migration-summary`.

When collecting incident evidence, retain UTC timestamps, request IDs,
container/image IDs, release tag, sanitized logs, health/status JSON, and recent
configuration changes. Exclude cookies, bearer tokens, claim codes, OAuth
codes, PKCE verifiers, passwords, webhook signing secrets, and message bodies.

## Explicit non-goals

This initial Docker package does not provide:

- Kubernetes manifests, Helm charts, or an orchestrator operator;
- multi-host or high-availability MySQL;
- automated off-host backup storage, retention, or restore scheduling;
- built-in TLS certificate issuance or a production reverse proxy;
- zero-downtime schema migration guarantees;
- automatic database downgrade or down migrations;
- centralized log aggregation, metrics storage, or paging;
- managed secret storage or automatic secret rotation;
- horizontal worker scaling; or
- support for exposing MySQL publicly.

These are deployment/operator responsibilities or future supported packaging,
not implied capabilities of the three-service Compose stack.
