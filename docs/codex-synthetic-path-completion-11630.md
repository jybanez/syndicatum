# Bounded synthetic path completion (#11630) - source only

Both failed native attempts11616 and11628, their five-file trees and original
pins/manifests remain immutable. No native harness rerun or live operation was
performed during this correction. Full native correctness remains unproven.

| Exact execution segment | Source correction / inert evidence |
| --- | --- |
| Assert-FixedPath traversal | Require typed FileSystemInfo; file/directory parents through the volume root; reject null, premature termination, cycles/depth and reparses at every level |
| Metadata errors | Explicit get_Value() calls preserve getter failures; require valid name/attribute types. Stubbed Get-Item missing/denied errors keep original IDs; FullName/Attributes/Directory/Parent getter errors propagate |
| Path admission | Empty/relative/drive-relative/escaping/outside-root/UNC/device/extended-prefix/ADS and exact/descendant live roots rejected before metadata seam |
| Harness TEMP ancestor walk | Require DirectoryInfo, explicit typed metadata getters, volume-root termination and cycle/depth bounds |
| Harness flat inventory/failure evidence | Typed FileSystemInfo/DirectoryInfo checks, explicit metadata getters, no PSIsContainer; unknown/reparse/directory/type/length errors remain STOP |
| Callback factory | Existing actual-constructor suite with all11 native/file primitives stubbed, original helper errors and retained normalized-name checks unchanged |
| Streams/ACL/session | Existing-file stream APIs and typed security/hashtable fields have no provider-parent assumption; existing fake storage/occupied-error/byte-preservation tests retained |

The real-helper suite calls the actual Assert-FixedPath body with Get-Item and
ALL metadata/parent getters overridden. It covers file and directory leaves,
plain typed ancestors to C:\, reparse at every chain level, absent/inaccessible/
null/invalid metadata, errors from each getter, premature termination, cycles,
forbidden input classes and original errors. No filesystem metadata API is read.
AST regressions prohibit PSIsContainer in the two actual execution sources.
These do not replace constructor or fake-storage tests and do not prove native
API behavior. This pass is limited to the short synthetic execution path; it is
not a platform/configuration audit or a new architecture requirement.

## Proposed next identity

Source proposes distinct leaf
`syndicatum-fixed-validation-394881fdf8ef477bafb3174d62c159bd` for a future
owner-reviewed test. No tree is created, renamed, deleted or reused here. The
packet keeps BOTH retained prior leaves separate. The harness refuses earlier
approval references; no new owner request is issued before source review and
green exact-head CI. Same flat five-file/API/resource/no-scratch/cooperative-time/
no-kill/no-retry/no-cleanup scope applies. The canonical retrieval bridge, live
storage/cutover integration, production durability and all other machines remain
outside this proposal; live gates and #11582 anchors are unchanged.
