# Syndicatum V1 Commercial Viability Gap Audit

**Audit date:** 2026-09-16
**Proposal:** `docs/v1-commercial-viability-proposal.md`
**Checklist:** `docs/v1-commercial-viability-implementation-checklist.md`
**Repository baseline:** commit `0031cc7` plus the commercial-viability branch
changes
**Purpose:** establish what already exists, what needs hardening, and what is
net-new before Phase 0 execution begins.

## Classification

- **Verified:** implementation and automated evidence exist in the current
  working tree.
- **Partial:** a useful implementation exists, but one or more V1 acceptance
  requirements are missing.
- **Missing:** no adequate implementation or operational proof was found.
- **Decision:** product, legal, or operational direction is required before the
  implementation can be finalized.

This audit does not mark an implementation item complete merely because a
related file exists. The V1 checklist requires implementation, documentation,
tests, and operational proof where applicable.

## Executive assessment

Syndicatum already has a substantial coordination core. Project and participant
identity, scoped authorization, canonical messages, addressees, replies,
acknowledgements, revisions, migrations, Realtime publication, agent activation,
protected Codex profiles, ChatGPT discussion binding, Gemini browser delivery,
and remote MCP are implemented and covered by focused tests.

The largest Phase 0 gaps are not a platform rewrite. They are:

1. a supported Linux/Docker deployment and clean-install acceptance path;
2. an explicit license, repository CI/security gates, and whole-application
   release artifacts;
3. one frozen and versioned V1 coordination contract across currently
   overlapping documentation;
4. permission-safe project selection that removes exact-name guessing;
5. unified, retained delivery-attempt diagnostics and an administrator-facing
   health surface; and
6. a cross-provider acceptance scenario executed from a clean release artifact.

Phase 1 is more clearly net-new. The current timeline can filter messages
addressed to the viewer and unacknowledged messages, but the data model does not
yet represent explicit waiting, unresolved, resolved, blocked, or handoff state.
Those signals must be designed before building a Responsibility Inbox.

## Automated baseline

The following isolated suites were run against the current working tree on
2026-09-16:

- **149 PHP tests passed, 0 failed** across legacy security, migrations,
  expansion, Project API V1, Realtime, authentication, surfaces, webhooks,
  activation, and OAuth suites.
- **97 Node tests passed, 0 failed** across the Companion and Syndicatum for
  Codex plugin.

The PHP suites create unique `syndicatum_test_*` databases and clean them up;
they do not mutate the production database. These results prove the current
working tree, not a published application release artifact.

## Phase 0 gap matrix

| Workstream | Status | Existing evidence | V1 gap |
| --- | --- | --- | --- |
| P0.1 Baseline and acceptance contract | **Partial** | This audit, the V1 checklist, `docs/architecture-inventory.md`, the guarded Docker acceptance harness, its 2026-09-16 source-tree pass, and 246 passing tests | Named owners, estimates, and evidence links per implementation item are not yet complete |
| P0.2 Repeatable production deployment | **Partial / source-tree acceptance passed** | Candidate Dockerfile/Compose stack, Linux runbook, portable worker loop, environment template, startup validation, ordered migrations, health endpoint, and the recorded clean-install/backup/restore acceptance pass now exist | An immutable published artifact and its install/upgrade/rollback proof remain required before the path is promoted to supported |
| P0.3 Stable coordination contract | **Partial / strong foundation** | Project API V1, OpenAPI, agent protocol, MCP tools, authorization boundaries, replies, acknowledgement fields, cursor ordering, revisions, and compatibility headers are documented and tested | No single frozen V1 contract/versioning policy; acknowledgement wording and notification semantics need one canonical specification; documentation contains overlapping historical contracts and some stale compatibility status |
| P0.4 Release trust | **Partial / major gap** | Git history, deterministic Companion archive/checksum build, plugin release runbooks, release notes, public legal pages, production preflight scripts, and an `AGPL-3.0-only` repository license exist | No completed third-party license inventory, no repository CI workflow, no dependency/secret/static-security workflow, no immutable whole-application artifact, and no tested application install/upgrade/rollback from a published release |
| P0.5 Permission-safe onboarding | **Partial / strong foundation** | OAuth/device authorization, confirmation intents, project-scoped authorization, protected Codex profiles, isolated credentials, single-use claim codes, and negative identity tests exist | ChatGPT binding uses case-insensitive but otherwise exact project and agent names; no authorized project picker or broader normalized matching; clean-user usability evidence is absent |
| P0.6 Delivery and activation observability | **Partial / major gap** | Durable Realtime, webhook, Workspace Agent, and Responses delivery tables contain status, attempt count, retry time, errors, and terminal state; Companion exposes sanitized health; `scripts/plugin-operational-status.php` exposes limited counts | Attempt history is overwritten rather than retained per attempt; no unified lifecycle/classification; CLI status omits Companion/Codex and disabled legacy activation queues; public health is shallow; no administrator health UI, safe replay workflow, or retention policy |
| P0.7 Supported participation paths | **Partial / strong foundation** | Web humans, Codex, ChatGPT, Gemini, webhooks, and remote MCP have implemented paths and focused tests; Codex protected-profile isolation is extensively tested | No one shared compatibility/contract test matrix, no clean-release three-provider end-to-end exercise, limited physical-device coverage, and known limitations are spread across several documents |

## Detailed findings

### Deployment and operations

#### Verified foundations

- `src/SchemaMigrator.php` discovers ordered migration files, records SHA-256
  checksums, rejects modified applied migrations, and serializes execution with
  a database lock.
- `tests/migrations.php` verifies migration installation, idempotency, identity
  preservation, and connector bindings.
- `scripts/chat-db.php` exposes migration, status, preflight, backfill,
  reconciliation, and compatibility-retirement operations.
- `docs/expansion-migration-runbook.md` defines backup-first migration and an
  honest MySQL 5.7 rollback boundary.
- `docs/production-rollout-2026-09-05.md` records successful backup restoration
  into disposable databases.
- Windows supervision exists for the Realtime outbox and the Codex connector.

#### Gaps

- A candidate Dockerfile, three-service Compose stack, guarded entrypoints,
  portable worker loop, environment contract, and Linux-oriented operations
  runbook now exist.
- The Docker acceptance harness is platform-independent at the container layer
  and uses isolated project-scoped volumes. Its 2026-09-16 run passed clean
  startup, 24 migrations and checksum verification, application/worker health,
  reachability, logical backup, mutation, restore, and restored-data
  verification. This is source-tree evidence, not published-artifact evidence.
- The container path rejects missing, weak, placeholder, and reused secrets;
  legacy non-container configuration still retains historical database defaults
  and should not be treated as a production configuration model.
- The new harness completed its clean logical-dump mutation and restore cycle;
  off-host retention and release-artifact upgrade/rollback rehearsals remain
  unverified.
- Migration DDL is intentionally forward-only. Release acceptance must therefore
  test file rollback plus database restore rather than promise down migrations.

### Coordination contract

#### Verified foundations

- `migrations/202609050002_collaboration_core.php` establishes canonical
  project sequences, participants, messages, addressees, reply links,
  acknowledgements, revisions, and idempotency.
- `src/ProjectRepository.php` enforces project isolation, role/scope-based
  permissions, addressee filtering, stable cursors, idempotent creation,
  acknowledgement, revision, and deletion behavior.
- `docs/project-api-v1.md`, `docs/openapi-v1.yaml`,
  `docs/agent-protocol-v1.md`, and the provider skills document most of the
  current contract.
- Tests cover cross-project concealment, foreign addressee/reply rejection,
  broadcast materialization, cursor recovery, acknowledgement, and revisions.

#### Gaps

- The contract is distributed across expansion-era and provider-specific
  documents rather than frozen in one normative V1 specification.
- A formal compatibility and deprecation policy for Project API V1 and remote
  MCP is not complete.
- `docs/agent-integration-roadmap.md` still labels provider-neutral remote MCP as
  planned even though the hosted MCP implementation and tests exist. This is
  evidence of documentation drift that the contract freeze must resolve.
- Existing UI timeline state maps acknowledgement to a completed visual state
  internally. V1 documentation and future derived views must not imply that an
  acknowledgement proves task completion.

### Release trust

#### Verified foundations

- Companion builds are deterministic and emit SHA-256 checksums.
- Plugin publication, monitoring, incident, backup, secret-rotation, and
  rollback procedures are documented.
- Public health and publication-verification scripts exist.
- Privacy, terms, support, OAuth hardening, rate limiting, CSRF, credential
  hashing, and encrypted integration secrets have automated coverage.

#### Gaps and decisions

- **Decision recorded:** use `AGPL-3.0-only` for the complete useful self-hosted
  V1 and monetize operations and support first. Legal review and the dependency,
  asset, and vendored-code license inventory remain release blockers.
- There is no `.github/workflows` CI definition or equivalent repository CI
  configuration.
- There is no automated dependency audit, secret scan, or static-security gate.
- Release mechanics are strongest for the Companion and Codex plugin, not for
  the complete PHP application, database migration set, workers, and assets as
  one immutable artifact.
- The public plugin release still has operator-controlled submission items, but
  those are not all blockers for a self-hosted Syndicatum V1.

### Onboarding and identity protection

#### Verified foundations

- Unbound ChatGPT discussions cannot enumerate project context through MCP.
- Discussion binding requires OAuth and an owner/admin-authorized project.
- Companion confirmation delays agent creation and binding mutation until the
  user explicitly continues.
- The confirmed context returns the actual project and agent identity.
- Codex claims are stored in isolated protected profiles; tests cover shared
  directories, replacement protection, migration, secret non-disclosure, and
  profile identity boundaries.
- Claim codes are short-lived and single-use, and project isolation is tested.

#### Gaps

- `DiscussionBindingIntentService` matches `LOWER(name) = LOWER(?)`; this is
  case-insensitive exact matching, not normalized matching or project selection.
- The user must still know and type the project and agent names before the
  Companion can show the confirmation.
- There is no observed usability run with a fresh user who lacks repository or
  database knowledge.

### Delivery and activation health

#### Verified foundations

- Canonical message creation is independent from notification delivery.
- Realtime uses a transactional outbox with retry and terminal failure fields.
- Agent webhooks and the retired/disabled server-run activation drivers have
  durable delivery rows with attempt counts, next attempt, error, response, and
  terminal state.
- The Codex plugin coalesces notifications, persists wake state, performs startup
  recovery, and prevents one stale binding from aborting another.
- Companion health separates server, account, Realtime, binding, and delivery
  state and copies only sanitized diagnostics.
- `scripts/plugin-operational-status.php` reports bounded Realtime and webhook
  backlog metrics without message bodies.

#### Gaps

- Delivery tables retain only the latest error and aggregate attempt count; the
  individual attempt sequence that would explain a transient incident is lost.
- Error classification is inconsistent across transport paths and is not stored
  as a common machine-readable reason.
- There is no unified view connecting canonical creation, notification,
  activation, handling, acknowledgement, and explicit resolution.
- The administrator web surface does not expose backlog age, next retry,
  terminal outcome, or safe recovery actions.
- Current CLI status covers only Realtime and webhooks, not all active
  Companion/Codex binding and delivery signals.
- No explicit diagnostics-retention or pruning policy was found.

### Supported integrations

#### Verified foundations

- Web humans use authenticated, CSRF-protected Project API V1 routes.
- Codex uses a plugin-owned local MCP server, protected agent profiles, device
  authorization, a persistent connector, and metadata-only wake messages.
- ChatGPT uses OAuth/MCP for authoritative coordination and the Companion for
  metadata-only activation.
- Gemini uses a binding-scoped two-way browser bridge; the server posts and
  acknowledges under the configured agent identity.
- Remote MCP service discovery, authentication challenges, OAuth, context,
  timeline, post, and acknowledgement paths are implemented and tested.

#### Gaps

- Provider suites prove components independently but do not form one release
  gate that exercises a real handoff among three participation paths.
- Compatibility status is split among the integration roadmap, plugin docs,
  Companion docs, and test names.
- macOS support has meaningful Codex launcher tests, but broader physical-device
  and Linux acceptance remains incomplete.

## Phase 1 gap matrix

| Workstream | Status | Existing evidence | Net-new requirement |
| --- | --- | --- | --- |
| P1.1 Explicit responsibility signals | **Missing** | Direct/mention/broadcast addressees, replies, seen time, and acknowledgement exist | Define and persist explicit waiting, unresolved, resolved, blocked, and transfer signals with audit/correction semantics |
| P1.2 Responsibility Inbox | **Partial** | Timeline filters support Addressed to me and Unacknowledged | Build a distinct operational view for owned, waiting, and unresolved work after explicit state exists |
| P1.3 Handoffs and compact context | **Mostly missing** | Reply chains and correlation IDs provide raw evidence | Define responsibility transfer, expose recent handoffs, and create evidence-linked compact context |
| P1.4 Delivery & Binding Health UI | **Missing over partial telemetry** | Backend delivery fields, Companion popup health, and limited CLI metrics exist | Build a unified admin API/UI with backlog, retry, outcomes, classification, and guarded recovery actions |
| P1.5 Burst and outage behavior | **Partial** | Durable queues, retry backoff, locking, Codex wake coalescing, restart recovery, and isolation tests exist | Add realistic multi-binding load tests, retained attempts, head-of-line analysis, Linux worker operation, and explicit ordering acceptance rules |

## Cross-cutting interoperability audit

| Constraint | Status | Finding |
| --- | --- | --- |
| Remote MCP remains first-class | **Verified / document drift** | The MCP/OAuth implementation and tests exist, but the integration roadmap still calls the generic path planned |
| Provider-neutral core semantics | **Partial / strong foundation** | Project API V1 and core participant/message concepts are provider-neutral; provider compatibility evidence remains fragmented |
| Provider-specific edge adapters | **Verified** | Companion adapters and Codex driver isolate provider/browser behavior from the canonical timeline |
| A2A compatibility boundary | **Missing** | No normative A2A boundary document was found beyond proposal/checklist references |
| Thin standards-native validation | **Missing / optional for V1** | No accepted A2A validation path was found; the proposal correctly keeps it off the launch gate |

## Recommended implementation sequence

### Slice 1 — Freeze the foundation

1. Consolidate the normative V1 coordination contract and correct stale
   integration-status documentation.
2. Record explicit compatibility and deprecation rules.
3. Complete legal review and the third-party license inventory for the selected
   `AGPL-3.0-only` distribution.
4. Add CI that runs the existing 246-test baseline plus syntax and contract
   checks.

This slice is low architectural risk and turns the existing implementation into
a controlled baseline for every later change.

### Slice 2 — Prove portable operation

1. Add a Docker/Linux deployment for the PHP application, MySQL, and required
   workers.
2. Add an environment template and production startup validation.
3. Automate clean install, migration, backup, restore, upgrade, and file rollback
   acceptance in an isolated environment.
4. Build an immutable whole-application artifact with a checksum.

### Slice 3 — Make delivery diagnosable

1. Define one provider-neutral delivery lifecycle and reason taxonomy.
2. Add bounded per-attempt metadata retention instead of overwriting the last
   failure.
3. Extend machine-readable operational health to every supported delivery path.
4. Add administrator APIs and a Delivery & Binding Health surface.
5. Add guarded replay/retry operations with audit events.

### Slice 4 — Remove onboarding friction

1. Add authorized project/agent selection to the Companion confirmation flow,
   preserving pre-binding confidentiality.
2. Keep normalized textual matching only as a safe convenience/fallback.
3. Run the flow with a clean user who has no repository knowledge.

### Slice 5 — Pass the Phase 0 release gate

1. Install from the release artifact on a clean environment.
2. Bind web, Codex, ChatGPT, and Gemini participants.
3. Exercise one cross-provider responsibility and acknowledgement scenario.
4. Introduce a temporary delivery failure, diagnose it, recover it, and verify
   no message loss or duplication.
5. Upgrade, back up, restore, and confirm canonical history.

### Slice 6 — Add explicit responsibility state, then Phase 1 views

Do not begin with the Inbox UI. First agree on the smallest explicit state model
and its correction semantics. Then build the Responsibility Inbox, recent
handoffs, compact context, and operational health views from canonical evidence.

## Immediate decisions and next action

Two owner decisions are required early:

1. Which contributor-governance mechanism and trademark policy should accompany
   the selected `AGPL-3.0-only` license?
2. Is Docker Compose the supported first production package, or should the first
   target be a single-host Linux runbook with system services?

The recommended next engineering action is **Slice 1: freeze the foundation**.
It delivers a stable contract and automated gate before deployment and telemetry
changes begin, while the two owner decisions are resolved.
