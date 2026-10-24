# 0.2.10 HomeServer pilot: procedure design, NOT executable authority

Status: DESIGN ONLY under Syndicatum message 11431. Do not execute this document,
create a live-attempt backup, install, stop a listener, or change registrations.
An exact reviewed implementation and separate execution authorization are required.
S5, S6, macOS and fleet rollout remain on hold.

## Exact acceptance object

- Source: `9141daa08d896f2524be30b96d01ab2a14398757`.
- ZIP project file: `62a4d4f0-436b-4c3c-8038-eedf65fb2807`.
- ZIP SHA-256: `331e47a6ee3cd690846ac7f2d302566658e4be195c3a734228f457f55e61feeb`.
- Manifest project file: `a66a336d-acc9-4b03-a95c-0754bfef8c25`.
- Manifest SHA-256: `d54107333c8344ec4811d0e4d96a07c6904b69ba3747327d0c0d832f9c6c98ce`.
- Version 0.2.10, 48 files, independently reproduced by HomeServer.
- `codex-v0.2.10` is an intended ref, NOT an existing tag or publication.
  Never fetch mutable main or substitute a tag installation command.

## Preconditions and unresolved design decisions

1. Retain the private association of both malformed participant-state artifacts:
   A: 4761 bytes, SHA-256
   `b40134f5a1eb03ff2ff989fd5493047247e07d0dadaf830514c68d35d746fa73`;
   B: 24732 bytes, SHA-256
   `575643b90c0f71591befad5c2e4d0309b4051ecc84e556495e7dc929b26eab78`.
   Fresh measurements must match, or STOP for review. Never restore a syntax prefix.
2. Resolve or explicitly retain the unexplained listener PID transition risk.
   The old PID is not a permissible stop target: revalidate executable, entry,
   creation time, parent and both lock owners immediately before any approved stop.
3. The Scheduled Task has source/action/trigger/SID ownership evidence. Revalidate
   exact task XML and current-user SID at execution time; any drift means STOP.
4. Preserve the differently named HKCU Run value exactly. Its ownership is unproven.
   Never normalize or remove it, execute its launcher, or assume it is harmless.
5. Mandatory pre-install gate: if the unverified spaced-name Run entry is present,
   STOP before backup execution, task removal, listener stop or installation.
   The candidate checks only the canonical no-spaces value name; it may ignore
   this different value. Do not rely on candidate migration to reject it.
   Preserving-and-ignoring an active sign-in launcher is NOT successful migration.
   Only independent ownership evidence plus explicit approval for exact removal
   can change this gate; that would require a superseding reviewed procedure.
6. Select and review an exact artifact-only plugin installation mechanism. The
   provenance ZIP is not itself an installer. Do not copy it over a running cache,
   edit Codex installation records ad hoc, or invoke unreviewed old wrappers.
7. Prove candidate fail-closed handling of both malformed routes in a fixture:
   neither original artifact changes; no default state replaces it; no queue,
   acknowledgement, startup recovery or retry occurs for those failed routes;
   healthy routes remain available and aggregate health is explicitly degraded.
   This is isolation, NOT recovery of either route's local history. Assessor
   message 11434 accepts this proposed baseline subject to external verified
   preservation, unchanged source hashes and post-start skipped-route evidence.
   Both routes remain unavailable/not recovered, and their server-side unhandled
   activity must remain unacknowledged. This is not physical execution authority.

## Proposed single-attempt phases (not authorized to run)

Each phase must record preconditions, intended action, confirmed result and error
separately. An existing attempt marker prohibits replay. An uncertain result stops
the procedure; do not auto-retry, delete evidence or automatically roll back.

1. Allocate a previously nonexistent, external, private evidence destination on a
   verified fixed volume outside installed roots/webroot. Validate resolved paths,
   free capacity, access boundaries and exclusive attempt identity before writes.
2. Owner closes Codex normally. The final reviewed mechanism must prevent owned
   scheduled startup across the complete critical window, not only stop its PID.
   Exact owned-task removal is the proposed suppression, but must occur only after
   retained registration capture and approved pre-mutation revalidation. It must
   not touch the unverified Run entry. Concurrent launch/owner ambiguity means STOP.
3. Obtain the approved quiescence/preservation sequence: a verified consistent
   backup of plugin data, credential references/configuration, profiles/bindings,
   state/history, marketplace/cache and full lifecycle registrations is required
   BEFORE installed-root mutation. Because stopping/suppressing is also mutation,
   the implementation must explicitly define its preliminary registration capture,
   read-only inventory and permission boundaries; do not improvise this ordering.
4. Preserve both malformed files byte-for-byte in the external backup with detached
   hashes, sizes and mtimes. Verify all backup entries against quiescent sources.
   Export task XML and exact Run value privately. Do not upload secrets or protected
   backups to public project storage. Keep originals in place, unmodified.
5. Under the approved artifact-only mechanism, verify ZIP and manifest hashes,
   validate all entries against the 48-file manifest (reject traversal, duplicates,
   extra entries and symlinks), then stage outside installed roots. Install only
   after exact source/target preimages and process/lock quiescence are revalidated.
6. Owner opens Codex normally. Verify manifest/version/runtime bytes, one shared
   session-owned listener, advancing fresh health, exact locks/owner identities,
   no new sign-in autostart and owned-task absence. The unverified Run entry must
   not have been present at the approved attempt boundary; if it is observed,
   STOP, retain evidence and do not claim migrated lifecycle acceptance.
   Verify both malformed artifacts remain identical and their routes are isolated.
7. Exercise the approved physical matrix: two owning sessions share one listener;
   close one and listener stays; close last and listener stops without reopening
   Codex; one authorized safe addressed message while closed remains unacknowledged;
   normal reopening recovers it once; healthy and unavailable routes stay isolated.
   Distinguish two app/CLI owning processes from two windows of one process.
8. Retain final bytes/hashes, credentials/bindings preservation evidence, route and
   state outcomes, process/health/lock evidence, task/Run comparisons, warnings and
   every stop condition. Do not claim healthy acceptance for degraded routing.

## Review gates

Before an executable attempt, submit the exact implementation, fixture results,
artifact-only install mechanism, registration comparisons, backup consistency and
ordering proof, corrupt-route isolation evidence, physical operator steps and
failure/no-replay behavior. No authorization follows from this draft or its merge.
