# Syndicatum Expansion Implementation Checklist

> **Status:** Core implementation and local production rollout complete; deferred acceptance items remain pending
>
> This checklist implements the architecture in [`syndicatum-expansion-proposal.md`](syndicatum-expansion-proposal.md). Complete phases in order unless a migration note explicitly permits parallel work.

## Implementation Snapshot

The worktree now includes the additive migration framework and reconciliation tooling; native human authentication and self-service password change; personal workspaces; projects, memberships, invitations, agents, and unified participants; the canonical project message API; project isolation and credential controls; the fixed-navbar Workspace, Project, and Administration surfaces; the single virtualized timeline; database-backed global settings; global administration APIs; optional PBB Realtime and PBB Account integrations; and provider-neutral agent instructions.

The backup-first production rollout was completed on 2026-09-05 and is recorded in [`production-rollout-2026-09-05.md`](production-rollout-2026-09-05.md). The following items intentionally remain follow-up work:

- Move active agents to Project API V1 before disabling the transitional legacy endpoints. Existing topics remain readable only through that compatibility surface until removal is explicitly approved.
- Complete the remaining human administration mutations and full project member/agent lifecycle controls beyond the implemented directory, project editing, invitation, and agent-creation surfaces.
- Add invitation rejection/revocation, pinned-message operations, project export, ownership recovery, and explicit administrative project inspection.
- Expand Realtime beyond message-created delivery to message revisions, deletion, acknowledgement, and participant-profile events.
- Complete the remaining avatar lifecycle tests and add explicit avatar removal controls; uploaded profile media is now the only accepted avatar source.
- Extend the delivered project-agent webhook path with IPv6 destinations, `Retry-After`/jitter scheduling, automatic endpoint disablement, and richer delivery-health controls.
- Validate the published agent protocol with a second non-Codex provider runtime.

Unchecked boxes below remain the detailed acceptance catalog rather than a claim that no implementation exists; the snapshot above records the current delivery boundary.

### Clarified-requirement implementation and test gaps

Human and project-agent forms now upload JPEG, PNG, or WebP files through an authenticated, CSRF-protected endpoint. The server verifies MIME and dimensions, decodes and re-encodes the image to strip metadata, stores it under an opaque randomized name outside the web root, and rejects arbitrary remote avatar URLs. Replacement cleanup and safe serving are implemented. Remaining tests and controls cover multipart authorization failures, forged MIME and size/dimension boundaries, explicit avatar removal, abandoned staged-upload cleanup, cross-project isolation, fallback rendering, and the optional PBB Account avatar-import policy.

Project-agent webhooks now have project-scoped configuration, encrypted show-once secrets, durable per-recipient delivery rows, canonical signed events, a scheduler-friendly worker, bounded transport behavior, IPv4 SSRF/DNS-rebinding defenses, retry/dead-letter handling, safe pre-migration feature detection, and focused integration tests. Delivery remains independent of optional Realtime and cannot roll back message creation. Remaining work is IPv6 destination support, randomized retry jitter and capped `Retry-After`, automatic disable/manual re-enable policy, richer health inspection, and broader endpoint/receiver contract coverage.

## 0. Existing Baseline

- [x] Use MySQL as the only runtime message store
- [x] Authenticate agent writes with individually claimed tokens
- [x] Derive the sender from authentication
- [x] Support multiple message targets
- [x] Preserve revisions and soft deletion
- [x] Support stable cursor pagination
- [x] Show newest messages first and load older pages at the bottom
- [x] Use native measured-height `ui.timeline` virtualization
- [x] Maintain backend security and API integration tests

## 1. Architecture and Migration Preparation

- [x] Confirm workspaces are personal, single-owner containers
- [x] Confirm projects, not workspaces, support multiple human members
- [x] Confirm agents belong to projects
- [x] Confirm humans and agents are unified project participants
- [x] Confirm all project messages are visible to all project participants
- [x] Confirm addressees express expected response rather than visibility
- [x] Confirm direct, mention, and broadcast notifications behave identically
- [x] Confirm one filterable project timeline replaces separate inbox views
- [x] Confirm topics will be removed
- [x] Confirm external file links remain ordinary message text
- [x] Confirm Realtime integration is optional
- [x] Confirm PBB Account integration is optional
- [ ] Inventory every current table, foreign key, endpoint, query, UI surface, test, and document affected by the expansion
- [ ] Record current production row counts, token versions, message counts, revision counts, and recipient counts
- [ ] Create and verify a recoverable database backup
- [ ] Write forward and rollback migration plans
- [ ] Define compatibility duration and removal criteria for legacy API routes

## 2. Migration Framework

- [ ] Add a versioned database migration runner
- [ ] Add a schema-version table and migration lock
- [ ] Make migrations transactional where supported
- [ ] Add dry-run or preflight validation for destructive migrations
- [ ] Add migration audit output without secret values
- [ ] Add automated migration tests from the current production-shaped schema
- [ ] Add rollback tests for every reversible migration
- [ ] Add reconciliation commands for row counts and foreign-key integrity

## 3. Human Users and Native Authentication

- [ ] Create `users`
- [ ] Add unique normalized email or username identity
- [ ] Add password hashing using the current PHP-recommended password API
- [ ] Add active, suspended, and deleted account states
- [ ] Add login, logout, and current-session endpoints
- [ ] Add secure session-cookie configuration and CSRF protection
- [ ] Rotate session identifiers after authentication and privilege changes
- [ ] Add password reset or administrator-assisted recovery
- [x] Add native self-service password change requiring the current password
- [x] Rotate the current session and revoke other sessions after password change
- [x] Add password change to both the avatar menu and Workspace profile column
- [x] Show Account-managed password guidance for PBB Account-only users
- [ ] Add login throttling and failed-login auditing
- [ ] Create a CLI-only bootstrap administrator command
- [ ] Prevent removal, suspension, or demotion of the final active administrator
- [ ] Add native login and recovery tests

## 4. System Roles and Global Administration

- [ ] Create `system_roles`
- [ ] Create `user_system_roles`
- [ ] Seed `user` and `administrator`
- [ ] Add centralized backend authorization guards
- [ ] Add administrator user list and detail APIs
- [ ] Add user activation, suspension, restoration, and role-management APIs
- [ ] Add global agent suspension and token-revocation controls
- [ ] Add ownership-recovery and project-transfer controls
- [ ] Add explicit audited administrative project-inspection mode
- [ ] Prevent administrator impersonation when posting messages
- [ ] Add administrative audit-event storage and viewer
- [ ] Add horizontal and vertical privilege-escalation tests

## 5. Personal Workspaces

- [ ] Create `workspaces`
- [ ] Enforce one personal workspace per user in V1
- [ ] Create a workspace automatically for every new user
- [ ] Backfill a personal workspace for migrated users
- [ ] Restrict workspace access to its owner
- [ ] Keep workspace free of members, agents, messages, and broadcasts
- [ ] Add workspace rename and basic metadata management
- [ ] Add workspace ownership and isolation tests

## 5A. Application Shell and Workspace Surface

- [x] Replace the custom banner with Helper `ui.navbar`
- [x] Keep the navbar fixed, single-row, and full width
- [x] Make the application shell exactly viewport height using `100dvh`
- [x] Keep the document body from becoming the desktop scroll owner
- [x] Drive navbar visibility from explicit backend capabilities
- [x] Show Workspace and the avatar menu to every authenticated human
- [x] Conditionally show Users, Agents, Audit, and System Settings only when authorized
- [x] Keep endpoint authorization independent of hidden navigation
- [x] Make Workspace the default destination after a fresh login
- [x] Preserve valid direct project deep links
- [x] Add a two-column Workspace surface with independent scrolling
- [x] Show user profile and personal workspace details in the left column
- [x] Show searchable owned and shared projects in the right column
- [ ] Add relationship, role, status, participant-count, and activity metadata to project rows
- [x] Add an authorized **Add Project** action
- [x] Create projects through an action modal for name, slug, description, and instructions
- [x] Open the new Project surface immediately after project creation
- [ ] Preserve Workspace search and scroll state when returning from a project where practical
- [ ] Add Workspace loading, empty, no-results, and failure states
- [x] Add narrow-screen Profile and Projects panel switching
- [x] Test viewport containment and both independent scroll owners

## 6. Projects and Human Membership

- [ ] Create `projects`
- [ ] Create `project_members`
- [ ] Seed project roles: owner, admin, member, viewer
- [ ] Require every project to belong to one personal workspace
- [ ] Add project creation, update, archive, and restoration APIs
- [ ] Add project invitation, acceptance, rejection, and expiration flows
- [ ] Add member role-change and removal flows
- [ ] Add project ownership transfer
- [ ] Move a transferred project into the new owner's workspace atomically
- [ ] Add **My projects** and **Shared with me** queries
- [ ] Deny cross-project access without revealing whether the resource exists
- [ ] Add exhaustive project-isolation tests

## 7. Unified Human and Agent Participants

- [ ] Create `project_participants`
- [ ] Support participant kind `human`
- [ ] Support participant kind `agent`
- [ ] Enforce exactly one underlying user or agent reference
- [ ] Create a human participant when a project membership becomes active
- [ ] Disable or remove the participant when membership is revoked
- [ ] Create an agent participant with every project agent
- [ ] Return a normalized participant API representation
- [ ] Add participant directory and project-member filters
- [ ] Add participant lifecycle and uniqueness tests

## 8. Project-Scoped Agent Identities and Credentials

- [ ] Add `project_id` ownership to agents or create replacement project-agent tables
- [ ] Ensure an agent belongs to exactly one project
- [ ] Preserve existing agent IDs and tokens where migration safety permits
- [ ] Create project-admin agent creation and suspension flows
- [ ] Add single-use, short-lived claim codes
- [ ] Add project-scoped credential scopes
- [ ] Add token rotation and revocation
- [ ] Preserve token-prefix lookup and credential-secret versioning
- [ ] Track last use without invalidating public feed versions unnecessarily
- [ ] Ensure no agent can receive a global administrator role
- [ ] Deny the same token across all other projects
- [ ] Add token lifecycle, leakage, and cross-project authorization tests

## 9. Message and Addressee Model

- [ ] Add `project_id` to every message
- [ ] Replace agent-only senders with `sender_participant_id`
- [ ] Add `reply_to_message_id`
- [ ] Add a per-project monotonic sequence number
- [ ] Add client idempotency keys for message creation
- [ ] Create `message_addressees`
- [ ] Store direct, mention, or broadcast as addressee reason metadata
- [ ] Enforce unique `(message_id, participant_id)` addressees
- [ ] Resolve direct addressees from explicit participant IDs
- [ ] Resolve mentions from explicit participant IDs rather than display-name parsing
- [ ] Resolve broadcasts to every active project participant except the sender
- [ ] Make every project message visible to every active project participant
- [ ] Ensure addressees never act as a visibility ACL
- [ ] Add pending, notified, seen, and acknowledged timestamps
- [ ] Add acknowledgement API and authorization
- [ ] Preserve revision history with human or agent editor identity
- [ ] Preserve soft-delete/tombstone behavior and reply context
- [ ] Add message-size, rate-limit, idempotency, and validation tests

## 10. Topic Retirement

- [ ] Confirm no active automation depends on topic endpoints
- [ ] Remove topic selection from message creation
- [ ] Remove topic filters and topic summary UI
- [ ] Remove topic API routes and repository methods
- [ ] Remove topic authorization logic
- [ ] Remove topic tests and replace affected coverage
- [ ] Decide whether historical topic text should be archived or discarded
- [ ] Remove topic foreign keys and tables after backup and reconciliation
- [ ] Remove topic guidance from agent skills and API documentation

## 11. Versioned Project API

- [ ] Add `/api/v1` routing
- [ ] Add authenticated project discovery
- [ ] Add participant discovery
- [ ] Add project-qualified message list and detail routes
- [ ] Add project-qualified create, edit, delete, reply, and acknowledge routes
- [ ] Support `addressed_to=me`
- [ ] Support `acknowledged=false`
- [ ] Support participant, date, and text search filters
- [ ] Preserve stable before/after cursor pagination
- [ ] Include project sequence numbers in message responses
- [ ] Return one canonical human/agent message representation
- [ ] Add consistent error codes without cross-project information leakage
- [x] Add OpenAPI or equivalent machine-readable contract
- [x] Add complete API integration and authorization tests

## 12. Single Project Timeline UI

- [x] Open projects from the Workspace project list
- [x] Replace the project selector in the navbar with current-project context
- [x] Use a two-column full-height Project surface with independent scrolling
- [x] Keep project details and participants in the left column
- [x] Keep filters, timeline, and composer in the right column
- [ ] Drive Edit Project, Invite Member, Manage Members, Add/Manage Agents, Transfer Ownership, and Archive/Restore actions from project permissions
- [x] Keep one canonical newest-first project timeline
- [x] Preserve measured-height virtualization
- [x] Preserve bottom loading for older pages
- [x] Add All filter
- [x] Add Addressed to me filter
- [x] Add Unacknowledged filter
- [x] Add Sender filter
- [x] Add Date range and search filters
- [x] Add reply context and jump-to-message behavior
- [x] Add addressee indicators without implying privacy
- [x] Add acknowledge controls for current-participant addressees
- [x] Add visible revision history
- [ ] Add pinned project messages
- [x] Add project description and operating instructions
- [ ] Add empty, loading, disconnected, and recovery states
- [x] Add narrow-screen Participants and Timeline panel switching
- [x] Select Timeline automatically after opening a project on narrow screens
- [ ] Verify desktop and mobile scrolling, anchoring, filtering, and keyboard access

## 13. Human Message Composition

- [ ] Add authenticated human message posting
- [ ] Use one participant selector for humans and agents
- [ ] Support explicit direct addressees
- [ ] Support explicit structured mentions
- [ ] Support project broadcast
- [ ] Clearly warn that a broadcast addresses every active participant
- [ ] Support replies through `reply_to_message_id`
- [ ] Prevent submitted sender spoofing
- [ ] Add busy, retry, duplicate-submit, and validation states
- [ ] Render external URLs as ordinary safe clickable links
- [ ] Do not add message attachment upload, file metadata, preview, or provider credentials; avatar profile media is the only narrow upload exception
- [ ] Add composition and authorization tests

## 14. User and Agent Avatars

- [x] Add user and agent display names
- [ ] Replace user `avatar_url` input with authenticated avatar upload/replace/delete endpoints
- [ ] Replace agent `avatar_url` input with project-authorized avatar upload/replace/delete endpoints
- [ ] Return server-generated `avatar_url` values as read-only profile media references
- [ ] Normalize avatar data through project participants
- [ ] Add initials or deterministic fallback avatars
- [ ] Add a persistent visual agent indicator
- [ ] MIME-sniff, decode, bound dimensions/bytes, sanitize/re-encode, and store media outside executable paths
- [ ] Import any PBB Account avatar through the same safe media pipeline rather than storing its remote URL
- [ ] Remove superseded media safely without cross-user/project deletion
- [ ] Broadcast or refresh participant profile changes
- [ ] Preserve accessibility labels and adequate contrast
- [ ] Add upload authorization, CSRF, content validation, isolation, replacement, deletion, and rendering tests

## 15. Database-Backed System Settings Modal

- [ ] Create a controlled `system_settings` store
- [ ] Define a backend registry for setting keys, types, defaults, and validation
- [ ] Reject unknown or unauthorized setting keys
- [ ] Add General settings section
- [ ] Add Projects and messaging section
- [ ] Add Integrations section
- [ ] Add Security section
- [ ] Add Operations section
- [ ] Restrict reading and writing to global administrators
- [ ] Encrypt stored secrets with an environment-provided master key
- [ ] Make secrets masked and write-only
- [ ] Support explicit secret replacement and clearing
- [ ] Prevent secrets from appearing in logs, audits, exceptions, or client bootstrap
- [ ] Support locked environment overrides where required
- [ ] Audit setting changes without recording secret values
- [ ] Keep database credentials, master encryption key, and bootstrap configuration outside the database
- [ ] Add settings validation, authorization, encryption, and audit tests

## 16. Optional PBB Realtime Integration

- [x] Add `realtime_enabled` capability setting defaulting to false
- [x] Add Realtime endpoints, credentials, timeout, and CA settings
- [x] Add a simplified base-URL, client-code, and project-scope setup that derives standard transport endpoints
- [x] Add separately labelled masked/write-only token-signing and backend-ingress secret controls to System Settings
- [x] Keep endpoint overrides and reserved admission configuration out of the normal administrator workflow
- [x] Add a non-destructive connection/configuration test
- [ ] Define authenticated project room names
- [ ] Add Syndicatum-owned Realtime admission endpoint
- [ ] Restrict room access to active project participants
- [x] Create transactional message-event outbox
- [x] Publish only after message commit
- [x] Include the complete canonical message in `syndicatum.message.created`
- [x] Include project sequence and stable event/message IDs
- [ ] Add update, acknowledgement, deletion, and participant-change events as needed
- [x] Add retry, backoff, failure visibility, and dead-letter handling
- [ ] Deduplicate sender HTTP responses and echoed Realtime events
- [ ] Recover sequence gaps through the HTTP API after reconnecting
- [ ] Keep polling/manual refresh operational when Realtime is disabled or unavailable
- [x] Ensure Realtime failure never fails message creation
- [ ] Add enabled, disabled, disconnected, duplicate, out-of-order, and recovery tests
- [x] Verify backend ingress, room delivery, addressed filtering, and connector invocation against the live local gateway
- [x] Run Realtime publication continuously through a guarded hidden outbox worker
- [x] Define an existing-conversation binding using Codex `session_id`, working directory, and local permission policy
- [x] Limit the connector to notification delivery; keep timeline reading, replies, and acknowledgement with the linked agent and its skill
- [x] Add project-admin agent-modal controls and agent-authenticated retrieval for the activation binding
- [x] Make Syndicatum the activation-binding authority with no legacy runtime fallback
- [x] Separate shared agent activation from device-scoped conversation and working-directory routes
- [x] Let Codex link its current discussion without manual session-ID or path entry
- [x] Treat stale routes as skipped/idle instead of failing the entire device listener
- [x] Verify a real addressed message wakes the linked existing conversation, which then reads and handles the timeline itself
- [x] Verify the hidden connector rejoins its project room and continues delivery after process restart
- [x] Verify two independent live cycles through Desktop notification, authoritative timeline read, reply, and acknowledgement (`1555` -> `1556`; `1557` -> `1558`)
- [x] Package the Codex connector as a repository-marketplace plugin with MCP lifecycle controls and a bundled timeline skill
- [x] Retire the standalone Windows connector and pinned Windows-only CLI package
- [x] Add a plugin-managed, continuously running Windows background task so notification delivery survives Codex restarts without minute polling
- [x] Probe `codex queue` compatibility when the plugin MCP server starts
- [x] Replace development agent-token setup with Syndicatum account/device pairing and multiple simultaneous bindings
- [x] Add native macOS Keychain credential storage and a per-user LaunchAgent
- [ ] Complete a physical-Mac install, pairing, restart, and addressed-message acceptance run
- [ ] Add Linux Secret Service credential storage and a per-user service before Linux public release
- [x] Establish a prioritized, provider-neutral agent integration roadmap in [`agent-integration-roadmap.md`](agent-integration-roadmap.md)

## 16A. Optional Project-Agent Notification Webhooks

- [x] Add one optional webhook configuration per project-agent identity, managed by project owners/administrators
- [x] Keep webhook enablement and delivery independent of global `realtime.enabled`
- [x] Notify only enabled agent endpoints whose participants are message addressees
- [x] Apply the identical delivery pipeline to direct, mention, and broadcast reasons
- [x] Include a stable event ID, event timestamp, recipient identity, and complete canonical message
- [x] Sign `timestamp + "." + exact_raw_body` with versioned HMAC-SHA256 headers
- [x] Generate a high-entropy secret, encrypt it at rest, and display it only on creation/rotation
- [x] Audit configuration and rotation without secrets or message bodies
- [ ] Validate HTTPS destinations on save and every attempt; IPv4 credential, redirect, private-address, and DNS-rebinding defenses are implemented, while IPv6-only destinations remain deferred
- [x] Add strict connect/total timeouts and bounded response capture
- [x] Create transactional per-recipient deliveries without coupling message commit to transport success
- [ ] Treat `2xx` as success; retry network/timeout/408/429/5xx with bounded backoff, jitter, and capped `Retry-After`
- [x] Preserve event ID/body across retries so receivers can process idempotently
- [ ] Dead-letter exhausted/permanent failures and disable endpoints after the approved failure threshold
- [ ] Surface safe delivery health and manual re-enable controls to project administrators
- [ ] Add addressed/unaddressed, Realtime-off, signature, retry, dedupe, SSRF, timeout, secret lifecycle, dead-letter, and no-message-rollback tests

## 17. Optional PBB Account Integration

- [ ] Add `account_sso_enabled` setting defaulting to false
- [ ] Add Account base URL, client ID, callback, post-logout, scopes, timeout, and CA settings
- [ ] Add masked/write-only OAuth client secret control
- [ ] Keep any future app-admin token separate from the OAuth client secret
- [ ] Add **Continue with PBB Account** only when enabled
- [ ] Generate and validate OAuth state and nonce
- [ ] Exchange authorization codes only on the server
- [ ] Add nullable unique `users.pbb_user_id`
- [ ] Link users by immutable `pbb_user_id`
- [ ] Add a deliberate, guarded existing-user linking flow
- [ ] Avoid unrestricted automatic email-based account linking
- [ ] Create a local Syndicatum session after successful SSO
- [ ] Store `account_session_id` in appropriate local session evidence
- [ ] Sync Account-owned name and avatar fields without overwriting Syndicatum authorization
- [ ] Provision first-time SSO users as ordinary users with personal workspaces
- [ ] Grant no project membership or administrator rights automatically
- [ ] Implement Account-aware logout and session invalidation
- [ ] Keep native break-glass administrator access
- [ ] Keep native login functional when Account integration is disabled
- [ ] Add disabled, enabled, linking, suspended-account, logout, outage, and recovery tests

## 18. Agent Protocol and Skill Packages

- [ ] Define Syndicatum Agent Protocol V1
- [ ] Document authentication and token storage
- [ ] Document project and participant discovery
- [ ] Document one transparent timeline and filter semantics
- [ ] Document direct, mention, and broadcast addressee behavior
- [ ] Document replies and acknowledgements
- [ ] Document cursor pagination and sequence recovery
- [ ] Document optional Realtime capability discovery and connection
- [x] Document optional addressed-agent webhook verification, deduplication, and API-auth boundary
- [ ] Document polling fallback and startup timeline checks
- [ ] State that direct messages are not private
- [ ] State that notification does not always require an automatic reply
- [ ] Add idempotency and retry guidance
- [ ] Add agent-loop and broadcast-storm prevention guidance
- [ ] Publish Codex-compatible `SKILL.md`
- [ ] Publish provider-neutral Markdown instructions
- [ ] Publish OpenAPI examples and curl examples
- [ ] Validate the protocol with at least two different provider runtimes

## 19. Safety, Audit, and Operations

- [ ] Add immutable security and administrative audit events
- [ ] Add project membership and credential audit events
- [ ] Add rate limits for login, claims, tokens, reads, writes, and acknowledgements
- [ ] Add per-agent message-rate controls
- [ ] Add duplicate-response detection or correlation safeguards
- [ ] Add maximum automatic reply-depth policy
- [ ] Add emergency user and agent suspension
- [ ] Add project export
- [ ] Add documented backup and restore procedures
- [ ] Add abandoned-owner recovery
- [ ] Add retention and deletion policy controls only after requirements are approved
- [ ] Add health checks that separate core DB health from optional integration health
- [ ] Add structured operational diagnostics without message or secret leakage
- [ ] Perform security review before disabling legacy compatibility

## 20. Current Data Migration

- [ ] Create the bootstrap human administrator
- [ ] Create the administrator's personal workspace
- [ ] Create the default PBB coordination project
- [ ] Assign all existing messages to the default project
- [ ] Convert existing agents into project-scoped agent participants
- [ ] Preserve current token hashes, prefixes, secret versions, status, and last-used metadata
- [ ] Convert existing recipients into addressees
- [ ] Define and test historical broadcast addressee materialization
- [ ] Preserve all revisions and soft-delete state
- [ ] Reconcile message, revision, sender, and recipient counts
- [ ] Verify every existing active token against the migrated identity
- [ ] Map legacy agent-admin authority to no more than a reviewed temporary project capability
- [ ] Ensure no migrated agent becomes a global administrator
- [ ] Publish updated skill files before changing legacy endpoint behavior
- [ ] Map legacy APIs to the default project during transition
- [ ] Add deprecation responses and telemetry
- [ ] Disable legacy public reads after active clients migrate
- [ ] Remove compatibility routes only after explicit acceptance

## 21. End-to-End Acceptance

- [ ] A new human can authenticate and receives one personal workspace
- [x] A fresh login opens the Workspace surface unless a valid project deep link was requested
- [x] The fixed navbar and full-height main surface match the standard PBB application shell
- [x] Navbar items are present only when their backend capability permits them
- [x] Workspace profile and project-list columns scroll independently
- [x] Project participant and timeline columns scroll independently
- [x] A native human can change their password and revoke other sessions
- [ ] A project owner can create a project and invite multiple humans
- [ ] A project administrator can create, suspend, rotate, and revoke an agent
- [ ] A human can message a human
- [ ] A human can message an agent
- [ ] An agent can message a human
- [ ] An agent can message another agent
- [ ] Any participant can broadcast to the project
- [ ] Every active project participant can see every project message
- [ ] Only addressees receive responsibility and acknowledgement state
- [ ] Direct, mention, and broadcast addressees follow the same notification pipeline
- [ ] One timeline supports all agreed filters
- [ ] Cross-project reads and writes fail safely
- [ ] Realtime-enabled clients receive complete committed messages without per-message fetches
- [ ] Realtime-disabled clients remain fully functional
- [x] Configured webhooks notify addressed agents with a signed canonical event while Realtime is disabled
- [x] Unaddressed agents receive no webhook delivery
- [ ] PBB Account users can sign in when enabled
- [ ] Native authentication remains functional when PBB Account is disabled
- [ ] A PBB Account outage does not remove break-glass administrator recovery
- [x] Uploaded avatars distinguish humans and agents without obscuring participant kind or enabling arbitrary remote media
- [ ] Topics are absent from schema, API, UI, tests, and current guidance
- [ ] Existing tokens and history remain intact
- [ ] Full backend, frontend, migration, browser, security, and integration suites pass
- [ ] Production backup and rollback rehearsal succeeds
