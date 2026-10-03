# V1 Project File Storage Implementation Checklist

Status: Draft for project-owner review
Prepared: October 3, 2026
Source: [V1 Project File Storage Proposal](v1-project-file-storage-proposal.md)

> No storage feature implementation is authorized by this checklist yet. The
> project owner must approve the checklist and the decisions in Gate A before
> work proceeds beyond the already completed configuration foundation.

## 1. Outcome and scope

V1 provides ordinary, durable project file sharing:

- file bytes are stored under the installation's configured private local
  storage root;
- canonical metadata and project associations are stored in MySQL;
- Syndicatum issues permanent, unguessable public URLs that do not require
  authentication;
- images and PDFs render safely where supported, while other files download;
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

### 5.1 Recommended global settings

| Controlled setting | User-facing field | Recommended default | Required guardrail | Approval |
| --- | --- | --- | --- | --- |
| `storage.local_base_path` | Storage location | No portable default; administrator must supply an absolute server path | Private, writable, and outside the public application root | [x] Implemented |
| `storage.max_upload_bytes` | Maximum file size | 25 MiB | Integer range with a server hard ceiling; enforced while streaming before storage is committed | [ ] |
| `storage.max_files_per_action` | Maximum files per action | 10 | Integer range 1–50; enforced by browser, human API, and agent API | [ ] |
| `storage.allowed_content_types` | Allowed file types | All server-supported downloadable types | Canonical multi-select/presets only; administrators may narrow the server allowlist but cannot add unsupported executable types | [ ] |
| `storage.inline_preview_types` | Inline preview types | PNG, JPEG, GIF, WebP, PDF, and plain text | Administrators may select a subset only; the hard safe-preview allowlist cannot be expanded from Settings | [ ] |
| `storage.installation_quota_bytes` | Installation storage quota | Unlimited (`0`) | Nonzero values must exceed the per-file maximum and are enforced before committing an upload | [ ] |
| `storage.default_project_quota_bytes` | Default project storage quota | Unlimited (`0`) | Nonzero values must exceed the per-file maximum; project usage is calculated from canonical live file records | [ ] |
| `storage.deleted_content_retention_days` | Deleted-content retention | 0 days | Public access ends immediately regardless of byte-retention period; metadata tombstones and audit evidence remain | [ ] |
| `storage.public_cache_max_age_seconds` | Public file cache duration | 300 seconds | Bounded nonnegative duration; replacement, deletion, and link regeneration must use the approved cache invalidation behavior | [ ] |

- [ ] Add these fields to the existing canonical Storage tab, grouped into
  **Location**, **Uploads**, **Capacity**, and **Delivery** sections.
- [ ] Load the modal before settings data, retain canonical busy/loading
  behavior, and disable only controls whose data or authorization is not ready.
- [ ] Validate all applicable fields before busy state or requests. Cross-field
  errors—such as a quota below the maximum file size—must appear beside the
  fields and in the structured multi-error summary.
- [ ] Store byte values canonically while presenting ordinary MiB/GiB units to
  administrators.
- [ ] Lock environment-overridden settings visibly and omit them from browser
  mutations.
- [ ] Audit changes without recording file content, private paths beyond the
  setting value already authorized for administration, or other secrets.
- [ ] Apply one backend policy service to human, agent, and future provider
  upload paths so settings cannot be bypassed.

Settings for malware scanners, CDN credentials, S3 providers, reconciliation
schedules, and project-specific quota overrides are not shown until their later
capabilities exist.

### 5.2 Fixed product and security rules

These are contracts, not administrator preferences, and must not be presented
as toggles:

- [ ] Anyone possessing a permanent public link can read its file without
  signing in; every attachment surface states this plainly.
- [ ] Public IDs are cryptographically unguessable and never expose sequential
  database IDs, local paths, or provider URLs.
- [ ] No uploaded content executes under the Syndicatum application origin.
  HTML, SVG, and other active formats are either excluded by the administrator's
  allowed-type selection or forced to download under a safe content type.
- [ ] Every accepted type is server-inspected. Browser filenames, extensions,
  and MIME claims are never trusted as the safety decision.
- [ ] Ordinary uploads receive a new public ID. A URL is preserved only through
  an explicit **Replace published file** action.
- [ ] Deletion invalidates public access immediately, returns a clear unavailable
  response, and retains the approved metadata tombstone/audit evidence.
- [ ] Link regeneration is an explicit confirmed action that creates a new
  public ID and invalidates the former link.
- [ ] Active participants may upload only where they already have authority to
  create or update the target record. The uploader and project owner/admin may
  manage the canonical file subject to reference and lifecycle safeguards.
- [ ] V1 uses local storage and origin delivery. It has no CDN dependency and
  makes no claim that an unscanned file is malware-free.
- [ ] Deliver reusable attachments in order: timeline messages; tasks and task
  activity; deliverables; project description/instructions. All remain required
  before V1 is complete.

- [ ] Record the approved settings, defaults, ranges, and fixed rules in the
  storage proposal.
- [ ] Confirm whether public-link regeneration is included in V1 or moved to
  Phase 2 while preserving the fixed rule for its eventual behavior.

**Exit evidence:** owner-approved global setting catalog and fixed rules are
recorded in the proposal, followed by an authoritative Syndicatum project-plan
proposal for implementation.

## 6. Gate B — Storage, schema, and service contracts

### 6.1 Schema and migration

- [ ] Add a forward-only migration for canonical `project_files` metadata.
- [ ] Store project ownership, unguessable public ID, storage driver, provider-
  neutral storage key, original/display name, server-verified MIME type, byte
  size, SHA-256 digest, uploader participant, timestamps, and deletion state.
- [ ] Add unique constraints for public IDs and provider/storage keys.
- [ ] Add indexes for project listing, creation order, deletion state, and
  integrity/reconciliation operations.
- [ ] Model attachments with explicit project-qualified foreign-key integrity;
  do not copy storage paths or provider URLs into messages, tasks, activities,
  deliverables, or project records.
- [ ] Preserve immutable authorship and audit evidence for attachment,
  replacement, deletion, and link-regeneration events.
- [ ] Add the new durable tables and columns to backup metadata, baseline drift
  checks, clean-install schema, migration reconciliation, and package tests.
- [ ] Verify clean MySQL 8.4 installation and supported MySQL 5.7-to-8.4
  migration paths.

### 6.2 Provider-neutral storage service

- [ ] Define a `FileStorage` contract for streaming writes, reads, existence,
  size, checksum, deletion, and provider-to-provider copy/migration.
- [ ] Implement the local driver using only generated provider-neutral keys;
  never use the original filename as a physical path.
- [ ] Keep temporary and final objects inside the validated storage root and
  prevent traversal, separator injection, symlink escape, and path aliasing.
- [ ] Stream uploads while enforcing the byte limit and calculating SHA-256;
  do not load complete files into PHP memory.
- [ ] Inspect content using server-side bytes rather than trusting browser
  filename extensions or `Content-Type` headers.
- [ ] Write to a private temporary object and atomically publish the final local
  object only after validation succeeds.
- [ ] Define compensation for filesystem-success/database-failure and database-
  success/filesystem-failure cases without automatically replaying an
  uncertain mutation.
- [ ] Return storage keys and canonical metadata internally; never return an
  absolute server path to a browser or agent.
- [ ] Add test doubles that exercise the same interface used by the local and
  future S3 drivers.

### 6.3 Authorization and service layer

- [ ] Centralize project-file authorization and cross-project concealment in a
  dedicated service rather than duplicating it across routes.
- [ ] Validate that every uploader and attachment target is an active participant
  or project record in the same project.
- [ ] Make create, attach, replace, delete, and regenerate operations audited and
  idempotent where retries are supported.
- [ ] Use optimistic versions for mutable metadata and replacement state.
- [ ] Define safe reconciliation states for pending, available, unavailable,
  deleted, and integrity-failed objects.

**Exit evidence:** migration, schema, storage-service, authorization, isolation,
streaming, traversal, failure-compensation, and package-contract tests pass.

## 7. Gate C — Upload API and permanent public delivery

### 7.1 Permissioned project API

- [ ] Add project-qualified upload and list routes using stable project public
  identifiers where the current API contract requires them.
- [ ] Add metadata rename, explicit replacement, deletion, and—if approved for
  V1—public-link regeneration routes.
- [ ] Require the existing human session/CSRF boundary or project-agent bearer
  boundary as appropriate; never accept caller-supplied uploader identity.
- [ ] Parse multipart uploads with explicit limits before mutation and reject
  missing, empty, oversized, truncated, or malformed files visibly.
- [ ] Support a stable idempotency key for upload mutations and reconcile an
  uncertain result before any replay.
- [ ] Return canonical metadata and a Syndicatum-owned public URL, never the
  local path or a provider URL.
- [ ] Add pagination and bounded filters for project file lists.
- [ ] Add consistent error codes without leaking another project's file
  existence.
- [ ] Add request and response schemas to OpenAPI and the Project API guide.

### 7.2 Public read route

- [ ] Add `GET` and `HEAD` handling for
  `/files/{public_id}/{cosmetic_filename}` without authentication.
- [ ] Resolve only the opaque public ID; the filename segment must not select a
  physical file or change authorization.
- [ ] Return a clear unavailable response for deleted or invalidated links
  without exposing storage internals.
- [ ] Stream local bytes and support safe range requests where required for PDF
  usability.
- [ ] Set verified `Content-Type`, safe `Content-Disposition`,
  `X-Content-Type-Options: nosniff`, search-engine exclusion, ETag/checksum, and
  the approved cache policy.
- [ ] Force potentially active or unknown formats to download; do not render
  HTML or SVG under the Syndicatum origin.
- [ ] Normalize response filenames safely against control characters, header
  injection, invalid Unicode, and platform-specific separators.
- [ ] Disable directory listing and verify that storage-root files cannot be
  fetched directly through the web server.
- [ ] Ensure public IDs are generated with cryptographically secure entropy and
  cannot be enumerated from adjacent records.

### 7.3 Security acceptance

- [ ] Test traversal, double encoding, malformed IDs, filename confusion,
  symlinks, MIME spoofing, polyglots, oversized bodies, partial uploads,
  duplicate submission, cross-project mutation, and unauthorized deletion.
- [ ] Confirm public delivery does not set application session state or reveal
  owner, project, uploader, internal IDs, paths, or provider credentials.
- [ ] Confirm server and application logs avoid file bodies, credentials, and
  unnecessary sensitive metadata.

**Exit evidence:** an authorized browser client can upload a file and an
anonymous clean browser session can read its permanent URL; API, security, and
OpenAPI contract suites pass.

## 8. Gate D — Product surfaces and agent workflow

### 8.1 Reusable Helper attachment component

- [ ] Inspect Helper's complete upload/attachment components and supported
  loading, progress, validation, cancellation, preview, and error APIs before
  adding application-specific UI.
- [ ] Coordinate a shared Helper enhancement only for a verified component gap.
- [ ] Open attachment modals immediately in their canonical loading state and
  load target metadata afterward.
- [ ] Validate applicable fields and files before entering busy state or making
  a mutation.
- [ ] Keep invalid modals open, preserve chosen files/entered labels, focus the
  first invalid field, and present multiple errors using the required
  structured summary.
- [ ] Permit cancellation during read-only loading, abort where supported, and
  ignore late responses after dismissal or target changes.
- [ ] Show per-file progress, success, failure, retry guidance, and an explicit
  uncertain-outcome state without automatic mutation replay.
- [ ] Display **Anyone with this link can access the file** wherever a public
  link can be copied or opened.

### 8.2 Timeline messages

- [ ] Attach files during message composition without sending a message before
  all selected attachment records are confirmed.
- [ ] Define and test cleanup behavior when uploads succeed but message creation
  is cancelled or fails.
- [ ] Render compact attachment metadata, safe inline image/PDF preview, copy-
  link, and download actions in timeline cards and message detail.
- [ ] Preserve reply, revision, deletion, pagination, virtualization, and
  realtime reconciliation behavior.

### 8.3 Tasks, task activity, deliverables, and project details

- [ ] Support attachment upload and display on task records.
- [ ] Support attachment upload and display on immutable task activity/evidence
  records without weakening lifecycle authorization.
- [ ] Support attachment upload and display on deliverables while retaining the
  existing external `artifact_url` compatibility boundary until migration is
  explicitly approved.
- [ ] Support attachment upload and display for project description/instruction
  resources without embedding binary data into those text fields.
- [ ] Ensure removal from one surface does not delete a canonical file still
  referenced by another surface.
- [ ] Provide accessible preview labels, keyboard operation, loading skeletons,
  empty states, and responsive layouts.

### 8.4 Agent access and skills

- [ ] Add permissioned Project API/MCP tools for upload, file lookup, and
  attachment association using streams or supported content transfer—not local
  filesystem paths from an agent machine.
- [ ] Bound encoded or multipart agent uploads so transport expansion cannot
  bypass the server byte limit.
- [ ] Update installed Syndicatum skills with the public-link security model,
  supported targets, idempotency guidance, and no-path/no-credential rules.
- [ ] Ensure agents can return the permanent Syndicatum URL and canonical file
  metadata after confirmed upload.
- [ ] Verify at least one human upload and one agent upload end to end.

**Exit evidence:** browser and agent acceptance passes for every required
surface, including accessibility, responsive behavior, cancellation, partial
failure, and uncertain-outcome recovery.

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

- [ ] Update application surfaces, architecture inventory, Project API,
  OpenAPI, backup/restore, deployment, security, and operations documentation.
- [ ] Update the in-app User Guide for upload, sharing, deletion/replacement,
  public-link risk, administrator storage setup, and recovery.
- [ ] Add every new runtime source, route, migration, asset, skill, and document
  to canonical release packaging.
- [ ] Verify local-file directories and content are never included in source or
  release packages by accident.
- [ ] Add focused unit, schema, API, browser surface, security, backup, restore,
  package, Windows, and Linux Docker acceptance coverage.
- [ ] Run the complete repository CI matrix, including clean install, migration,
  canonical release, webroot access, MySQL compatibility, encrypted backup
  round trip, and Docker source acceptance.
- [ ] Request Commercial Assessor review of the user promise, public-link copy,
  limits, and packaging implications.
- [ ] Reconcile the implementation against every acceptance criterion in the
  proposal and record any deliberate exception for owner approval.
- [ ] Deploy only after owner approval; then verify the configured production
  storage root, permissions, free space, anonymous public read, authenticated
  upload, and backup inclusion.

### V1 final acceptance

- [ ] An authorized human uploads a file and receives a permanent Syndicatum
  URL.
- [ ] An authorized agent uploads a file and receives the same canonical
  metadata and URL model.
- [ ] The URL opens without authentication in a clean browser session.
- [ ] The URL survives logout, restart, deployment, and complete backup/restore.
- [ ] Supported images and PDFs render inline; active/unknown formats cannot
  execute under the application origin.
- [ ] Files cannot be enumerated through directory listing or predictable IDs.
- [ ] Cross-project listing, attachment, replacement, and deletion fail safely.
- [ ] Deletion makes the old URL clearly unavailable and retains the approved
  audit/tombstone evidence.
- [ ] Complete backup and restore verify counts, sizes, and checksums for both
  metadata and content.
- [ ] Restored URLs are byte-for-byte and character-for-character identical to
  the originals.
- [ ] Documentation plainly states that anyone with the link can access the
  file.

## 11. Phase 2 — Operational hardening after local-storage V1

- [ ] Add administrator storage reporting and optional per-project quota
  overrides; retain the V1 installation quota and default-project quota.
- [ ] Add report-first orphan reconciliation and explicit cleanup approval.
- [ ] Add scheduled checksum/integrity verification and safe repair guidance.
- [ ] Add optimized Nginx `X-Accel-Redirect` delivery on supported Linux
  deployments without changing URLs.
- [ ] Complete replacement, deletion, and link-regeneration safeguards deferred
  from V1, if any.
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

## 13. Review questions for the project owner

1. Do you approve the proposed global setting catalog, validation rules, and
   defaults—particularly 25 MiB per file and 10 files per action?
2. Do you approve unlimited-by-default installation and project quotas, with
   immediate enforcement whenever an administrator sets a nonzero value?
3. Should HTML and SVG be included among supported downloads but always forced
   to download, or omitted from the default allowed-type selection entirely?
4. Should public-link regeneration ship in V1, or should it remain Phase 2 while
   deletion and explicit replacement ship first?
5. Do you approve the fixed authority and surface-delivery rules in section 5.2?
6. After approval, should this checklist be translated into formal Syndicatum
   milestones and deliverables for owner approval before engineering tasks are
   created?

