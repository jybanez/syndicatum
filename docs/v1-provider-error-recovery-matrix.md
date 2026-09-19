# V1 provider error and recovery matrix — candidate

**Status:** source-tree evidence inventory, not an installed-client certification.
**Contract:** [V1 coordination contract](v1-coordination-contract.md), candidate revision 1.0.
**Release:** none. Record an immutable artifact/version and client versions before
any live acceptance result is promoted to a release claim.

## Evidence layers

For every supported path and scenario, record all three layers separately:

1. **Adapter/component:** the relevant automated test and its exact assertion.
2. **Server/telemetry:** canonical message, binding, queue/attempt, and audit
   state, without message bodies or credentials in operational logs.
3. **Installed client:** what a normal operator saw, did, and recovered on a
   specified product/client version and OS/browser.

Only layer 1 is present for several paths today. A server-side queued record is
not proof that a client received or handled a message. A client notification is
not proof that the canonical message was posted or acknowledged.

## Current path boundaries

| Path | Identity and context | Source-tree evidence | Installed-client status |
| --- | --- | --- | --- |
| Web human | Session, project membership, CSRF on mutations | `tests/project-api.php` covers authentication, project concealment, CSRF, addressing, replies, acknowledgement, cursors, and idempotent writes. | Clean-release, fresh-user handoff unverified. |
| Codex | Locally protected project-agent profile; separate connector activation binding | `plugins/codex/test/profile-timeline.test.mjs` checks protected-profile timeline calls and stable post keys; `plugins/codex/test/syndicatum-client.test.mjs` checks recovery batch and safe TLS classification. | Installed-release, second-device, restart, revocation, and cross-project recovery unverified. |
| ChatGPT | OAuth account plus confirmed discussion or short-lived interactive context | `tests/project-api.php` checks unbound/invalid-context MCP denial; `tests/chatgpt-oauth.php`, `tests/agent-activation.php`, `tests/surfaces.php`, and `companion/test/package.test.mjs` cover portions of OAuth, binding, notification, and client packaging. | Earlier live acceptance used a superseded consent flow; current-flow clean-release recovery unverified. |
| Gemini | Exact Companion-bound browser discussion, server-authorized agent reply | `tests/agent-activation.php` checks bound-identity response and post/acknowledge behavior; Companion package tests inspect adapter routing. | Physical browser, interrupted delivery, duplicate/restart, and clean-release acceptance unverified. |
| Remote MCP service token | Provisioned token pins one active project-agent membership; no ChatGPT discussion binding | `tests/project-api.php` checks diagnosis, project-scoped discovery, post/read, foreign-project concealment, restricted agent scope, and revocation. | Independent-client token provisioning/rotation and installed-release interoperability unverified. |

## Failure and recovery scenarios

Use **not applicable** only when the path has no such activation mechanism; do
not translate it into a pass. The currently known source-tree checks are listed
below. Blank operational/client evidence is work to do, not an implied success.

| Scenario | Component/adapter evidence now | Server/telemetry evidence required | Installed-client acceptance required |
| --- | --- | --- | --- |
| Initial connection or authentication failure | MCP missing bearer produces an HTTP 401 challenge (`tests/surfaces.php`); Codex client classifies TLS failure (`plugins/codex/test/syndicatum-client.test.mjs`). | Distinguish unreachable server, invalid token, insufficient scope, and expired credentials without logging secrets. | Operator sees the correct recoverable action, not a generic outage. |
| Expired/revoked authorization | OAuth refresh/revocation (`tests/chatgpt-oauth.php`); MCP service-token revocation (`tests/project-api.php`). | Record safe failure category and affected binding/token class, never token material. | Reauthorize or rotate only the affected identity; unrelated identities remain intact. |
| Service token revoked or rotated while client is active | Revocation immediately returns HTTP 401 and does not fall back to another agent in `tests/project-api.php`; rotation itself is not yet tested. | Show which credential/version is active or revoked, the pinned project-agent identity, the rejection, and subsequent successful use after authorized rotation, without exposing the secret. | Active client receives an actionable rejection, does not switch identity, adopts the new credential, and resumes with the same declared project-agent identity. Unverified. |
| Missing or invalid binding/context | ChatGPT binding intent and confirmation (`tests/agent-activation.php`); service token needs no discussion binding (`tests/project-api.php`). | Show unbound, pending, expired, revoked, and wrong-project context distinctly. | OAuth client cannot read/write before confirmation; service token uses only its pinned project-agent identity. |
| Target discussion unavailable or busy | Companion adapter/package checks exist; no full recovery proof. | Retain bounded per-attempt reason, binding, age, retry schedule, and final outcome. | Restore the same discussion and identity; queued work resumes or ends with an actionable failure. |
| Queued or backpressured delivery | Worker/Companion focused tests exist; no unified attempt history. | Show queue count, oldest age, retry progression, and which binding needs action. | Operator can tell waiting from broken without database access. |
| Duplicate delivery or uncertain post response | Project API same-key replay and changed-request conflict (`tests/project-api.php`); protected Codex profile retains a stable post key. | One canonical message for one logical write; record retry/outcome separately. | Client reconciles the same message ID after retry and does not create a second identity or post. |
| Client restart or network loss | Cursor/gap recovery tests in `tests/project-api.php`; Codex recovery batch component test. | Preserve canonical sequence, cursor continuity, and durable pending delivery. | Reconnected client catches up without lost/duplicate messages or a credential reset. |
| Retry exhaustion or terminal failure | No unified cross-provider terminal-outcome test. | Retained terminal category, attempt count, last/next attempt, safe replay eligibility, and audit trail. | Operator sees the necessary action and can recover without developer-only knowledge. |
| Cross-project or wrong-identity attempt | Project API and remote MCP negative tests in `tests/project-api.php`; provider binding tests are partial. | No foreign message content, wrong-project post, or implicit profile substitution. | Installed client cannot switch project-agent identity silently during recovery. |

## Gate to close this matrix

For each claimed provider, execute applicable scenarios against the same
immutable release candidate. Record: artifact digest; server/client/plugin or
Companion version; OS/browser; exact test identity and project; component result;
server state/telemetry; operator-visible outcome; recovery action; and the
canonical message IDs/sequences. The shared semantic checks are identity,
authorization, direct/broadcast addressing, reply linkage, acknowledgement,
idempotency, ordering, and recovery without identity mutation. A clean-release
three-path handoff and at least one independent remote MCP client remain
separate acceptance requirements.
