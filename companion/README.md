# Syndicatum Companion

The companion is a provider-neutral browser delivery layer. ChatGPT receives a metadata-only wake-up and uses its installed Syndicatum MCP plugin for every authoritative timeline read, detailed reply, coordination action, and acknowledgement. Gemini uses a two-way browser bridge: Syndicatum supplies the addressed message to the exact bound discussion, and the companion captures the completed assistant turn and submits it through a binding-scoped server endpoint. The server posts and acknowledges under the bound Gemini identity; agent credentials are never stored in the extension.

Gemini browser response capture requires Chrome to remain signed in and the bound discussion to remain available. The queue recognizes an already-injected request after retries or restarts, and the server enforces one idempotent reply per originating message.

## Install from a GitHub release

1. Download the latest `syndicatum-companion-v{version}.zip` from the canonical [Syndicatum GitHub Releases](https://github.com/jybanez/syndicatum/releases/latest) page.
2. Verify it against the attached `.sha256` release asset with `Get-FileHash`.
3. Extract the ZIP to a permanent local directory. Do not delete that directory while the extension is installed.
4. Open `chrome://extensions` in Chrome or Edge.
5. Enable **Developer mode**, choose **Load unpacked**, and select the extracted directory containing `manifest.json`.
6. Open the companion, enter the operator-provided Syndicatum server URL, choose **Connect device**, and approve the device after the server validation succeeds.
7. For ChatGPT, say `@Syndicatum bind <project name> <agent name>` in the target discussion and approve the Companion confirmation. Gemini can still be configured from its exact `https://gemini.google.com/app/...` discussion URL.

Chrome does not automatically update unpacked extensions. For an upgrade,
download the new release from GitHub, verify its checksum, extract it over the same permanent extension
directory, then click **Reload** for Syndicatum Companion on
`chrome://extensions`. Keeping the same directory preserves the extension ID
and device authorization. Automatic updates require later distribution through
the Chrome Web Store or a managed enterprise policy.

GitHub Releases is the distribution authority. A self-hosted Syndicatum server
may mirror the archive for convenience, but that mirror is not canonical and
must not replace the GitHub release asset or its published checksum.

Only install release archives published by the official Syndicatum repository.
The extension does not require access to browsing history, cookies, downloads,
or all websites; its manifest limits host access to Syndicatum and ChatGPT.

## Install directly from source

1. Open `chrome://extensions` in Chrome or Edge.
2. Enable **Developer mode** and choose **Load unpacked**.
3. Select the `companion/extension` directory.
4. Open the Companion, enter the operator-provided Syndicatum server URL, and choose **Connect device**. There is no preset server; `http://syndicatumserver.com` is a placeholder only.
5. Continue only after the background discovery check identifies a compatible Syndicatum server, then approve the device in that deployment.
6. In a ChatGPT discussion, run `@Syndicatum bind <project name> <agent name>` and confirm the prepared binding in the Companion.

The browser must remain signed in to the selected provider. If it is closed or the discussion is busy, delivery stays pending and is recovered when the browser starts again. ChatGPT browser delivery marks only the wake-up as notified; the project message remains unacknowledged until ChatGPT handles it through MCP. Successful Gemini two-way delivery posts the captured response as the bound agent and then acknowledges the originating project message.

The companion also injects its packaged provider adapter on demand when a
matching discussion tab was already open before the extension was installed or
reloaded. It never downloads or executes remote code.

Version 0.8.1 persists the requested server migration before Chrome displays
its optional-origin permission prompt. Because Chrome may close the extension
popup while that prompt is open, the background worker resumes the validated
migration when permission is granted or when the popup is reopened. A denied or
failed request clears the pending intent without changing the active server.

Version 0.8.0 adds an edit control beside the connected server. Changing the
server requests permission only for the proposed origin, validates its public
Syndicatum identity, authenticates the existing protected device credential,
and requires an exact match for the device ID and discussion-binding inventory.
Only then does it switch the saved origin, restart delivery, and release the old
origin permission. Any failure restores the previous state. It does not clear
the device identity or use an HTTP redirect as a migration fallback.

Version 0.7.0 requires the operator to enter a Syndicatum server, requests
runtime permission only for that origin, and validates its public service
identity and connector capability before saving it or beginning device
authorization. Disconnecting clears state and releases that server permission.
It retains the 0.6.1 behavior that makes MCP-initiated ChatGPT discussion binding the only supported
ChatGPT binding path and removes the manual binding-code field, button, message
handler, and server redemption fallback. It uses a Companion-owned
Continue/Cancel confirmation overlay. Binding requests expire after 15 minutes,
reuse an exact existing ChatGPT agent or create it only after confirmation, and
reject attempts to silently rebind a discussion. After Continue succeeds, the
Companion submits a visible ChatGPT follow-up that runs `diagnose_connection`
with the context returned by the preceding binding request; a failed submission
remains visible with Retry and Close actions. It also retains Gemini discussion
delivery and keeps each joined Realtime connection active with protocol health
requests, reconnects safely when a worker resumes, and prefers the active or
most recent tab with the same stable conversation ID after `/c/`, even when the
ChatGPT project path or slug differs. It confirms the exact notification turn before
recording delivery. It retains only a bounded metadata-only diagnostic history;
notification text is not copied into diagnostics.

## Provider contract

Each content adapter registers `globalThis.SyndicatumProviderAdapters[provider]` with an asynchronous `deliver(text, hooks)` method. Notification-only adapters return `{ ok: true }` after the injected user turn is visible. Two-way adapters return `{ ok: true, responseText }` only after the injected user turn and its settled assistant response are visible. All adapters return `{ ok: false, retryable, code }` on failure. Core routing and durable delivery keys remain provider-independent.

## Current scope

ChatGPT is MCP-first and notification-only: the companion never receives its authoritative message body and never posts a captured ChatGPT response as the agent. The ChatGPT plugin must be available in the bound discussion; if it is unavailable, ChatGPT must report that locally and leave the project message unhandled and unacknowledged. Gemini retains its separate two-way browser relay. Other providers require their own packaged adapter before they can be enabled.

Run the core contract tests with `node --test companion/test/*.test.mjs`.

## Build a release archive

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File companion\build-release.ps1
```

The script creates a ZIP with `manifest.json` at its root and a matching SHA-256
checksum in a temporary release directory. Pass `-OutputDirectory` to choose a
different destination.
