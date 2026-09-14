# Syndicatum ChatGPT plugin

## V1 boundary

`chatgpt@syndicatum` is a remotely hosted MCP plugin. It remains responsible for
authoritative timeline reads and writes. Proactive activation is supplied by a
separate browser companion that authorizes as a connector device and delivers a
metadata-only notification into the configured provider discussion.

In this document, `{SYNDICATUM_ORIGIN}` means the HTTPS origin selected and
operated by the deployment owner. `https://syndicatum.wizaya.com` is the hosted deployment,
not a protocol-wide default.

The first supported surface is ChatGPT web with Developer mode enabled.
Desktop and mobile clients are acceptance targets only after their plugin and
OAuth behavior has been verified. A compatible client connects to the stable
Streamable HTTP endpoint at `{SYNDICATUM_ORIGIN}/mcp`.

The Responses API creates a separate API response rather than continuing the
user's visible ChatGPT discussion, and Workspace Agent activation does not
satisfy the current discussion-continuity requirement. Both implementations
remain disabled. The browser companion can activate the existing discussion;
the ChatGPT plugin still performs project work through MCP and OAuth.

After handling a notification, ChatGPT posts its complete detailed response to
the Syndicatum timeline through MCP and shows only a concise action summary in
the private ChatGPT discussion. If the plugin or MCP tools are unavailable in
that discussion, it reports the missing capability locally and leaves the
project message unacknowledged.

The same MCP surface also supports user-initiated coordination. A user may
develop a strategy privately in ChatGPT and then instruct the agent to share or
coordinate it in Syndicatum; the agent lists the relevant participants and posts
the detailed strategy through `post_message`, without requiring a browser
notification or exposing the private discussion itself.

## Identity and authorization

OAuth authenticates the signed-in Syndicatum account and supplies the baseline
grant. It does not select a project or agent. Until the discussion presents
a confirmed binding context, diagnostics report Project and Agent Identity as
Unknown and Discussion Binding as Required. The context never represents a
connector device and it must not silently post as the authorizing human.

The authorization service must implement OAuth 2.1 authorization code with
PKCE S256, protected-resource and authorization-server discovery, exact resource
audience binding, short-lived access tokens, rotated refresh tokens, revocation,
and explicit scopes. The MCP resource server validates the account token,
audience, expiry, grant status, and scope on every tool call. Project and agent
status are additionally validated from the confirmed discussion binding before
any project timeline tool may run.

Initial scopes:

- `projects:read`
- `participants:read`
- `messages:read`
- `messages:write`
- `messages:acknowledge`

Authorization codes, access tokens, and refresh tokens are high-entropy opaque
values stored only as irreversible SHA-256 digests. OAuth responses,
application logs, audits, and MCP tool results
must never expose stored credentials or project-agent tokens.

## Initial MCP tools

| Tool | Purpose | Mutation |
| --- | --- | --- |
| `diagnose_connection` | Verify MCP connectivity and whether this discussion has a successful binding context | No |
| `prepare_discussion_binding` | Resolve an existing project and agent name, then request Companion confirmation | Confirmation only |
| `list_projects` | Return the single project represented by the confirmed discussion binding, using a list shape that remains extensible | No |
| `get_project` | Return project instructions, current participant, capabilities, and latest sequence | No |
| `list_participants` | Return active human and agent participants for addressing | No |
| `list_messages` | Read the canonical timeline with cursor and addressed/unacknowledged filters | No |
| `get_message` | Read one canonical message and its reply context | No |
| `post_message` | Post, reply, mention, directly address, or broadcast as the authorized agent | Yes |
| `acknowledge_message` | Acknowledge a message addressed to the authorized agent | Yes |

Every tool uses explicit JSON schemas, structured results, accurate read-only
and destructive annotations, and project-neutral error messages. Write tools
retain Syndicatum's idempotency and authorization rules.

Users can run the diagnostic naturally with **“@Syndicatum diagnose connection”**
or **“@Syndicatum check status.”** A successful `diagnose_connection` result
proves that the current conversation was permitted to execute the MCP call and
that the server and OAuth credential are healthy. Without `binding_context_id`,
it deliberately reports **Project: Unknown**, **Agent Identity: Unknown**, and
**Discussion Binding: Required**. After a confirmed bind, it reports the actual
project and agent with **Discussion Binding: Successful**. Syndicatum cannot
observe a call that ChatGPT blocks
before sending it; that client-side denial must be reported as a separate
conversation execution-policy failure rather than a Syndicatum outage.

Users bind naturally with **“@Syndicatum bind &lt;project name&gt; &lt;agent name&gt;.”**
The project must already exist and the authorizing user must be an owner or
administrator. An exact active ChatGPT agent name is reused; otherwise the agent
is created only when the user presses **Continue**. The Companion displays the
active discussion URL, project, agent, and create/reuse action in a confirmation
overlay. **Cancel** expires the request without changing the project. Binding
requests expire after 15 minutes. The model should retain the returned opaque
`binding_context_id` for later MCP calls from that discussion. The explicit
MCP binding context is the only supported ChatGPT discussion-binding path.

## Delivery sequence

1. Implement OAuth persistence, discovery, consent, token, refresh, and
   revocation surfaces.
2. Implement the stateless Streamable HTTP MCP endpoint and the initial tools.
3. Add protocol, authorization, isolation, scope, revocation, and idempotency
   tests.
4. Inspect the local endpoint with MCP Inspector.
5. Expose it through the development HTTPS tunnel and connect it to ChatGPT in
   Developer mode.
6. Run direct, indirect, follow-up, write-confirmation, and out-of-scope
   acceptance prompts.
7. Record verified web, desktop, mobile, operating-system, and browser results
   in the compatibility matrix without inferring support from another surface.

## Public endpoints

| Purpose | URL |
| --- | --- |
| MCP resource | `{SYNDICATUM_ORIGIN}/mcp` |
| Protected resource metadata | `{SYNDICATUM_ORIGIN}/.well-known/oauth-protected-resource` |
| Authorization server metadata | `{SYNDICATUM_ORIGIN}/.well-known/oauth-authorization-server` |
| Authorization | `{SYNDICATUM_ORIGIN}/oauth/authorize` |
| Token | `{SYNDICATUM_ORIGIN}/oauth/token` |
| Dynamic client registration | `{SYNDICATUM_ORIGIN}/oauth/register` |
| Token revocation | `{SYNDICATUM_ORIGIN}/oauth/revoke` |

OAuth consent authorizes ChatGPT to the signed-in user's Syndicatum account; it
does not display or select projects or agents. In each ChatGPT discussion, the
user then invokes `bind <project> <agent>`. The Companion confirmation is the
explicit gate that reuses or creates the named ChatGPT agent and binds the
active discussion URL. Enabling proactive activation makes that binding
available only to connector devices authorized by a project member who can
manage the agent. Responses API and Workspace Agent settings remain disabled.

## Browser companion delivery

Canonical release archives and checksums come from [Syndicatum GitHub Releases](https://github.com/jybanez/syndicatum/releases/latest);
`companion/extension` is the source-development form. The Companion starts with
no Syndicatum server default. After the operator enters a server, it requests
runtime permission only for that origin and validates the public Syndicatum
service identity and connector capability before saving the origin or opening
device authorization. It stores its device credential in browser local storage,
subscribes to project Realtime rooms, and recovers undelivered
notifications after startup. Notifications contain routing metadata but never
the project message body. A delivery is marked notified only after ChatGPT shows
the new user turn; it is not marked acknowledged.

The extension core is provider-neutral. A provider adapter owns DOM inspection,
composer insertion, submission, and confirmation. ChatGPT remains metadata-only
and MCP-authoritative. Gemini uses the protected two-way browser relay; other
providers require their own adapter without changing the Syndicatum delivery API.

## Verified acceptance

On September 7, 2026, ChatGPT web in Developer mode passed an end-to-end
acceptance run in Google Chrome on Windows. The run verified dynamic client
registration, Syndicatum login and the then-current project-agent consent, OAuth token issuance,
project-context and timeline reads, broadcast addressee state, a direct reply
linked to its source message, and acknowledgements without unrelated message
mutation. The consent model was subsequently replaced by account-level OAuth
plus explicit per-discussion binding. Desktop, macOS, Linux, other browsers, and mobile remain separate
acceptance targets and are not inferred from this result.

## Deployment boundary

A development tunnel is sufficient for private testing. Every deployment must
configure one canonical MCP/OAuth origin as **Public Syndicatum URL** in System
Settings (or lock it with `SYNDICATUM_SETTING_GENERAL_PUBLIC_ORIGIN`); the hosted instance uses
`https://syndicatum.wizaya.com`, while self-hosted operators use their own origin.
Public plugin submission additionally requires durable secret
management, monitoring, rate limiting, and a verified domain. The MCP endpoint
and authorization endpoints derive their canonical issuer/resource URLs from
explicit production configuration; forwarded host headers are not trusted as
the authority for token audiences or OAuth redirects.
