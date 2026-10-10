# Integration distribution production gate

Status: active production-readiness contract. Last assessed 2026-10-09.

Owner direction recorded 2026-10-04: productionize the Codex channel first,
with Windows 10 22H2 and Windows 11 as the initial target operating systems.
The planned support policy is the current stable release plus the immediately
previous stable release; the first stable release has no stable predecessor.
Neither target is a production support claim until its installed acceptance
passes. OpenAI and Companion store publication remain later phases.

This document separates source capability, pilot distribution, and production
distribution for Syndicatum's AI integrations. A source test, unpacked browser
extension, local marketplace installation, or successful operator preflight is
not a production publication claim. The machine-readable companion policy is
[`release/integration-distribution-policy-v1.json`](../release/integration-distribution-policy-v1.json).

## Current decision

No integration channel is production-distributable yet. The hosted service
passes the repository's publication preflight, but the external publication and
installed-client acceptance gates below remain open.

| Integration | Current channel | Classification | Production blocker |
| --- | --- | --- | --- |
| ChatGPT hosted MCP | Developer/custom MCP connection | Development | No approved public OpenAI plugin listing; current public-review artifact, domain challenge, scans, reviewer materials, and directory identity are not retained. |
| Codex connector | Git-backed local marketplace; `codex-v0.2.7` source candidate | Pilot | Immutable releases through `0.2.6` are published but unpromoted. Windows 10 and Windows 11 accepted exact `0.2.6` installed/fresh-task behavior with recovered routes and fail-closed health, while active routed delivery remains open. A later new-agent attempt showed that obsolete checkout-local credential state could still block a modern claim before the request reached Syndicatum. The current candidate removes that obsolete migration dependency entirely; immutable publication, exact installed claim acceptance, and a valid routed-delivery binding remain open. |
| Companion for Chrome | GitHub ZIP or source loaded unpacked | Pilot | Developer mode is required and unpacked extensions do not auto-update. No Chrome Web Store identifier or reviewed listing exists. |
| Companion for Edge | GitHub ZIP or source loaded unpacked | Pilot | Developer mode is required. No Edge Add-ons identifier, Partner Center certification, or store-managed update acceptance exists. |
| Gemini through Companion | Same unpacked Companion package | Pilot | It inherits the Companion store-distribution blockers. Exact 0.10.24 ChatGPT delivery is accepted; the latest retained Gemini-specific installed correlation evidence remains 0.10.18. |
| Mobile application | None | Gated | It remains excluded until the owner explicitly approves mobile implementation. |

The Codex package and the hosted OpenAI plugin are distinct distribution
artifacts even though both expose Syndicatum workflows. The Codex package owns a
local connector and background process. Public OpenAI submission requires a
stable public HTTPS MCP endpoint. Do not silently replace one model with the
other or call a local marketplace listing a public-directory release.

## Machine-readable promotion evidence

The distribution policy records every channel gate as a stable ID, requirement,
status, and evidence list. A gate may be `open`, `blocked`, or `passed`; a
`passed` gate must retain at least one non-secret evidence reference for the
exact artifact. A channel may be classified as `production` only when it is
production eligible, has a published identifier, and every required gate is
passed with retained evidence. Repository tests enforce this fail-closed
promotion rule.

Current open or blocked gates intentionally carry no placeholder evidence. Add
only durable paths, run URLs, provider identifiers, or sanitized records that
were actually verified. Never convert a source test, draft dashboard, local
install, or inferred compatibility result into publication evidence.

## Verified repository and service evidence

The following evidence was current on 2026-10-10:

- application release candidate: `v1.0.0-rc.3`;
- portable OpenAI public-review candidate: `0.1.0`;
- Codex source candidate: `0.2.7` (`codex-v0.2.7`). Releases through `0.2.6`
  are published but unpromoted. Windows 10 and Windows 11 acceptance of `0.2.6`
  verified exact marketplace and installed bytes, the expected catalog,
  project-scoped identity and reads, recovered routes, connector ownership,
  advancing heartbeat timestamps, and installed-code reload and fail-closed
  health behavior. Active routed delivery remains open, and a later new-agent
  attempt exposed the obsolete checkout-local credential migration dependency
  removed by `0.2.7`. See the
  [0.2.6 installed/fresh-task acceptance record](evidence/codex-0.2.6-windows-installed-acceptance-2026-10-10.md),
  the
  [0.2.5 sanitized acceptance record](evidence/codex-0.2.5-windows-installed-acceptance-2026-10-10.md),
  the
  [0.2.4 sanitized acceptance record](evidence/codex-0.2.4-windows-installed-acceptance-2026-10-10.md),
  the
  [0.2.3 sanitized acceptance record](evidence/codex-0.2.3-windows-installed-acceptance-2026-10-10.md)
  and the earlier
  [0.2.2 record](evidence/codex-0.2.2-windows11-installed-acceptance-2026-10-09.md);
  none qualifies as a supported predecessor;
- Companion source manifest: `0.10.24`;
- Companion 0.10.24 installed acceptance passed on Chrome/Windows 11 for the
  unpacked pilot channel, including artifact/backup integrity, reload with
  identity and binding preservation, zero queued/review items, removal of the
  stale delivery error without replay, exact receipt confirmation, normal reply,
  and automatic handling-lease release. See the
  [sanitized acceptance record](evidence/companion-0.10.24-installed-acceptance-2026-10-09.md);
- live `scripts/verify-plugin-publication.ps1` passed against
  `https://syndicatum.wizaya.com`, including service health, support/privacy/
  terms pages, OAuth metadata and PKCE, protected-resource audience, MCP
  initialization and tool discovery, unauthenticated Bearer challenge, and
  production security headers;
- the Codex manifest has all four HTTPS public listing URLs and listing text
  within the public submission limits enforced by the source contract test;
- the Companion has deterministic ZIP/checksum generation and a verified
  in-place backup/rollback updater for unpacked installations.

These observations are prerequisites only. They do not prove OpenAI directory
approval, browser-store approval, automatic updates, or an installed release
on a second device.

The portable OpenAI candidate in [`plugins/openai-public`](../plugins/openai-public/README.md)
contains the production MCP URL, listing/legal metadata, approved icon, and
exactly five positive and three negative review cases. Its deterministic builder
is `tools/release/build_openai_plugin.py`. The demo recording, dedicated reviewer
account, portal-generated draft identity/domain challenge, scans, and approval
remain intentionally external to source control.

## Provider publication requirements

### OpenAI public plugin directory

ChatGPT and Codex share the public Plugins Directory. A local or workspace
marketplace does not publish to that directory. For the hosted MCP integration,
the production submission must retain:

1. the exact semantic-versioned ZIP and checksum;
2. verified developer or business identity and required policy attestations;
3. a stable public HTTPS MCP endpoint using streamable HTTP;
4. the completed `/.well-known/openai-apps-challenge` domain verification;
5. a successful current tool scan, accurate safety annotations, and passing
   skill scans;
6. website, support, privacy, and terms URLs;
7. exactly five positive and three negative test cases, run against a dedicated
   reviewer account with sample data;
8. an accessible demo recording and release notes;
9. the review decision, directory identifier, publication time, and rollback
   or disable procedure.

The current source package must not be submitted as though its local command
MCP were the hosted public endpoint. Create the public-review artifact only
after the portal-generated server identity/domain challenge is available and
keep reviewer credentials outside the package.

Official references, checked 2026-10-09:

- [OpenAI plugin submission](https://developers.openai.com/plugins/deploy/submission/)
- [OpenAI plugin guidelines](https://developers.openai.com/plugins/plugin-guidelines/)
- [OpenAI MCP deployment requirements](https://developers.openai.com/plugins/build/mcp-server/)
- [OpenAI package and marketplace behavior](https://developers.openai.com/plugins/build/plugins/)
- [OpenAI submission validation errors](https://developers.openai.com/plugins/deploy/submission-errors/)

### Codex repository marketplace

The Git marketplace is a legitimate private or repository distribution path,
but `--ref main` is a development/pilot channel because its content changes.
Production installation must use a reviewed immutable annotated tag or commit
and record the resolved commit. The stable channel is not open until all of the
following are retained for that exact ref:

- source commit, annotated tag, package version, checksum or equivalent Git
  object identity, and release notes;
- clean install on every supported OS and a second device;
- upgrade from the previous supported version without changing protected agent
  identity or device authorization;
- downgrade/rollback and recovery after a failed update;
- device revocation, credential replacement, restart, and project isolation;
- declared server compatibility range and support window.

Until a stable ref is designated, documentation may show `--ref main` only as
the pilot install path and must say so next to the command.

The repository now includes `tools/release/build_codex_plugin.py` and a
tag-triggered Windows release workflow. The builder emits a deterministic ZIP,
SHA-256 checksum, and per-file manifest bound to the full source commit and the
exact `codex-v<manifest-version>` ref. These artifacts establish provenance;
they do not pass the gate until the tag and GitHub release exist and the tagged
marketplace is exercised on Windows.

### Chrome Web Store and Microsoft Edge Add-ons

The production Companion must be store-installed or deployed through a managed
enterprise policy. Loading an unpacked ZIP is retained only for development and
pilot diagnosis.

Before Chrome submission, retain the developer account/listing identity,
single-purpose statement, permission justifications, accurate data-use and
privacy declarations, support path, package checksum, review result, and store
identifier. Each update must increment the manifest version, pass review, and
be tested as the exact published store version. Use a separate clearly marked
testing listing if a parallel beta is needed.

Before Edge submission, retain the Partner Center identity, ZIP, manifest and
listing metadata, availability, privacy/purpose/permission declarations,
certification notes, review result, and Add-ons identifier. Each package update
must increment the manifest version and complete certification.

Official references, checked 2026-10-09:

- [Chrome Web Store policies](https://developer.chrome.com/docs/webstore/program-policies/policies)
- [Chrome distribution and testing channels](https://developer.chrome.com/docs/webstore/cws-dashboard-distribution)
- [Chrome updates and rollback](https://developer.chrome.com/docs/webstore/update)
- [Microsoft Edge Add-ons publication](https://learn.microsoft.com/en-us/microsoft-edge/extensions/publish/publish-extension)
- [Microsoft Edge extension updates](https://learn.microsoft.com/en-us/microsoft-edge/extensions/update/update-extension)

## Compatibility and support matrix

The source versions below are test anchors, not production ranges.

| Server/application | Integration package | Provider/client | Current evidence | Production support claim |
| --- | --- | --- | --- | --- |
| `v1.0.0-rc.3` | Codex `0.2.7` source candidate | Codex Desktop/CLI on Windows 10 22H2 and Windows 11 | Source tests and deterministic tagged-release contract; `0.2.6` passed exact installed/fresh-task acceptance on both targets with explicit S5 startup/baseline qualifications. The current candidate removes obsolete checkout-local credential migration from protected-profile claims. | None until the exact tagged release passes installed claim acceptance and routed delivery succeeds through a valid binding. |
| `v1.0.0-rc.3` | Companion `0.10.24` | Chrome + ChatGPT web on Windows 11 | Source tests plus exact installed 0.10.24 unpacked-pilot acceptance: verified artifact and rollback backup, in-place update/reload, identity/auth/binding preservation, healthy Realtime, zero queue/review, stale-error retirement without replay, exact receipt, one normal reply, and automatic lease release | None until the exact reviewed Chrome Web Store build passes publication and store-managed lifecycle acceptance. |
| `v1.0.0-rc.3` | Companion `0.10.24` | Chrome + Gemini web | Source compatibility plus installed 0.10.18 exact-turn correlation acceptance | None until reviewed store build passes. |
| `v1.0.0-rc.3` | Companion `0.10.24` | Microsoft Edge | Source-compatible Chromium package only | None until Edge Add-ons build passes. |

Before the first production release, replace the null production ranges and
empty OS list in the machine-readable policy with reviewed values, name the
support owner, state the support end rule, and retain evidence for every row.
Any server, plugin, or Companion change outside those ranges requires a new
compatibility result rather than an inferred claim.

## Release, upgrade, and rollback contract

1. Freeze one server commit and the exact integration package versions.
2. Run source contracts and the live publication preflight.
3. Produce deterministic artifacts and checksums from the frozen commit.
4. Run clean-install, upgrade, downgrade, rollback, device migration,
   revocation, restart, and cross-project isolation against those artifacts.
5. Submit the unchanged artifact to the applicable provider review channel.
6. After approval, install the provider-published artifact on a clean device
   and repeat the critical journey. A dashboard draft or locally identical ZIP
   is not sufficient.
7. Publish only after the compatibility policy names the provider identifier,
   supported versions/OSes, support window, and evidence location.
8. Roll forward with a higher version for normal defects. Use a provider's
   supported rollback only after confirming data/schema compatibility. For a
   security event, disable/revoke the affected integration first and preserve
   audit evidence; do not automatically replay uncertain writes.

The application runbook remains
[`plugin-production-operations.md`](plugin-production-operations.md). Its
monitoring, rate-limit, audit, delivery-health, secret-rotation, incident, and
rollback controls apply to every published integration. Provider dashboards
and store-review health are additional production dependencies and must be
included in alerting and incident ownership.

## Production acceptance record

Create one evidence directory per release and channel. Record only non-secret
identifiers and sanitized observations.

| Gate | Required evidence |
| --- | --- |
| Artifact identity | Source commit/tag, semantic version, checksum, provenance, provider submission ID. |
| Publication | Validation/scan results, review result, published identifier and URL, publication time. |
| Legal and identity | Verified publisher, website, support, privacy, terms, domain verification. |
| Security | OAuth/authorization result, secret ownership/rotation, least permissions, rate limits, security headers, vulnerability and provider scans. |
| Operations | Health/metrics/logging, delivery health, audit visibility, on-call owner, incident and rollback drill. |
| Compatibility | Exact server/package/browser/OS rows and declared support window. |
| Lifecycle | Clean install, update, downgrade, rollback, restart, device migration, revocation, reinstall. |
| Isolation | Unauthorized, revoked, wrong-project, ambiguous-identity, and uncertain-outcome cases fail closed. |
| User journey | A non-developer installs without source checkout or developer mode, connects, performs the primary workflow, receives an update, and removes/revokes access. |

For installed Companion and Codex identity recovery, use
[`v1-companion-installed-acceptance.md`](v1-companion-installed-acceptance.md).
The current sanitized Companion result is
[`evidence/companion-0.10.24-installed-acceptance-2026-10-09.md`](evidence/companion-0.10.24-installed-acceptance-2026-10-09.md).
For provider behavior, keep
[`v1-cross-provider-contract-matrix.md`](v1-cross-provider-contract-matrix.md)
and [`v1-provider-error-recovery-matrix.md`](v1-provider-error-recovery-matrix.md)
with the evidence. Source matrices do not replace this production record.

## Owner-controlled blockers

Repository work cannot complete these steps without external authority:

- choose and verify the OpenAI publishing organization and public plugin name;
- create the OpenAI draft to obtain the submission identity and domain
  verification token, then supply reviewer access and approve publication;
- designate an immutable Codex stable ref and supported OS/support window;
- enroll in Chrome Web Store and Microsoft Partner Center, approve listing copy
  and privacy declarations, and submit the Companion packages;
- approve any mobile application scope. Mobile remains unplanned until then.

Keep these as visible blockers. Do not replace missing external evidence with a
source test, inferred success, or an unreviewed public claim.
