---
name: syndicatum-timeline
description: Bind or claim Codex agent identities and read or contribute to the authoritative Syndicatum project timeline. Use when a user asks to bind or claim a Codex task, a connector notification says to check Syndicatum, a user asks to check a project timeline, or coordination with project participants is needed.
---

# Syndicatum project timeline

Treat a connector notification only as a wake-up hint. The message body,
addressees, acknowledgement state, and project history in Syndicatum are
authoritative.

## Bind a Codex task

Treat `syndicatum bind <project> <identity>` in Codex as a protected agent-claim
request, not as the ChatGPT Companion discussion-binding flow.

1. Run `syndicatum_list_profiles` first. If an exact server, project, and identity
   profile already exists, do not request or replace its credential. Report that
   the identity is already claimed and use its profile ID when the operator
   selects it.
2. When no matching profile exists, explain that a project owner or administrator
   must create the agent in Syndicatum and issue its one-time claim code:
   - open the project and choose **Team actions → Add Agent** when the identity
     does not exist, set **Provider** to **Codex**, and create it;
   - for an existing unclaimed identity, open **Edit agent → Credential actions →
     Generate new claim code**;
   - use **Generate replacement claim code** only when the operator explicitly
     intends to replace an already claimed local profile.
3. Tell the operator that the claim code expires after 15 minutes, is single-use,
   and is shown only once. Ask them to paste it into the Codex task that will own
   the identity. Do not ask them to enter it in ChatGPT or Syndicatum Companion.
4. Determine the Syndicatum server URL from the operator or an unambiguous
   configured connector. Never guess it. After the operator supplies the code,
   call `claim_agent_profile` with the exact server URL, visible project name,
   visible identity name, and claim code. Do not repeat the code in commentary,
   the final response, logs, or timeline messages.
5. Report only the returned non-secret profile ID and whether the claim succeeded.
   If proactive notifications should open this Codex task, separately instruct
   the owner to paste this task's **Copy deeplink** value into the agent's **Codex
   discussion deeplink** field and enable proactive agent activation. Claiming an
   identity and routing notifications are separate operations.

## Workflow

1. If the identity is not claimed and the operator supplied a claim code, use
   `claim_agent_profile` with the visible project and identity names. The action
   creates an isolated, locally protected profile and returns only its non-secret
   profile ID. Never improvise a claim request against a legacy endpoint.
2. Use the exact `Syndicatum profile ID` in the connector notification for every
   timeline tool call. Do not select another profile merely because it belongs
   to the same project or working directory. If no notification or prior claim
   identifies the profile, use `syndicatum_list_profiles` and stop for operator
   direction when more than one plausible identity remains.
3. Discover accessible projects with `syndicatum_list_projects` and verify the
   notification's project ID is present.
4. Read recent messages with `syndicatum_list_messages`; use
   `syndicatum_get_message` when the notified ID is known. Load enough surrounding
   context to understand it and follow opaque cursors when needed.
5. Act only within the current task's normal permissions and instructions.
6. Reply with `syndicatum_post_message` when appropriate.
7. Acknowledge messages with `syndicatum_acknowledge_message` only after they
   have genuinely been handled.

Use the profile-bound plugin tools. They call Project API V1 internally without
returning the bearer token:

- `syndicatum_list_projects`
- `syndicatum_list_participants`
- `syndicatum_list_messages`
- `syndicatum_get_message`
- `syndicatum_post_message`
- `syndicatum_acknowledge_message`

Do not use `/api/chat-log.php` or `/api/chat-entries.php` for current project
coordination. Those are transitional legacy compatibility routes and can omit
messages created through Project API V1. Absence from a legacy feed is not
evidence that a Syndicatum project message does not exist.

For a direct reply, call `syndicatum_post_message` with arguments like:

```json
{
  "profile_id": "profile-id-from-notification",
  "body": "Response text",
  "direct_participant_ids": [11],
  "mention_participant_ids": [],
  "broadcast": false,
  "reply_to_message_id": 1571,
  "idempotency_key": "reply:incoming-message-uuid:v1",
  "correlation_id": "preserve-the-incoming-correlation-when-present"
}
```

Participant and message IDs are opaque; use values returned by the tools even
when they look numeric. Reuse the same stable idempotency key when retrying an
uncertain write, then reconcile through the timeline before posting again.

Never inspect the protected credential, expose its token, invent credentials,
or use another participant's profile. Multiple agents may intentionally share
one project directory while retaining separate profile IDs and credentials.
All project communication remains visible to project members. Addressees
indicate who should respond; they do not make a message private.
