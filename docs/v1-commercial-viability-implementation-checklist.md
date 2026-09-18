# Syndicatum V1 Commercial Viability Implementation Checklist

**Source:** `docs/v1-commercial-viability-proposal.md`
**Status:** Working implementation checklist
**Scope rule:** Phase 0 and the narrow Phase 1 surfaces are the V1 build scope.
Phase 2 validates commercial value and must not silently expand the product.

## Status conventions

- [ ] Not yet verified
- [x] Complete, with evidence linked or recorded beneath the item
- **P0:** launch blocking
- **P1:** required to demonstrate V1 product value
- **P2:** validation work that informs post-V1 investment

An item is complete only when its implementation, documentation, automated
checks, and operational proof are all present where applicable.

## Phase 0 — Reliable Deployable Core

### P0.1 Baseline and acceptance contract

- [x] Inventory the current deployment, release, identity, timeline, binding,
      delivery, and integration behavior.
- [x] Map each checklist item to existing implementation, missing work, owner,
      dependency, and acceptance evidence.
- [x] Record the candidate V1 Docker operating environment and explicit
      non-goals in `docs/docker-deployment.md`; the owner-selected MySQL 5.7.44
      baseline still requires Docker/release-artifact acceptance and security
      review before support is declared.
- [x] Establish a guarded, repeatable clean-environment acceptance harness in
      `scripts/docker-acceptance.ps1`.

**Exit evidence:** a reviewed gap matrix and a clean-install acceptance plan
that can be executed by somebody other than the primary developer.

**Baseline evidence:** `docs/v1-commercial-viability-gap-audit.md`. Ownership,
estimates, and the clean-install acceptance plan remain to be assigned.

### P0.2 Repeatable production deployment

**Database baseline:** MySQL 5.7.44 is the owner-selected V1 compatibility
target. Strict-mode CI and the isolated Docker source-tree install and
backup/restore rehearsal have passed on that version; see
[`docker-acceptance-2026-09-17.md`](docker-acceptance-2026-09-17.md) and the
[2026-09-18 local rerun](docker-acceptance-2026-09-18.md). The earlier
8.4 acceptance run is historical candidate evidence, not V1 baseline proof.
The acceptance harness builds project-unique images and asserts the running
database version and strict SQL mode before application startup.
The final 5.7 release is a legacy stabilization choice, not a claim that the
database receives current upstream security fixes or that external production
deployment is approved.

- [ ] Promote the candidate Linux/Docker deployment path to supported after its
      clean-environment acceptance run passes against a release artifact.
- [x] Pin and document runtime, database, web-server, worker, and extension
      requirements.
- [x] Provide an example production configuration without real credentials.
- [x] Document the configuration and secrets model, including rotation.
- [x] Add startup validation for missing or unsafe configuration.
- [x] Provide controlled, ordered, and repeatable database migrations.
- [x] Document upgrade and rollback procedures.
- [x] Provide backup and restore procedures.
- [x] Test backup restoration into a clean MySQL 5.7.44 environment.
      [`docker-acceptance-2026-09-17.md`](docker-acceptance-2026-09-17.md)
      records an isolated source-tree build, database mutation, logical backup,
      restore, and probe verification on the selected baseline. Published
      release-artifact acceptance remains open.
- [ ] Expose health states that distinguish application, database,
      authentication, binding, Realtime, queue, and activation delivery health.
      The local operator-status command now reports Realtime, webhook,
      Workspace Agent, and Responses API delivery backlogs separately, including
      stale/dead work, last-success UTC timestamps, and missing delivery tables.
      Missing paths now report `unknown` with null metrics rather than a healthy
      zero; the local read-only run reported `degraded` for one recent Realtime
      failure. A second [isolated Docker source-tree rerun](docker-acceptance-2026-09-18.md#delivery-observability-rerun)
      passed the fresh-install delivery-status assertion. This is partial
      queue and activation visibility;
      installed-client OAuth/Companion probes, sustained worker consumption,
      release-artifact verification, and the complete health-state contract
      remain open. Commercial Assessor project message 2468 also requires
      explicit queued/retry/dead/success semantics, verified partial-observability
      behavior for every path, and an operator-visible distinction between canonical
      message existence and successful activation/handling. Assessor message
      2475 adds explicit failure-case acceptance for missing tables,
      stale/no-recent-success, retry-exhausted dead letters, stalled worker,
      authenticated API and binding outcomes (healthy, stale/missing, revoked),
      plus verification from the exact published artifact. The current
      aggregate precedence is documented in the operator runbook; these
      failure-case assertions are not yet complete.
      Pure-state tests now assert that a recent terminal failure or pending
      work older than 300 seconds becomes `degraded`, including the exact
      300/301-second boundary; Realtime has one database-level retry/dead-letter
      fixture below, while other delivery paths and operator-action
      verification remain open.
      The [2026-09-18 isolated acceptance rerun](docker-acceptance-2026-09-18.md#explicit-worker-heartbeat-rerun)
      additionally proves a successful worker heartbeat at startup. A later
      [failure/recovery rerun](docker-acceptance-2026-09-18.md#durable-worker-status-failure-and-recovery-rerun)
      proves the operator status becomes `degraded` after a stopped worker's
      heartbeat ages past 120 seconds, then returns to `ok` after restart.
      A further [queued-work rerun](docker-acceptance-2026-09-18.md#stalled-worker-with-queued-work-rerun)
      proves that status keeps a pending Realtime event visible while the
      stalled worker is degraded and after it recovers. Actual consumption
      and terminal delivery under load remain unverified.
      A [declared-threshold rerun](docker-acceptance-2026-09-18.md#declared-worker-staleness-threshold-rerun)
      confirms the Docker health check and operator status use the same
      configurable V1 threshold (default 120 seconds) in the isolated
      source-tree build. A non-default Docker threshold, active failing-cycle
      distinction, and broader traffic behavior remain open.
      A [due-event recovery rerun](docker-acceptance-2026-09-18.md#due-event-recovery-and-single-receipt-rerun)
      now proves that one stable-UUID eligible outbox item remains visible
      and ages during stoppage, then reaches terminal `published` on the
      first attempt with one internal mock-ingress receipt after restart;
      the future-scheduled control item remains queued. Canonical post
      deduplication, crash-boundary exactly-once behavior, production ingress,
      retry/dead-letter integration at that point, and the exact published
      artifact remained unverified.
      A [retry-exhaustion rerun](docker-acceptance-2026-09-18.md#retry-exhaustion-and-dead-letter-observability-rerun)
      now verifies one Realtime outbox item's HTTP 503 retry, scheduled
      reavailability, terminal failure at the two-attempt test limit, and
      degraded operator status with attention; this does not cover the
      default eight-attempt cadence, other delivery queues, real ingress, or
      exact published bytes.
      An [uncertain-outcome rerun](docker-acceptance-2026-09-18.md#uncertain-outcome-replay-rerun)
      demonstrates that after a simulated remote side effect with HTTP 503,
      the worker retries the same stable UUID; the mock receiver sees two
      sends but applies one deduplicated effect, and the outbox eventually
      reaches `published`. This validates the local replay path and explicitly
      does not prove production receiver deduplication or exactly-once delivery.
      A [default eight-attempt rerun](docker-acceptance-2026-09-18.md#default-eight-attempt-contract-and-integration-rerun)
      now tests the actual outbox processor with no attempt-limit override:
      seven retries stay pending, attempt eight becomes terminal failed, and
      eight ingress sends are recorded. A pure executable contract test asserts
      the V1 delay sequence. Time was accelerated by advancing only the
      disposable row; real wall-clock scheduling and published bytes remain
      unverified.
      A [canonical-versus-activation rerun](docker-acceptance-2026-09-18.md#canonical-record-versus-activationhandling-rerun)
      verifies that a read-only operator diagnostic reports one active
      canonical message, a dead Responses API activation, and unconfirmed
      addressee handling as separate states. This is one direct fixture path,
      not exhaustive remote activation or published-artifact evidence.
      An [authenticated API rerun](docker-acceptance-2026-09-18.md#authenticated-api-and-revoked-session-rerun)
      verifies anonymous 401, valid disposable native-session 200, and
      revoked-session 401 over HTTP. That run alone did not test project
      membership, OAuth/MCP, binding states, or the exact published artifact.
      A [member-scoped rerun](docker-acceptance-2026-09-18.md#member-scoped-project-authorization-rerun)
      now verifies that one valid session sees its disposable project only
      during active membership, and that removed membership yields an empty
      project list and concealed direct context (404). A separate
      [foreign-owner rerun](docker-acceptance-2026-09-18.md#foreign-owner-project-authorization-rerun)
      verifies that the same session lists only its authorized project and
      receives 404 for a real project owned by another fixture user. That run
      did not cover OAuth/MCP, binding states, live production authorization,
      or published bytes.
      An [MCP authorization rerun](docker-acceptance-2026-09-18.md#mcp-oauth-and-service-token-authorization-rerun)
      now verifies missing/invalid token 401, valid account OAuth token as
      authenticated-but-unbound, valid project-agent service token as
      project-authorized, and 401 after each token's revocation. These were
      persisted token fixtures, not a full OAuth grant or discussion-binding
      flow; the published-artifact gate remains open.
      A [binding-health rerun](docker-acceptance-2026-09-18.md#mcp-binding-health-state-rerun)
      now surfaces `missing`, `invalid`, `healthy`, `stale`, `unusable`,
      `revoked`, and service-token `not_required` states in the same MCP
      diagnosis result; an unknown context has no project access, and the
      probe does not auto-create or substitute an agent identity.
      Confirmed context lookup also rechecks current owner, project, agent,
      participant, and Companion activation validity. This is an interactive
      fixture flow with direct state transitions, not a complete external
      Companion discussion or user-facing revocation acceptance.
      A read-only [operator MCP connection CLI](plugin-production-operations.md#monitoring)
      now projects the same non-secret binding vocabulary separately from
      authentication, project access, and transport state. Its 12 pure
      projection cases pass locally and are included in source-contract CI.
      An [isolated CLI-over-HTTP rerun](docker-acceptance-2026-09-18.md#operator-mcp-connection-cli-http-rerun)
      also proves invalid/revoked bearer, healthy/missing/stale/unusable OAuth
      binding, service-token, and unreachable-endpoint projections without
      captured credential output. Full OAuth/Companion consent and exact
      published-artifact acceptance remain open.
      A [missing-observability rerun](docker-acceptance-2026-09-18.md#missing-observability-failure-case-rerun)
      now asserts that a temporarily absent Realtime table yields `unknown`
      and null metrics, then returns to `ok` after restoration; the other
      failure cases and published-artifact proof remain open.
- [x] Document routine operation and incident-recovery commands.

**Exit evidence:** a clean machine can install, start, back up, restore, and
diagnose `v1.0.0` by following the documented procedure. Upgrade acceptance
begins with releases after `v1.0.0`, from the immediately previous supported
release.

### P0.3 Stable V1 coordination contract

**Current progress (2026-09-17):**
[`v1-coordination-contract.md`](v1-coordination-contract.md) is an audited
source-tree candidate, not a frozen contract.
[`v1-cross-provider-contract-matrix.md`](v1-cross-provider-contract-matrix.md)
maps current automated and bounded live evidence separately from unverified
release acceptance. The Project API regression suite has 21 passing checks,
including duplicate-address precedence, same-request replay, changed-request
conflict, strict acknowledgement filtering, and repeated acknowledgement.
Project discovery, context, permission-scoped participants, and canonical
message/page responses now have OpenAPI schemas checked against 23 real API
responses across 16 operation/status pairs by
`tests/openapi-message-contract.py`. Full API/provider error
schemas, cross-provider verification, and an immutable release-artifact
acceptance run keep the items below unchecked.
The provisioned remote-MCP service-token path now has source-tree tests for
project-agent context, post/read, isolation, scope restriction, and revocation;
OAuth tools are separately checked to require a confirmed binding context.
independent-client onboarding and installed-release acceptance remain open.
The [provider error/recovery matrix](v1-provider-error-recovery-matrix.md)
tracks component, server/telemetry, and installed-client evidence separately.

- [x] Review the observable claim-to-test traceability table with the
      Commercial Assessor. The revised
      [cross-provider matrix](v1-cross-provider-contract-matrix.md) at
      `d5749ca` was approved for the current candidate scope, and
      [PR CI run 35231389230](https://github.com/jybanez/syndicatum/actions/runs/35231389230)
      passed both required jobs. This review does not certify installed-client
      interoperability or freeze the complete V1 contract.

- [ ] Version and document project identity and membership boundaries.
- [ ] Version and document human and agent participant identity boundaries.
- [ ] Document authorization rules for every supported participant action.
- [ ] Document direct-address responsibility semantics.
- [ ] Document broadcast visibility and responsibility semantics.
- [ ] Document reply relationships and thread context.
- [ ] Document acknowledgement semantics.
- [ ] State explicitly that acknowledgement is not necessarily first read and
      is not evidence of task completion.
- [ ] Document the canonical timeline as the source of truth.
- [ ] Document notifications as activation hints rather than authoritative
      records.
- [ ] Define compatibility and deprecation rules for the V1 API and MCP surface.
- [ ] Add contract tests for identity, authorization, addressing, replies,
      acknowledgements, ordering, and pagination.

**Exit evidence:** the documented contract and automated tests agree across the
web app, Project API V1, MCP, and supported agent integrations.

### P0.4 Release trust

**Current progress:** `.github/workflows/contract-ci.yml` is a candidate
source-tree CI job for tracked PHP/JavaScript syntax, migrations,
Project API/OpenAPI, OAuth, activation, portable Codex/Companion adapter
checks, and isolated Docker lifecycle
acceptance. [Run 35228305400](https://github.com/jybanez/syndicatum/actions/runs/35228305400)
passed both jobs at commit `32882f4794fcf0c7b35ff14b55d2be0a418435f3`;
the Docker job verified MySQL 5.7.44 with `STRICT_TRANS_TABLES` before application
startup, then migrations, health, reachability, logical backup, mutation,
restore, and cleanup. Revision-bound logs are retained as GitHub Actions
artifacts for 30 days. This is source-tree evidence, not immutable-release-
artifact acceptance; the earlier MySQL 8.4 run is historical only. The local
development server is also MySQL 5.7.44 but currently uses non-strict SQL mode; its
configuration is not interchangeable with the strict candidate baseline.
[Run 35230102059](https://github.com/jybanez/syndicatum/actions/runs/35230102059)
also passed after CI packaged exact commit `a0538b6` as a checksummed archive,
unpacked it, and repeated Docker acceptance from that copy; see the
[archive rehearsal record](docker-acceptance-2026-09-17.md#checksummed-archive-rehearsal).
No tagged, published immutable V1 release artifact has been recorded. The job
does not yet cover the full PHP/JavaScript, documentation, migration, packaging,
and security release matrix below.
The candidate `security-inventory` CI job scans the source checkout for
dependency vulnerabilities, secrets, and configuration findings. It retains
revision-bound counts and non-secret finding identifiers, not raw secret matches. A detected secret
fails the job; vulnerability and configuration counts remain informational
until findings are triaged and an enforceable policy is agreed. This is not a
PHP/JavaScript static-code security audit, completed container-image review,
third-party license inventory, or security sign-off.
The Docker acceptance job now inventories both built images after exercising
the archived candidate. [Run 35259335422](https://github.com/jybanez/syndicatum/actions/runs/35259335422)
passed clean-install/backup-restore and reported 15 critical / 87 high
application-image findings and 4 critical / 97 high database-image findings
after removal of inherited build headers and the unused `curl` CLI.
Package/CVE/fix-version evidence is retained for triage; image findings remain
informational rather than an
enforced V1 severity policy. See the [security inventory](v1-security-inventory-2026-09-18.md).
The proposed `.github/workflows/release-rc.yml` path requires an annotated
`v1.0.0-rc.N` tag on protected `main` with reviewed release notes, reruns the
source and Docker checks at the tag, publishes their checksummed archive as a
prerelease, then downloads and clean-installs the published bytes. This is
workflow preparation, not evidence of a tagged release or a passing published-
artifact acceptance run; those gates remain open until a real RC run succeeds.
At `e8a4f59`, the workflow implementation passed both existing PR checks in
[run 35247230416](https://github.com/jybanez/syndicatum/actions/runs/35247230416).
Jonathan approved the [V1 tag-protection policy](v1-tag-protection-proposal.md)
in project message 2369. GitHub readback confirmed two active `refs/tags/v1.*`
rulesets: owner-only creation (`23612219`) and no-bypass update/deletion
(`23612208`). The ruleset configuration should be rechecked before tagging;
the repository administrator can still change the rulesets themselves.
CI acceptance requires a clean checkout of the exact candidate release commit,
retained results that identify that revision, and a required branch/release
check. GitHub branch protection on `main` now requires pull requests and the
up-to-date `source-contract` and `docker-source-acceptance` GitHub Actions
checks, including for administrators; force-push and deletion are disabled.
[Draft PR 1](https://github.com/jybanez/syndicatum/pull/1) is the current
candidate, not an approved merge or release. Portable fixture results must
remain labeled separately from installed client evidence; the required
release path must test at least one database mode/configuration representative
of the supported production deployment, including strict SQL behavior; permissive
local defaults alone are insufficient.

- [x] Select `AGPL-3.0-only`, publish the canonical license text, and document
      the open-core boundary. Legal review and the third-party license inventory
      remain release-gate work.
- [x] Draft and review the V1 release/versioning policy content. The
      [candidate policy](v1-release-policy.md) separates application,
      protocol, and installed-client versions and was approved by the
      Commercial Assessor for the current candidate scope.
- [x] Implement the candidate RC publication workflow. Commit `e8a4f59`
      requires an annotated `v1.0.0-rc.N` tag on `main`, reruns contract/Docker
      checks, publishes the tested archive with checksum/provenance, and
      installs the downloaded release asset. PR CI run 35247230416 passed;
      neither a real tag nor the new tag-triggered workflow has run yet.
- [x] Apply and read back V1 tag protection. Rulesets `23612219` and
      `23612208` are active with the approved pattern, rules, and bypass lists;
      see the [policy record](v1-tag-protection-proposal.md).
- [x] Draft the first RC-specific release notes at
      [`docs/releases/v1.0.0-rc.1.md`](releases/v1.0.0-rc.1.md), with the
      clean-install-only boundary, candidate runtime/schema head, exact source
      package versions, and open acceptance/security limitations. These notes
      are not a published release or a completed release gate.
- [ ] Produce and verify the first real RC evidence chain: exact protected-main
      commit, immutable tag, required CI run, archive/hash, published release,
      and downloaded-artifact acceptance on the declared baseline.
- [x] Decide the first-release support path: `v1.0.0` supports a fresh Docker
      installation only on the declared baseline. Jonathan confirmed this
      decision through the Commercial Assessor in project message 2337. The
      existing internal deployment is not a supported prior commercial release;
      its in-place upgrade is not a `v1.0.0` release gate. Data migration from
      it is separate assistance, not a supported upgrade promise.
- [ ] Define a repeatable release and rollback process.
- [ ] Add continuous integration for PHP, JavaScript, documentation contracts,
      migrations, and packaging.
- [ ] Add dependency, secret, and baseline static-security scanning.
- [ ] Review the security and deployment-hardening implications of using the
      terminal MySQL 5.7.44 release before any external production-readiness
      claim; keep this separate from functional compatibility on 5.7.44.
- [ ] Complete the [security acceptance table](v1-security-acceptance-table.md)
      for every CRITICAL and release-relevant HIGH container finding, including
      runtime exposure, compatible fix, mitigation, residual risk, and explicit
      acceptance. A green inventory scan is not security-gate evidence.
- [ ] Produce immutable release artifacts with checksums.
- [ ] Publish release notes and migration notes for each release.
- [ ] Test clean installation of `v1.0.0` from its published artifact, not only
      from a working tree. For later releases, test upgrade from the immediately
      previous supported published release.

**Exit evidence:** `v1.0.0` can be built, verified, clean-installed, backed up,
and restored using published artifacts and documentation. Later supported
releases must additionally pass prior-release upgrade and rollback acceptance.

### P0.5 Permission-safe onboarding and binding

**Current source/CI progress:** draft PR #4 keeps onboarding work separate from
the V1 merge candidate. Pre-binding protected-tool denial and confidentiality,
permission-scoped Unicode casefolding plus collapsed-whitespace resolution,
and confirmation-time revalidation of the stored project/agent IDs have passing
source tests and required PR checks. The Companion candidate now keeps the
canonical bound server, project, and agent visible after confirmation and
separates successful binding from submission of an MCP status-check request.
The Commercial Assessor accepted that post-bind identity and status behavior
at source/CI scope, not as an installed-client result.
The foreign-project normalized-binding regression at `dbdf14f` confirms that
an unauthorized account receives generic not-found results for both binding
preparation and interactive context, without creating an intent or revealing
the real foreign project/agent. The Commercial Assessor accepted this bounded
confidentiality sub-gate at source/CI scope in project message 2662;
[run 35330007628](https://github.com/jybanez/syndicatum/actions/runs/35330007628)
passed source-contract, archived-candidate Docker/MySQL 5.7 acceptance, and
security-inventory at that exact head.
Commercial Assessor messages 2688 and 2695 additionally accept claim-code
replacement/same-identity recovery and target-identity isolation at
documentation/source/CI scope, including real same-project and foreign-project
competing agents at `5ea44d7`. The
[installed-client procedure](v1-companion-installed-acceptance.md#separate-codex-profile-recovery-check)
now specifies a separate disposable Codex recovery sequence; it has not run.
These are not installed-client or published-artifact results. The items below
remain open until the actual Companion/discussion flow, usability, identity
isolation, and exact release bytes are accepted.
The installed-client test procedure is
[`v1-companion-installed-acceptance.md`](v1-companion-installed-acceptance.md);
it is not a passing acceptance record.

- [ ] Preserve pre-binding confidentiality: an unbound discussion cannot
      enumerate projects or participant identities.
- [ ] Replace byte-for-byte project-name guessing with normalized matching or an
      authorized project-selection step in the Companion confirmation flow.
- [ ] Present the selected server, project, and agent identity before final
      confirmation.
- [ ] Present the bound project and agent identity clearly after completion.
- [ ] Prevent accidental replacement or reuse of an existing protected identity.
- [ ] Preserve isolated credentials per agent profile.
- [x] Document claim-code expiry, single use, replacement, and recovery.
      The [Codex operator guide](codex-plugin.md) distinguishes an unclaimed
      code from an explicitly authorized replacement, states that the old token
      remains valid until the replacement is claimed, and describes same-identity
      recovery and immediate-revocation caution. The expansion regression
      verifies superseded/expired replacement codes, old-token invalidation on
      claim, and stable project participant identity. Installed recovery remains
      part of P0.5's separate acceptance gate.
- [ ] Add negative tests for cross-project, cross-participant, expired-code,
      reused-code, and unauthorized enumeration attempts.
- [ ] Test onboarding with a user who has no repository or database knowledge.

**Exit evidence:** a permitted user can bind the intended identity without exact
name guessing, while an unbound or unauthorized client learns nothing sensitive.

### P0.6 Delivery and activation observability

**Candidate source progress:** the Realtime outbox now reduces untrusted
remote response, transport, and exception text to bounded status-only
diagnostics before persistence or worker logging. The Realtime outbox candidate
also records last-attempt time and a bounded failure category and projects
retry/terminal state for one message. Webhook, Workspace Agent, and Responses
API delivery rows now use the same bounded HTTP/transport categories where
their evidence aligns, while retaining provider-specific queue states. This
is mapped in the [cross-provider taxonomy](v1-delivery-failure-taxonomy.md).
It is source/CI scope only, not completion of live cross-provider delivery or
operational recovery. Commercial Assessor accepted the shared taxonomy and
administrator Delivery health surface as bounded source/CI sub-items; neither
acceptance establishes a published or installed release.
The host-only operational command now includes a bounded 50-row diagnostic
sample per path, separating last failed attempt from current path state. This
operator-CLI increment is now complemented by an authenticated, read-only
administrator Delivery health surface. Its source/CI tests cover role gating,
retry/category separation, missing worker telemetry, and content-free output;
an isolated local-browser smoke showed the administrator route rendering all
four paths and an unknown worker state against a fresh test database. The
temporary database and server were removed afterward. Published/installed
visual and real operational recovery evidence is still required before the
full observability gate can close. The isolated Docker acceptance harness
exercises a stopped delivery worker, an aging Realtime queue, worker restart,
and a unique ingress receipt; it now also checks the administrator Delivery
health endpoint on both sides of that transition. This remains isolated
candidate evidence, not a production receiver or published-artifact claim.

- [ ] Define the delivery lifecycle and terminal outcomes.
- [ ] Record bounded metadata for each delivery attempt.
- [ ] Classify failures as backpressure, binding, routing, authentication,
      client availability, rate limiting, or internal error where evidence
      permits.
- [ ] Record pending count and oldest-pending age.
- [ ] Record attempt count, last attempt, next retry, and terminal outcome.
- [ ] Record binding health and last successful activation.
- [ ] Retain diagnostics long enough to investigate delayed notifications.
- [ ] Redact message contents, credentials, claim codes, and bearer tokens from
      operational diagnostics.
- [ ] Expose machine-readable operational health.
- [ ] Provide an administrator-facing delivery and binding health view.
- [ ] Document retry, dead-letter, replay, and recovery procedures.

**Exit evidence:** an operator can diagnose a delayed or failed activation from
retained product telemetry without inspecting credentials or guessing at cause.

### P0.7 Supported participation paths

- [ ] Define the supported V1 behavior matrix for web humans, Codex, ChatGPT,
      Gemini, and remote MCP clients.
- [ ] Apply the same identity and authorization semantics to each path.
- [ ] Apply the same timeline, addressee, reply, and acknowledgement semantics
      to each path.
- [ ] Verify protected-profile isolation for Codex.
- [ ] Verify discussion-binding isolation for ChatGPT.
- [ ] Verify Gemini participation against the documented contract.
- [ ] Verify remote MCP authentication, authorization, and error behavior.
- [ ] Run cross-provider handoff tests involving at least three independent
      participation paths.
- [ ] Document known limitations and supported recovery procedures per provider.

**Exit evidence:** the supported paths pass the same coordination contract tests
and can complete a real cross-provider handoff without privileged intervention.

### Phase 0 release gate

- [ ] A new team completes clean deployment without bespoke assistance.
- [ ] The team completes a clean `v1.0.0` installation and backup/restore
      exercise; prior-release upgrade/rollback applies after `v1.0.0`.
- [ ] The V1 coordination contract is versioned, documented, and tested.
- [ ] Supported integrations complete the cross-provider acceptance scenario.
- [ ] Operators correctly diagnose representative binding, authentication,
      backlog, retry, and client-availability failures.
- [ ] No unresolved P0 security, data-loss, or identity-isolation defects remain.

## Phase 1 — Coordination Operations

### P1.1 Explicit responsibility signals

- [ ] Define the minimum explicit state needed to represent waiting, unresolved,
      resolved, blocked, and responsibility transfer without inferring it from
      acknowledgement time.
- [ ] Preserve the canonical message and timeline history behind every derived
      state.
- [ ] Define correction and conflict behavior when participants disagree about
      responsibility or resolution.
- [ ] Add authorization and audit tests for responsibility-state changes.

**Exit evidence:** every derived responsibility state points to explicit,
auditable timeline evidence and can be corrected without rewriting history.

### P1.2 Responsibility Inbox

- [ ] Show work addressed to the current participant.
- [ ] Show unacknowledged addressed work.
- [ ] Show work waiting on another participant.
- [ ] Show acknowledged but explicitly unresolved work.
- [ ] Support filtering by project, participant, state, and age where useful.
- [ ] Link every item to its canonical timeline message and context.
- [ ] Provide clear empty, loading, stale, and error states.
- [ ] Verify keyboard, screen-reader, desktop, and mobile usability.

**Exit evidence:** a normal user can determine what they own, what is waiting,
and what needs attention without reconstructing a long chronological timeline.

### P1.3 Recent handoffs and compact context

- [ ] Represent explicit responsibility transfers.
- [ ] Show recent handoffs with source, destination, time, and status.
- [ ] Provide compact reply-thread or handoff summaries.
- [ ] Link summaries back to the exact canonical messages.
- [ ] Avoid generated summaries that conceal conflicting or missing evidence.
- [ ] Test long threads, edited messages, deleted messages, and deep replies.

**Exit evidence:** users can understand what changed hands and why while retaining
one-click access to the complete authoritative history.

### P1.4 Delivery & Binding Health product surface

- [ ] Show binding state per supported agent integration.
- [ ] Show pending and failed activation deliveries by binding.
- [ ] Show backlog count and age.
- [ ] Show retry state, next retry, and last successful activation.
- [ ] Distinguish canonical message creation from notification, activation,
      handling, acknowledgement, and completion.
- [ ] Provide safe operator actions for retry or replay where appropriate.
- [ ] Require confirmation and record an audit event for material recovery
      actions.

**Exit evidence:** an administrator can identify affected bindings, understand
delivery state, and perform a safe documented recovery.

### P1.5 Graceful burst and outage behavior

- [ ] Define expected queue behavior when a target is busy or unavailable.
- [ ] Keep queued work durable across worker and application restarts.
- [ ] Apply bounded retries with visible backoff and terminal outcomes.
- [ ] Prevent or mitigate silent head-of-line blocking.
- [ ] Preserve ordering where the coordination contract requires it.
- [ ] Load-test realistic bursts across multiple bindings.
- [ ] Test prolonged client unavailability and eventual recovery.
- [ ] Verify that messages remain canonical and are not duplicated or lost.

**Exit evidence:** burst and outage tests demonstrate durable work, explainable
delays, bounded recovery, and no silent loss or duplication.

### Phase 1 release gate

- [ ] Users can answer: What am I responsible for?
- [ ] Users can answer: What is waiting on another participant?
- [ ] Users can answer: What has not been acknowledged?
- [ ] Users can answer: What recently changed hands?
- [ ] Administrators can answer: Which bindings or deliveries need attention?
- [ ] Usability testing confirms those answers do not require manual timeline
      reconstruction.
- [ ] No derived view treats acknowledgement as first-read or task completion.

## Phase 2 — Design-Partner Validation

### P2.1 Pilot preparation

- [ ] Define one target profile: AI-forward engineering or product teams already
      coordinating humans across multiple AI systems.
- [ ] Begin recruitment during Phase 0.
- [ ] Recruit 5–10 design partners for active pilots.
- [ ] Document informed data collection, retention, privacy, and support terms.
- [ ] Capture each team's baseline process before Syndicatum adoption.
- [ ] Define each metric's exact event and timestamp semantics.
- [ ] Prepare onboarding, support, feedback, and incident-response playbooks.

### P2.2 Pilot measures

- [ ] Measure lost or unacknowledged requests.
- [ ] Measure explicitly unresolved responsibilities.
- [ ] Measure human follow-up effort.
- [ ] Measure duplicated or conflicting work.
- [ ] Measure cross-agent handoffs.
- [ ] Measure notification and activation failures and recovery.
- [ ] Measure work initiated by another participant rather than manually launched
      by an operator.
- [ ] Collect qualitative evidence about ownership clarity, waiting work, and
      confidence in project history.
- [ ] Compare results with each team's documented baseline.

### P2.3 Commercial decision gate

- [ ] Determine whether pilots show a repeatable reduction in lost requests,
      duplicated follow-up, or unresolved ownership.
- [ ] Identify the smallest segment with repeatable value and willingness to
      continue or pay.
- [ ] Decide whether the next paid investment is hosted operations,
      governance/audit, enterprise support, or further workflow refinement.
- [ ] Reject roadmap expansion when the core coordination outcome is not proven.
- [ ] Record the decision and supporting evidence.

## Cross-cutting interoperability constraints

- [ ] Keep remote MCP stable and first-class throughout V1.
- [ ] Preserve provider-neutral coordination semantics in the core.
- [ ] Keep provider-specific behavior in edge adapters.
- [ ] Document the intended A2A compatibility boundary.
- [ ] Validate one thin standards-native interoperability path if practical.
- [ ] Do not make a broad A2A gateway a V1 launch dependency.

## Scope guardrails

The following remain out of V1 unless pilot evidence and an explicit scope
decision bring them back:

- [ ] Defer the move from the 5.7.44 stabilization baseline to a supported
      MySQL LTS release until after V1 stability; track it explicitly as a
      post-V1 security and operations risk, not generic compatibility work.

- [ ] No proprietary LLM runtime.
- [ ] No full workflow or automation builder.
- [ ] No sophisticated task-graph engine.
- [ ] No vector/RAG platform.
- [ ] No model gateway.
- [ ] No large provider or SaaS connector catalog.
- [ ] No enterprise policy engine.
- [ ] No SAML, SCIM, or broad compliance package.
- [ ] No hosted high-availability program.
- [ ] No heavy analytics or evaluation suite.
- [ ] No large A2A implementation.
- [ ] No complex per-message or per-token monetization.

These boxes are checked only at V1 completion to confirm that scope was
successfully resisted, not because these features were implemented.

## Recommended execution order

1. Complete the baseline gap matrix and Phase 0 acceptance contract.
2. Stabilize and test the coordination contract before building derived views.
3. Establish deployment, migrations, backup/restore, CI, licensing, and releases.
4. Improve onboarding and add retained delivery diagnostics.
5. Harden supported integrations using one shared contract-test matrix.
6. Pass the Phase 0 clean-environment release gate.
7. Add explicit responsibility signals, then build the Responsibility Inbox.
8. Build compact handoffs and Delivery & Binding Health from canonical evidence.
9. Prove burst/outage behavior and pass the Phase 1 release gate.
10. Run design-partner pilots and use their evidence to choose post-V1 scope.
