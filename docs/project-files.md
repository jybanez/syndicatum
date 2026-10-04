# Project files and message attachments

Syndicatum can store files on the installation’s local filesystem, organize them
by project and folder, and attach them to timeline messages. This is project file
sharing, not a general document-management system or a confidential repository.
This guide describes main at `35a1cb5`; release and environment acceptance still
apply. See [Application surfaces](application-surfaces.md) and the
[Project API](project-api-v1.md) for UI and integration contracts.

## Access and sharing

Every active human and agent project participant can manage the project’s files,
subject to server authorization and project state. Management and listing require
authentication. Each available file also has one canonical `files/{public_id}`
URL that serves its bytes without authentication: **anyone with this link can
access the file**. Project membership does not restrict readers of that URL.

Use a service with appropriate access controls for confidential material. The
optional project Google Drive folder reference is separate from hosted Project
Files: it guides placement only when the participant already has authorized
external access. It neither grants access nor syncs or imports the folder’s
contents. Attaching a hosted file does not change external document permissions.

## Organize, attach, and view

1. Open the folder action beside the project menu to enter **Project Files**.
   Select a folder in the tree, create a subfolder with the plus action, or use
   **Upload files** to add a batch within the configured limit. The file grid
   searches and sorts on the server and retrieves a bounded page at a time.
   Desktop columns expose uploader, created/modified times, and availability;
   the compact mobile row retains the essential name, size, modified time, and
   action menu while the same metadata remains in the API.
2. Use a file row’s menu to copy its link, move, download, rename, or delete it.
   Uploading the same filename into the same folder asks for confirmation before
   replacing its content. Each upload is validated independently; check the
   result of each item when a batch only partly succeeds.
3. In the message composer, open the attachment picker. Select existing files
   across folders or upload into the current folder. Sending supports up to 20
   available files from the current project. The server rechecks them and saves
   the message with its ordered attachments atomically.
4. Messages with attachments show a paperclip. Available images and videos appear
   first in a media strip; remaining or unavailable attachments appear as compact
   file cards. In Project Files, image/video filenames open the media viewer,
   PDFs the PDF viewer, and JSON, Markdown, and CSV their bounded viewers. Other
   types open the canonical URL in a new tab, where browser and delivery policy
   determine viewing or download. Upload eligibility does not guarantee a viewer
   or browser codec; there is no universal file-format support claim.

Rename, move, and confirmed replacement retain the public ID and URL. Replacement
changes the bytes obtained through existing links, including old message
attachments; those associations are not frozen document versions. Deleting a
file makes its origin URL unavailable and leaves historical attachments
unavailable. Removing an attachment or deleting a message does not itself delete
the underlying project file. No public-link regeneration action exists. Neither
deletion nor replacement recalls copies already downloaded or cached elsewhere.
Avatars are separate assets and are not selectable in the attachment picker.

## Operator setup and limits

An administrator configures **System Settings → Storage** with a dedicated,
writable absolute server directory outside the public application root. The
local provider groups objects by opaque project public ID with internal shards;
logical folders and file metadata live in MySQL. Keep the server path private.
Changing a setting is not a migration of existing bytes to a new directory.

Storage settings control maximum upload size, files per action, allowed content
types, inline preview types, per-project capacity, and public caching. Recommended
defaults are 25 MiB per file, 10 files per upload action, 64 GiB per project, and
300 seconds of public caching; use the installation’s actual configured policy.
Server MIME inspection and quota enforcement still apply. The uploader sends
ordered 1 MiB chunks; that does not bypass storage policy or all reverse-proxy,
timeout, or disk limits. Private partial sessions expire after 24 hours.

Monitor disk capacity as well as project quota: temporary uploads, backup staging,
and retained or orphaned objects can consume space beyond available-file usage.
The current provider is local storage; S3-compatible storage remains future scope.
Mutation retries must preserve idempotency and version checks; reconcile an
uncertain result before starting a replacement operation.

## Backup and recovery boundary

The Storage directory and Recovery backup directory serve different purposes.
Database file records and message associations alone cannot restore file bytes.
The current format-3 canonical backup producer inventories and streams referenced
Project File objects with provider-neutral keys, sizes, MIME types, and SHA-256
digests. Protected restore inspection authenticates that manifest and stages and
verifies the file bytes before cutover. Windows creation and Linux/MySQL 8.4
round-trip acceptance preserve the public IDs and confirm that the original
URLs return byte-identical content after restore. Older backups or a SQL dump
alone still do not protect hosted Project Files.

Operators must still verify backup eligibility and storage health before relying
on a recovery set. Recovery needs intact public IDs, storage objects, and the
correct public origin and routing for old URLs to work. Missing/corrupt objects,
unwritable targets, interrupted streams, changed storage roots, and repeated
inspection/cutover remain explicit operational acceptance cases in the
[implementation checklist](v1-project-file-storage-implementation-checklist.md#93-operational-recovery-cases).
