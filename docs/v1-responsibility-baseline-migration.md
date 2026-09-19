# Historical direct-message responsibility baseline

Status: the Commercial Assessor recommended the conservative rule below in
Syndicatum message #2765. Source/CI migration acceptance and installed-client
acceptance remain separate gates.

## Why a baseline cannot be guessed

The legacy expansion migration preserves a direct message's sender, recipient,
time, edits, deletion state, and canonical timeline identity. It does not
contain a reliable event-time record of whether that recipient stayed active
through every later removal and reactivation. The responsibility migration
therefore leaves `message_addressees.responsibility_status_generation` null on
pre-existing direct rows. Copying the recipient's *current* generation into an
old row would incorrectly claim continuous responsibility.

New direct messages anchor the recipient's membership-status generation when
the canonical message is created. Later accepted transfer and explicit
restoration events renew that anchor. The generation is evidence/versioning
for the canonical event history, not a second mutable responsibility state.

## Current safe behavior

- Historical direct messages remain readable in the project timeline, with
  their original addressing and acknowledgement evidence.
- The derived responsibility inbox shows a row without a verified baseline as
  `state: unknown` and `projection_error:
  RESPONSIBILITY_BASELINE_UNAVAILABLE`. It does not call it open, resolved, or
  assigned to an active responder.
- A structured responsibility event against that row returns
  `RESPONSIBILITY_BASELINE_UNAVAILABLE` (HTTP 409) and rolls back the proposed
  event message. Idempotent retry semantics remain unchanged.
- No migration changes or deletes a historical direct row to manufacture a
  baseline. No mutable inbox status is introduced.

## Read-only preflight

With the application's normal database environment configured, run a global
summary or one project's detail:

```sh
php scripts/responsibility-baseline-preflight.php
php scripts/responsibility-baseline-preflight.php PROJECT_ID
```

The global summary reports distinct affected projects and total unverified
direct items. The project detail returns counts of total, verified, and
unverified direct-request baselines, including legacy items and any structured
events attached to unverified items. It also reports current addressee
validity (`active`, `inactive`, `missing`) and the oldest/newest affected
message timestamps and project sequences. **Current** validity does not
establish historical responsibility. The command reads the configured
database but does not write rows or emit message content, tokens, or names.
`events_on_unverified_baselines` should be zero; a nonzero result requires
investigation before release. Run it against the intended installed database
and retain its output as deployment evidence.

## Rule for unverified historical work

Do not automatically convert old direct messages into currently owed work.
Preserve them as history and require a new, explicit canonical direct request
to establish present responsibility. The Commercial Assessor recommended
this rule without a further owner decision. An owner-attested conversion
feature is outside initial P1.1; adding one later would require a separate
product and authority decision, with its own authorization, audit, UX, and
dispute semantics. Do not backfill live rows merely because the recipient is
active today.

## Acceptance evidence still needed

The MySQL test suite verifies that an actual legacy direct recipient is
preserved with a null baseline, appears as `unknown`, is counted by the
preflight, and cannot gain a structured responsibility event or orphan message
through a write attempt. A new explicit direct request receives a verified
anchor and normal `open` projection, while rerunning migration leaves the old
unknown baseline unchanged. Exact-head CI and installed-database preflight
counts still need to be retained; installed-client handling of `unknown`
remains open.
