# Local Docker acceptance evidence — 2026-09-18

## Result and scope

`pwsh ./scripts/docker-acceptance.ps1` passed on the local Windows/Docker
Desktop host after an uninterrupted build. The source checkout was at
`841dbbcbfb0f88727a64668689cb863a22cf5cb9` with uncommitted changes;
this is **local source-tree evidence**, not an immutable commit, published
release artifact, or external production acceptance.

## Observed environment and checks

- Docker Engine `29.1.3`; default OCI runtime `runc` version `1.3.4`.
- Isolated Compose project `syndicatum-acceptance-03e10896bf4d`.
- MySQL `5.7.44` with declared strict SQL mode before application startup.
- App and worker images built; all three services became healthy.
- Worker ran as UID 33. Apache ran as UID 33 and MySQL as UID 999; observed
  effective capabilities were zero with `NoNewPrivs=1` for both.
- All 25 migrations, application and machine-readable health, and root
  reachability passed.
- Logical backup, deliberate isolated-database mutation, restore, and probe
  verification passed.
- Harness exited 0 with `Docker acceptance passed: clean start, migrations,
  health, reachability, backup, and restore.` It removed the isolated
  containers, volumes, and networks; a project-label container query returned
  no remaining containers.

The Debian package-index download took 6m 15s at roughly 25 kB/s, explaining
why two earlier attempts were interrupted at that stage. Neither interruption
was evidence of a build defect. The successful run completed the full image
build and acceptance checks.

## Delivery-observability rerun

A later local rerun of `pwsh ./scripts/docker-acceptance.ps1` on the same
uncommitted source tree used isolated project
`syndicatum-acceptance-db6bdf018c0b`. The harness again verified MySQL
`5.7.44` with `STRICT_TRANS_TABLES`, 25 migrations, service health,
non-root/zero-capability process assertions, backup/mutation/restore, and
scoped cleanup. It additionally executed
`php scripts/plugin-operational-status.php` inside the app container and
required overall `ok`, all four delivery components `ok` with zero pending
items, no missing delivery tables, and a present `last_success_at` field for
each component (which may be null on a fresh install). The harness exited 0,
and a project-label container query returned no remaining containers.

This verifies fresh-install status shape and no initial backlog only. It does
not simulate missing tables, delayed work, dead letters, worker failure,
authenticated/binding probes, or installed published-release behavior. Those
cases remain to be tested before the broader operational-health gate closes.

## Explicit worker-heartbeat rerun

Another local source-tree rerun on 2026-09-18 used isolated Compose project
`syndicatum-acceptance-e28df1c55de0`. The acceptance harness now reads the
worker container's Docker health result after startup and explicitly requires
`healthy`, in addition to requiring the worker's non-root UID. Its healthcheck
depends on a fresh `/tmp/syndicatum-worker-heartbeat` file written only after
both processing jobs complete successfully. This run printed `Verified worker
heartbeat health check: healthy`, passed the existing migration, delivery
observability, process-privilege, reachability, and backup/restore checks,
and exited 0. The project-labelled container query after cleanup was empty.

This proves the worker completed a successful cycle during isolated startup.
It does not test a stalled worker, sustained consumption under load, the
Windows Scheduled Task mode, or the exact published artifact. At the time of
this earlier rerun, the application operator-status JSON did not yet include
worker liveness; the later rerun below exercises the new status field.

## Missing-observability failure-case rerun

A further local source-tree rerun used isolated project
`syndicatum-acceptance-11cf33091dae`. After the healthy baseline, the harness
temporarily renamed only that project's `message_events_outbox` table,
required the operator-status command to exit with attention and report overall
`unknown`, Realtime `unknown`, the table in `missing_delivery_tables`, and a
null pending count, then restored the table in a `finally` block. A second
status read returned overall `ok` with no missing tables. The full migration,
worker heartbeat, process-privilege, reachability, and backup/restore stages
passed; the harness exited 0 and no project-labelled containers remained.

This covers one missing delivery table on a disposable fresh install. It does
not prove the other missing-table branches, database-unavailable behavior,
stale-success or dead-letter thresholds, stalled-worker behavior, or the
published release artifact.

## Durable worker-status failure and recovery rerun

The local source-tree rerun in isolated project
`syndicatum-acceptance-0cf7d5f6c803` applied 26 migrations, including a new
`delivery_worker_heartbeats` table. The Docker worker now records a database
heartbeat only after both processing jobs succeed. The operator-status JSON
reports that worker separately from queue backlog, with `unknown` for absent
history, `degraded` after 120 seconds without a successful cycle, and `ok`
for a recent cycle. The harness required an initial `ok` heartbeat, stopped
the disposable worker, aged that project's heartbeat to 121 seconds, required
overall and worker `degraded` plus operator attention, restarted the worker,
and required overall and worker `ok` again. The missing-table, migration,
privilege, reachability, backup/restore, and cleanup stages also passed; the
harness exited 0, and no project-labelled containers remained.

This proves the stalled-worker/recovery status path on a disposable Docker
source-tree build. It does not prove sustained consumption under traffic,
Windows Scheduled Task behavior, authenticated/binding probes, or the exact
published release artifact.

## Stalled worker with queued work rerun

The next local source-tree rerun used isolated project
`syndicatum-acceptance-6b2ccc60ad31`. The harness stopped the worker, created
a valid disposable user/workspace/project and a future-available Realtime
outbox event, then aged the successful-cycle heartbeat beyond 120 seconds.
Operator status reported at least one queued event while worker and overall
state were `degraded` with `attention=true`. After the worker restarted, its
state and the aggregate returned to `ok` while the future event remained
queued; the fixture was then removed and Realtime pending count returned to
zero. All 26 migrations, the missing-table test, process-privilege checks,
backup/restore, and scoped cleanup passed; the harness exited 0 and no
project-labelled containers remained.

This demonstrates that queue presence and worker liveness are separate
signals. It does not demonstrate actual consumption of a due event or
end-to-end delivery, nor the exact published artifact.

## Declared worker-staleness threshold rerun

The subsequent local source-tree rerun used isolated project
`syndicatum-acceptance-72bc8ea0309a`. Worker staleness is now a declared V1
configuration setting, `SYNDICATUM_WORKER_STALE_SECONDS` (default 120; accepted
range 30–3600). The same setting drives the Docker worker health check and
operator-status JSON. The harness compared heartbeat age against the reported
threshold, then aged the heartbeat one second beyond it during a stopped-worker
queued-event scenario. It verified degraded worker/overall state, operator
attention, and recovery after restart. All 26 migrations, the missing-table
case, process-privilege checks, backup/restore, and scoped cleanup passed; the
harness exited 0 and no project-labelled containers remained.

This establishes consistent default-threshold behavior in the disposable
source-tree build. It does not prove consumption of the queued event after
restart, distinguish an active failing worker from an absent worker, test a
non-default threshold in Docker, or validate the exact published artifact.

## Due-event recovery and single-receipt rerun

The next isolated source-tree run used project
`syndicatum-acceptance-d653dccca92f` and an acceptance-only Realtime ingress
on the project's internal Docker network, with no host-published port. The
harness stopped the worker and inserted two distinct events into a disposable
project: one due three minutes earlier with a stable UUID, and one scheduled
one hour ahead. It confirmed the worker and overall states were degraded,
operator attention was raised, two events were pending, and the oldest pending
age increased while the worker remained stopped.

After restart, the due event's unique outbox row had one attempt,
`published_at` set, and no `failed_at`. The mock ingress recorded exactly one
receipt under that event UUID, including after another worker cycle. Pending
count fell to one because the future-scheduled event correctly remained
queued. Fixture cleanup returned pending count to zero. All 26 migrations,
the missing-table case, process-privilege checks, backup/restore, and scoped
cleanup passed; the harness exited 0 and no project-labelled containers
remained.

This is a narrow, positive delivery-recovery proof for one due outbox event
against a deterministic 202 ingress. It is not a general exactly-once
guarantee across crashes between remote acceptance and local publication, nor
does it prove canonical message-post deduplication, production Realtime
delivery, dead-letter/retry behavior, or the published release artifact.

## Retry exhaustion and dead-letter observability rerun

The follow-up isolated source-tree run used project
`syndicatum-acceptance-09a19772652e`. After the positive due-event recovery
case, the harness stopped the worker and added one eligible outbox event with a
stable UUID. The internal-only ingress returned a controlled HTTP 503 for this
event. An explicit outbox processor invocation with a two-attempt test limit
left it pending after the first attempt, recorded retry output, and scheduled
its next availability later than creation. The harness advanced only that
disposable item's availability, then invoked the processor again. The same row
reached `failed_at` with two attempts, no `published_at`, and exactly two
ingress attempt records. Operator status reported Realtime and overall
`degraded`, `attention=true`, and a recent failed item. The fixture was
removed, normal worker operation resumed, and status returned to `ok`.

All 26 migrations, the missing-table case, process-privilege checks,
backup/restore, and scoped cleanup passed; the harness exited 0 and no
project-labelled containers remained. This proves a deterministic retry and
retry-exhausted dead-letter state transition for one outbox item. It does not
exercise the production default eight-attempt cadence over real time, other
delivery queues, a real Realtime backend, or the exact published artifact.

## Uncertain-outcome replay rerun

The next isolated source-tree run used project
`syndicatum-acceptance-dcb8a8d77182`. Its internal-only ingress simulated a
destination that applied a side effect for a stable event UUID but returned
HTTP 503 before Syndicatum could mark local success. The first worker attempt
therefore remained locally pending with `attempt_count=1`. After advancing
only that disposable item's retry time, the worker resent the same UUID; the
mock receiver returned HTTP 202 and recognized the existing effect. The
outbox row reached `published_at` on attempt two, with no `failed_at`. The
ingress recorded **two sends but one deduplicated effect**. The fixture was
removed, status recovered, and all earlier acceptance stages, backup/restore,
and scoped cleanup passed; the harness exited 0 with no project-labelled
containers remaining.

This deliberately demonstrates the uncertainty window and that the same
stable identity is reused on retry. The one-effect result depends on behavior
implemented in the disposable mock receiver. It is **not** evidence that the
production Realtime destination deduplicates, nor is it a general exactly-once
guarantee or proof from the published release artifact.

## Default eight-attempt contract and integration rerun

The next isolated source-tree run used project
`syndicatum-acceptance-752884b05e2b`. The worker's default Realtime attempt
limit is now named in code (`MessageOutbox::DEFAULT_MAX_ATTEMPTS = 8`) and the
CLI uses it when no override is supplied. The executable
`tests/outbox-retry-contract.php` passed, asserting that limit and the retry
delay sequence in seconds: 5, 30, 120, 600, 1800, 3600, then 3600 for later
attempts. The Docker harness invoked the actual outbox processor **without**
`--max-attempts` for a stable-UUID event receiving HTTP 503. It verified
attempts 1–7 remained pending and rescheduled; attempt 8 became terminal
`failed_at`, never `published_at`; the mock ingress recorded eight sends; and
operator status reported Realtime/overall degraded with attention. Only the
disposable item's due time was advanced between attempts, avoiding hours of
test waiting. The earlier recovery and uncertainty cases, all 26 migrations,
backup/restore, and scoped cleanup passed; the harness exited 0 and no
project-labelled containers remained.

This is revision-bound evidence for the default limit and schedule values
and an accelerated integration test of their terminal behavior. It does not
measure real wall-clock backoff timing under production scheduling, prove
other queues' limits, verify receiver deduplication, or test published bytes.

## Canonical record versus activation/handling rerun

The next isolated source-tree run used project
`syndicatum-acceptance-a820977eb220`. A disposable fixture inserted an active
canonical project message addressed to an agent, alongside a terminal `dead`
Responses API activation row and no notification, seen, or acknowledgement
timestamp. The new read-only, body-free operator command
`php scripts/plugin-message-delivery-status.php --message-id=NUMBER`
reported the canonical message as `active`, that activation path as `failed`,
and the addressee handling state as `unconfirmed` independently. It also
reported Realtime as `not_enqueued` rather than implying publication. The
fixture and agent were removed in foreign-key-safe order; operator status
returned to `ok`. The complete preceding acceptance cases, all 26 migrations,
backup/restore, and scoped cleanup passed; the harness exited 0 and no
project-labelled containers remained. A pure projection contract test also
passed (`tests/message-delivery-status.php`).

This proves the diagnostic's state separation for one direct database fixture
in a disposable source-tree build. It does not prove every canonical message
creation or activation route, an actual remote Responses API rejection, an
agent's human-equivalent handling, or the exact published artifact.

## Authenticated API and revoked-session rerun

The isolated source-tree run `syndicatum-acceptance-46745f488f02` added an
HTTP-level authorization case. It created a random, disposable human session
for a fixture user, without printing the token. `GET /api/v1/projects.php`
returned 401 without a session; `GET /api/v1/session.php` identified the
fixture user and `GET /api/v1/projects.php` returned 200 with that valid
session; after database revocation, the same project request returned 401.
The session and user were removed with the fixture. All 26 migrations, the
existing delivery cases, backup/restore, and scoped cleanup passed. The
harness exited 0 and no project-labelled containers remained.

This proves the anonymous, valid, and revoked native-session paths against
the disposable local app build. It does not prove OAuth/MCP authorization,
agent credential scopes, remote binding health, or the exact published
artifact. The valid user had an empty project scope; a member-scoped read
is a separate test.

## Member-scoped project authorization rerun

The next isolated source-tree run used project
`syndicatum-acceptance-d053b21cc455`. With the same valid disposable native
session, the projects API initially returned an empty list. After creating
active project-member and human-participant records, it returned exactly the
fixture project and the project-context endpoint returned that project. After
changing membership to `removed`, the list was empty again and direct
project-context access returned 404. The membership fixture was cleared
before the remaining delivery tests. The full acceptance suite, all 26
migrations, backup/restore, and scoped teardown passed; exit code was 0 and
no project-labelled containers remained.

This demonstrates an active-to-removed membership boundary against the
disposable local app. It does not yet test a different owner's project, OAuth
or MCP credentials, discussion-binding state, or the published artifact.

## Foreign-owner project authorization rerun

A further isolated source-tree run, `syndicatum-acceptance-9a2e186802cb`,
created a second real project with a different owner, active membership, and
an active human participant. The first owner's valid session still listed only
its own authorized project; direct `GET /api/v1/project.php` for the foreign
project returned 404. The earlier active-to-removed membership and session
revocation checks also passed. Both fixture owners and their project records
were removed, followed by the full delivery, 26-migration, backup/restore,
and scoped teardown sequence. The harness exited 0 and a project-label query
found no remaining containers.

This establishes the native-session foreign-project concealment case for two
disposable owners. It does not establish OAuth/MCP scope enforcement,
discussion-binding validity, live production authorization, or published-byte
acceptance.

## MCP OAuth and service-token authorization rerun

The next isolated source-tree run, `syndicatum-acceptance-bc3538637296`,
tested the HTTP `tools/call` authorization boundary rather than protocol
discovery. A disposable local public origin and hashed tokens were configured
without printing bearer values. Missing and invalid authorization returned
401. A valid account-scoped OAuth access-token fixture produced
`authentication_valid=true`, `project_access_valid=false`, and discussion
binding `Required`; revoking that token restored 401. A valid project-agent
service-token fixture produced authenticated, project-authorized
`context_type=service_token`; revocation restored 401. The OAuth client and
agent fixtures were removed. All 26 migrations, preceding acceptance cases,
backup/restore, and scoped teardown passed with exit code 0 and no remaining
project-labelled containers.

The OAuth access-token row was inserted as a disposable fixture, so this
proves the MCP HTTP authorization check against persisted token state, not
the complete authorization-code/PKCE issuance path. The account token was
intentionally unbound; this does not prove a healthy discussion binding,
binding expiry/revocation, external ChatGPT connection, or the exact published
artifact. The separate `tests/chatgpt-oauth.php` exercises OAuth grant and
revocation service logic but is not this HTTP acceptance case.

## MCP binding-health state rerun

The next isolated source-tree run, `syndicatum-acceptance-cc897f9daca8`,
added `binding_health.state` to the existing MCP `diagnose_connection`
response. A valid OAuth token without a context reported `missing`; a
confirmed, current interactive context reported `healthy` with project
access; expiring its context row reported `stale` without project access;
removing the context creator's project membership reported `unusable`
without project access; changing the row to `cancelled` reported `revoked`
without project access. A valid project-agent service token reported
`not_required` for discussion binding. Context tokens were random and only
hashes were stored. The full 26-migration acceptance, prior auth/delivery
cases, backup/restore, and scoped teardown passed with exit code 0 and no
remaining project-labelled containers. Local PHP 8.2 suites also passed:
7 activation/binding, 22 Project API, and 6 OAuth service checks.

The source now also refuses a confirmed context when its owner membership,
project, agent, participant, or linked enabled Companion activation is no
longer usable; the activation/binding suite tests expiry, disabled activation,
removed membership, and suspended participant. The HTTP rerun used direct
disposable state transitions for the interactive context; it did not exercise
the full Companion confirmation/cancellation UI, external ChatGPT discussion,
or exact published artifact. `revoked` in this rerun represents a cancelled
fixture row, not a demonstrated user-facing post-confirmation revoke action.

A follow-up isolated source-tree run, `syndicatum-acceptance-fd136e145a2a`,
also asserted that an unknown context reports `invalid` with no project
access, and that the diagnostic probes leave the fixture's agent-identity
count at exactly one (no automatic identity creation or substitution). The
full acceptance run passed with exit code 0, including MySQL 5.7.44 strict
mode, 26 migrations, authorization and delivery cases, backup/restore, and
scoped teardown; no project-labelled containers remained. These are local
source-tree assertions, not full OAuth authorization-code/PKCE or external
Companion-flow evidence.

## Operator MCP connection CLI HTTP rerun

The isolated source-tree run `syndicatum-acceptance-698940f09731` exercised
`scripts/plugin-mcp-connection-status.php` over loopback HTTP against the
running app container, in addition to the direct MCP assertions. The CLI
accepted a disposable bearer and optional binding context only on STDIN;
the Docker command arguments contained only the loopback URL, and the harness
asserted neither credential appeared in captured stdout/stderr. No CLI output
artifact was retained. The projected JSON distinguished invalid and revoked
bearers (`authentication: invalid`, binding `unknown`, no project access), a
valid OAuth bearer with missing, stale, or unusable binding (authentication
`valid`, exact binding state, project access false), a healthy confirmed
interactive binding (valid/healthy/true), and a valid project-agent service
token (valid/`not_required`/true, `service_token` context). An unreachable
loopback MCP endpoint projected `unknown`, never `ok`.

MySQL 5.7.44 strict-mode startup, 26 migrations, prior authorization and
delivery assertions, logical backup/restore, and scoped teardown all passed
with exit code 0. No project-labelled containers remained. The two preceding
diagnostic runs failed before this successful rerun because the harness called
port 80 inside the app container; the image serves Apache on port 8080. The
corrected local URL was used for this passing run. This is real CLI-over-HTTP
source-tree evidence using persisted token/binding fixtures, not external
OAuth/Companion consent or exact published-artifact proof.

## Still open

This run cannot promote the release-artifact or security gates: its inputs were
not immutable published V1 bytes, it does not review all scanner findings or
host-runtime advisories, and it does not prove external production operation.
The V1 checklist and security acceptance table retain those gates as open.
