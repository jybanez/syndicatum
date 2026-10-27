# Developer packet preimage classification

Source-only response to 11563, reconciled to native B candidate review 11569.
This classifies the packet's complete preimage categories. It neither captures a
new cutoff nor refreshes historical 11543/11545 evidence. No executable packet is
ready while native review, platform validation and new owner authority are absent.

| Preimage category | Class | Exact rule |
| --- | --- | --- |
| CLI executable/version; native module and PowerShell executable hashes; mechanism head | A: hard invariant | Exact reviewed identity before any action; no PATH substitution |
| Candidate commit/version, archive/manifest, full candidate file set/bytes | A | Exact reviewed commit 9141daa and complete hashes; no new source substituted |
| Marketplace name/source/config/ref/managed HEAD; installed target cache | A | Exact preimage or exact explicitly authorized stage postimage; only native target cache replacement is permitted |
| Unrelated marketplace/config/cache inventories | A | Complete identity set and exact bytes/typed configuration outside the expressly allowed target change; no blanket exclusions |
| Runtime code and metadata; protected identity, credential and binding configuration; aliases | A | Exact raw hashes and identity sets; never display credential contents |
| Inactive malformed backup artifact | A | Exact bytes/hash; no parse, repair, replay, replacement or cleanup |
| Connector Task XML, name/path/action/principal/trigger | A | Exact owned object before suppression, verified absence afterward only under NEW explicit suppression authority |
| Connector Run value, type, raw command and registry-view identity | A | Exact preimage before deletion; only exact named value may become absent; aliasing is not a general missing-value exception |
| Owned listener PID/start/image/entry; both lock identities/liveness | A | Revalidate ownership immediately before stop; afterward only verified exit and absent/original dead-owner lock artifacts; no stale-lock cleanup |
| Shared staging and concurrent marketplace operations | A | Empty/zero freshly checked immediately before add; never clean entries to pass |
| Unrelated Task identities/full raw XML and Run entries | A | Exact newly authorized cutoff through every command boundary; no global timing masks |
| Retained Task's Interval/StartBoundary difference | A | Cause unproven from retained XML; no semantic exception is justified |
| Canonical route filename set, path/handle/volume identity, ownership and state schema | A | Complete exact set, stable source/copy identity and strict JSON; unexpected route/field/file is STOP |
| Pending notifications, processed history and active wake payload | A | No automatic advancement exception. Preserve current bytes at a separately authorized new cutoff; freeze them afterward |
| Last-sequence observation while old runtime is active | B: expected-moving observation | Movement is classified, not presumed legitimate. A needs separately anchored identity/queue proof and a NEW reviewed rule; B freezes even sequence after quiescence |
| UTC/QPC observation values | B | Native paired clock proof, stable boot/frequency, bounded measurement uncertainty, <=120-second observation age; discontinuity/future/stale evidence stops |
| Live diagnostic health/log/temporary observations | B | Retain separately with capture identity/time/limits; never use moving diagnostics as proof that protected state was preserved or routing passed |
| Private recovery manifests, approved plans, raw evidence, journal records/heads and old attempt result | A | Immutable after sealing; new observations append under new IDs, never replace a baseline or resume 11545 |
| Anything not classified above | A / STOP | No inferred benign movement or implicit refresh; add a reviewed rule before proceeding |

Class B is not an execution exception. Without its required independent evidence
and new policy authority, a changed value still stops. Old state progression is
not retrospectively legitimized by a later stable capture. The runtime cloud guard
is an accepted behavior of the exact CLI, not a renewed hidden-value prerequisite.

## Nine route changes: just-in-time preservation rule

Retain original recovery bytes and failed-attempt observations permanently.
Seven later comparisons were sequence-only; two also involved queue/wake/history.
Do not give all nine a sequence-only allowance. A fresh B cutoff can be captured
only after NEW owner-authorized connector suppression/stop and independent
quiescence, using complete current raw-state sets and handle/path/copy checks.
That is a new current observation, not a restoration, authorization of past
changes, or replacement of the old approval's preimage. Every subsequent command
boundary compares the exact sealed raw bytes/set, including lastSequence. Any
writer/queue/history/wake/schedule change or expired proof stops without recapture.

Before cutover authority exists, this remains a source rule. No new live snapshot
or executable packet is manufactured to make the old hashes pass.

## Unrelated Task: retained evidence result

Retained original/current XML confirms unchanged Actions and Principals, while
Interval/StartBoundary differ. XML values and author metadata do not identify the
actor that made the change. No scheduler audit event or owner authorization was
retained with those snapshots. Therefore scheduler/owner-managed benign movement
is NOT established. Keep full-XML comparison and a hard STOP; do not infer cause
from vendor name, stable executable or elapsed time.

## New packet readiness

The native candidate and adversarial fixtures supply source preparation, not a
current private executable packet. Fresh B evidence requires expanded owner
authority; the source gates are closed and platform/durability validation remains
unproven. Until those prerequisites pass, a new private record may identify the
current source head and proposed IDs only if marked uncaptured/unapproved. It
must not claim fresh live evidence, refresh the old packet, or request execution.
