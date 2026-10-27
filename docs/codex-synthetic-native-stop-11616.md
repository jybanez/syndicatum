# Immutable synthetic native test STOP #11616

Owner approval event11616 approved request11614 at exact source
157747f5253f1066a9f755ce7bb885368469bfd7. Canonical approval, clean source,
packet/harness/backend/runtime pins and absent fixed test root passed admission.
The harness was invoked ONCE and exited1 during backend session opening:
`Assert-FixedPath` was not recognized within a GetNewClosure callback.
This is a failed native test, not validation or production acceptance.

Five approved regular files were retained without cleanup/retry/repair/rename:

| File | Bytes | SHA256 |
| --- | --- | --- |
| attempt.json | 273 | 2c9128d48825fc63b987bb30c47409d58b747807cf87c43d94dc065c37b5a068 |
| manifest.json | 730 | cd3e3e6aefbb9f1646b57385d115af7dd3d8697e5de6eb5cb8640dacf2a69238 |
| result.json | 373 | 350084cba0638b2bb5b72866a9edad0ca5532137cd333f021c8262bf5e13cb43 |
| records.bin | 8192 | 9f1dcbc35c350d6027f98be0f5c8b43b42ca52b7604459c0c42be3aa88913d47 |
| heads.bin | 8192 | 9f1dcbc35c350d6027f98be0f5c8b43b42ca52b7604459c0c42be3aa88913d47 |

Both containers remain entirely zero-filled: the write/flush/readback and
occupied-rejection test did not complete. result.json records STOP, retained
inventory, no replay and production_readiness=false. Native setup did establish
the two synthetic files/ACLs and obtain their initial handle/ACL identity manifest;
that partial setup is not a successful storage-session test. Raw private manifest
paths were not uploaded or posted. No live connector/profile/cache/Task/Run/process
or production state was inspected/modified. Both live gates remain closed.

Approval11616 is exhausted. The fixed test root now EXISTS as retained evidence.
It cannot be reused, cleaned or renamed under this authority. A future isolated
attempt needs reviewed corrected source/CI and a separately explicit owner
decision on its test-tree identity; no replacement root is chosen here.

## Subsequent source-only correction

New-FixedBoundApi captures explicit bound helper scriptblocks. The Windows API
factory now passes its private path validator and captures its ACL helper
explicitly, rather than resolving private command names from a dynamic closure
module. A pure regression invokes returned callbacks outside their defining
module, proves both private helpers resolve and enforce validation, and uses the
old unbound pattern as a negative control. No native API is invoked by that test.
Changing the current source packet's backend pin describes NEW source only;
the prior Git packet and retained attempt evidence remain unchanged. Native
correctness remains unverified and no second harness invocation is authorized.
