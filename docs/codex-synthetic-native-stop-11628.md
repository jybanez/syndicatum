# Immutable synthetic native test STOP #11628

Owner event11628 approved request11626 at exact source
48456b005cb4624c42d15c5d895429600a95733b. Canonical approval, clean source,
all source/runtime pins, absent new root and unchanged11616 evidence passed.
The harness ran ONCE and exited1: property PSIsContainer was unavailable during
real path traversal. No retry, cleanup, rename or native acceptance followed.

| Retained file | Bytes | SHA256 |
| --- | --- | --- |
| attempt.json | 273 | 1dfc6b83b42a03c0a984c4b4be574a925a2ccfc0801a1952d68f8adeb84e44df |
| manifest.json | 730 | df59ab42a95aa5137c20e9e678a258083421505e87621809648401257546bea3 |
| result.json | 373 | 8c1a25d1d5731daa8a86e01da368c27a156a1d7205db2a60c4b54f8b03a842bb |
| records.bin | 8192 | 9f1dcbc35c350d6027f98be0f5c8b43b42ca52b7604459c0c42be3aa88913d47 |
| heads.bin | 8192 | 9f1dcbc35c350d6027f98be0f5c8b43b42ca52b7604459c0c42be3aa88913d47 |

Exactly five approved regular files remain; both containers are all-zero and
the record/head pattern test did not complete. result.json explicitly records
STOP, retained inventory, no replay and production_readiness=false. Neither
this tree nor11616's older tree may be reused/removed/renamed under old authority.
No live connector/profile/cache/Task/Run/process/production operation occurred.

## Bounded source correction and coverage gap

FileInfo.Directory and DirectoryInfo.Parent return typed filesystem objects;
the provider-added PSIsContainer property cannot be assumed on those parent
objects. Assert-FixedPath now requires FileSystemInfo and branches on DirectoryInfo
instead. Its actual-helper inert test stubs Get-Item and overrides ALL metadata/
parent getters with note properties on typed path objects. It reproduces the old
failure after the first provider-shaped node, validates the corrected walk, and
rejects reparse/untyped nodes without accessing native metadata. The constructor
test's stubbing of Assert-FixedPath was never coverage of that helper's internals.

## Related source-only normalized-path comparison

Separately identified by source review (NOT the observed failure cause): the
previous API compared a normalized handle name to a prefixed input path, which
assumes the opened spelling and normalized spelling coincide. Microsoft's
[GetFinalPathNameByHandleW](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-getfinalpathnamebyhandlew)
distinguishes normalized names (default) from opened names. The new candidate
captures the observed normalized handle name in each NEW synthetic setup manifest
and requires that exact retained name later, together with unchanged volume/file
identity, length, ACL, single-link and reparse checks. No path-string exception or
name-only substitution is introduced. Inert tests allow a retained normalized name
different from the input spelling but reject any change to the retained name.
No additional native probe or old-manifest refresh was performed.

All new source hashes describe an unapproved candidate only. Both failed trees,
their original manifests and approved Git revisions remain immutable. No next
test root is selected here; current root now exists as11628 evidence and blocks
admission. Corrected source review/CI and fresh explicit owner authority would be
required for any future distinct test. Both live dispatch gates remain closed.
