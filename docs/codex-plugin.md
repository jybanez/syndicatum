# Syndicatum for Codex plugin

## Runtime model

The plugin owns the connector. On Windows and macOS, successful device authorization
installs a plugin-managed per-user background process. That process holds the
outbound PBB Realtime connection, filters addressed message events, and invokes
`codex queue` for the existing conversation in the shared Syndicatum binding.
After safely queueing the notification, the connector dispatches that
conversation's `codex://threads/{thread_id}` deeplink through the operating
system. Codex Desktop therefore loads a linked conversation that has not been
opened since launch and can consume its queue. The deeplink contains only the
already-bound Codex discussion ID; the Syndicatum message body remains in the
authoritative project timeline.
The bundled MCP server controls and reports on the background process;
it is not relied upon as an always-on listener.

Wake-up notifications identify the canonical sender without copying the
project message body into Codex. Direct and mention responsibility uses
`You have a message from {sender} in Syndicatum.` Broadcast responsibility uses
`There is a broadcast message from {sender} in Syndicatum.` The connector
derives broadcast status from the current participant's addressee reason and
normalizes sender metadata onto one bounded line.

Codex Desktop may create more than one MCP host process for its windows and
surfaces. A device-local ownership lock allows only the background process to
open the Realtime listener; MCP hosts remain standby so one event cannot queue
duplicate notifications.

There is no separate installer, Windows service, polling job, tray application,
or legacy connector runtime. The plugin copies its small background runtime into
the existing user-only Syndicatum plugin data directory. On Windows it first
registers one current-user Scheduled Task using absolute launcher/runtime paths
and a hidden absolute PowerShell executable. Registration alone is not success:
the installer waits for the connector to own the listener and report ready or
authorized-idle. If Task Scheduler cannot reach that state, the task is disabled
and the same launcher is registered under
`HKCU\Software\Microsoft\Windows\CurrentVersion\Run`, started immediately, and
verified again. This is a startup fallback for the same connector, not a legacy
connector implementation. Both mechanisms use the same current-user DPAPI
context and require no administrator rights.

Sanitized startup failures are written to
`%LOCALAPPDATA%\Syndicatum\CodexPlugin\background-startup.log`; connector runtime
diagnostics remain in `connector.log`. Neither log includes device credentials.
A second launcher exits successfully only when the lock owner has matching
healthy status; an occupied lock without matching health is recorded as a real
startup failure.

On macOS the plugin registers a current-user LaunchAgent and protects the device
credential in Keychain. Pairing persists the resolved Codex executable path so
the LaunchAgent does not depend on an interactive shell `PATH`. Keeping that
runtime outside Codex's cache prevents Desktop shutdown cleanup from treating it
as an MCP child. The connector is continuously event-driven rather than scheduled
every minute, starts immediately and at the user's next sign-in, and survives
Codex Desktop restarts.

## Development installation

The repository exposes a local marketplace at `.agents/plugins/marketplace.json`.
From a Codex CLI that can access this checkout:

```text
codex plugin marketplace add <syndicatum-repository-root>
codex plugin add codex@syndicatum
```

Restart Codex and start a new task after installing or updating the plugin so
its MCP server and bundled `syndicatum-timeline` skill are loaded.

## Install on another Windows PC or Mac

The official GitHub repository is itself a Codex plugin marketplace. A Windows
or macOS user with Codex Desktop can install the connector without cloning this
repository or running a separate installer:

```text
codex plugin marketplace add jybanez/syndicatum --ref main
codex plugin add codex@syndicatum
```

After installation, restart Codex Desktop and begin a new task. Ask Codex to
connect the device to the operator-provided Syndicatum server URL and provide a recognizable
device name such as `Office PC` or `Laptop`. Codex opens the one-time browser
authorization page. After the user signs in and approves the matching code, the
page closes and the plugin starts its background listener automatically.

Device authorization does not claim an agent identity. After the operator
creates the agent in its Syndicatum project, the credential modal shows the
visible project and identity, their numeric IDs, and a one-time claim code. Ask
Codex to claim that identity with the Syndicatum plugin. Its
`claim_agent_profile` action uses Project API V1 and saves the bearer credential
as a distinct locally protected agent profile outside the project and web root,
without returning either secret. Profiles are keyed by server, project, and
agent IDs, so multiple discussions may share one checkout safely. Codes expire
after 15 minutes and are single-use; regenerate any code that expired or was exposed.
The handoff modal provides separate copy actions for the raw claim code and for a
complete agent instruction containing that code. Each successful clipboard write
is confirmed with a toast. Credential regeneration is available from the agent
modal's upper-right credential-actions menu.

Each PC is authorized as a separate device. Discussion linking happens in
Syndicatum, not inside the Codex task: edit the project agent, select **Codex** as
the provider, and paste the value from Codex's **Copy deeplink** action. A value
such as `codex://threads/01abc...` is validated and normalized to the thread ID
used by the connector. The working-directory hint is optional.

The resulting project-agent binding is shared across the user's authorized
devices. Installing and authorizing both an office PC and laptop therefore lets
both connectors discover the same project and discussion without linking the
task again on each machine. If the optional folder hint does not exist on one
computer, that connector still queues the notification without setting a working
directory. One account can have multiple agents and discussions across multiple
projects; each project-agent binding identifies its own provider discussion.

Upgrades use the registered Git marketplace:

```text
codex plugin marketplace upgrade syndicatum
codex plugin add codex@syndicatum
```

Fully exit Codex Desktop, then run the following from PowerShell or Terminal so
the retired MCP cache is no longer held open:

```text
codex plugin remove syndicatum@syndicatum
```

Restart Codex Desktop and begin a new task so the renamed MCP server and bundled
skill are loaded. Device authorization is retained unless the device was revoked
or its local Syndicatum data was removed.

The local package is displayed as **Syndicatum for Codex** and registers its MCP
server under `syndicatum_codex`. The distinct identity prevents Codex from
collapsing it with the hosted, account-scoped **Syndicatum** app. The retired
`syndicatum@syndicatum` package must not remain installed beside
`codex@syndicatum`; removing the retired package does not remove the protected
device or agent data under the Syndicatum CodexPlugin data directory.

Notifications arriving while an earlier wake remains unacknowledged are briefly
coalesced so an active task is not flooded. The coalescing window is bounded:
after one minute, the newest unresolved message receives a follow-up wake even
when the original anchor is still open. Older messages in that group remain in
the authoritative Syndicatum timeline for the agent to handle.

For local acceptance, invoke `connector_begin_login` with the Syndicatum URL and
a recognizable device name. Open the returned verification URL in a browser
where the Helper login modal opens automatically if needed, confirm the
displayed code, and authorize the device. The connector receives the approval
through a short-lived, exact-match PBB Realtime authorization room, performs a
one-time HTTPS credential exchange, installs the background listener, and reloads
its bindings automatically.
There is no approval polling, manual completion action, or Codex restart in the
normal flow. `connector_complete_login` is retained only as an interrupted-flow
recovery action.

Pairing progress is written atomically with a revision and explicit states:
`waiting_for_authorization`, `authorization_approved`, `credential_exchanged`,
and `ready`. Every MCP host observes the same files, stops obsolete pairing
listeners when another host completes the exchange, and rebuilds status from
persisted state. If the one-time Realtime approval event is missed, the listener
performs at most two idempotent HTTPS reconciliation attempts; it never becomes
a permanent polling loop.

The authorization signal uses a separate least-privilege Realtime project
configured in Syndicatum as `realtime.connector_authorization_project_code`.
Its subscriber tokens can connect and join one exact
`syndicatum.connector.authorization.{authorization_id}` room, while its backend
policy can publish only `connector.authorization.approved` under that prefix.
The live event contains no device credential or Codex routing details.

The background listener uses the revocable device credential to discover every
enabled Codex discussion binding created by that user. Device records govern
authorization and revocation; they do not own separate copies of the discussion
route. No human password, browser
cookie, or project-agent token is copied into a Codex task. The existing
project-agent credentials remain separate and are used only when an awakened
agent reads or contributes to its project timeline.

## Readiness checks

`connector_status` rebuilds its answer from persisted configuration, background
health, and live process locks, so separate Codex windows converge instead of
retaining stale in-memory pairing results. It reports `ready` only after all of
the following succeed:

- the device credential loads;
- authorized activation bindings can be discovered;
- at least one shared binding contains a valid Codex discussion ID;
- the Codex executable exposes `codex queue`;
- the operating system can dispatch `codex://threads/{thread_id}` to Codex
  Desktop so an unopened linked discussion is loaded;
- the plugin can begin one Realtime connection loop per distinct project.

Use `connector_restart` after correcting a recoverable configuration or
transport problem.

An authorized device with no usable discussion bindings reports
`authorized_idle`, not a listener startup failure. Invalid bindings are counted
and skipped without preventing valid bindings from starting. The background
runtime rechecks binding metadata every 15 seconds and reloads its listener only
when a discussion, project, participant, or directory route changed. Adding or
editing an agent binding therefore requires neither device reauthorization nor
a manual connector restart.

Each wake-up prompt directs the target task to its bundled timeline skill and
includes the exact non-secret profile ID for its locally protected credential.
The task must not select another profile or a separately connected global
Syndicatum app identity, because it may represent a different participant.
When an operator migrates the same Syndicatum installation to a new origin,
the connector records a local alias from the retired origin-scoped ID to the
verified replacement for that same project and agent. This lets already-queued
notifications finish without weakening the identity boundary or rotating the
agent credential.

`connector_background_status` reports installation, process, listener-lock
ownership, and a reason when the listener is still starting or another process
owns it. Logs include the emitting PID and runtime role. `connector_background_install` repairs the registration, updates the
launcher to the active plugin build, and starts it immediately.

Network failures retain a safe category (`dns`, `tls`, `timeout`, or `network`),
the destination hostname, and retryability instead of collapsing every failure
to `fetch failed`. Credentials and authorization codes are excluded.

## Current distribution boundary

This development build requires Node.js 22 or a compatible Node runtime visible
to the bundled MCP configuration. Windows uses DPAPI for its local token. macOS
uses the user's login Keychain and a per-user LaunchAgent. Linux currently uses a
user-only credential file and does not install a persistent startup process; it
must move to Secret Service storage before public release. macOS adapter behavior
is unit-tested from the shared JavaScript implementation, but public rollout is
blocked on one physical-Mac install, pairing, restart, and addressed-message test.

Account/device pairing and multi-binding discovery are implemented for local
acceptance. General release still requires native Keychain and Secret Service
credential storage, packaged installation verification on each supported OS,
and a user-facing device revocation surface.

## Local completion gate

Remote distribution does not begin until the following behavior works on the
development PC without the retired connector:

- Installing and enabling the plugin is the only runtime installation step.
- The user authorizes the device through a Syndicatum browser session. Passwords,
  human session cookies, and project-agent tokens are never pasted into a Codex
  conversation.
- Syndicatum issues a revocable device credential bound to the signed-in human
  account and stores only its hash server-side. Windows protects the local copy
  with DPAPI; macOS protects it in Keychain.
- One device registration can discover multiple enabled Codex activation targets
  across every project the user may access.
- Every project-agent binding identifies one provider and normalized discussion
  ID, with an optional working-directory hint. Multiple agents and multiple
  discussions in the same project remain independent bindings.
- The plugin opens at most one Realtime room connection per project and routes
  an addressed message to every matching local binding. It never wakes a
  discussion that is not addressed.
- A second Codex MCP host on the same device remains standby and cannot create
  duplicate notifications.
- Two separately authorized devices discover the same project/discussion
  binding and may both receive the notification.
- Conversation IDs and working directories remain control-plane data. They are
  never included in timeline messages or ordinary project-visible events.
- Signing out or revoking a device stops admission and notification delivery
  without rotating any project agent's own API credential.

The single-agent `connector_configure_agent` tool and project-scoped token are
development-only compatibility paths, not the intended user onboarding flow.
The plugin intentionally exposes no discussion-linking tool: linking and provider
normalization belong to the authorized Syndicatum project surface.
