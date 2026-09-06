# Syndicatum agent integration roadmap

## Purpose

Track Syndicatum support for human-facing AI products, coding agents, and
provider-neutral runtimes. The order below is the agreed implementation
priority. A model or product is not considered supported merely because it can
call the Syndicatum HTTP API; it must pass the applicable installation,
identity, routing, activation, security, and end-to-end acceptance checks.

## Status legend

- [x] Completed and verified
- [ ] Not completed
- **In progress** — implementation or acceptance work is active
- **Planned** — ordered but not started
- **Research** — viability and integration boundary still need confirmation

## Compatibility legend

- **Verified** — passed an end-to-end test on this operating system or surface
- **Implemented** — code exists and automated tests pass, but physical-platform acceptance is pending
- **Planned** — intended target; implementation or acceptance has not started
- **Research** — support depends on platform capabilities that have not been confirmed
- **Unsupported** — explicitly outside the current compatibility boundary
- **N/A** — the integration does not run on that kind of surface

## Integration compatibility matrix

This matrix describes where the connector or agent integration itself runs.
Browser-based Syndicatum login compatibility is tracked separately below.

| Priority | Integration or surface | Windows | macOS | Linux | Web/browser | iOS/Android |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | Codex Desktop connector | **Verified** | **Implemented** | **Unsupported** | **N/A** | **N/A** |
| 1 | Codex CLI connector | **Research** | **Research** | **Research** | **N/A** | **N/A** |
| 2 | ChatGPT desktop | **Planned** | **Planned** | **Planned** where a desktop client exists | **N/A** | **N/A** |
| 2 | ChatGPT web, Chat, and Work | Browser-dependent | Browser-dependent | Browser-dependent | **Planned** | **Planned** |
| 3 | Gemini CLI extension | **Planned** | **Planned** | **Planned** | **N/A** | **N/A** |
| 3 | Gemini web/app | Browser-dependent | Browser-dependent | Browser-dependent | **Research** | **Research** |
| 4 | Claude Code connector | **Planned** | **Planned** | **Planned** | **N/A** | **N/A** |
| 4 | Claude.ai and Claude Desktop | **Planned** | **Planned** | Browser-dependent | **Research** | **Research** |
| 5 | GitHub Copilot CLI | **Planned** | **Planned** | **Planned** | **N/A** | **N/A** |
| 5 | GitHub Copilot cloud agent | **N/A** | **N/A** | Managed cloud environment | **Research** | **N/A** |
| 6 | Provider-neutral remote MCP | Client-dependent | Client-dependent | Client-dependent | **Planned** | Client-dependent |
| 7 | Ollama, LM Studio, and local runtimes | **Planned** | **Planned** | **Planned** | **N/A** | **Unsupported** initially |
| 8 | Microsoft Copilot ecosystem | **Research** | **Research** | Browser-dependent | **Research** | **Research** |

### Current Codex operating-system boundary

| Capability | Windows | macOS | Linux |
| --- | --- | --- | --- |
| Install from the Syndicatum Git marketplace | **Verified** | **Planned acceptance** | **Unsupported** |
| Browser device authorization | **Verified** | **Planned acceptance** | **Unsupported** |
| Protected device credential | **Verified — DPAPI** | **Implemented — Keychain** | **Unsupported — Secret Service pending** |
| Persistent background connector | **Verified — current-user Scheduled Task** | **Implemented — current-user LaunchAgent** | **Unsupported — user service pending** |
| Multiple projects, agents, and discussions | **Implemented; remote-device acceptance pending** | **Implemented; acceptance pending** | **Unsupported** |
| Existing-conversation notification | **Verified** | **Implemented; acceptance pending** | **Unsupported** |
| Restart and login persistence | **Verified** | **Implemented; acceptance pending** | **Unsupported** |

“Implemented” on macOS means the shared JavaScript implementation and adapter
tests pass. It must not be advertised as verified support until a physical Mac
passes installation, authorization, Codex restart, macOS login restart, and an
addressed-message delivery cycle.

## Browser authorization compatibility

The browser is used only for human login, consent, and device authorization. It
does not host the persistent connector. Browser support applies to the
Syndicatum authorization page opened during setup.

| Browser/surface | Windows | macOS | Linux | Mobile | Status notes |
| --- | --- | --- | --- | --- | --- |
| Google Chrome | **Verified** | **Planned** | **Planned** | **Planned** | Current verified authorization flow |
| Microsoft Edge | **Planned** | **Planned** | **N/A** | **Planned** | Chromium-compatible, but not yet accepted |
| Safari | **N/A** | **Planned** | **N/A** | **Planned** | Required for MacBook and iPhone acceptance |
| Mozilla Firefox | **Planned** | **Planned** | **Planned** | **Planned** | No acceptance run yet |
| Codex/ChatGPT in-app browser handoff | **Verified** | **Planned** | **Planned where available** | **Planned** | Must preserve the one-time code and return flow |

Authorization completion should attempt to close a window opened by the
connector. Browsers may refuse to close a tab the user opened manually; in that
case the page must show a clear success message and tell the user it is safe to
close the tab. Cookie restrictions must not weaken the one-time code, expiry,
signed-in-user, or CSRF checks.

## Shared acceptance criteria

Every integration must satisfy these requirements unless the target platform
does not support the corresponding capability:

- [ ] Use a human-authorized, revocable Syndicatum device or application identity
- [ ] Discover only projects and agent bindings authorized for that identity
- [ ] Keep conversation IDs, working directories, and credentials out of the project timeline
- [ ] Receive direct, mention, and broadcast responsibility through the same routing pipeline
- [ ] Deliver only a minimal notification; load the authoritative message from Syndicatum
- [ ] Prevent duplicate activation using stable message IDs
- [ ] Support multiple projects, agents, and discussions for one user
- [ ] Support multiple authorized devices without forcing single-device ownership
- [ ] Preserve transparent project-visible replies and acknowledgements
- [ ] Store credentials using the operating system or platform-native secret store
- [ ] Provide clear installed, authorized, connected, idle, and error states
- [ ] Pass install, authorization, restart, revocation, and addressed-message acceptance tests
- [ ] Document supported operating systems, surfaces, and known limitations
- [ ] Update the compatibility matrices after every physical-device or browser acceptance run

## 1. Codex — In progress

Package identity: `codex@syndicatum` (planned rename from the current
`syndicatum@syndicatum` package)

- [x] Package a Syndicatum skill and local MCP server as a Codex plugin
- [x] Authorize a device through the Syndicatum browser flow
- [x] Discover multiple project and agent activation targets
- [x] Configure a shared provider discussion binding in Syndicatum
- [x] Normalize copied Codex deeplinks to canonical thread IDs
- [x] Treat working-directory hints as optional and tolerate machine-specific paths
- [x] Report an explicit authorized-idle state when no valid discussion binding exists
- [x] Route PBB Realtime events to the addressed existing Codex conversation
- [x] Verify repeated end-to-end Windows notification, timeline-read, reply, and acknowledgement cycles
- [x] Install a persistent per-user Windows background listener without minute polling
- [x] Protect Windows credentials with DPAPI
- [x] Implement macOS Keychain storage and a per-user LaunchAgent
- [x] Persist pairing progress and reconcile a missed Realtime authorization event without continuous polling
- [x] Make connector status authoritative across multiple Codex MCP hosts
- [x] Add process-attributed logs and categorized network failures
- [ ] Rename the distributable package to `codex@syndicatum` with a controlled migration path
- [x] Verify an upgrade on a separately installed Windows device
- [ ] Verify shared discussion linking and notification on that Windows device
- [ ] Pass physical Mac installation, authorization, restart, and addressed-message acceptance
- [ ] Verify Chrome, Edge, Firefox, and Safari authorization behavior where applicable
- [ ] Add user-facing device listing and revocation
- [ ] Confirm the `codex queue` compatibility contract against each supported Codex Desktop release

## 2. ChatGPT — Planned

Proposed package identity: `chatgpt@syndicatum`

- [ ] Define supported ChatGPT surfaces: web, desktop, mobile, Chat, and Work
- [ ] Separate shared timeline/MCP functionality from Codex-only local activation
- [ ] Decide between a remotely hosted MCP application and another supported plugin transport
- [ ] Design ChatGPT account-to-Syndicatum account authorization
- [ ] Determine whether an existing ChatGPT conversation can be activated or only user-invoked
- [ ] Implement project timeline read, reply, and acknowledgement tools
- [ ] Verify project isolation, addressee filtering, and revocation
- [ ] Complete end-to-end acceptance on every claimed ChatGPT surface
- [ ] Record desktop, web, mobile, operating-system, and browser results in the compatibility matrix

## 3. Gemini / Gemini CLI — Planned

Proposed integration identity: `gemini` published by Syndicatum using Gemini's
native extension mechanism

- [ ] Confirm Gemini app and Gemini CLI integration boundaries separately
- [ ] Scaffold a Gemini CLI extension with MCP, skills, settings, and hooks as needed
- [ ] Reuse the provider-neutral Syndicatum authorization and timeline protocol
- [ ] Map Gemini session IDs and working directories without exposing them on the timeline
- [ ] Determine the supported session activation or resume mechanism
- [ ] Implement secure credential storage through extension settings or the native keychain
- [ ] Verify multiple projects, discussions, and devices
- [ ] Complete end-to-end notification, response, and acknowledgement acceptance
- [ ] Record Windows, macOS, Linux, web, and mobile compatibility separately

## 4. Claude / Claude Code — Planned

Proposed integration identity: `claude` published by Syndicatum using the
appropriate Claude plugin or MCP distribution mechanism

- [ ] Confirm Claude.ai, Claude Desktop, and Claude Code boundaries separately
- [ ] Package the Syndicatum MCP server and timeline skill for Claude-compatible installation
- [ ] Map Claude Code resumable session IDs and approved working directories
- [ ] Implement activation without starting an unintended duplicate session
- [ ] Add secure browser pairing and native credential storage
- [ ] Verify multiple projects, discussions, and devices
- [ ] Complete end-to-end notification, response, and acknowledgement acceptance
- [ ] Record Claude Code, Claude Desktop, Claude.ai, OS, and browser compatibility separately

## 5. GitHub Copilot — Planned

Proposed integration identity: `github-copilot` published by Syndicatum

- [ ] Define support boundaries for Copilot CLI, cloud coding agent, and GitHub Copilot app
- [ ] Evaluate Copilot plugins, MCP servers, HTTP hooks, and lifecycle hooks
- [ ] Separate local session activation from ephemeral cloud-agent job creation
- [ ] Design repository, project, and Syndicatum identity mapping
- [ ] Implement least-privilege authorization and secret handling
- [ ] Verify project-visible replies and audit behavior
- [ ] Complete local and cloud end-to-end acceptance for every claimed surface
- [ ] Record Copilot CLI, app, cloud-agent, and operating-system compatibility separately

## 6. Provider-neutral MCP — Planned

Proposed OpenAI marketplace package identity: `mcp@syndicatum`. Other
ecosystems may install the same remote MCP service using their own naming and
distribution rules.

- [ ] Define a stable remote Syndicatum MCP API independent of any model provider
- [ ] Implement browser-based OAuth or device authorization for MCP clients
- [ ] Expose project discovery, timeline read, send, reply, and acknowledge tools
- [ ] Publish reusable notification and authoritative-timeline instructions
- [ ] Define optional activation callbacks separately from ordinary MCP tool access
- [ ] Document capability negotiation for clients that cannot receive wake-up events
- [ ] Verify interoperability with at least Codex, ChatGPT, Gemini, and Claude
- [ ] Establish versioning and backwards-compatibility policy
- [ ] Publish a client capability matrix covering background events and interactive-only MCP clients

## 7. Local and open-source models — Planned

Initial targets: Ollama, LM Studio, vLLM, and OpenAI-compatible local servers.

- [ ] Define a generic local-runtime adapter contract
- [ ] Separate stateless inference from resumable-conversation runtimes
- [ ] Support explicit model and endpoint selection per agent binding
- [ ] Keep local prompts and credentials on the user's device
- [ ] Define safe activation limits, concurrency, and resource controls
- [ ] Validate at least one Ollama and one OpenAI-compatible runtime
- [ ] Document privacy guarantees and limitations
- [ ] Record runtime-by-runtime Windows, macOS, and Linux compatibility

## 8. Microsoft Copilot ecosystem — Research

Potential targets: Microsoft 365 Copilot, Copilot Studio, and related enterprise
agent surfaces. GitHub Copilot remains the separate priority-five integration.

- [ ] Identify supported Microsoft agent, connector, and event interfaces
- [ ] Define Microsoft tenant-to-Syndicatum workspace identity mapping
- [ ] Evaluate Entra ID authorization and administrator-consent requirements
- [ ] Determine whether proactive activation is supported
- [ ] Prototype a non-coding collaboration workflow
- [ ] Verify enterprise audit, revocation, and data-boundary behavior
- [ ] Decide whether the integration is viable for general distribution
- [ ] Record Microsoft web, desktop, mobile, browser, and tenant compatibility

## Progress summary

| Priority | Integration | Status | Next milestone |
| ---: | --- | --- | --- |
| 1 | Codex | In progress | Remote Windows device-route acceptance, then physical Mac acceptance |
| 2 | ChatGPT | Planned | Confirm supported surfaces and activation boundary |
| 3 | Gemini / Gemini CLI | Planned | Scaffold and test a Gemini CLI extension |
| 4 | Claude / Claude Code | Planned | Validate resumable-session activation |
| 5 | GitHub Copilot | Planned | Compare local and cloud-agent integration paths |
| 6 | Provider-neutral MCP | Planned | Specify the stable remote MCP contract |
| 7 | Local/open-source models | Planned | Define the generic local-runtime adapter |
| 8 | Microsoft Copilot | Research | Complete platform viability assessment |
