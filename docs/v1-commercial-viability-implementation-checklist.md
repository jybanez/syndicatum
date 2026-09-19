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
deployment is approved. Internal/non-production `v1.0.0-rc.1` release run
[35424177468](https://github.com/jybanez/syndicatum/actions/runs/35424177468)
subsequently clean-installed the independently downloaded, hash-verified
published archive and repeated migrations, health/recovery, backup, and
restore on MySQL 5.7.44. Commercial Assessor message 2843 accepts this as the
release-grade P0.2 evidence for RC1; a material later RC change reopens it.

- [x] Promote the candidate Linux/Docker deployment path to the supported
      **internal/non-production RC1** path after clean-environment acceptance
      passed against the exact published artifact. This does not authorize
      external/design-partner distribution or production use.
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
- [x] Expose health states that distinguish application, database,
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
      Those statements record the evidence state at each historical rerun.
      Exact published-byte RC1 run 35424177468 subsequently repeated the
      aggregate health, worker failure/recovery, due-event, retry-exhaustion,
      uncertain-replay, canonical-versus-activation, token/binding, backup,
      and restore checks. Commercial Assessor message 2843 closes P0.2 for
      RC1 while keeping installed Companion/OAuth usability in P0.5 and live
      external/production receivers out of this acceptance scope.
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
At that historical checkpoint no tagged, published immutable V1 release
artifact had been recorded. RC1 run 35424177468 now supplies that artifact and
packaging evidence; security disposition remains open as described below.
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
The `.github/workflows/release-rc.yml` path requires an annotated
`v1.0.0-rc.N` tag on protected `main` with reviewed release notes, reruns the
source and Docker checks at the tag, publishes their checksummed archive as a
prerelease, then downloads and clean-installs the published bytes. The first
real run, [35424177468](https://github.com/jybanez/syndicatum/actions/runs/35424177468),
passed for immutable internal/non-production `v1.0.0-rc.1` at protected-main
SHA `5e9b4f4161c026cc663abd0b587ea474bf5150de`; the independently re-downloaded
archive SHA-256 is
`57567f70748707d98ad57100e7e1bd81e322f69be7953b8a7f9230fea912ee99`.
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
[PR 6](https://github.com/jybanez/syndicatum/pull/6) merged the reviewed
commercial-viability candidate into protected `main`; main-push run
[35423325090](https://github.com/jybanez/syndicatum/actions/runs/35423325090)
passed all required jobs before the separate RC tag authorization. Portable fixture
results must remain labeled separately from installed client evidence. The
required release path must test at least one database mode/configuration
representative of the supported production deployment, including strict SQL
behavior; permissive local defaults alone are insufficient.

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
      real tag-triggered run 35424177468 then passed on `v1.0.0-rc.1`.
- [x] Apply and read back V1 tag protection. Rulesets `23612219` and
      `23612208` are active with the approved pattern, rules, and bypass lists;
      see the [policy record](v1-tag-protection-proposal.md).
- [x] Draft the first RC-specific release notes at
      [`docs/releases/v1.0.0-rc.1.md`](releases/v1.0.0-rc.1.md), with the
      clean-install-only boundary, candidate runtime/schema head, exact source
      package versions, and open acceptance/security limitations. The notes are
      now published with RC1; publication does not close the security gate.
- [x] Produce and verify the first real RC evidence chain: exact protected-main
      commit, immutable tag, required CI run, archive/hash, published release,
      and downloaded-artifact acceptance on the declared baseline. Evidence is
      recorded in release run 35424177468 and Syndicatum messages 2842–2843.
- [x] Decide the first-release support path: `v1.0.0` supports a fresh Docker
      installation only on the declared baseline. Jonathan confirmed this
      decision through the Commercial Assessor in project message 2337. The
      existing internal deployment is not a supported prior commercial release;
      its in-place upgrade is not a `v1.0.0` release gate. Data migration from
      it is separate assistance, not a supported upgrade promise.
- [ ] Define a repeatable release and rollback process.
- [x] Add continuous integration for PHP, JavaScript, documentation contracts,
      migrations, and packaging.
- [x] Add dependency, secret, and baseline static-security scanning. Scanner
      execution is complete; vulnerability disposition remains a separate open
      gate below.
- [ ] Review the security and deployment-hardening implications of using the
      terminal MySQL 5.7.44 release before any external production-readiness
      claim; keep this separate from functional compatibility on 5.7.44. The
      [proposed external host/runtime baseline](v1-external-host-runtime-baseline.md)
      records MySQL 5.7 as an internal-RC-only baseline and recommends MySQL
      8.4 LTS compatibility/migration acceptance before normal external use.
      The Assessor accepted that direction in project message 2859. No owner
      decision is needed to implement and test 8.4; a temporary external 5.7
      exception would require separate, time-bounded owner residual-risk
      approval. [PR 8](https://github.com/jybanez/syndicatum/pull/8)
      preserves the 5.7 defaults and adds separate pinned MySQL 8.4.11 clean-
      install and 5.7-to-8.4 logical migration jobs. Commercial Assessor
      messages 2869 and 2877 close both database sub-gates for their documented
      scope. The migration evidence exercised GitHub merge-ref candidate
      `8bce52f` (parents protected main `5e9b4f4` and branch head `0e1141f`),
      not the branch head in isolation. PR 8 then merged through protected main
      as `27d8010`; main-push run 35442123805 passed source, security inventory,
      5.7 lifecycle, 8.4 clean install, and 5.7-to-8.4 migration jobs. The
      subsequent MySQL 8.4 image-hardening work merged through protected main
      as `9b564fc` after exact-head run 35449991246 and passed main-push run
      35452115230. It pins fixed OpenSSL/libevent packages, removes unused
      `mysql-shell` and its private Python environment, and leaves only the
      exact `gosu` CRITICAL/HIGH tranche approved `not_applicable` by the
      Commercial Assessor in messages 2883 and 2885. The **external
      host/runtime baseline** and **overall external promotion** gates remain
      open; RC1 remains the immutable 5.7.44 internal candidate, and the merged
      8.4 work belongs to a subsequent release chain.
- [ ] Complete the [security acceptance table](v1-security-acceptance-table.md)
      for every CRITICAL and release-relevant HIGH container finding, including
      runtime exposure, compatible fix, mitigation, residual risk, and explicit
      acceptance. A green inventory scan is not security-gate evidence.
      Commercial Assessor messages 2851–2853 approve all 19 exact-RC CRITICAL
      rows (ten `unreachable`, nine `not_applicable`, no residual-risk
      acceptance), closing the CRITICAL sub-gate. Message 2864 additionally
      approves the ten exact-binary `gosu` HIGH rows (nine `not_applicable`,
      one `unreachable`, no residual-risk acceptance), closing that bundled-
      helper sub-gate. Message 2871 approves all five application `libcurl4`
      HIGH rows (one `not_applicable`, four `unreachable`, no residual-risk
      acceptance), closing that exact-call-contract sub-gate. Messages 2883
      and 2885 close the future MySQL 8.4 image-security tranche after fixed
      OpenSSL/libevent package refresh, removal of unused `mysql-shell` Python
      tooling, and exact-binary `gosu` review; protected-main run 35452115230
      verifies that result after PR 9 merged as `9b564fc`. Residual-risk
      acceptances remain zero. MySQL 5.7 stays confined to immutable internal
      RC1 evidence; the external host/runtime minimums and overall external
      promotion gate remain open.
- [ ] Approve and automate the
      [external host/runtime baseline](v1-external-host-runtime-baseline.md),
      including Docker/Compose, maintained containerd/runc, supported OS/kernel,
      network boundary, operator privileges, and retained preflight evidence.
      Draft PR 10 adds the fail-closed collector and its CI contract. Automation
      does not close this item: a real compliant Ubuntu 24.04 amd64 host must
      produce a passing checksummed evidence bundle and complete the exact
      archived-candidate lifecycle before Assessor review.
- [x] Produce immutable release artifacts with checksums for RC1.
- [x] Publish release notes and migration notes for RC1.
- [x] Test clean installation of the `v1.0.0-rc.1` published artifact, not only
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
Commercial Assessor accepted the shared taxonomy, administrator Delivery
health surface, and real-worker recovery exercise as bounded source/isolated-
candidate sub-items in project messages 2618, 2629, and 2637. The terminal-
recency correction at `adfc48e` was accepted at source/candidate scope in
message 2644; [run 35327128084](https://github.com/jybanez/syndicatum/actions/runs/35327128084)
passed source-contract, archived-candidate Docker/MySQL 5.7 acceptance, and
security-inventory jobs. These acceptances do not establish a published or
installed release, a live production receiver, or live Workspace/Responses
activation.
The host-only operational command now includes a bounded 50-row diagnostic
sample per path, separating last failed attempt from current path state. This
operator-CLI increment is now complemented by an authenticated, read-only
administrator Delivery health surface. Its source/CI tests cover role gating,
retry/category separation, missing worker telemetry, and content-free output;
an isolated local-browser smoke showed the administrator route rendering all
four paths and an unknown worker state against a fresh test database. The
temporary database and server were removed afterward. The isolated Docker
acceptance harness exercises a stopped delivery worker, an aging Realtime
queue, worker restart, and a unique ingress receipt. It also checks the
administrator Delivery
health endpoint on both sides of that transition. This remains isolated
candidate evidence, not a production receiver or published-artifact claim.
Exact published-byte run 35424177468 repeated the health and recovery checks
from independently downloaded `v1.0.0-rc.1`, tied to the archive hash and
release provenance. Commercial Assessor message 2843 accepts P0.6 as achieved
for RC1, subject to reopening if a later RC materially changes delivery or
health code. This remains internal/non-production evidence and is not a live
production-receiver or external installed-client claim.

- [x] Define the delivery lifecycle and terminal outcomes.
- [x] Record bounded metadata for each delivery attempt.
- [x] Classify failures as backpressure, binding, routing, authentication,
      client availability, rate limiting, or internal error where evidence
      permits.
- [x] Record pending count and oldest-pending age.
- [x] Record attempt count, last attempt, next retry, and terminal outcome.
- [x] Record binding health and last successful activation.
- [x] Retain diagnostics long enough to investigate delayed notifications.
- [x] Redact message contents, credentials, claim codes, and bearer tokens from
      operational diagnostics.
- [x] Expose machine-readable operational health.
- [x] Provide an administrator-facing delivery and binding health view.
- [x] Document retry, dead-letter, replay, and recovery procedures.

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

**Scoped P1.1 sub-gates reviewed by Commercial Assessor:**
[`v1-responsibility-state-proposal.md`](v1-responsibility-state-proposal.md)
defines an append-only, canonical-message-backed event model and explicit
conflict/correction behavior. The Assessor approved these semantics for draft
PR #6 head `7cf0a07` in project message #2726. The Assessor separately
accepted the pure reducer transition/role matrix at `7c0eabc` (#2738), the
bounded persistence and canonical-message atomicity/idempotency paths at
`2acdad9` (#2740), and genuine concurrent two-writer serialization plus HTTP
409 conflict mapping at `a8a6241` (#2743), and the bounded API foreign-ID /
exercised-role paths at `d3d3705` (#2751), all at **source/CI scope only**.
The Assessor later accepted the broader persisted/API authorization matrix
and pure derived inbox/shared-projector read contract at draft PR #6 head
`aad3489` (#2764), also at bounded source/API/CI scope. The historical
responsibility-baseline migration and non-inventive read-only preflight at
`3595eae` were accepted at source/CI scope in #2771. These decisions do
not close P1.1: installed-client/UI acceptance remains open. The implementation
items below remain unchecked until their full exit evidence is reviewed.

The Assessor accepted the Responsibility Inbox source-client architecture and
action contract at `5a33613` (#2775), with the navigation qualification in
#2776. The client fetches exact canonical evidence and attempts to focus its
mounted row in the existing Helper timeline. When virtualization or filters
leave the row unmounted, the source client now provides a read-only evidence
dialog with project sequence, reply context, revision/tombstone state, and
explicit navigation without changing timeline filters. This initially had
source-only evidence; the narrow installed probe below now confirms the
mounted and filtered-out navigation paths. Broader authenticated behavior,
keyboard/focus/accessibility, and full P1.1/P1.2 remain open.

The Assessor accepted **P1.2 canonical evidence navigation/deep-link fallback
at source-client/CI scope** for exact head `7f69fda` in message #2779. This
closes the source-level qualification in #2776, not the installed or
accessibility gate. The authenticated probe below supplied bounded installed
evidence for mounted-row focus and filtered-out dialog fallback with unchanged
filters; dialog focus trap, Escape/return focus, and keyboard activation still
need installed accessibility acceptance.

The first [isolated installed-client probe](v1-responsibility-installed-acceptance-2026-09-19.md)
at `7f69fda` verified a direct request in the owner’s waiting view, mounted
and filtered-out canonical evidence navigation, and an owner withdrawal that
updated the inbox and appended one canonical timeline event. A second
authenticated participant then exercised acknowledgment, start, block,
resolution proposal, and owner acceptance. It is a narrow positive probe;
the remaining role/state, conflict, history, responsive, and accessibility
acceptance cases are still open. The Assessor accepted the owner-side positive
path and installed evidence navigation at isolated-probe scope in #2781,
then the two-identity positive path in #2783. At exact head `af483a3`, CI run
`35389996471` passed all required jobs, allowing the historical `Previously
blocked` label correction at source/CI scope (#2785). At that checkpoint it
had not yet been verified in an installed image. The later isolated wording
probe below closes only that observation. The checkboxes remain
unchecked pending full evidence. Other installed checks include HTTP 409
conflict/recovery UX, transfer/orphan and dispute/reopen paths, realistic
pagination/history and unknown baseline, edit/tombstone navigation, mobile,
keyboard/focus accessibility, and the resolved historical-label recheck.

At exact head `5e6ea70`, a second isolated installed-client probe recorded
owner withdrawal reconstructed after browser reload, exact request `#1` to
evidence `#2` linkage, and a same-key/same-content retry returning the original
message without a duplicate. The same item was then reopened with evidence
`#3`, and an installed API write using stale event `#2` correctly returned
HTTP 409 without changing the one open item. See [the installed acceptance
record](v1-responsibility-installed-acceptance-2026-09-19.md). This narrows the
reload/linkage/replay and server conflict evidence only. The Assessor accepted
installed canonical-state reconstruction and idempotent retry for the exercised
owner-side path, plus stale-conflict semantics at installed API scope, in #2791;
exact-head docs-only CI support was accepted in #2793. P1.1/P1.2 remained open.

A two-browser owner-side conflict probe then found that `5e6ea70` correctly
returned 409, refreshed the stale waiting list, and wrote no duplicate, but
routine live polling quickly replaced the specific `nothing was posted`
notice. Client commit `d9ccd63` preserves that conflict notice until explicit
refresh/view change and does not claim a successful refresh when it fails. An
exact-archive installed image of `d9ccd63` reproduced the 409 with two
authenticated views: the refreshed list and persistent notice were visible,
switching to `Resolved` showed the same withdrawn request, and canonical
sequence remained at four successful events with no stale-write message.
See [the installed acceptance record](v1-responsibility-installed-acceptance-2026-09-19.md).
The Assessor accepted installed browser stale-action conflict refresh/no-side-
effect UX for this exercised owner-side path in #2795. Exact docs/evidence
head `92cbb1a` passed all required CI jobs in run `35398146572` (#2796).
Broader roles/states, transfer, dispute, pagination/history, edit/tombstone,
and accessibility remain open; neither P1.1 nor P1.2 is closed overall.

The [installed wording probe](v1-responsibility-installed-acceptance-2026-09-19.md)
then used the exact `d9ccd63` application image with fresh MySQL 5.7.44 data
and two authenticated synthetic humans. Its blocked-then-accepted request
appeared in `Resolved` as `Previously blocked · Work started`, including after
a full reload. The authenticated projection retained `blocked=true` and one
resolved request. The Assessor accepted this exercised installed historical-
qualifier wording sub-gate in #2803; evidence/checklist head `e961a95` passed
all three required V1 CI jobs in run `35401728565` (#2804). This is not
historical-baseline, accessibility, P1.1/P1.2, or release acceptance.

The subsequent three-identity installed transfer/orphan probe found and fixed
an asymmetric responder-generation defect. Exact image `771a222` now preserves
the reduced responder when recording transfer acceptance. In a fresh MySQL
5.7.44 stack, the installed browser exercised offer, decline, re-offer,
acceptance, member removal to `Orphaned`, owner-mediated recovery offer, and
acceptance by a different active responder. The recovered request appeared
once as open in that responder's `My work` view. See the
[installed acceptance record](v1-responsibility-installed-acceptance-2026-09-19.md#transfer-and-orphan-recovery-probe).
The Assessor accepted installed transfer, orphan derivation, and explicit
reassignment/recovery for this exercised three-human path in #2809. Exact
evidence head `db45f36` passed all required jobs in run `35408890560`.
Pagination/history/unknown baseline, edit/tombstone, mobile, accessibility,
and overall P1.1/P1.2 remain open.

The same exact installed image was then exercised in a fresh two-identity
MySQL 5.7.44 stack for requester dispute and explicit reopen. The responder
proposed resolution; the requester disputed it; the responder submitted a
revised proposal; the requester accepted it and then reopened it with a reason.
The installed views moved the one item through `Decisions needed`, `Disputed`,
`Resolved`, and back to open `Waiting on others`, while the canonical timeline
contained exactly six ordered messages and the projection retained the same
responder. See the
[installed acceptance record](v1-responsibility-installed-acceptance-2026-09-19.md#dispute-revised-resolution-and-reopen-probe).
The Assessor accepted requester dispute, revised proposal, acceptance, and
explicit reopen for this exercised two-human path in #2811. Exact evidence
head `4595cb9` passed all required jobs in run `35410305194`. Other role
variants and the remaining pagination/history/unknown, edit/tombstone,
responsive, accessibility, and overall gates remain open.

The next exact-image installed probe created 55 direct requests in one fresh
MySQL 5.7.44 project and reproduced one unverified historical baseline. The
installed API paginated 50 + 5 with 55 unique request IDs; the historical row
projected as `unknown` with no invented owner or evidence. The installed Inbox
showed 50 items plus `Load older work`, then 55 unique cards after paging. Its
`Historical / unknown` filter preserved the older-page affordance when the
first page had zero matches, then showed only request `#1` as `Unknown` / `Not
verified` with explicit guidance that it was not an active assignment. See the
[installed acceptance record](v1-responsibility-installed-acceptance-2026-09-19.md#pagination-history-and-unknown-baseline-probe).
This closes only the exercised single-project 50+5 pagination/no-duplicate and
unknown-baseline presentation path. Broader multi-project scale,
edit/tombstone, responsive/accessibility, and overall gates remain open. The
Commercial Assessor accepted this bounded installed sub-gate in project message
#2815; exact evidence head `a3f0d86` passed all required jobs in GitHub Actions
run `35412134767`.

The next exact-image installed probe exercised edit and soft-delete fidelity on
an offscreen canonical request. One request plus 51 newer direct requests put
the exercised message outside the timeline's first 50 rows. After one edit,
the Inbox loaded the older card and its canonical fallback preserved message
ID, sequence, sender, addressee, timestamp, thread identity, the edited body,
and `1 revision; Current visible revision`. After soft-delete, the same card
retained the responsibility projection and showed the removal timestamp,
`historical evidence retained`, and explicit non-destructive tombstone wording.
The authenticated API returned one revision, a non-null deletion timestamp,
and one retained responsibility row across its 50 + 2 cursor pages. This
closes the exercised one-edit/soft-delete/offscreen-fallback path. Commercial
Assessor accepted that bounded installed path in project message #2825 after
exact-head `d46151c` passed all required V1 CI jobs in run `35417530872`.
Multiple edits, reply-parent tombstones, and other role-specific evidence paths
remain future hardening rather than claims of this acceptance.

The next exact-image installed probe exercised the owner-side Inbox at 1440 x
1000 desktop and 390 x 844 mobile viewports with 52 direct requests. Both
viewports had zero document-level horizontal overflow; the 330px mobile card
and 352px canonical dialog stayed within the 390px viewport. Keyboard Enter
opened the Inbox and evidence dialog, the dialog initially focused its Back
action, Escape closed it, and focus returned to the exact evidence trigger.
The Inbox exposed a named region and uniquely labelled view select; its status
was a polite live region; the dialog was title-linked; and the empty candidate
page explicitly retained the load-older continuation instead of claiming
global exhaustion. No browser console errors occurred. After exact-head
`0d9bc91` passed all required V1 CI jobs in run `35419811071`, Commercial
Assessor accepted in project message #2827 the installed responsive/mobile
usability and keyboard/focus/semantic-status behavior for the exercised
owner-side path and declared the remaining required installed P1.2 usability
matrix complete for the bounded V1 scope. This is not a WCAG conformance claim
or accessibility certification. Named screen-reader speech,
high-contrast/forced-colors, 200% zoom, an exhaustive browser/device matrix,
and every actor role remain future hardening outside the accepted scope.

Historical-baseline handling and a read-only migration preflight are described
in [the responsibility migration rule](v1-responsibility-baseline-migration.md).
The Commercial Assessor recommended its non-inventive policy in message #2765,
accepted the source/CI evidence in #2771, and accepted the exercised installed
unknown-baseline presentation path in #2815. Broader historical-scale evidence
remains open, and automatic backfill is not authorized.

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

- [x] Show work addressed to the current participant.
- [x] Show unacknowledged addressed work.
- [x] Show work waiting on another participant.
- [x] Show acknowledged but explicitly unresolved work.
- [x] Support filtering by project, participant, state, and age where useful.
- [x] Link every item to its canonical timeline message and context.
- [x] Provide clear empty, loading, stale, and error states.
- [x] Verify keyboard, screen-reader, desktop, and mobile usability within the
      accepted bounded V1 scope; this is not a WCAG certification or an
      exhaustive assistive-technology/browser/device matrix.

**Exit evidence:** a normal user can determine what they own, what is waiting,
and what needs attention without reconstructing a long chronological timeline.

**V1 disposition:** the Commercial Assessor closed the remaining required
installed P1.2 usability matrix for the bounded owner-side scope in project
message #2827 after exact-head `0d9bc91` passed all required jobs in run
`35419811071`. The narrower limitations recorded above remain future hardening.

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
