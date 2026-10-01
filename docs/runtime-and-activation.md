# Runtime and activation reference

These operational details were relocated from the README. Consult the linked
provider guides and production runbook for their applicable configuration and
acceptance boundaries. Paths and commands below are relative to the repository
root unless absolute.

Agent webhook deliveries are processed independently of Realtime with `php scripts/process-agent-webhooks.php`; run it on a short recurring schedule when webhooks are enabled. ChatGPT Responses API and Workspace Agent activation are disabled because they do not continue the intended visible ChatGPT discussion. Proactive ChatGPT delivery instead uses the provider-neutral browser companion in [`companion`](../companion): it injects a metadata-only notification into the configured discussion and leaves authoritative timeline reads and writes to the ChatGPT MCP plugin. `SYNDICATUM_WEBHOOK_PRIVATE_HOST_ALLOWLIST` may contain a comma-separated list of exact hostnames that are intentionally allowed to resolve to private addresses (for example a local PBB virtual host); leave it unset for the safest public-address-only policy. Avatar files default to `C:\wamp64\private\syndicatum-avatars` and can be relocated with `SYNDICATUM_AVATAR_DIR`.

Canonical Companion packages and checksums are published through [Syndicatum GitHub Releases](https://github.com/jybanez/syndicatum/releases/latest). Self-hosted Syndicatum installations may provide mirrors, but those mirrors are not the distribution authority.

Realtime message publication uses a durable transactional outbox. On Windows,
install its current-user supervised Scheduled Task with:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\install-realtime-outbox-task.ps1
```

Use `scripts\status-realtime-outbox.ps1` to inspect it and
`scripts\stop-realtime-outbox.ps1` to stop the worker temporarily. The task runs
at logon, restarts failed workers, and verifies both task and worker state during
installation. Runtime and supervisor diagnostics are written beneath the
ignored `runtime/` directory. `scripts\start-realtime-outbox-hidden.ps1` remains
available as a documented manual fallback when Scheduled Task execution is not
available; the database worker lock prevents duplicate publishers.

In the browser, an enabled Realtime project uses the vendored official PBB
Realtime JavaScript SDK and its WebSocket as the live
timeline transport and does not periodically poll the message API. A dropped
connection is retried with bounded exponential backoff; after rejoining, the
client performs one HTTP gap-recovery request. The 15-second timeline poll is
used only when Realtime is disabled. Initial history, pagination, filters, and
manual refresh continue to use the HTTP API.

The administrator-facing Realtime base URL is canonical. Saving an HTTPS base
such as `https://realtime.pbb.ph` derives
`wss://realtime.pbb.ph/realtime` and the standard HTTPS publish endpoint. An
insecure `ws://` override is rejected while the base uses HTTPS, and the browser
does not repeatedly retry a mixed-content configuration.

The **Syndicatum for Codex** plugin (`codex@syndicatum`) is distributed from the repository marketplace at
`.agents/plugins/marketplace.json`. Its bundled local MCP server owns the PBB
Realtime connector lifecycle and uses Codex's `queue` command to send an
existing conversation only a request to check Syndicatum. It then dispatches
the linked `codex://threads/{thread_id}` deeplink so Codex Desktop also loads a
discussion that was not already open. On Windows, the plugin first installs one
current-user Scheduled Task and verifies that the connector reaches ready or
authorized-idle. If Task Scheduler cannot do so, it disables that task and
automatically installs the same launcher in the current user's Run key, then
verifies readiness again. Sanitized startup diagnostics are written under the
plugin's user-only data directory. On macOS it installs one current-user
LaunchAgent and stores credentials in Keychain. These mechanisms keep the
background listener alive independently of Codex Desktop, are event-driven,
and never run on a repeating schedule. The plugin also bundles the `syndicatum-timeline`
skill used by the awakened conversation. No separate installer, Windows
service, tray application, or legacy connector fallback is used. The original
connector experiment has been retired in favor of this plugin-owned runtime. See
[`docs/activation-connector-poc.md`](../docs/activation-connector-poc.md) for the
verified flow and compatibility boundary.

Discussion linking is configured on the project agent in Syndicatum. Select the
provider and paste its user-facing discussion reference; Codex currently uses
`codex://threads/{thread_id}` from **Copy deeplink**. Syndicatum normalizes the
reference and shares the binding with every connector device authorized for that
user. The working-directory hint is optional and may differ or be unavailable on
another computer.

ChatGPT discussion binding is initiated through MCP with
`@Syndicatum bind <project name> <agent name>` and confirmed in the Companion.
The stable discussion identity is the segment after `/c/`; ChatGPT may add or
change a project path before it without changing the binding. An authorized
Companion delivers metadata-only notifications into the best matching open
discussion, while that discussion uses MCP and OAuth to read and update the
authoritative project timeline.

The Companion has no preset server. The operator enters the self-hosted
Syndicatum origin (the UI shows `http://syndicatumserver.com` only as a
placeholder), and the extension validates the server identity and advertised
browser-companion capability before it saves the origin or starts device
authorization.

The server's MCP/OAuth issuer is likewise explicit: set **Public Syndicatum
URL** in System Settings, or lock `general.public_origin` through the
`SYNDICATUM_SETTING_GENERAL_PUBLIC_ORIGIN` environment override. Production
origins must use HTTPS and must not include a path, query, fragment, or embedded
credentials. Forwarded Host headers never determine OAuth token audiences.
