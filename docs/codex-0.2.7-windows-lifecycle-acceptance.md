# Codex 0.2.7 Windows lifecycle acceptance

Status: guarded procedure awaiting commercial review and explicit execution
authorization. This is not an installation command, production promotion, or
support claim.

## Purpose and targets

This procedure closes only the remaining Codex 0.2.7 installed lifecycle
evidence needed by the integration-distribution gate. The owner-approved target
matrix is:

| Target | Operating system | Required role in the acceptance |
| --- | --- | --- |
| Syndicatum Developer machine | Windows 11 | Primary exact-tag baseline and coordination; no destructive credential exercise on the Developer identity. |
| HomeServer | Windows 11 | Independent second-device, restart, rollback, and recovery evidence. |
| S5 | Windows 10 22H2 | Exact 0.2.7 install/upgrade, restart, rollback, and recovery evidence. |

Use immutable `codex-v0.2.7` at commit
`68c586b17628b387f08e42da3f17199f0f8824e7`. The release ZIP SHA-256 is
`9cb722c34572ccf38480fdcb7574aa92d6394f838c297440d1e4ce030c079378` and
the provenance-manifest SHA-256 is
`43af9597eb6fb0085ce1335cd197b377009cbeb0fca27bf5edbe02d7463a4bfd`.
Never substitute mutable `main`, a HomeServer payload, or files copied between
machines.

## Authorization boundary

The procedure must be approved as one bounded plan before execution. Each
machine performs at most one approved transition sequence. Stop rather than
improvise when a precondition, hash, path boundary, authorization state, or
expected result differs.

The following remain separately authorized actions and must not be inferred
from approval of this document:

- revoking or replacing a credential;
- suspending an agent or device;
- using a newly generated claim or authorization code;
- deleting a marketplace, plugin cache, protected profile, binding, queue, or
  history record;
- deploying application files or promoting a release.

Credential replacement and revocation must use a disposable acceptance identity,
never Syndicatum Developer, HomeServer Codex, S5 Codex, or another production-
relevant identity. The owner performs or explicitly approves the server-side
security action. Claim codes and tokens are never recorded in evidence.

## Required preservation

Before any transition, create a recoverable backup outside the web root,
marketplace checkout, plugin cache, and plugin data root. Record only its safe
location class, timestamp, inventory, and SHA-256 manifest; do not publish local
paths or secret material.

The backup must preserve, without reading or disclosing secrets:

- marketplace and installed plugin bytes;
- Codex marketplace/plugin configuration;
- protected profile containers and device authorization state;
- connector bindings, queue/history state, and health snapshots;
- any unrelated local files that the bounded transition could touch.

Verify the backup by inventory and hash before continuing. Capture exact
preimages for every file the transition is allowed to replace. Files outside
that allowlist must remain byte-identical. Existing credentials, bindings,
project data, uploaded files, application deployment, and unrelated state must
not be copied into project storage or changed by plugin-version transitions.

Create the external backup with
`scripts/codex-windows-lifecycle-backup.ps1`. The helper accepts only fully
qualified paths on ready fixed local volumes, proves that its new backup subtree
does not overlap the webroot or any managed/protected source in either direction,
rejects reparse points throughout all path chains and source trees, measures the
complete pre-copy inventory (including empty directories), and requires measured
capacity plus a safety margin. It writes only beneath the new backup subtree,
persists and hashes the manifest, then revalidates the backup and unchanged
sources from that persisted manifest. Any failure is a stop condition: retain the
partial evidence and do not clean up or replay automatically.

## Read-only baseline on every target

Record a sanitized baseline before closing Codex Desktop:

1. Windows edition and build.
2. Marketplace ref and resolved commit, installed manifest version, release and
   installed inventory counts, and exact candidate hashes.
3. Protected project/agent identity IDs without credential values.
4. Device/background status, listener ownership, heartbeat freshness, binding
   and project counts, unavailable-binding count, and startup mechanism.
5. Authoritative project bootstrap/read success and one project-isolation
   negative result using an already approved fixture.
6. Queue/review/history counts and any pre-existing diagnostic qualifications.

An unexpected running Codex process, tracked marketplace change, unexpected
untracked marketplace file, unresolved configuration reload, stale health,
unknown listener owner, unavailable binding, or unmatched inventory is a stop
condition. The Codex-managed `.codex-marketplace-install.json` file is the only
permitted untracked marketplace file, and only when it parses successfully and
its Git source, immutable ref, and resolved revision exactly match the checkout.
The managed checkout shape must be either a full checkout (`sparse_paths: []`) or
the canonical sparse checkout (`.agents/plugins`, `plugins/codex`); any other
sparse-path set is a stop condition. Include the metadata in the external backup
and evidence inventory; never delete or silently ignore it.

The coordinator must designate the exact non-sensitive, owner-approved foreign-
project message or task ID used for the negative isolation check. A target must
confirm only the expected concealed/not-found result and must not record foreign
content. If no exact fixture is supplied, stop before transition rather than
inventing one or selecting another protected identity.

## Transition sequence

### A. Exact 0.2.7 restart acceptance

Fully exit Codex Desktop. Verify no Codex plugin host owns the marketplace or
plugin-cache files. Install or confirm the marketplace at `codex-v0.2.7`,
install `codex@syndicatum`, restart Desktop, and open a genuinely new task.

Use `scripts/codex-windows-lifecycle-transition.ps1` as the reviewed closed-
Desktop transition mechanism. Pass the verified external backup manifest and an
external report path. `Inspect` is read-only, `RejectFixture` exercises the
deterministic pre-install rejection without changing bytes, and `Apply` is the
only mode permitted to call the Codex marketplace/plugin commands. Its exact
mutation allowlist is the Syndicatum marketplace checkout, the Syndicatum Codex
plugin-cache subtree, and Codex's marketplace/plugin registry state files. The
plugin data root is never allowlisted for mutation. The helper fingerprints its
stable protected profile, credential-container, alias, and connector-
configuration files; volatile listener locks, health, logs, and delivery state
remain outside the transition helper and are compared through the separately
recorded baseline/evidence counts. Before either `RejectFixture` or `Apply`, the
helper fails closed if Codex Desktop is running, if any process command line
references the managed marketplace or plugin-cache roots, if process inspection
cannot complete, or if any file under those roots cannot be opened for exclusive
read access. It reports these conditions without killing processes or repairing
state. `Inspect` does not require quiescence and remains read-only.

The new task must report installed manifest 0.2.7, healthy plugin-managed
background service, listener ownership, fresh heartbeat, expected nonzero
route counts, zero unavailable bindings, protected identity continuity, and
authoritative project-scoped reads. Do not repair a failed baseline inside this
step.

### B. Rollback and forward recovery

Only HomeServer and S5 perform the live version cycle. With Codex fully exited,
transition the tagged marketplace to `codex-v0.2.6`, reinstall the plugin, then
restart into a genuinely new task. Verify exact 0.2.6 bytes, preserved external
profile/device/binding state, healthy routing, and authoritative reads.

Fully exit again and return to immutable `codex-v0.2.7`. Verify the same 0.2.7
postconditions as step A. The cycle must not create a new participant, duplicate
introduction, duplicate route, or replay queued work. Do not claim 0.2.6 as a
supported predecessor merely because the rollback succeeds.

### C. Failed-update recovery

Exercise failure only through the reviewed transition mechanism and only after
the verified backup exists. Run its `RejectFixture` mode for the intended target
version; it injects a deterministic candidate-revision mismatch before any Codex
command, verifies the marketplace, plugin cache, protected plugin data, and
registry fingerprints remain identical, and writes a sanitized external report.
Confirm the previous installed version remains loadable and no credential,
binding, queue, or history state changes.

If the mechanism can fail after replacement begins, restore only the allowlisted
preimages from the verified backup while Codex is closed, then restart once and
verify the complete baseline. Never retry an uncertain mutation automatically.

### D. Disposable credential replacement and revocation

Use one owner-designated disposable project agent. Capture its non-secret IDs
and a successful baseline read/write. The owner generates one replacement claim
code; the intended Codex task consumes it once without logging it. Verify the
old credential fails closed, the replacement preserves the same participant
identity and project scope, and unrelated profiles remain usable.

The owner then performs the approved revocation or suspension check. Verify the
disposable profile loses access without fallback to another identity. Recover
only through a newly authorized code when the owner explicitly requests it.
Do not acknowledge or replay unrelated queued messages during this exercise.

### E. Second-device and routing acceptance

HomeServer and S5 each prove independent device authorization while discovering
the same intended project binding. Send one uniquely correlated test request to
each target. Each target must authoritatively read it, reply once using its own
protected profile, and acknowledge only after handling. Verify no cross-project
delivery, duplicate wake, duplicate reply, or queue replay.

## Stop and rollback rules

Stop immediately on any hash/ref mismatch, access-denied backup, unexpected
file target, identity or project mismatch, stale/ambiguous connector ownership,
route loss, credential exposure, duplicate delivery, or uncertain mutation
result. Do not change service configuration, application deployment, Companion,
other plugins, or server data to make the test pass.

When stopping before replacement, leave the current installation untouched.
When stopping after a confirmed partial replacement, restore only the verified
allowlisted preimages with Codex closed, restart once, capture health evidence,
and escalate. Preserve failure artifacts outside project storage without secret
contents.

## Evidence contract

Each machine returns a sanitized record containing:

- target and OS build;
- exact refs, commits, versions, inventories, and public release hashes;
- backup manifest hash and verification result, without local paths;
- every step attempted, start/end timestamps, exit/result state, and whether a
  mutation occurred;
- pre/post health, route, identity, project-scope, queue/history, and file-
  integrity results;
- correlation IDs and public Syndicatum message IDs for routed tests;
- all deviations, qualifications, stop conditions, and recovery actions.

The coordinator accepts only evidence for an exact reviewed procedure revision.
Results are consolidated into a new evidence record and policy change for
Commercial Assessor review. Production promotion remains a separate owner
decision after every required gate is passed.
