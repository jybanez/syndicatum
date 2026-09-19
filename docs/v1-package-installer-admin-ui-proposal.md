# Syndicatum V1 Installer and Package Administration UI Proposal

**Status:** Owner-approved on 2026-09-20 for UI-first, placeholder-safe, Helper-first implementation

**Parent architecture:** `docs/v1-canonical-package-installer-backup-restore-proposal.md`

**Scope:** What administrators will see and when each UI surface will be implemented

## Purpose

The parent proposal defines the package, installer, backup, and restore safety contracts. This supplement makes the user experience concrete.

V1 has two distinct interfaces:

1. a **Web Installer** shown only while a new instance is uninstalled; and
2. an administrator-only **Backup / Restore** surface inside an installed instance.

Both use Syndicatum's current dark theme and native Helper UI components. They do not create a second administration application or design system.

## Owner decision and implementation authority

Jonathan approved this UI direction in Syndicatum message #2957 with three binding implementation instructions:

- start with the visible UI;
- use explicit placeholders where package/install/backup/restore behavior is not yet implemented;
- implement Helper-first.

The approved direction includes:

- a guided, full-page installer for new instances;
- a new **Backup / Restore** global-administration item;
- three plain-language actions: **Get clean package**, **Build backup package**, and **Restore backup package**;
- full-page backup and restore workflows, with modal dialogs only for re-authentication and bounded confirmations;
- staged restore only in V1, with no control that overwrites the live instance;
- UI shells and placeholders first, then reusable helpers and package/baseline behavior, then real installer and backup/restore connections.

Placeholders must be non-deceptive: they may say **Not yet available**, disable actions with an explanation, or use fixture data clearly marked development-only. They must never imply that a real install, backup, restore, checksum verification, or package download completed.

## Navigation and placement

For a system administrator, the expanded global navigation becomes:

1. Home
2. Users
3. Agents
4. Audit
5. Delivery health
6. Backup / Restore
7. System Settings

The new item appears after **Delivery health** and immediately before **System Settings**. It is absent for non-administrators, not merely disabled. Its route is stable and bookmarkable, for example `/admin/packages`.

When no valid installed marker exists, normal application routes do not expose a partially working workspace. They direct the operator to the installer, for example `/install`. After successful installation, that route shows **Installer locked** and a link to sign in.

## Shared interaction rules

- One primary action per step.
- Plain-language headings appear before technical metadata.
- Technical details remain available without dominating the default view.
- Sensitive actions require re-authentication and a separate confirmation.
- Long operations expose durable server-side state and can be revisited safely.
- Refresh never restarts an operation.
- Passwords, credentials, secrets, and decrypted content never appear in URLs, logs, summaries, or browser history.
- Every error states what failed, whether anything changed, and the safe next action.

## Web Installer

### Layout

The installer is a centered responsive page, not a modal:

```text
+------------------------------------------------------------------+
| Syndicatum                                      Installation      |
|                                                                  |
|  1 Ownership  2 System  3 Database  4 Administrator  5 Complete |
|  --------------------------------------------------------------  |
|  Step title                                                      |
|  Short explanation                                               |
|                                                                  |
|  [step content]                                                   |
|                                                                  |
|  [Back]                                           [Continue]      |
+------------------------------------------------------------------+
```

On narrow screens the stepper becomes **Step 2 of 5 — System check**. No horizontal page scrolling is required.

### Step 1 — Prove ownership

The first screen prevents the first internet visitor from claiming the instance.

It shows:

- **Prove you control this installation**;
- a bootstrap-token field when that deployment mode supports it, or exact instructions for creating a matching proof file through SSH/hosting file manager;
- **Verify ownership**;
- expiration and single-use guidance;
- non-revealing failure feedback.

Successful proof advances to the system check. It is not an administrator login and expires when installation is abandoned.

### Step 2 — Check this server

Checks are grouped under:

- PHP runtime and required extensions;
- HTTPS/public-origin safety;
- writable application paths;
- protected/private path placement;
- upload, memory, archive, and process limits;
- operator assumptions requiring attention.

Every check is labeled **Pass**, **Warning**, or **Blocked**. Blocked requirements prevent Continue. The page offers **Run checks again** and a copyable non-secret diagnostic summary.

### Step 3 — Connect the database

Fields:

- MySQL host, port, database name, username, and password;
- TLS mode;
- CA certificate input or protected-file reference when required.

**Test connection** is non-destructive. Success displays server version, TLS state, charset/collation, database emptiness, and privilege results. A non-empty or unsupported database is blocked with no “continue anyway” override. Baseline initialization happens only after final review.

### Step 4 — Create the administrator

Fields:

- display name;
- email address;
- password and confirmation.

The current password policy is visible, validation is accessible and inline, and the page states that this becomes the first system-administrator account.

### Step 5 — Review and install

The review shows only non-secret values:

- Syndicatum version, source package identity, and schema baseline;
- server compatibility result;
- database host/port/name, never its password;
- administrator display name/email;
- protected configuration destination and public origin;
- confirmation that historical pre-baseline migrations will not run.

Selecting **Install Syndicatum** shows durable stages:

1. validate package and environment;
2. apply current baseline;
3. write protected configuration;
4. create administrator;
5. lock installer;
6. verify application health.

Completion shows **Open Syndicatum**. Failure names the stage, target state, and safe retry/cleanup action. The instance is never labeled installed before health verification succeeds.

## Backup / Restore administration

### Landing page

This is a full administration surface using the current global header and panel styling:

```text
+------------------------------------------------------------------------+
| Global administration                                                  |
| Backup / Restore                                        [Refresh]       |
|                                                                        |
| Installed release                                                      |
| Version 1.x · Baseline … · Package verified · System healthy          |
|                                                                        |
| +--------------------+ +--------------------+ +---------------------+  |
| | Get clean package  | | Build backup       | | Restore backup      |  |
| | Download verified  | | Create encrypted   | | Validate and restore|  |
| | release files.     | | recovery package.  | | to a staged target. |  |
| | [View package]     | | [Build backup]     | | [Start restore]     |  |
| +--------------------+ +--------------------+ +---------------------+  |
|                                                                        |
| Recent operations                                                      |
| Type       Started by      Status       Date              [View]       |
+------------------------------------------------------------------------+
```

The release summary shows application version, schema baseline/head, shortened package digest with copy-details action, verification/health status, known update status, last successful backup time, mandatory backup-encryption status, and registered persistent-asset/storage status.

### Get clean package

The view shows:

- installed version and matching canonical package;
- source tag/commit and schema baseline;
- SHA-256 with copy button;
- PHP/MySQL compatibility summary;
- release notes/provenance;
- **Download package**, **Download checksum**, and **Download manifest**.

If the trusted release index is unreachable, the UI offers **Verify manually supplied package**. It never rebuilds a clean package from the live server.

Explicit states are: verified/downloadable, package unavailable, allowed update available, release index unreachable, and supplied package invalid/not allowed.

### Build backup package

This is a guided in-page workflow.

#### Configure

- current data is fixed to **Included** for this action;
- recovery password and confirmation;
- optional operator note;
- required persistent assets listed as included and not uncheckable in V1;
- warning that Syndicatum cannot recover the password.

Required persistent assets cannot be omitted in minimum V1 because doing so would knowingly create an incomplete recovery package. A future redacted/export package may use a different contract; it must not weaken the recovery-package guarantee.

#### Review and authorize

The page summarizes included data classes/assets, source version/baseline, and estimated size when available. It never previews secret values.

**Build encrypted backup** opens the native re-authentication dialog, followed by a bounded confirmation naming the audited operation. Download also requires a sufficiently recent re-authentication; otherwise the same native dialog is shown again.

#### Build and download

Step-based progress is used instead of a fake percentage:

- coordinate snapshot;
- export allowed data;
- collect registered assets;
- encrypt and hash;
- verify;
- ready for download.

The result shows filename, creation time, source identity, encrypted size, ciphertext/archive SHA-256, non-secret package identifier, **Download backup**, single-use expiry, and **Delete now**. Reloading the browser does not cancel or duplicate server work.

### Restore backup package

Restore is full-page because the source, destination, and cutover boundary must remain visible.

#### Upload

- drag/drop and file chooser;
- recovery-password field;
- size limits;
- statement that validation finishes before any target write.

#### Validate and inspect

After authenticated validation, show:

- package kind and integrity;
- source application version, schema baseline, and creation time;
- data classes/assets;
- compatibility result;
- warnings/blockers;
- **No executable application code detected**;
- no decrypted records or secrets.

Wrong-password, tamper, wrong-kind, archive-policy, and incompatibility failures stop here.

#### Choose staged target

Database/TLS fields mirror the installer for the separate target. The current live database is visibly identified and cannot be selected. The target check must prove an empty or allowed incomplete staging target. V1 has no **Overwrite this installation** option.

#### Review and authorize

The plan lists:

1. apply matching baseline;
2. import allowed data/assets;
3. install portable instance keys safely;
4. invalidate transient sessions/challenges;
5. pause pending deliveries for reconciliation;
6. apply supported forward migrations;
7. verify integrity/health;
8. generate cutover checklist.

The administrator re-authenticates, enters a short phrase such as `RESTORE TO STAGING`, and selects **Restore to staged target**.

#### Result

Progress is durable. Failure is labeled **Staging incomplete** and leaves the current live instance untouched.

Success provides source/target identities, verification and migration results, delivery-reconciliation state, checksummed report download, and hosting-specific cutover checklist. Minimum V1 has no automatic **Switch live now** button.

### Recent operations

The landing page lists package, backup, validation, and staged-restore operations with type, initiating administrator, timestamps, status, non-secret identifier, and **View**.

Statuses are queued, running, succeeded, failed, expired, or deleted. Audit remains authoritative; this list never exposes passwords, credentials, decrypted manifests, sensitive notes, or internal paths.

## Supporting Settings changes

Backup and restore are not hidden inside Settings. Settings remains focused on runtime configuration and may gain a read-only **Installation identity** group showing:

- installed application version;
- schema baseline and schema head;
- package identity/provenance link;
- database host/name and TLS summary, never username secrets or password;
- installer locked status.

The group links to **Backup / Restore** for package operations rather than duplicating its controls.

## Audit additions

The existing Audit surface records and can display:

- canonical package download initiated and completed/failed;
- backup build initiated and completed/failed;
- backup download completed or expired;
- backup validation attempted and accepted/rejected;
- staged restore initiated and completed/failed;
- temporary backup deleted;
- actor/administrator identity;
- source and target application/schema identities;
- non-secret package checksum/reference;
- timestamps and sanitized failure category.

Audit metadata never includes recovery passwords, database credentials, portable secrets, decrypted content, or public temporary-download URLs.

## Dialogs and notifications

Modal dialogs are limited to:

- administrator re-authentication;
- backup confirmation;
- staged-restore confirmation;
- temporary-package deletion;
- acknowledgement of an allowed warning.

Multi-step forms, validation reports, progress, and final reports remain full-page. Toasts provide short feedback, but durable results stay on the page.

## Permissions and security presentation

- Non-administrators do not see the navigation item or route metadata.
- Sensitive actions require recent re-authentication.
- Secret fields are never repopulated and clear after submission/navigation.
- Copy controls are limited to non-secret digests, identifiers, and diagnostics.
- Download links show their short-lived, single-use expiry.
- The UI states that sensitive operations are audited.
- A checksum is never presented as authenticating an untrusted release source by itself.

## Responsive and accessible behavior

Minimum requirements:

- complete keyboard operation and visible focus;
- associated labels, descriptions, validation messages, and error summaries;
- progress announcements without excessive repetition;
- text and icons in addition to color for status;
- correct dialog focus trapping/restoration;
- safe wrapping of long digests;
- stacked action cards and labeled row cards on narrow screens;
- no horizontal page scroll at supported mobile widths;
- reduced-motion support;
- refresh/history behavior that cannot duplicate operations.

## Required non-happy states

Every surface deliberately covers:

- initial loading;
- no previous operations;
- release index unavailable;
- operation queued behind a lock;
- operation already running elsewhere;
- validation rejected before write;
- backup failure with cleanup state;
- staged-restore failure with target-incomplete state;
- expired download;
- insufficient server limits;
- expired session/re-authentication;
- network interruption followed by authoritative status reconciliation.

Retry must query authoritative operation state before starting new work.

## Implementation timing

### Phase 1 — UI-first foundation and contracts

Ship the visible structure first using native Helper components:

- first-run installer shell and step navigation;
- administrator **Backup / Restore** navigation item and landing page;
- clean-package, backup, and staged-restore workflow screens;
- Settings installation-identity group and Audit presentation hooks;
- responsive, keyboard, focus, loading, empty, offline, and failure states;
- explicit **Not yet available** placeholders and disabled actions for unavailable backend operations;
- route/capability names;
- screen and state inventory;
- operation state machine and API/error vocabulary;
- field-level data classification;
- accessibility/responsive acceptance tests;
- fixture-driven browser-test skeletons;
- final user-facing security copy.

All placeholder state is visibly labeled. No placeholder can claim a real operation completed.

### Phase 2 — Helper/service and package-baseline foundation

Build reusable helpers/services beneath the approved screens for manifest validation, installation identity, baseline metadata, bootstrap proof, compatibility, encryption, archive safety, persistent assets, audit emission, and provenance. CI then produces the package, manifest, checksum, provenance, and baseline evidence. UI routes/controllers orchestrate these helpers rather than owning low-level logic.

### Phase 3 — Connect the Web Installer

Replace installer placeholders with the real protected flow:

- installer route/shell;
- ownership, system, database, administrator, review/install, progress, failure, completion, and lockout screens;
- responsive, keyboard, accessibility, and clean-host browser acceptance.

Backup/restore is not yet added to installed instances.

### Phase 4 — Connect Backup / Restore

Replace installed-instance placeholders with real operations:

- clean-package retrieval and verification;
- encrypted-backup workflow;
- staged-restore workflow;
- Recent operations;
- native re-authentication/confirmation dialogs;
- progress, reports, and audit linkage;
- desktop/mobile administrator browser acceptance.

### Phase 5 — Distribution adapters

No new primary workflow is expected. The clean-package view may gain verified GitHub provenance links, and its package identity must match Docker image labels.

### Phase 6 — Release review

Finalize help text, screenshots, accessibility/regression and cross-browser evidence, operator documentation, release gates, and Commercial Assessor review.

## UI acceptance criteria

The UI is complete only when:

1. an authorized clean-host operator can complete installation without routine shell use;
2. an unauthenticated visitor cannot claim or initialize the instance;
3. an administrator can identify and retrieve/verify the canonical installed package;
4. encrypted backup creation does not leak secrets into DOM after submission, logs, URLs, or audit metadata;
5. wrong-password or modified backup fails before any target write;
6. restore can target only an empty/staged destination, never the serving database;
7. progress survives reload and reflects authoritative server state;
8. failures explain source impact and safe recovery;
9. flows are keyboard-operable, responsive, and screen-reader understandable;
10. non-administrators cannot discover route metadata;
11. every completion has a durable non-secret result and audit event;
12. automated browser tests cover principal success/failure states with the backend package fixtures.

## Explicit V1 UI deferrals

The following controls are intentionally absent:

- automatic live cutover or in-place restore;
- scheduled/recurring backups and retention management;
- incremental/differential or selective table restore;
- unencrypted backup;
- browser archive editing;
- downgrade controls;
- multi-node/HA orchestration;
- automatic cloud-storage upload.

## Recommended next decision

Implementation proceeds under Jonathan's approval beginning with the UI-first/helper-first Phase 1 slice. Material design conflicts still require owner direction; ordinary placeholder, helper, test, and integration work proceeds autonomously with Commercial Assessor review.
