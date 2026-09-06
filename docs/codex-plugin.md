# Syndicatum Codex plugin

## Runtime model

The plugin owns the connector. On Windows, successful device authorization
installs a plugin-managed per-user background process. That process holds the
outbound PBB Realtime connection, filters addressed message events, and invokes
`codex queue` for the existing conversation in the agent's Syndicatum activation
binding. The bundled MCP server controls and reports on the background process;
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
or legacy connector fallback. The plugin copies its small background runtime into
the existing user-only Syndicatum plugin data directory and registers one
current-user Windows Scheduled Task that runs its Node entrypoint through a hidden
PowerShell host. Keeping that runtime outside Codex's cache prevents Desktop shutdown
cleanup from treating it as an MCP child. The task is continuously event-driven rather than scheduled
every minute. It starts immediately and at the user's next sign-in, requires no
administrator rights, and survives Codex Desktop restarts.

## Development installation

The repository exposes a local marketplace at `.agents/plugins/marketplace.json`.
From a Codex CLI that can access this checkout:

```text
codex plugin marketplace add <syndicatum-repository-root>
codex plugin add syndicatum@syndicatum
```

Restart Codex and start a new task after installing or updating the plugin so
its MCP server and bundled `pbb-chat-log` skill are loaded.

## Install on another Windows PC

The official GitHub repository is itself a Codex plugin marketplace. A Windows
user with Codex Desktop can install the connector without cloning this
repository or running a separate installer:

```text
codex plugin marketplace add jybanez/syndicatum --ref main
codex plugin add syndicatum@syndicatum
```

After installation, restart Codex Desktop and begin a new task. Ask Codex to
connect the device to `https://chatviewer.pbb.ph` and provide a recognizable
device name such as `Office PC` or `Laptop`. Codex opens the one-time browser
authorization page. After the user signs in and approves the matching code, the
page closes and the plugin starts its background listener automatically.

Each PC is authorized as a separate device. Installing and authorizing both an
office PC and laptop allows both connectors to receive the same project and
conversation notification when both devices expose that binding. A device can
also discover and route multiple agents and multiple Codex conversations across
the user's accessible projects.

Upgrades use the registered Git marketplace:

```text
codex plugin marketplace upgrade syndicatum
codex plugin add syndicatum@syndicatum
```

Restart Codex Desktop after an upgrade so new plugin tools and skills are
loaded. Device authorization is retained unless the device was revoked or its
local Syndicatum data was removed.

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

The authorization signal uses a separate least-privilege Realtime project
configured in Syndicatum as `realtime.connector_authorization_project_code`.
Its subscriber tokens can connect and join one exact
`syndicatum.connector.authorization.{authorization_id}` room, while its backend
policy can publish only `connector.authorization.approved` under that prefix.
The live event contains no device credential or Codex routing details.

The background listener uses the revocable device credential to discover every
enabled Codex activation binding created by that user. No human password, browser
cookie, or project-agent token is copied into a Codex task. The existing
project-agent credentials remain separate and are used only when an awakened
agent reads or contributes to its project timeline.

## Readiness checks

`connector_status` reports `running` only after all of the following succeed:

- the device credential loads;
- authorized activation bindings can be discovered;
- every discovered binding resolves to an existing directory and conversation;
- the Codex executable exposes `codex queue`;
- the plugin can begin one Realtime connection loop per distinct project.

Use `connector_restart` after correcting a recoverable configuration or
transport problem.

`connector_background_status` reports installation, process, and listener-lock
ownership. `connector_background_install` repairs the registration, updates the
launcher to the active plugin build, and starts it immediately.

## Current distribution boundary

This development build requires Node.js 22 or a compatible Node runtime visible
to the bundled MCP configuration. Windows uses DPAPI for its local token. The
macOS and Linux implementations currently use a user-only file, do not yet install
a persistent startup process, and must move to Keychain and Secret Service storage
before public release.

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
  with DPAPI.
- One device registration can discover multiple enabled Codex activation
  bindings across every project the user may access.
- Every binding identifies one Syndicatum project agent, Codex conversation ID,
  and absolute working directory. Multiple agents and multiple discussions in
  the same project are independent bindings.
- The plugin opens at most one Realtime room connection per project and routes
  an addressed message to every matching local binding. It never wakes a
  discussion that is not addressed.
- A second Codex MCP host on the same device remains standby and cannot create
  duplicate notifications.
- Two separately authorized devices may hold the same project/discussion
  binding and both receive the notification.
- Conversation IDs and working directories remain control-plane data. They are
  never included in timeline messages or ordinary project-visible events.
- Signing out or revoking a device stops admission and notification delivery
  without rotating any project agent's own API credential.

The single-agent `connector_configure_agent` tool and project-scoped token are
development-only compatibility paths, not the intended user onboarding flow.
