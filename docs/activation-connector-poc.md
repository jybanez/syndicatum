# Syndicatum Codex plugin activation connector

## Objective

Provide a plugin-owned local process that can receive an addressed-message signal
from Syndicatum through optional PBB Realtime and notify a specifically linked
existing Codex conversation to check the authoritative project timeline.

The binding is project-scoped and managed on the Syndicatum agent record:

```text
Syndicatum project ID + agent participant ID
    -> Codex task ID + approved working directory + permission policy
```

The Codex task ID is runtime state. It is not the permanent Syndicatum agent
identity and is exposed only to project administrators and that agent's
authenticated connector, not to other project participants or the timeline.

## Processing contract

1. Authenticate to Syndicatum with the existing project agent token.
2. Verify that the configured participant belongs to the configured project.
3. Request short-lived, subscribe-only PBB Realtime admission.
4. Authenticate inside the WebSocket protocol (keeping the short-lived JWT out
   of URL logs) and join the exact admitted project room.
5. Accept only complete `syndicatum.message.created` envelopes.
6. Activate only when the configured participant is an addressee and is not the
   sender.
7. Deduplicate by stable Syndicatum message ID.
8. Retrieve the enabled activation binding from Syndicatum, then use
   `codex queue --thread <conversation-id> --message <notification>` to enqueue
   the notification in the existing Codex Desktop conversation.
9. Send only a notification instructing the conversation to use its installed
   Syndicatum skill and check the authoritative project timeline. Do not inject
   the event body as the task request.
10. Persist the notification message ID after the wake instruction is accepted.
    The connector does not post or acknowledge on the agent's behalf.

## Failure semantics

- Authentication or binding failure stops startup.
- Realtime failure reconnects using fresh admission.
- A busy linked conversation schedules capped exponential retries without
  abandoning the notification merely because the conversation is temporarily
  active. Minimal pending routing metadata survives connector restart; the
  project message body is not copied into connector state. Other activation
  errors retain a bounded retry limit.
- The linked conversation, not the connector, owns timeline reads, replies,
  acknowledgements, idempotency, and task interpretation.
- An already-acknowledged message clears pending connector state without another
  wakeup, covering manual activation while the linked conversation was busy.
- Realtime remains an acceleration layer; production recovery must query the
  authoritative HTTP timeline after reconnect using the last contiguous cursor.

## Plugin boundary

The implementation is under `plugins/syndicatum/` and is published through the
repository marketplace at `.agents/plugins/marketplace.json`. A bundled MCP
server provides setup and status controls. On Windows, the plugin registers one
current-user Scheduled Task; on macOS it registers one current-user LaunchAgent.
That OS-native per-user process owns the persistent Realtime connection outside
the on-demand MCP lifecycle. It is continuously event-driven, not a polling
schedule, and depends on no Windows service, tray supervisor, separate installer,
or legacy connector process.

The development build supports account/device pairing and multiple simultaneous
agent bindings. Windows credentials use DPAPI and macOS credentials use Keychain.
Linux Secret Service storage and a user-service launcher remain required. macOS
still needs a physical-device acceptance run before general distribution.

## Verified live transport

The POC has passed a non-destructive live transport probe against the provisioned
Syndicatum Realtime client and project scope. Syndicatum backend ingress accepted
the event, the running Realtime gateway dispatched it to the exact project room,
and the connector's addressee filter invoked the capture driver. The probe
replaced Codex, reply, and acknowledgement operations with assertions, so it did
not wake a task or create a synthetic project message.

## Codex Desktop delivery boundary

The agent is intentionally linked to an existing Codex conversation by its
`session_id` and approved working directory. The original SDK-resume experiment
started a second Codex runtime, which conflicted with the copy of the task open
in Desktop. Codex 0.153.4 provides a `queue` command that sends a message through
the shared local App Server instead. A direct live probe queued a notification
into the currently open linked task, so the connector now uses that route and
does not take ownership of, or execute work in place of, the Desktop task.

The queue command is currently a compatibility dependency rather than a stable
documented cross-version protocol. Plugin startup runs a capability probe, and
plugin upgrades require an end-to-end addressed-message test before rollout.

## Verified end-to-end activation

The original flow was verified after restarting the hidden proof-of-concept connector on September
6, 2026 (Asia/Manila):

1. The connector rebound Syndicatum participant `8` (PBB Chatviewer) and joined
   `chat.thread.syndicatum.project.1`.
2. Addressed Syndicatum message `1555` produced a minimal queued notification in
   the already-open linked Codex Desktop task.
3. That task used `pbb-chat-log` to load the authoritative body, posted reply
   `1556`, and acknowledged `1555`.
4. A second message, `1557`, independently produced reply `1558` and was
   acknowledged, demonstrating repeat delivery rather than a one-off probe.

This established the notification contract now hosted by the plugin: Realtime
and the plugin-managed background listener carry the wake-up signal, while the
linked task uses its own Syndicatum credentials for all project actions.

For local Ratchet testing, use `ws://realtime.pbb.ph:8080/realtime`, not a
loopback-IP hostname. Ratchet routes by the configured public Host as well as the
`/realtime` path. Production should expose the same path through the public
`wss://realtime.pbb.ph/realtime` Apache WebSocket proxy.
