# Syndicatum plugin production operations

This runbook covers the hosted MCP/OAuth plugin at
`https://syndicatum.wizaya.com`. It contains no credentials. Store secrets,
database backups, and incident evidence outside the web root with access limited
to the service operator.

## Deployment preflight

1. Record the intended Git commit and create a verified database backup.
2. Run the complete PHP, Companion, and Codex plugin test suites.
3. Build the Companion archive twice with Windows PowerShell 5.1
   (`powershell.exe`) and require identical SHA-256 values.
4. Deploy application files without replacing environment secrets or protected
   agent/connector profiles.
5. Run `scripts/verify-plugin-publication.ps1` against the public HTTPS origin.
6. Complete one OAuth reconnect, one MCP-only interactive-context read, and one
   Companion delivery/reply/acknowledgement cycle with non-sensitive test data.
7. Record the commit, artifact checksums, test counts, operator, and time.

## Monitoring

Monitor and alert on these signals without logging message bodies, bearer
tokens, authorization codes, refresh tokens, cookies, PKCE verifiers, or
discussion URLs:

- `/api/v1/health.php` availability, latency, database status, and expanded
  schema status;
- authenticated MCP `diagnose_connection` results: inspect
  `checks.authentication_valid`, `checks.project_access_valid`, and
  `binding_health.state` independently. OAuth discussion contexts can report
  `missing`, `pending`, `invalid`, `healthy`, `stale`, `revoked`, or `unusable`;
  project-agent service tokens report `not_required` for discussion binding.
  A reachable MCP server or valid OAuth token alone is not proof of a healthy
  project binding. Do not log the binding context token or discussion URL;
- MCP request count, latency, HTTP 401/429/5xx counts, JSON-RPC/tool errors, and
  errors grouped by tool name;
- OAuth authorize/token/revoke count, latency, invalid-grant rate, 429 count,
  and 5xx count;
- Realtime outbox worker presence, Scheduled Task result, oldest pending event,
  retry count, and dead-letter count;
- webhook backlog and terminal delivery failures;
- Companion server-health and delivery-health reports supplied voluntarily by
  an operator, using only the built-in sanitized diagnostics;
- disk space, database backup age, TLS certificate expiry, and unexpected
  application process exits.

For a specific MCP connection, the operator can run
`php scripts/plugin-mcp-connection-status.php --url=https://syndicatum.wizaya.com/mcp`
with one JSON object on standard input containing `access_token` and, when
applicable, `binding_context_id`. Supply that input through an approved secret
source; never put either value in command arguments, shell history, logs, or a
ticket. The CLI calls the read-only `diagnose_connection` tool and emits only
`authentication` (`valid`, `invalid`, or `unknown`), the non-secret `binding`
state, `project_access`, `context_type`, and an overall state. HTTP 401 is
`authentication: invalid` with binding `unknown`; HTTP 403 is degraded but
leaves authentication `unknown` (it can indicate a scope denial). A valid OAuth token with a
missing/stale/unusable context is `authentication: valid` with its specific
binding state and no project access; a project-agent service token reports
`binding: not_required`. Transport or malformed-response failures are
`unknown`, never healthy. Exit codes are 0 for healthy, 2 for a known degraded
state, and 3 for unknown/input failure. This is a point-in-time credential
probe, not a fleet-wide count and not a substitute for full OAuth/Companion
or published-artifact acceptance.

Run the credential-free public preflight with
`scripts/verify-plugin-publication.ps1`. On the application host, run
`php scripts/plugin-operational-status.php` to obtain JSON counts for active
rate-limit blocks, Realtime backlog/recent failures, webhook backlog/recent
dead letters, Workspace Agent trigger deliveries, and Responses API activation
deliveries. The latter two include queued/retrying (and, for Responses API,
waiting) work, dead entries created within the last 24 hours, and oldest
pending age. The delivery tables do not record a separate time of transition
to `dead`, so this counter must not be read as the number that *became* dead
within the last 24 hours.
Each delivery path now reports `ok`, `degraded`, or `unknown` and the last
successful publish/delivery timestamp (UTC, or `null` when none is recorded).
Missing tables produce `unknown` with null counts, and the overall state is
never `ok` when a delivery path cannot be observed. The current aggregate
precedence is explicit: any unknown delivery or worker component makes the
overall state `unknown` (even if another observable path is `degraded`);
otherwise any `degraded` component makes the overall state `degraded`; only
four observable `ok` delivery paths and an `ok` worker produce overall `ok`.
The worker component reads a database heartbeat written after a successful
Docker worker cycle: absent history/table is `unknown`, age above the declared
`SYNDICATUM_WORKER_STALE_SECONDS` threshold is `degraded`, and a recent
heartbeat is `ok`. V1 defaults to 120 seconds (supported 30–3600); keep it
above the configured worker interval. Status JSON reports the effective
`worker.stale_after_seconds`. Inspect per-path states as well
as the aggregate so a known failure is not hidden by another unknown path.
This contract does not yet assert freshness of delivery `last_success_at`, so
an idle queue with no success history is not proof of a successful delivery.
Exit code `0` is healthy, `2` requires operator attention, and `3` means the
database status could not be read. Pair this with
`scripts/status-realtime-outbox.ps1` for process and Scheduled Task state.

Suggested initial alert thresholds should be tuned after observing normal
traffic: health unavailable twice in five minutes; any sustained MCP/OAuth 5xx
rate; a sharp invalid-grant or 429 increase; no outbox worker for two checks;
oldest pending delivery over five minutes; a dead-letter increase; backup older
than 24 hours; or TLS expiry within 21 days.

## Delivery and idempotency boundary

Treat outbound processing as **at least once with idempotency or
reconciliation where supported**, not exactly-once delivery. A canonical
project message is written once under a non-empty client idempotency key
scoped to project and sender; the database also stores a request fingerprint
so reuse with different content is rejected. That protects the canonical
message write. It does not mean every notification or activation happens once.

| Path | Stable identity sent by Syndicatum | Receiver acceptance evidenced | Receiver persistence/deduplication evidenced | Post-uncertainty retrieval/audit evidenced |
| --- | --- | --- | --- | --- |
| Realtime outbox | `event_uuid` as JSON `event_id` | Local worker treats HTTP 202 as acceptance; one disposable mock accepted it. Production acceptance remains unverified. | The disposable mock deduplicates by UUID; production receiver behavior is unverified. | No production lookup by `event_id` is established. |
| Agent webhook | `delivery_uuid` in `X-Syndicatum-Delivery` and `Idempotency-Key` | Local worker treats HTTP 2xx as acceptance; each operator-controlled receiver must be tested. | Receiver-specific; not enforced by Syndicatum and no general receiver guarantee is evidenced. | Receiver-specific logs or query API are needed; no general lookup is established. |
| Workspace Agent trigger | `delivery_uuid` in `Idempotency-Key` | Local worker treats HTTP 202 as acceptance and stores a returned run ID or conversation URL when supplied. | Acceptance of the key as a deduplication contract is unverified. | Returned run ID/URL is locally auditable when received, but lookup by key after a lost response is unverified. |
| Responses API activation | `delivery_uuid` in `Idempotency-Key` on creation | A returned response ID is stored and polled to terminal state when received. | Key retention and deduplication after an uncertain creation response are unverified for V1. | A stored response ID is pollable; lookup by key when creation succeeded but its ID was lost is unverified. |

The send and local success update are separate operations. If the destination
accepts a request but the worker crashes, times out, or loses the response
before committing success, a retry can resend the same stable identity.
Operators should inspect the canonical message and the delivery row separately,
then reconcile the destination by that identity when possible before replaying
or declaring a notification lost. Never infer activation success from the
mere existence of the canonical message. The isolated Docker acceptance test
proves one observed receipt for one Realtime item after worker recovery and a
two-attempt retry-exhausted case against a mock; it does not prove external
deduplication or eliminate this uncertain-outcome window.
A separate mock case applies one remote effect before returning HTTP 503;
Syndicatum then replays the same UUID and eventually marks it published after
HTTP 202. The mock receives two requests but applies one effect only because
*that mock* deduplicates. Until the production receiver's behavior is verified,
operators must assume a repeat external effect remains possible.
The Realtime outbox default is eight total attempts. Retry delays after
successive failed attempts are 5, 30, 120, 600, 1800, and 3600 seconds,
remaining at 3600 seconds thereafter; terminal failure is recorded on the
eighth unsuccessful attempt. This is a code and accelerated local acceptance
contract, not evidence of production receiver deduplication.

For one specific canonical message, an authorized host operator can run
`php scripts/plugin-message-delivery-status.php --message-id=NUMBER`. The
read-only JSON intentionally omits the message body and credentials. Inspect
`canonical.state`, `realtime.events[*].state`, and each addressee's
`activation` path and `handling_state` separately. `accepted` means a
destination accepted a delivery, not that the agent read or acted on it;
`acknowledged` records the participant's explicit handling acknowledgement.
`not_enqueued` does not by itself imply a failed path; check binding and
routing configuration. A missing delivery table is `unknown`, not zero work.

## Incident response

1. Preserve timestamps, request IDs, sanitized logs, process state, deployment
   commit, and recent configuration changes.
2. Classify the incident as availability, authorization, data exposure,
   delivery/routing, or dependency failure.
3. For suspected credential exposure, revoke the affected OAuth clients/tokens,
   connector devices, and agent credentials before restoring traffic. Rotate
   server secrets only through the documented secret store; never paste them
   into project messages or issue trackers.
4. For a bad application release, stop writes if integrity is uncertain, retain
   the failed files and database for analysis, restore the previous verified
   application artifact, and verify schema compatibility before reopening.
5. Run the publication verifier and a bounded end-to-end acceptance cycle after
   recovery.
6. Record impact, root cause, remediation, and follow-up actions without copying
   protected content into the incident summary.

## Backup and restore

- Create encrypted, timestamped database backups before every production
  release and at least daily while the hosted service is active.
- Keep application artifacts and their hashes independently from database
  backups. Protected credentials are database/environment state, not release
  files.
- Test restore into an isolated database and non-production origin regularly.
- Never restore a database over the only surviving copy. Restore to a new
  database, validate migrations and counts, then switch the application through
  controlled configuration.
- After restore, revoke tokens issued after the backup time unless their state
  can be reconciled reliably.

## Secret rotation

- Rotate one secret class at a time: TLS/private keys, application encryption
  secret, token-HMAC secret versions, Realtime signing/ingress secrets, and
  third-party OAuth client secrets.
- Use supported primary/previous-secret overlap only where the implementation
  explicitly provides it. Set a deadline, observe migration, then remove the
  previous secret.
- Reconnect and rerun the relevant authentication and delivery acceptance tests
  after each rotation.

## Rollback boundary

Application-file rollback is acceptable only when the previous release supports
the current database schema. Once a migration or new canonical write cannot be
read safely by the prior release, stop writes and restore a verified database
backup into a new database instead of attempting a partial reverse migration.
