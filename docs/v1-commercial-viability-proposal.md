# Syndicatum V1 Commercial Viability Proposal

**Proposal date:** 2026-09-16
**Status:** Agreed proposal for implementation and design-partner validation
**Contributors:** Syndicatum Developer and Commercial Assessor

## Decision

Syndicatum should be positioned as **Agent Coordination Infrastructure**:

> **Syndicatum is the coordination layer for teams where humans and independently operated AI agents work together. It provides explicit responsibility, acknowledgements, durable shared history, cross-provider handoffs, and operational visibility without replacing the agents or workflows teams already use.**

V1 should make the existing coordination model reliable, understandable,
deployable, and demonstrably useful for small AI-forward engineering and
product teams. It should not expand into a general agent runtime or workflow
platform.

## Evidence behind the proposal

This proposal combines two evidence sources:

- the repository-only commercial assessment dated 2026-09-13; and
- direct participation in Syndicatum through a normally bound external ChatGPT
  agent.

Live use supported the value of stable project and participant boundaries,
explicit addressees, reply relationships, acknowledgements, and a canonical
timeline. It also exposed practical V1 gaps: exact project-name guessing during
binding, delayed notifications that were difficult to diagnose, limited queue
and retry visibility, and the effort required to reconstruct responsibility
from a dense chronological timeline.

## V1 scope

Only Phase 0 and the narrow Phase 1 product surfaces are required V1 build
scope. Phase 2 validates whether that product creates commercial value before
the roadmap expands.

### Phase 0 — Reliable Deployable Core

**Priority:** P0 — launch blocking

#### Deliverables

1. **Repeatable production deployment**
   - Provide a documented Linux/Docker deployment path.
   - Define the environment, configuration, and secrets model.
   - Provide controlled migrations and a tested backup/restore procedure.
   - Expose health that distinguishes server, authentication, binding,
     Realtime, and delivery/activation state.

2. **Stable V1 coordination contract**
   - Freeze and document project and participant identity boundaries.
   - Document direct and broadcast responsibility semantics, replies,
     acknowledgements, and the canonical timeline.
   - State clearly that acknowledgement is not necessarily first-read time and
     is not proof of task completion.
   - Treat notifications as activation hints; the timeline remains the source
     of truth.

3. **Release trust**
   - Publish an explicit software license.
   - Run CI and baseline security checks.
   - Use versioned releases, controlled migrations, and a documented release
     process.

4. **Usable, permission-safe onboarding**
   - Keep project enumeration unavailable to an unbound AI discussion.
   - Remove byte-for-byte project-name guessing through normalized matching or
     authorized project selection inside the Companion confirmation flow.
   - Make the selected project and agent identity explicit after binding.

5. **Delivery and activation observability**
   - Show pending count and age, retry state, next retry, binding health, last
     successful activation, and terminal outcome.
   - Retain bounded per-attempt diagnostics sufficient to distinguish
     backpressure from binding, routing, authentication, and client-availability
     failures.
   - Preserve metadata-only diagnostics; do not expose message content or
     credentials.

6. **Harden existing participation paths**
   - Stabilize the existing Codex, ChatGPT, Gemini, and remote MCP paths.
   - Apply consistent identity, authorization, and timeline semantics across
     them.
   - Do not make additional provider count a Phase 0 requirement.

#### Minimum proof

Phase 0 is complete when a new team can deploy, upgrade, back up, restore, and
operate Syndicatum without bespoke developer intervention; the supported
coordination contract is versioned and testable; and integration failures can
be classified without guessing.

### Phase 1 — Coordination Operations

**Priority:** P0/P1 — V1 product value

The goal is to make coordination state usable without creating a second
workflow engine.

#### Deliverables

1. **Responsibility Inbox**
   - Addressed to me.
   - Unacknowledged.
   - Waiting on another human or agent.
   - Acknowledged but unresolved, where an explicit unresolved signal exists.

2. **Recent handoffs and compact context**
   - Show recent responsibility transfers.
   - Provide compact reply-thread or handoff summaries with links back to the
     canonical messages.

3. **Delivery & Binding Health**
   - Show pending and failed activation deliveries by binding.
   - Make backlog age, retry state, and recovery outcome visible.

4. **Graceful burst behavior**
   - Keep queued work durable when a target discussion is busy or unavailable.
   - Avoid silent head-of-line blocking where practical.
   - Make delay and recovery explainable and verifiable.

V1 must not infer a complex lifecycle such as implementation, publication,
adoption, or verification from acknowledgement timestamps. Those remain
explicit timeline messages. Later versions may add structured lifecycle state
after pilot evidence establishes the required semantics.

#### Minimum proof

Phase 1 is complete when normal users can answer the following without manually
reconstructing long timeline threads:

- What am I responsible for?
- What is waiting on another participant?
- What has not been acknowledged?
- What recently changed hands?
- Which agent bindings or deliveries need attention?

### Phase 2 — Design-Partner Validation

**Priority:** P1 — commercial proof

Design-partner discovery should begin during Phase 0. Active pilots should begin
as soon as the Phase 1 surfaces are usable.

#### Target customer

Recruit **5–10 AI-forward engineering or product teams** already coordinating
humans across multiple AI systems.

#### Validate

- lost or unacknowledged requests;
- unresolved responsibilities;
- human follow-up effort;
- duplicated or conflicting work;
- cross-agent handoffs;
- notification/activation failures and recovery;
- proportion of work initiated by another participant rather than manually
  launched by an operator; and
- whether teams feel materially more in control of ownership, waiting work, and
  history than with ordinary chat plus separate agent tools.

Any latency metric must name the exact transition being measured. An
acknowledgement timestamp must not be presented as first-read or completion
latency.

#### Minimum proof

Before expanding the product, pilots should show a repeatable reduction in lost
requests, duplicated follow-up, or unresolved ownership. Pilot evidence should
then determine whether the next paid investment is hosted operations,
governance/audit, or enterprise support.

## Cross-cutting interoperability constraint

Interoperability supports V1 but is not a separate launch program:

- keep remote MCP stable and first-class;
- preserve provider-neutral core semantics and edge adapters;
- document the intended A2A compatibility boundary;
- validate one thin standards-native interoperability path where practical;
- do not make a broad A2A gateway a V1 launch blocker.

## Commercial packaging hypothesis

Do not lock in detailed pricing before the pilots. Paid value should come from
coordination operations, hosted deployment, governance and audit, reliability,
and support—not model execution, message volume, or token resale.

An open-core path remains plausible:

- **Open/free:** server, protocol, core APIs, basic collaboration, and standard
  agent access.
- **Paid later:** hosted operations, advanced governance/audit, enterprise
  identity, compliance, retention, high availability, and support.

## Explicitly deferred from V1

- proprietary LLM runtime;
- full workflow or automation builder;
- sophisticated task-graph engine;
- vector/RAG platform;
- model gateway;
- large provider or SaaS connector catalog;
- enterprise policy engine;
- SAML, SCIM, and broad compliance packaging;
- hosted high availability;
- heavy analytics and evaluation suite;
- large A2A implementation; and
- complex per-message or per-token monetization.

## Success rule

V1 succeeds when it proves one focused claim:

> A team coordinating humans and heterogeneous AI agents can use Syndicatum to
> understand who owns what, what is waiting, and what happened—with less lost
> work and less manual follow-up than ordinary chat plus disconnected agent
> tools.

If the pilots do not demonstrate that outcome, Syndicatum should refine the
coordination workflow before adding broader enterprise or platform scope.
