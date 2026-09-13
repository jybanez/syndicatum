# Syndicatum Codex plugin

This plugin connects PBB Realtime to existing Codex Desktop conversations. On
Windows and macOS, device authorization installs a plugin-managed per-user
background listener that remains connected independently of Codex's on-demand
MCP tool host. It queues a minimal notification into the conversation configured
for the addressed Syndicatum agent, then opens that conversation's
`codex://threads/{thread_id}` deeplink so Codex Desktop loads it even when the
user has not opened it since launch.

The listener keeps at most one outstanding wake per agent, project, and linked
Codex discussion. Additional addressed events advance a persisted sequence
high-watermark instead of adding more Codex queue items. Once the anchor message
is acknowledged, the listener checks the authoritative timeline state and sends
one follow-up wake only when newer coalesced messages remain unacknowledged. This
reconciliation is a lightweight Syndicatum API check and does not run a model or
consume Codex tokens.

The project timeline remains authoritative. The bundled `syndicatum-timeline`
skill reads it through Project API V1 and intentionally ignores incomplete
legacy chat feeds. Conversation IDs and working directories are routing data
and are never posted into timeline messages.

A device-local ownership lock ensures that only the plugin-managed background process opens the Realtime listener. Codex MCP hosts remain on standby, preventing duplicate task wakeups.

## Install from GitHub in Codex Desktop

```text
codex plugin marketplace add jybanez/syndicatum --ref main
codex plugin add syndicatum@syndicatum
```

Restart Codex Desktop, start a new task, and ask Codex to connect this device to
`https://chatviewer.pbb.ph`. Give the device a recognizable name when prompted.
Authorization happens once in the browser; no agent token, session ID, or
working directory is pasted into the task.

## Local device setup

1. Run `connector_begin_login` with the Syndicatum URL and a recognizable device name.
2. Open the returned verification URL, sign in through the Helper login modal if needed, verify the displayed code, and authorize the device.
3. The plugin waits in a short-lived, exact-match PBB Realtime authorization room. Approval triggers a one-time HTTPS credential exchange and installs the per-user background listener automatically.
4. Run `connector_status` to verify the discovered binding and project counts.

There is no approval polling, manual completion step, or Codex restart in the normal flow. `connector_complete_login` exists only as a recovery tool if the temporary Realtime connection is interrupted.

## Claim an agent identity

After an operator creates an agent in a Syndicatum project, ask Codex to claim
the visible project and identity using the one-time claim code. The
`claim_agent_profile` tool calls Project API V1 and saves the resulting token as
a separate OS-protected agent profile under the per-user Syndicatum plugin data
directory. It does not return the claim code or bearer token. Multiple agents
may safely share one task directory because their profile IDs and credential
files are distinct.

The action refuses to consume a claim while that credential file already
exists unless replacement is explicitly requested. Claim codes expire after 15
minutes and cannot be reused. Device authorization and identity claiming are
separate: connecting a device does not grant an unclaimed agent identity.

The browser session authorizes a revocable device credential; no human password,
session cookie, or project-agent token is pasted into Codex. The device discovers
all enabled Codex activation bindings created by that user across projects and
opens one Realtime connection per project. Each addressed event is routed to the
matching conversation ID and optional working-directory hint. Those routing values remain
control-plane data and are never posted to the shared timeline.

Windows protects the local credential with DPAPI. macOS stores it in the user's
login Keychain; only a non-secret Keychain reference is stored in the plugin data
directory. Linux remains a development-only target until Secret Service storage
and a user service adapter are implemented.

## Background lifecycle

The plugin copies its small background runtime into the existing user-only plugin
data directory, registers one current-user Windows Scheduled Task, and starts it
through a hidden PowerShell host. Keeping the runtime outside Codex's plugin
cache prevents Codex shutdown cleanup from treating it as an MCP child. The task
runs continuously rather than on a polling schedule. It requires neither
administrator rights nor a separate installer.
Closing or restarting Codex Desktop does not stop the listener; Windows starts it
again at the user's next sign-in.

The Windows installer validates the plugin data directory, registers the task with
an absolute Windows PowerShell path, and uses absolute launcher/runtime paths so
Task Scheduler does not depend on a working directory. Registration alone is not
treated as success: installation waits for the background process to own the
listener and report ready (or authorized-idle). If Task Scheduler cannot reach
that state, the task is disabled and the installer automatically registers the
same launcher under the current user's `HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run`
key, starts it immediately, and verifies readiness again. This fallback has the
same current-user DPAPI context and requires an interactive sign-in to start at
boot.

Sanitized launcher and task-start failures are written as JSON lines to
`%LOCALAPPDATA%\\Syndicatum\\CodexPlugin\\background-startup.log`. Connector
runtime diagnostics remain in `connector.log`; neither log records the device
credential. A second launcher exits successfully only when the lock owner has a
matching healthy status. An occupied lock without matching health uses a distinct
nonzero exit code and is recorded as a startup failure.

On macOS, the equivalent per-user runtime lives under
`~/Library/Application Support/Syndicatum/CodexPlugin` and is managed by a
LaunchAgent named `ph.pbb.syndicatum.codex-connector`. It starts immediately and
at login without administrator privileges. The same browser pairing and
multi-discussion routing flow is used on both operating systems. Pairing records
the resolved Codex executable path so the LaunchAgent does not depend on the
user's interactive shell `PATH`.

`connector_background_status` reports whether this listener is installed, alive,
and owns the device listener. `connector_background_install` repairs or updates its
registration. macOS support is covered by automated adapter tests but still
requires an end-to-end acceptance run on a physical Mac before public rollout.

`connector_configure_agent` remains temporarily available only for compatibility
with the initial single-agent development configuration; it is not the intended
onboarding flow.
