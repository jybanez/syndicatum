# V1 Project File Storage Proposal

Status: Proposed; configuration foundation implemented
Created: October 2, 2026  
Audience: Product, engineering, operations, security, and support

## 1. Summary

Add simple, durable project file storage to Syndicatum. The first release stores
file content on the installation's local filesystem while keeping file metadata
in MySQL. A provider-neutral storage interface permits a later move to
S3-compatible storage without changing database references or public URLs.

Files are intended for dependable, low-friction sharing. Every stored file
receives a permanent, unguessable public Syndicatum URL that works without
authentication until an authorized user deletes the file or regenerates its
public identifier.

The configuration foundation is now present: administrators can set
`storage.local_base_path` from the **Storage** tab in System Settings. Saving
validates and prepares a private, writable absolute directory outside the
public application root. This does not yet enable uploads, file records,
previews, downloads, or permanent public links; those remain implementation
work described below.

## 2. Product principles

- Uploading a file should be as simple as attaching it to a message.
- Shared links must not expire automatically.
- Images and PDFs should render directly in supported browsers.
- Syndicatum, rather than the underlying storage location, owns the public URL.
- Local filesystem storage is the default provider.
- S3-compatible storage is an optional progression for larger installations.
- A complete backup must include both MySQL data and stored file content.
- The interface must plainly state that anyone with the link can access the
  file.

This feature is for convenient, durable sharing rather than confidential file
distribution. Projects requiring recipient authentication, fine-grained file
permissions, or regulated document controls should use a service designed for
that purpose, such as Google Drive, and link it from Syndicatum.

## 3. User experience

Authorized users and agents can attach files to:

- Timeline messages;
- tasks and task activity;
- deliverables; and
- project descriptions or instructions where applicable.

After upload, Syndicatum shows the filename, type, size, uploader, and creation
time, together with **Copy link**, **Download**, and authorized deletion or
replacement actions. Supported images and PDFs receive an inline preview.

Every file surface displays the notice:

> Anyone with this link can access the file.

An example permanent URL is:

```text
https://syndicatum.example/files/k8Px4m/board-infographic.png
```

The filename segment is cosmetic. Syndicatum resolves the opaque public ID to
the canonical file record and storage object. The URL must continue to work
after logout, application restart, deployment, backup restoration, and storage
provider migration.

## 4. Storage architecture

Introduce a `FileStorage` interface with `local` and `s3` drivers. Business
logic must address stored content through provider-neutral storage keys rather
than machine-specific absolute paths or provider URLs.

### 4.1 Local driver

The default driver stores content outside the public web root under a configured
storage root. A generated storage key may resolve to a path such as:

```text
/srv/syndicatum/files/7f/2a/7f2ad4...bin
```

The application controls key generation and placement. Original filenames must
not determine physical storage paths.

Development and Windows installations may initially stream files through the
application. Linux production installations should support Nginx
`X-Accel-Redirect` so the application resolves metadata while Nginx performs the
file transfer.

### 4.2 S3 driver

The optional S3-compatible driver supports services such as Garage, SeaweedFS,
MinIO, Amazon S3, and compatible providers. Buckets remain an implementation
detail; Syndicatum continues to issue its own permanent URLs.

Moving a file from local storage to S3 changes only its provider and storage
key. It must not change its public ID or any reference from a message, task, or
deliverable.

### 4.3 Storage interface

The initial interface should cover:

- writing a stream and returning a storage key;
- opening a stored object for reading;
- testing object existence;
- reporting object size and checksum;
- deleting an object; and
- copying or migrating an object between providers.

Upload responses return metadata and a permanent public URL. They must never
return an absolute server filesystem path.

## 5. Data model

Add a canonical project-file record with fields equivalent to:

| Field | Purpose |
| --- | --- |
| `id` | Internal identifier. |
| `project_id` | Owning project. |
| `public_id` | Cryptographically unguessable permanent-link identifier. |
| `storage_driver` | Storage provider, initially `local` or `s3`. |
| `storage_key` | Provider-neutral file location. |
| `original_name` | Filename supplied at upload. |
| `display_name` | Optional user-facing renamed label. |
| `mime_type` | Server-verified content type. |
| `size_bytes` | Stored content size. |
| `sha256` | Integrity and duplicate-detection digest. |
| `uploaded_by_participant_id` | Participant responsible for the upload. |
| `created_at` | Upload time. |
| `deleted_at` | Soft-deletion or tombstone time. |

Associations from messages, tasks, deliverables, and other records reference the
canonical file ID. They must not duplicate storage paths or provider URLs.

## 6. API outline

The initial HTTP surface should be equivalent to:

```text
POST   /api/v1/projects/{project}/files
GET    /api/v1/projects/{project}/files
PATCH  /api/v1/files/{file}
DELETE /api/v1/files/{file}
GET    /files/{public_id}/{filename}
```

Upload, mutation, and project-list operations require existing project
authorization. The permanent public read route does not require authentication.
Agent integrations receive equivalent permissioned upload and attachment
capabilities through the Project API and installed skills.

Deletion and public-ID regeneration are explicit, audited operations. Replacing
file content should preserve the public URL only when the user deliberately
chooses to replace the published file.

## 7. Rendering and delivery rules

The initial release may render the following formats inline after validating
their content type:

- PNG, JPEG, GIF, and WebP images;
- PDF; and
- plain text where appropriate.

Other formats download as attachments. Potentially active formats, including
HTML and SVG, must not execute under the Syndicatum application origin.

Responses should include appropriate content type, content disposition,
`X-Content-Type-Options: nosniff`, caching, and search-engine exclusion headers.
Directory listing is disabled, public IDs are cryptographically unguessable,
and filenames are sanitized for display and response headers.

These measures reduce accidental discovery and unsafe rendering; they do not
make possession of a public link confidential.

## 8. Backup and restore contract

A Syndicatum backup is incomplete unless it contains:

1. the MySQL database export;
2. all stored file content referenced by durable file records;
3. a manifest of storage keys, sizes, content types, and checksums; and
4. non-secret storage configuration metadata needed to interpret the archive.

Provider credentials, secret keys, and infrastructure-specific access tokens
must not be embedded in the backup.

For the local driver, backup archives the configured file-storage content. For
the S3 driver, backup streams referenced objects into the backup package rather
than assuming the external bucket will remain available.

Backup creation must use a maintenance lock or a defined consistent-snapshot
procedure so database metadata and file content cannot drift during capture.
Implementations should stream file content into the backup rather than requiring
free staging space equal to the complete storage set.

Restore must:

1. restore the database under the existing trusted restore contract;
2. restore file content into the selected storage provider;
3. verify file counts, sizes, and checksums against the manifest;
4. report missing, unexpected, or corrupt objects; and
5. preserve every existing public ID and permanent URL.

Restore succeeds only when both database and file-storage verification succeed.
Partial restoration must remain staged and must not be presented as a complete
recovery.

## 9. Local-to-S3 migration

Provide an administrative, resumable migration operation that:

1. copies each object to the configured S3 provider;
2. verifies size and checksum at the destination;
3. updates the canonical record only after successful verification;
4. preserves the public ID and all record associations;
5. records progress and failures in an auditable report; and
6. retains the local copy until the operator confirms migration completion.

The operation must be safe to rerun. A failed object must remain readable from
its prior provider and must not produce a broken public link.

## 10. Limits and safeguards

Configuration should support:

- maximum upload size;
- allowed or blocked content types;
- per-project and installation-wide storage quotas;
- upload rate limits;
- filename normalization;
- orphan-file reconciliation;
- checksum verification; and
- explicit confirmation before deleting or regenerating a shared link.

Malware scanning may be introduced later through the storage interface without
blocking the local-storage foundation.

## 11. Delivery phases

### Phase 1 — Local storage foundation

- Add the storage abstraction and local driver.
- Add the canonical database records and attachment associations.
- Implement uploads and permanent public delivery.
- Add safe image and PDF previews.
- Support message, task, activity, and deliverable attachments.
- Add permissioned agent upload capability.
- Extend backup and restore to include local file content.

### Phase 2 — Operational hardening

- Add quotas and administrative storage reporting.
- Add orphan reconciliation and integrity verification.
- Add optimized Nginx delivery for Linux deployments.
- Add replacement, deletion, and public-link regeneration safeguards.
- Exercise backup and restore with large and interrupted file sets.

### Phase 3 — S3 progression

- Add the S3-compatible driver.
- Add resumable local-to-S3 migration.
- Extend backup and restore acceptance to S3-backed installations.
- Verify provider migration without public-link changes.

## 12. Acceptance criteria

- An authorized user or agent can upload a file and receive a permanent URL.
- The URL opens without authentication in a new browser session.
- The URL remains valid after logout, restart, deployment, and complete backup
  restoration.
- Supported images and PDFs render inline.
- Files cannot be enumerated through directory listing or predictable IDs.
- Deleting a file causes its URL to return a clear unavailable response.
- A complete backup contains and restores both metadata and file content.
- Restore verifies file counts, sizes, and checksums before reporting success.
- Restored public URLs are identical to their original URLs.
- Moving from local storage to S3 does not change existing URLs.
- Documentation clearly explains that anyone possessing a Syndicatum file link
  can access its content.

## 13. Explicit non-goals for V1

- Recipient authentication for public links.
- Expiring or signed sharing URLs.
- Folder synchronization or desktop-drive clients.
- Collaborative document editing.
- Per-file access-control lists.
- Public directory browsing.
- Replacing Google Drive or regulated document-management systems.

The initial implementation deliberately remains ordinary: filesystem content,
MySQL metadata, stable Syndicatum URLs, and a storage boundary that permits S3
only when deployment scale requires it.
