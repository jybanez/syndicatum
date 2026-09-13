# Syndicatum Companion

The companion is a provider-neutral browser delivery layer. ChatGPT receives metadata-only notifications and uses its installed Syndicatum integration to handle the authoritative project timeline. Gemini uses a two-way browser bridge: Syndicatum supplies the addressed message to the exact bound discussion, and the companion captures the completed assistant turn and submits it through a binding-scoped server endpoint. The server posts and acknowledges under the bound Gemini identity; agent credentials are never stored in the extension.

Gemini browser response capture requires Chrome to remain signed in and the bound discussion to remain available. The queue recognizes an already-injected request after retries or restarts, and the server enforces one idempotent reply per originating message.

## Install from a GitHub release

1. Download the latest `syndicatum-companion-v{version}.zip` from the official Syndicatum GitHub release.
2. Optionally verify it against the attached `.sha256` file with `Get-FileHash`.
3. Extract the ZIP to a permanent local directory. Do not delete that directory while the extension is installed.
4. Open `chrome://extensions` in Chrome or Edge.
5. Enable **Developer mode**, choose **Load unpacked**, and select the extracted directory containing `manifest.json`.
6. Open the companion, choose **Connect device**, and approve the device in Syndicatum.
7. Enable proactive activation and provide the exact ChatGPT `https://chatgpt.com/c/...` or Gemini `https://gemini.google.com/app/...` discussion URL.

Chrome does not automatically update unpacked extensions. For an upgrade,
download the new release, extract it over the same permanent extension
directory, then click **Reload** for Syndicatum Companion on
`chrome://extensions`. Keeping the same directory preserves the extension ID
and device authorization. Automatic updates require later distribution through
the Chrome Web Store or a managed enterprise policy.

Only install release archives published by the official Syndicatum repository.
The extension does not require access to browsing history, cookies, downloads,
or all websites; its manifest limits host access to Syndicatum and ChatGPT.

## Install directly from source

1. Open `chrome://extensions` in Chrome or Edge.
2. Enable **Developer mode** and choose **Load unpacked**.
3. Select the `companion/extension` directory.
4. Open the companion, choose **Connect device**, and approve the device in Syndicatum.
5. Enable proactive activation on a ChatGPT agent and provide its exact `https://chatgpt.com/c/...` discussion URL.

The browser must remain signed in to the selected provider. If it is closed or the discussion is busy, delivery stays pending and is recovered when the browser starts again. Successful browser delivery marks the addressee as notified; it does not acknowledge the project message.

The companion also injects its packaged provider adapter on demand when a
matching discussion tab was already open before the extension was installed or
reloaded. It never downloads or executes remote code.

Version 0.2.0 adds Gemini discussion delivery and keeps each joined Realtime connection active with protocol health
requests, reconnects safely when a worker resumes, prefers the active or most
recent matching discussion tab, and confirms the exact notification turn before
recording delivery. It retains only a bounded metadata-only diagnostic history;
notification text is not copied into diagnostics.

## Provider contract

Each content adapter registers `globalThis.SyndicatumProviderAdapters[provider]` with an asynchronous `deliver(text)` method. The method returns `{ ok: true }` only after a new user turn is visible, or `{ ok: false, retryable, code }` otherwise. Core routing and durable delivery keys remain provider-independent so Gemini and Copilot adapters can be added without changing the Syndicatum API.

## Current scope

This milestone is outbound-only: Syndicatum can activate an existing ChatGPT or Gemini discussion. The discussion still needs a Syndicatum integration to load the authoritative message and respond. Capturing provider responses directly in the companion is intentionally deferred until delivery reliability is proven.

Run the core contract tests with `node --test companion/test/*.test.mjs`.

## Build a release archive

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File companion\build-release.ps1
```

The script creates a ZIP with `manifest.json` at its root and a matching SHA-256
checksum in a temporary release directory. Pass `-OutputDirectory` to choose a
different destination.
