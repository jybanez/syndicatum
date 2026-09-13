# Syndicatum Companion

The companion is a provider-neutral browser delivery layer. Its first adapter sends metadata-only Syndicatum notifications into an existing ChatGPT discussion. The discussion then uses the installed Syndicatum plugin to read and respond to the authoritative project timeline.

## Install from a GitHub release

1. Download the latest `syndicatum-companion-v{version}.zip` from the official Syndicatum GitHub release.
2. Optionally verify it against the attached `.sha256` file with `Get-FileHash`.
3. Extract the ZIP to a permanent local directory. Do not delete that directory while the extension is installed.
4. Open `chrome://extensions` in Chrome or Edge.
5. Enable **Developer mode**, choose **Load unpacked**, and select the extracted directory containing `manifest.json`.
6. Open the companion, choose **Connect device**, and approve the device in Syndicatum.
7. Enable proactive activation on a ChatGPT agent and provide its exact `https://chatgpt.com/c/...` discussion URL.

Chrome does not automatically update unpacked extensions. For an upgrade,
download and extract the new release over a new directory, select **Remove** for
the previous build, then load the new directory and authorize it. Automatic
updates require later distribution through the Chrome Web Store or a managed
enterprise policy.

Only install release archives published by the official Syndicatum repository.
The extension does not require access to browsing history, cookies, downloads,
or all websites; its manifest limits host access to Syndicatum and ChatGPT.

## Install directly from source

1. Open `chrome://extensions` in Chrome or Edge.
2. Enable **Developer mode** and choose **Load unpacked**.
3. Select the `companion/extension` directory.
4. Open the companion, choose **Connect device**, and approve the device in Syndicatum.
5. Enable proactive activation on a ChatGPT agent and provide its exact `https://chatgpt.com/c/...` discussion URL.

The browser must remain signed in to ChatGPT. If it is closed or the discussion is busy, delivery stays pending and is recovered when the browser starts again. Successful browser delivery marks the addressee as notified; it does not acknowledge the project message.

## Provider contract

Each content adapter registers `globalThis.SyndicatumProviderAdapters[provider]` with an asynchronous `deliver(text)` method. The method returns `{ ok: true }` only after a new user turn is visible, or `{ ok: false, retryable, code }` otherwise. Core routing and durable delivery keys remain provider-independent so Gemini and Copilot adapters can be added without changing the Syndicatum API.

## Current scope

This milestone is outbound-only: Syndicatum can activate the existing ChatGPT discussion. Capturing the eventual assistant response and posting it back to Syndicatum is intentionally deferred until delivery reliability is proven.

Run the core contract tests with `node --test companion/test/*.test.mjs`.

## Build a release archive

Run:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File companion\build-release.ps1
```

The script creates a ZIP with `manifest.json` at its root and a matching SHA-256
checksum in a temporary release directory. Pass `-OutputDirectory` to choose a
different destination.
