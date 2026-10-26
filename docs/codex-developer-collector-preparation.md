# Developer collector preparation after A1

Request: Commercial Assessor message 11731. This is source preparation, not
installation authority or a completed collector admission packet.

## Retained evidence

Owner request 11728 / positive event 11729 authorized the completed A1 capture.
Developer report 11730 records six exit-zero commands, seven authority receipts,
29 files / 307295 bytes and fingerprint
`d3b478c05817f426b78f3e5324d068924b6cef00da5ab3a2c973ac49f38e42f1`.
Assessor acceptance is of Developer-observed evidence, not independent inspection
of the private files. That capture and the stopped captures are consumed; do not
rerun, reuse, clean up or amend them.

Retained result 1 supplies the exact target marketplace root. Retained result 2
supplies the installed `codex@syndicatum` source path and version 0.2.7. Actual
paths remain private. Neither record establishes a complete installed inventory,
writer quiescence, shared staging emptiness at installation time or future
runtime provenance. The 48-file candidate archive at commit
`9141daa08d896f2524be30b96d01ab2a14398757` is a separate object and must never
substitute for the installed layout.

## Implemented reader change

`NativeInstallReader` now imports the accepted `check_nonreparse_chain` from the
A1 runner. This checks lstat and, on Windows, direct GetFileAttributesW results
for every ancestor, including the volume root; any reparse tag or unavailable
attributes stop the read. The import adds the runner to the explicit production
source dependency list. It does not invoke the A1 runner or authorize a capture.

Checks precede root/tree/ref access and surround content reads. An absent partial
postimage requires a checked parent and an actually absent leaf; a dangling
reparse is not absence. Artifact reads are capped at 16 MiB plus one sentinel
byte and require the observed size to match. Git ref reads are capped at 1 MiB
plus one sentinel byte and require stable size, modification time and inode.
Directory/file/inventory limits remain 256 / 512 / 16 MiB. These repeated path
checks do not constitute atomic Windows handle-based race prevention or a
writer-quiescence observation. The production gate remains unconditional.

## Remaining integrated packet requirements

The actual metadata-producer adapter, its complete source/runtime pins, exact
staging/unrelated root mapping, independent writer observation and runtime
dependency closure are still pending. An empty caller-supplied writer list is
not native evidence. Missing coverage or ambiguous writers must return
unavailable, not ready. No blanket operating-system attestation is sufficient.

The next owner scope must explicitly name any new process observation, broader
inventory walk or allocation. Preparation here launches none of those operations.
It must distinguish loader/module reads, Python bytecode/cache behavior, CLI
logging/configuration reads, staging effects and network operations. Existing A1
module handshakes do not prove that dependency closure.

The B setup plan must bind four new private roles: external backup, writer,
independent head, private receipt. Each requires a unique unused root outside
webroot/protected identity state, owner/SYSTEM ACL identities, a manifest and
verified readback. Existing captures are never those roots. No root is allocated
by this document.

Retain the existing capacity envelope: writer and independent head each
512 x 65536 x 2 = 67108864 bytes; receipt 128 x 49152 x 2 = 12582912 bytes;
containers total 146800640 bytes. With 33554432 logical evidence bytes and a
16777216-byte collector allowance, preparation totals 197132288 bytes, below
the 201326592-byte private setup cap by 4194304 bytes. The external backup has
a separate 1073741824-byte cap; actual required backup size remains unmeasured.
Do not treat this arithmetic as a free-space, successful allocation or timing
measurement. The 120000 ms total/freshness bounds remain; missing measured
performance or capacity evidence blocks admission rather than enlarging bounds.

One eventual B/C/D decision must bind admitted acquisition, bounded setup and
verified backup, then the exact same-session stop/install sequence and
owner-controlled normal reload/acceptance. All missing prerequisites must fail
before mutation. No production collector launch, gate opening, merge,
installation, activation, monitoring or remote-machine action is authorized.
