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
[`docker-acceptance-2026-09-17.md`](docker-acceptance-2026-09-17.md). The earlier
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
- [x] Document routine operation and incident-recovery commands.

**Exit evidence:** a clean machine can install, start, upgrade, back up, restore,
and diagnose Syndicatum by following the documented procedure.

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
- [ ] Define and review the V1 versioning policy. A
      [candidate policy](v1-release-policy.md) now separates application,
      protocol, and installed-client versions; release review remains open.
- [ ] Define a repeatable release and rollback process.
- [ ] Add continuous integration for PHP, JavaScript, documentation contracts,
      migrations, and packaging.
- [ ] Add dependency, secret, and baseline static-security scanning.
- [ ] Review the security and deployment-hardening implications of using the
      terminal MySQL 5.7.44 release before any external production-readiness
      claim; keep this separate from functional compatibility on 5.7.44.
- [ ] Produce immutable release artifacts with checksums.
- [ ] Publish release notes and migration notes for each release.
- [ ] Test installation and upgrade from the published artifact, not only from a
      working tree.

**Exit evidence:** a tagged release can be built, verified, installed, upgraded,
and rolled back using published artifacts and documentation.

### P0.5 Permission-safe onboarding and binding

- [ ] Preserve pre-binding confidentiality: an unbound discussion cannot
      enumerate projects or participant identities.
- [ ] Replace byte-for-byte project-name guessing with normalized matching or an
      authorized project-selection step in the Companion confirmation flow.
- [ ] Present the selected server, project, and agent identity before final
      confirmation.
- [ ] Present the bound project and agent identity clearly after completion.
- [ ] Prevent accidental replacement or reuse of an existing protected identity.
- [ ] Preserve isolated credentials per agent profile.
- [ ] Document claim-code expiry, single use, replacement, and recovery.
- [ ] Add negative tests for cross-project, cross-participant, expired-code,
      reused-code, and unauthorized enumeration attempts.
- [ ] Test onboarding with a user who has no repository or database knowledge.

**Exit evidence:** a permitted user can bind the intended identity without exact
name guessing, while an unbound or unauthorized client learns nothing sensitive.

### P0.6 Delivery and activation observability

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
- [ ] The team completes an upgrade, backup, and restore exercise.
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
