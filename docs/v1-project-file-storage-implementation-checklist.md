# V1 Project File Storage Implementation Checklist

Status: Approved and in progress
Prepared: October 3, 2026
Source: [V1 Project File Storage Proposal](v1-project-file-storage-proposal.md)

> The project owner approved this checklist and its project-plan conversion on
> October 3, 2026. Implementation proceeds gate by gate; incomplete operations
> remain visibly unavailable rather than simulating success.

Reconciled October 4, 2026 against merged implementation PR #87, documentation
PR #88, completed tasks #101 and #103, the task #102 completion branch, and the
passing focused storage, API, UI, package, migration, and MySQL CI evidence.
Unchecked items remain either unimplemented, not yet accepted in the required
environment, or explicitly deferred to Phase 2 or Phase 3.

## 1. Outcome and scope

V1 provides ordinary, durable project file sharing:

- file bytes are stored under the installation's configured private local
  storage root;
- canonical metadata and project associations are stored in MySQL;
- Syndicatum issues permanent, unguessable public URLs that do not require
  authentication;
- supported images and video render in Helper's Media Viewer, PDFs render in
  Helper's PDF Viewer, JSON/Markdown/CSV use their complete canonical Helper
  viewers, and other files open in a new tab/window or download;
- the public URL remains stable across logout, restart, deployment, backup and
  restore, and later storage-provider migration; and
- a complete Syndicatum backup includes both database records and file bytes.

V1 is not a confidential document-management system. It does not add expiring
links, recipient authentication, per-file ACLs, collaborative editing, public
directory browsing, or desktop folder synchronization.

## 2. Status meanings

- `[x]` — implemented and verified in the repository, or explicitly confirmed
  by the project owner where noted.
- `[ ]` — not implemented or not yet verified.
- **Decision gate** — owner approval is required before dependent work starts.
- **Exit evidence** — the minimum proof required to close a phase.

## 3. Delivery gates and dependencies

```text
Gate A: global settings and fixed rules
  -> Gate B: storage and data contracts
    -> Gate C: upload and public delivery
      -> Gate D: product surfaces and agent access
        -> Gate E: backup and restore
          -> Gate F: release acceptance

Phase 2 operational hardening and Phase 3 S3 support remain separate follow-up
work after the complete local-storage V1 passes Gate F.
```

Work may be prepared in parallel inside a gate, but no later gate may be
reported complete until its dependency gates have passed.

## 4. Phase 0 — Configuration foundation

- [x] Register `storage.local_base_path` as a controlled database-backed system
  setting.
- [x] Add a **Storage** tab and required **Storage location** field to the
  canonical System Settings form modal.
- [x] Validate the field before submission and include it in the structured
  multi-field validation summary.
- [x] Require an absolute server filesystem path.
- [x] Reject a path inside, equal to, or containing the public application
  root.
- [x] Create the configured directory when possible and require it to be
  writable.
- [x] Require owner-only `0700` permissions on Linux/POSIX.
- [x] Keep project-file storage distinct from Recovery backup storage.
- [x] Include the storage validator in the canonical release package.
- [x] Document the setting and its current configuration-only boundary in the
  technical docs and in-app User Guide.
- [x] Project owner confirmed a development storage location has been saved in
  System Settings. This is operator evidence, not a portable repository
  default.

**Exit evidence:** PR #84, repository tests, canonical-release checks, and the
owner-confirmed saved setting.

## 5. Gate A — Global storage settings and fixed rules

Most installation-level choices belong in the **Storage** tab so an
administrator can change them without a deployment. Defaults must make a new
installation usable without requiring technical tuning. Server-side security
ceilings still apply even when an administrator chooses a more permissive
value.

Do not expose an inert setting. A field appears only when its behavior is fully
implemented, validated, enforced by every applicable upload path, audited where
appropriate, and documented.

Owner decisions recorded October 3, 2026: render only supported image, audio,
and video media inline; give every active human and agent project participant
the same audited file-management actions; deliver the project Files modal
before timeline message attachments; and direct projects needing restricted or
secure file management to Google Drive or another dedicated service.

### 5.1 Recommended global settings

| Controlled setting | User-facing field | Recommended default | Required guardrail | Approval |
| --- | --- | --- | --- | --- |
| `storage.local_base_path` | Storage location | No portable default; administrator must supply an absolute server path | Private, writable, and outside the public application root | [x] Implemented |
| `storage.max_upload_bytes` | Maximum file size | 25 MiB | Integer range with a server hard ceiling; enforced while streaming before storage is committed | [x] Owner-approved |
| `storage.max_files_per_action` | Maximum files per action | 10 | Integer range 1–50; enforced by browser, human API, and agent API | [x] Owner-approved |
| `storage.allowed_content_types` | Allowed file types | All server-supported downloadable types | Canonical multi-select/presets only; administrators may narrow the server allowlist but cannot add unsupported executable types | [x] Owner-approved |
| `storage.inline_preview_types` | Inline media types | Safe supported image, audio, and video types | Administrators may select a media subset only; PDF uses its separately fixed viewer policy, while other documents, archives, text, HTML, and SVG cannot be added | [x] Owner-approved |
| `storage.default_project_quota_bytes` | Default project storage quota | 64 GB per project | Must exceed the per-file maximum; each project's canonical live-file usage is enforced independently before committing an upload | [x] Owner-approved |
| `storage.deleted_content_retention_days` | Deleted-content retention | 0 days | Public access ends immediately regardless of byte-retention period; metadata tombstones and audit evidence remain | [x] Owner-approved |
| `storage.public_cache_max_age_seconds` | Public file cache duration | 300 seconds | Bounded nonnegative duration; replacement and deletion must use the approved cache behavior | [x] Owner-approved |

- [x] Add these fields to the existing canonical Storage tab, grouped into
  **Location**, **Uploads**, **Capacity**, and **Delivery** sections.
- [x] Load the modal before settings data, retain canonical busy/loading
  behavior, and disable only controls whose data or authorization is not ready.
- [x] Validate all applicable fields before busy state or requests. Cross-field
  errors—such as a quota below the maximum file size—must appear beside the
  fields and in the structured multi-error summary.
- [x] Store byte values canonically while presenting ordinary MiB/GiB units to
  administrators.
- [x] Lock environment-overridden settings visibly and omit them from browser
  mutations.
- [x] Audit changes without recording file content, private paths beyond the
  setting value already authorized for administration, or other secrets.
- [x] Apply one backend policy service to human, agent, and future provider
  upload paths so settings cannot be bypassed.

Settings for malware scanners, CDN credentials, S3 providers, reconciliation
schedules, and project-specific quota overrides are not shown until their later
capabilities exist.

### 5.2 Fixed product and security rules

These are contracts, not administrator preferences, and must not be presented
as toggles:

- [x] Anyone possessing a permanent public link can read its file without
  signing in; every attachment surface states this plainly.
- [x] One canonical permanent URL is used for both internal rendering and
  external sharing. Media sources, **Open**, **Copy link**, browser save,
  and agent responses must not substitute an authenticated preview, signed,
  local-path, provider, or thumbnail URL.
- [x] Public IDs are cryptographically unguessable and never expose sequential
  database IDs, local paths, or provider URLs.
- [x] No uploaded content executes under the Syndicatum application origin.
  HTML, SVG, and other active formats are either excluded by the administrator's
  allowed-type selection or forced to download under a safe content type.
- [x] Every accepted type is server-inspected. Browser filenames, extensions,
  and MIME claims are never trusted as the safety decision.
- [x] Ordinary uploads receive a new immutable public ID. When a filename
  already exists in the selected folder, Helper confirms replacement before
  any bytes are sent; the confirmed replacement preserves that public ID.
- [x] Deletion invalidates public access immediately, returns a clear unavailable
  response, and retains the approved metadata tombstone/audit evidence.
- [x] Public IDs cannot be regenerated. A file's generated canonical URL is
  permanent for the lifetime of the file record.
- [x] Every active human and agent project participant can upload, move, rename,
  download, copy the public link for, and delete every file in that
  project. Every operation records the actor and immutable audit evidence.
  External integration participants receive no file-management authority
  unless a later explicit capability is approved.
- [x] V1 uses local storage and origin delivery. It has no CDN dependency and
  makes no claim that an unscanned file is malware-free.
- [x] Deliver the project **Files** management modal first, then timeline message
  attachments. Task, activity, deliverable, and project-detail attachments are
  follow-up scope and do not block initial V1 acceptance.
- [x] Syndicatum storage is deliberately simple team storage. Projects requiring
  restricted team permissions or secure document management use Google Drive
  or another dedicated service and link it from Syndicatum.

- [x] Record the approved settings, defaults, ranges, and fixed rules in the
  storage proposal.
- [x] Exclude public-link regeneration permanently; use one immutable canonical
  URL for internal and external use.

**Exit evidence:** owner-approved global setting catalog and fixed rules are
recorded in the proposal, followed by an authoritative Syndicatum project-plan
proposal for implementation. Gate A was approved October 3, 2026.

## 6. Gate B — Storage, schema, and service contracts

### 6.1 Schema and migration

- [x] Add a forward-only migration for canonical `project_files` metadata.
- [x] Store project ownership, unguessable public ID, storage driver, provider-
  neutral storage key, original/display name, server-verified MIME type, byte
  size, SHA-256 digest, uploader participant, timestamps, and deletion state.
- [x] Add unique constraints for public IDs and provider/storage keys.
- [x] Add indexes for project listing, creation order, deletion state, and
  integrity/reconciliation operations.
- [x] Model attachments with explicit project-qualified foreign-key integrity;
  do not copy storage paths or provider URLs into messages, tasks, activities,
  deliverables, or project records.
- [x] Preserve immutable authorship and audit evidence for upload,
  same-name replacement, move, and deletion events.
- [x] Add the new durable tables and columns to backup metadata, baseline drift
  checks, clean-install schema, migration reconciliation, and package tests.
- [x] Verify clean MySQL 8.4 installation and supported MySQL 5.7-to-8.4
  migration paths.

### 6.2 Provider-neutral storage service

- [x] Define a `FileStorage` contract for streaming writes, reads, existence,
  size, checksum, deletion, and provider-to-provider copy/migration.
- [x] Implement the local driver using only generated provider-neutral keys;
  never use the original filename as a physical path.
- [x] Keep temporary and final objects inside the validated storage root and
  prevent traversal, separator injection, symlink escape, and path aliasing.
- [x] Stream uploads while enforcing the byte limit and calculating SHA-256;
  do not load complete files into PHP memory.
- [x] Inspect content using server-side bytes rather than trusting browser
  filename extensions or `Content-Type` headers.
- [x] Write to a private temporary object and atomically publish the final local
  object only after validation succeeds.
- [x] Define compensation for filesystem-success/database-failure and database-
  success/filesystem-failure cases without automatically replaying an
  uncertain mutation.
- [x] Return storage keys and canonical metadata internally; never return an
  absolute server path to a browser or agent.
- [ ] Add test doubles that exercise the same interface used by the local and
  future S3 drivers.

### 6.3 Authorization and service layer

- [x] Centralize project-file authorization and cross-project concealment in a
  dedicated service rather than duplicating it across routes.
- [x] Validate that every uploader and file target is an active participant
  or project record in the same project.
- [x] Make create, upload, confirmed same-name replacement, move, and delete operations audited and
  idempotent where retries are supported.
- [x] Use optimistic versions for mutable metadata and replacement state.
- [x] Define safe reconciliation states for pending, available, unavailable,
  deleted, and integrity-failed objects.

**Exit evidence:** migration, schema, storage-service, authorization, isolation,
streaming, traversal, failure-compensation, and package-contract tests pass.

## 7. Gate C — Upload API and permanent public delivery

### 7.1 Permissioned project API

- [x] Add project-qualified upload and list routes using stable project public
  identifiers where the current API contract requires them.
- [x] Add metadata rename, move, confirmed same-name replacement, and deletion
  routes in V1. Do not expose a public-link regeneration route.
- [x] Require the existing human session/CSRF boundary or project-agent bearer
  boundary as appropriate; never accept caller-supplied uploader identity.
- [x] Parse multipart uploads with explicit limits before mutation and reject
  missing, empty, oversized, truncated, or malformed files visibly.
- [x] Support a stable idempotency key for upload mutations and reconcile an
  uncertain result before any replay.
- [x] Return canonical metadata and a Syndicatum-owned public URL, never the
  local path or a provider URL.
- [ ] Add pagination and bounded filters for project file lists.
- [x] Add consistent error codes without leaking another project's file
  existence.
- [x] Add request and response schemas to OpenAPI and the Project API guide.

### 7.2 Public read route

- [x] Add unauthenticated `GET` and `HEAD` handling for the immutable,
  rename-safe `/files/{public_id}` route.
- [x] Use that exact canonical route for rendered image sources and supported
  document previews as well as externally shared links. Right-clicking a
  rendered image and copying its address must produce the intended permanent
  public URL.
- [x] Resolve only the opaque public ID; display names never select a physical
  file or change authorization.
- [x] Return a clear unavailable response for deleted or invalid links
  without exposing storage internals.
- [x] Stream local bytes and support safe single-range requests for PDF and video
  usability.
- [x] Set verified `Content-Type`, safe `Content-Disposition`,
  `X-Content-Type-Options: nosniff`, search-engine exclusion, ETag/checksum, and
  the approved cache policy.
- [x] Force potentially active or unknown formats to download; do not render
  HTML or SVG under the Syndicatum origin.
- [x] Normalize response filenames safely against control characters, header
  injection, invalid Unicode, and platform-specific separators.
- [x] Disable directory listing and verify that storage-root files cannot be
  fetched directly through the web server.
- [x] Ensure public IDs are generated with cryptographically secure entropy and
  cannot be enumerated from adjacent records.

### 7.3 Security acceptance

- [x] Test traversal, double encoding, malformed IDs, filename confusion,
  symlinks, MIME spoofing, polyglots, oversized bodies, partial uploads,
  duplicate submission, cross-project mutation, and unauthorized deletion.
- [x] Confirm public delivery does not set application session state or reveal
  owner, project, uploader, internal IDs, paths, or provider credentials.
- [x] Confirm server and application logs avoid file bodies, credentials, and
  unnecessary sensitive metadata.

**Exit evidence:** an authorized browser client can upload a file and an
anonymous clean browser session can read its permanent URL; API, security, and
OpenAPI contract suites pass.

## 8. Gate D — Product surfaces and agent workflow

### 8.1 Reusable Helper attachment component

- [x] Inspect Helper's complete upload/attachment components and supported
  loading, progress, validation, cancellation, preview, and error APIs before
  adding application-specific UI.
- [x] Coordinate a shared Helper enhancement only for a verified component gap.
- [x] Open attachment modals immediately in their canonical loading state and
  load target metadata afterward.
- [x] Validate applicable fields and files before entering busy state or making
  a mutation.
- [x] Keep invalid modals open, preserve chosen files/entered labels, focus the
  first invalid field, and present multiple errors using the required
  structured summary.
- [x] Permit cancellation during read-only loading, abort where supported, and
  ignore late responses after dismissal or target changes.
- [x] Show per-file progress, success, failure, retry guidance, and an explicit
  uncertain-outcome state without automatic mutation replay.
- [ ] Display **Anyone with this link can access the file** wherever a public
  link can be copied or opened.

### 8.2 Project Files management modal

- [x] Add a project-level **Files** action that opens the canonical modal
  immediately in its loading state, then loads the first file page.
- [ ] List filename, type, size, uploader, creation/update time, and availability
  with bounded pagination, search, sort, loading skeletons, empty state, and
  recoverable error state.
- [x] Let every active human and agent participant upload, copy, move, download,
  rename, and delete files in that project.
- [x] Require Helper confirmation before a same-name replacement sends bytes,
  and confirmation before deletion. Replacement preserves the immutable URL.
- [x] Record the actor, file, action, prior/current identifiers or metadata as
  appropriate, and timestamp for every mutation without logging file bodies or
  local paths.
- [x] Open supported images and video in Helper's Media Viewer, PDFs in Helper's
  PDF Viewer, and JSON/Markdown/CSV in their complete bounded Helper viewers
  from the filename. Audio-player integration remains a separate media slice;
  files without an assigned viewer open in a new browser tab.
- [x] Set rendered media sources and copy/open actions to the same canonical
  public URL; do not introduce an internal-only preview or thumbnail URL.
- [x] Reconcile remote file changes through the established realtime/refresh
  model without silently discarding an in-progress local action.

Foundation update (October 3, 2026): the project action opens a large canonical
loading modal and mounts Helper's complete tree, grid, form-modal, and uploader
components. **Create folder** and **Upload files** use the participant-authorized,
CSRF-protected, idempotent mutation service. File rows now provide **Copy link**,
**Move to**, **Download**, **Rename**, and **Delete**, and the immutable public
delivery route is active.

### 8.3 Timeline message attachments

- [x] Attach files during message composition without sending a message before
  all selected attachment records are confirmed.
- [x] Define and test cleanup behavior when uploads succeed but message creation
  is cancelled or fails.
- [x] Render compact attachment metadata; inline only supported image, audio,
  and video media; and provide **Open in new tab** or **Download** for all other
  files.
- [x] Set rendered media sources and copy/open actions to the same canonical
  public URL; do not introduce an internal-only preview or thumbnail URL.
- [x] Preserve reply, revision, deletion, pagination, virtualization, and
  realtime reconciliation behavior.

Delivery update (October 4, 2026): Helper `0.21.229` supplies the complete
repository picker and composer's custom attachment adapter. Syndicatum opens the
picker immediately in its loading state, supports cross-folder multi-selection,
uploads into the current folder through the existing chunked transport, and
uses visible breadcrumbs plus a folder-first icon row list. File selection is
shown through a whole-row highlight rather than checkboxes or a duplicate
selected-file list. Reload and Upload are borderless icon actions in the modal
header. Escape clears selections retained across folders on its first press and
closes the picker when no selection remains; Cancel and Close dismiss directly.
On narrow mobile layouts, short folders use the available modal body without an
artificial inner scrollbar; genuinely long folders scroll in the modal body
while the header and footer remain fixed.
Confirmed uploads use Helper's canonical success toast without inserting an
inline success row; actionable upload failures remain visible inside the picker.
The message composer places a compact borderless paperclip and visible
**Attach files** label below the textarea in the same responsive row as its
delivery and keyboard instructions.
The picker renders selected files with Helper's upload
queue. Message creation reauthorizes
up to 20 same-project file IDs and commits the message and ordered associations
atomically. Cancelling or failing a message does not delete already-confirmed
canonical uploads; the files remain available in Project Files.
Timeline messages render available image and video attachments first through
Helper's wrapping media strip and shared gallery viewer. Other file types and
unavailable media follow as compact file cards. The media-strip lifecycle is
bound to the virtualized message card so rerendering cannot leave hidden viewers.

### 8.4 Deferred attachment surfaces

- [ ] In Phase 2, support attachment upload and display on task records.
- [ ] In Phase 2, support attachment upload and display on immutable task activity/evidence
  records without weakening lifecycle authorization.
- [ ] In Phase 2, support attachment upload and display on deliverables while retaining the
  existing external `artifact_url` compatibility boundary until migration is
  explicitly approved.
- [ ] In Phase 2, support attachment upload and display for project description/instruction
  resources without embedding binary data into those text fields.
- [ ] Ensure removal from one surface does not delete a canonical file still
  referenced by another surface.
- [ ] Provide accessible preview labels, keyboard operation, loading skeletons,
  empty states, and responsive layouts.

### 8.5 Agent access and skills

- [x] Add permissioned Project API/MCP tools for upload, file lookup, and
  attachment association using streams or supported content transfer—not local
  filesystem paths from an agent machine.
- [x] Bound encoded or multipart agent uploads so transport expansion cannot
  bypass the server byte limit.
- [x] Update installed Syndicatum skills with the public-link security model,
  supported targets, idempotency guidance, and no-path/no-credential rules.
- [x] Ensure agents can return the permanent Syndicatum URL and canonical file
  metadata after confirmed upload; it must be the same URL the human UI renders.
- [ ] Verify at least one human upload and one agent upload end to end.

**Exit evidence:** browser and agent acceptance passes for the project Files
modal and timeline attachments, including accessibility, responsive behavior,
cancellation, partial failure, and uncertain-outcome recovery.

## 9. Gate E — Backup, restore, and recovery completeness

### 9.1 Backup production

- [ ] Extend the canonical backup producer to include all referenced local file
  objects in addition to MySQL and existing persistent assets.
- [ ] Add a file-storage manifest containing provider-neutral key, size, MIME
  type, and SHA-256 for every archived object.
- [ ] Include only non-secret storage interpretation metadata; never archive
  provider credentials, tokens, or host-specific secrets.
- [ ] Stream file content into the archive without requiring a second complete
  storage copy in temporary staging.
- [ ] Define and implement a maintenance lock or consistent-snapshot sequence so
  database records and file bytes cannot drift during capture.
- [ ] Fail backup clearly on missing, changing, unreadable, unexpected, or
  checksum-mismatched referenced content.
- [ ] Preserve backward restore support for valid pre-file-storage backups.

### 9.2 Staged restore

- [ ] Extend protected restore inspection to validate storage manifest paths,
  roles, counts, sizes, and checksums before extraction or database cutover.
- [ ] Stage restored file bytes privately and prevent archive traversal,
  symlink, executable-placement, duplicate-path, and decompression attacks.
- [ ] Restore bytes into the selected target provider and verify them before
  presenting recovery as complete.
- [ ] Preserve every canonical public ID, association, and permanent URL.
- [ ] Keep a partial or failed file restore staged; do not label the combined
  database/file recovery successful.
- [ ] Include file-storage verification and cutover evidence in the recovery
  report and audit history.
- [ ] Exercise a complete backup/restore round trip in the supported Linux
  Docker/POSIX recovery environment and confirm the original public links work.
- [ ] Exercise Windows backup creation using the configured local storage root.

### 9.3 Operational recovery cases

- [ ] Test missing object, corrupt bytes, checksum mismatch, insufficient space,
  unwritable target, interrupted stream, changed storage root, and repeated
  inspection/cutover attempts.
- [ ] Document operator recovery for storage-root loss and for moving a restored
  installation to a different absolute local path.
- [ ] Update the backup eligibility and delivery-health signals so an
  unprotected or unhealthy file store cannot be mistaken for a complete backup.

**Exit evidence:** a verified backup restores MySQL and file content together,
all manifest checks pass, and pre-restore permanent URLs return identical bytes
after cutover.

## 10. Gate F — Documentation, packaging, and release acceptance

- [x] Update application surfaces, architecture inventory, Project API,
  OpenAPI, backup/restore, deployment, security, and operations documentation.
- [x] Update the in-app User Guide for upload, sharing, deletion/same-name replacement,
  public-link risk, administrator storage setup, and recovery.
- [x] Add every new runtime source, route, migration, asset, skill, and document
  to canonical release packaging.
- [x] Verify local-file directories and content are never included in source or
  release packages by accident.
- [ ] Add focused unit, schema, API, browser surface, security, backup, restore,
  package, Windows, and Linux Docker acceptance coverage.
- [ ] Run the complete repository CI matrix, including clean install, migration,
  canonical release, webroot access, MySQL compatibility, encrypted backup
  round trip, and Docker source acceptance.
- [x] Request Commercial Assessor review of the user promise, public-link copy,
  limits, and packaging implications.
- [x] Reconcile the implementation against every acceptance criterion in the
  proposal and record any deliberate exception for owner approval.
- [ ] Deploy only after owner approval; then verify the configured production
  storage root, permissions, free space, anonymous public read, authenticated
  upload, and backup inclusion.

### V1 final acceptance

- [x] An authorized human uploads a file and receives a permanent Syndicatum
  URL.
- [ ] An authorized agent uploads a file and receives the same canonical
  metadata and URL model.
- [ ] The URL opens without authentication in a clean browser session.
- [ ] Right-clicking an internally rendered image and choosing **Copy image
  address** yields that same URL, which opens outside Syndicatum without
  authentication.
- [ ] The URL survives logout, restart, deployment, and complete backup/restore.
- [x] Supported images and video render in Helper's Media Viewer, PDFs in
  Helper's PDF Viewer, and JSON/Markdown/CSV in their dedicated safe viewers.
  Other files open in a new tab/window or download; active/unknown formats
  cannot execute under the application origin.
- [x] Every active human and agent project participant can upload, move, rename,
  download, copy links, and delete project files, with attributable audit
  evidence for every action.
- [x] Files cannot be enumerated through directory listing or predictable IDs.
- [x] Cross-project listing, attachment, replacement, and deletion fail safely.
- [x] Deletion makes the old URL clearly unavailable and retains the approved
  audit/tombstone evidence.
- [ ] Complete backup and restore verify counts, sizes, and checksums for both
  metadata and content.
- [ ] Restored URLs are byte-for-byte and character-for-character identical to
  the originals.
- [x] Documentation plainly states that anyone with the link can access the
  file.

## 11. Phase 2 — Operational hardening after local-storage V1

- [ ] Add administrator storage reporting and optional project-specific quota
  overrides; retain the V1 global default of 64 GB per project.
- [ ] Add report-first orphan reconciliation and explicit cleanup approval.
- [ ] Add scheduled checksum/integrity verification and safe repair guidance.
- [ ] Add optimized Nginx `X-Accel-Redirect` delivery on supported Linux
  deployments without changing URLs.
- [ ] Complete replacement and deletion safeguards deferred from V1, if any.
- [ ] Extend attachments to tasks, task activity, deliverables, and project
  details.
- [ ] Exercise large, concurrent, and interrupted file sets through upload,
  delivery, backup, and restore.
- [ ] Evaluate optional malware-scanner integration through the storage
  interface.
- [ ] Define CDN enablement and cache-purge operations only if measured traffic
  justifies them.

## 12. Phase 3 — S3-compatible progression

- [ ] Add controlled S3-compatible provider settings and protected credentials.
- [ ] Implement the S3 driver behind the existing `FileStorage` contract.
- [ ] Support Garage, SeaweedFS, MinIO, Amazon S3, and other compatible providers
  only to the extent verified by a provider contract suite.
- [ ] Add resumable local-to-S3 migration with per-object copy, size/checksum
  verification, audit reporting, and safe retry.
- [ ] Update a canonical file record to S3 only after its destination object is
  verified; keep the local copy until operator confirmation.
- [ ] Keep failed objects readable from their previous provider.
- [ ] Preserve all public IDs, URLs, and attachment associations.
- [ ] Extend backup/restore acceptance to S3-backed installations without
  assuming the external bucket will remain available.

## 13. Owner approval record

Gate A was approved by the project owner on October 3, 2026:

- 25 MiB maximum file size and 10 files per action;
- a 64 GB default storage quota for each project;
- one canonical permanent URL for internal and external use;
- immutable public IDs with no regeneration action;
- inline rendering limited to safe media plus the dedicated PDF Viewer policy;
- equal audited file-management authority for every active human and agent
  project participant;
- project Files management modal first, followed by timeline attachments; and
- conversion of the approved checklist into formal Syndicatum milestones and
  deliverables before engineering tasks are created.

