# V1 archive-reader acceptance evidence

Status: validate-only reader candidate at commit `8b7fd59` plus the explicit
fixture matrix in this document's follow-up commit. Extraction and package
producers remain deferred.

## Trust and side-effect boundary

`ArchiveSafetyReader::validate()` is the only public validation entry point. It
requires an `ArchiveValidationContext` containing the exact expected archive
SHA-256 and sidecar-manifest SHA-256. A sidecar is not authoritative merely
because it parses. The manifest bytes must first match trusted provenance, and
the archive bytes must match the trusted digest before manifest identity or
inventory claims are accepted.

The reader copies the input bytes, with a hard byte cap, into a private `0600`
temporary snapshot. Raw structure inspection and entry streaming use that
immutable snapshot, eliminating path-replacement races between those phases.
The snapshot is deleted before return. Validation extracts no entry, executes
no SQL, and mutates no application state. Its return value is an evidence report
without an archive path and is explicitly not extraction authority.

There is deliberately no public "structurally valid = trusted" mode. A canonical
release supplies the expected hashes from protected release provenance. A future
uploaded backup must obtain the exact archive and sidecar identities from its
authenticated-encryption envelope after successful authentication; defining
that envelope/decryption producer is a later gate. The current API does not grant
restore authority to an unauthenticated uploaded archive.

The trusted backup catalog distinguishes required payload paths from optional
allowed paths. Every backup requires `metadata/recovery.json` and at least one
declared logical-data file, even when that file represents an empty table and is
zero bytes. Persistent assets remain optional, but the manifest presence flag
must exactly match whether asset-role files exist. Removing a required file from
both archive and manifest therefore still fails against trusted context.

## Configured resource limits

| Resource | V1 limit | Enforcement point |
| --- | ---: | --- |
| Input/archive bytes | 536,870,912 bytes (512 MiB) | Bounded snapshot copy, before ZIP parsing |
| Archive entries | 2,048 | EOCD/raw central-directory inspection |
| Central directory | 16,777,216 bytes (16 MiB) | Raw EOCD boundary check |
| Sidecar manifest | 2,097,152 bytes (2 MiB) | Before JSON parsing |
| One uncompressed entry | 268,435,456 bytes (256 MiB) | Raw metadata, then streamed-byte counter |
| Aggregate uncompressed bytes | 2,147,483,648 bytes (2 GiB) | Raw metadata before entry streaming |
| One-entry compression ratio | 100:1 | Raw metadata before entry streaming |
| Aggregate compression ratio | 20:1 | Raw metadata before entry streaming |
| Recovery/portable-secret JSON | 1,048,576 bytes (1 MiB) | Bounded streaming capture |
| Logical-data records | 100,000 per archive | Streaming counter shared across logical files |
| One NDJSON record | 2,097,152 bytes (2 MiB) | Cursor-based streaming line parser |

The reader accepts only STORE and raw DEFLATE. ZIP64, data descriptors,
encryption flags, multidisk archives, extras, comments, and unknown flags are
rejected. It never expands an entry to disk. Metadata limits are checked before
entry decompression, and streamed output is stopped if it exceeds the inspected
size or the per-file cap.

## Adversarial fixture matrix

`tests/archive-safety.php` builds raw ZIP bytes so malformed central/local
structures are exercised at the archive layer rather than mocked above it.

| Threat or invariant | Negative fixture / fail-closed behavior |
| --- | --- |
| Parent traversal | Raw `../app/index.php` name rejected |
| Absolute/drive paths | Raw `/app/index.php` and `C:/app/index.php` rejected |
| Portable aliases | Windows-reserved `CON` and invalid `?` path rejected; package suite covers all frozen invalid characters |
| Case collision | `app/index.php` plus `app/Index.php` rejected |
| File/ancestor collision | Exact and case-folded `app/node` plus `app/node/child` rejected; sibling files pass |
| Duplicate name | Duplicate central-directory logical name rejected |
| Hardlink/local alias | Two central records pointing at one local offset rejected as aliased/overlapping records |
| Special UNIX types | Directory, symlink, block device, character device, FIFO, and socket modes rejected |
| Undeclared archive entry | Extra raw ZIP entry rejected by exact inventory equality |
| Missing archive entry | Manifest entry absent from ZIP rejected by exact inventory equality |
| File digest mismatch | Mutated payload with unchanged manifest rejected |
| Content-tree mismatch | Stale/forged canonical tree digest rejected |
| Declared/streamed size mismatch | Mismatched uncompressed-size metadata rejected |
| Entry-count overflow | Reader instantiated below fixture count rejects before streaming |
| Oversized file | Per-file limit below fixture size rejects before streaming |
| Aggregate overflow | Aggregate limit below fixture total rejects before streaming |
| Compression bomb | Highly compressible DEFLATE entry rejected at configured ratio |
| ZIP64 | `0xffffffff` ZIP64 sentinel fixture rejected |
| Data descriptor | General-purpose bit 3 fixture rejected |
| Encryption/unknown flags | Encryption-bit fixture rejected |
| Local/central disagreement | Name and flag disagreement fixtures rejected |
| Unsupported method | Unknown compression method rejected |
| Extras/comments | Local extra, central extra, entry comment, and EOCD comment rejected |
| Multidisk | Nonzero disk marker rejected |
| Preamble/gap/overlap/trailing bytes | Separate raw fixtures reject each structural form |
| Executable backup content | Shell/PHP/polyglot bytes fail strict NDJSON-object grammar |
| Hidden backup names | Hidden path segment rejected by the backup namespace contract |
| Executable permissions/extensions | Package contract rejects executable bits and closed executable extensions, including SQL |
| Duplicate JSON keys | Manifest, recovery metadata, and NDJSON record fixtures reject duplicates before associative decoding |
| Object/list ambiguity | Recovery file-list objects cannot masquerade as JSON arrays |
| Malformed NDJSON | BOM/shebang, PHP line, missing final LF, non-object, and empty-record forms reject |
| NDJSON amplification | 100,000 dense short records validate within the regression budget; 100,001 or a lower configured budget rejects |
| Media disguise | `.png` containing PHP instead of PNG signature rejects |
| Backup completeness | Missing required recovery metadata or logical-data path rejects even if manifest agrees; zero-byte empty-table file and absent optional assets pass |
| Schema-authority smuggling | Recovery metadata containing `ddl` or any unknown policy key rejects |
| Trusted-operation mismatch | Release manifest under backup context rejects |
| Trusted archive mismatch | Archive SHA different from trusted context rejects |
| Trusted sidecar mismatch | Manifest bytes different from trusted provenance hash reject |

The package suite separately pins exact manifest/archive role namespaces,
regular-files-only inventory, portable path policy, canonical JSONL bytes, and
content-tree golden hashes. The independent Python verifier reproduces the
canonical fixtures without using the PHP implementation.

Media prefix checks establish only that an allowlisted extension has its expected
file signature. They do not fully decode an image or prove that arbitrary asset
bytes are safe to interpret in every downstream context. Restored assets remain
untrusted data and must be served with fixed non-executable permissions, safe
content types, and no script execution from the asset location.

## Verification commands

The following pass on both PHP 7.4.33 and PHP 8.2.29:

```text
php tests/package-contract.php
php tests/archive-safety.php
```

The independent canonical fixture verifier passes:

```text
python tests/canonical-inventory-contract.py
```

The core regression suite remains `26 passed, 0 failed`. CI runs the archive
suite in `source-contract` on Ubuntu/PHP 8.2 and in
`package-contract-portability` on Windows/PHP 7.4.
