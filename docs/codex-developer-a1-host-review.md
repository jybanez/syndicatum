# Parent + wrapper correction after partial A1 STOP11698

Source/scope review only; no execution authority. Original accepted payload6593116
is changed only by the reviewed fresh attempt identity and its scope binding at
8bc1e5ac51fa8ef0323e0a9432ccf8627ed2cbb5. Current transport source must be bound
separately by both parent and wrapper digests in the single future owner decision.

The occupied original capture contains25 retained files/247723 bytes, including one
successful --version result, STOP and authority/outcome records. Original canonical
3717-byte d31b0341272b8dd602f4ad1c316eb0127d87e264bc6c35aaf4af43c9b52b899a
proposal remains unchanged. No cleanup, reuse, resume or replay. Stopped requests/
events11683/11684 and11697/11698 are rejected by the new wrapper.

New proposal (not approval): canonical3718 bytes, SHA256
51a21324b2d7e701aff04bac049fa5022fd7cbfed1084902977ccea0d6218845;
reserved capture developer-a1-140ab028ea4e4f00821e07a46b8f8260. The new proposal
was created separately in existing local private Documents, with current runtime
hashes/SID checked and absent new capture confirmed. No capture was allocated.
The22 payload files remain the accepted set; only runner CAPTURE_ID and scope
identity text change. The parent and wrapper are separate source/owner bindings.

## Actual parent implementation

codex_developer_a1_parent.js is a self-contained expression evaluated byte-for-byte
in the trusted parent's existing JavaScript runtime. It implements canonical ASCII
JSON, Base64 and SHA256 entirely in memory, verified against standard Node hashing
in tests. There is NO digest helper process, ad-hoc interpreter substitution or
exec_command call in its pump. The caller supplies the existing tool capabilities,
known48hex session, request/event IDs and mode. The parent makes exact protected
get_message reads using profile1f397680dd1cddc0.2.33 at every checkpoint.

Long JSON terminal challenges are gone. The wrapper emits only a17-character
marker !Q<tag>:<sequence>!, where tag is the first12 hex SHA256 characters of the
already-known session/request/event/mode tuple. Parent accepts the exact compact
literal; it NEVER strips/reassembles arbitrary ANSI/CRLF into a marker or authority.
Echoed Base64 response lines cannot contain the marker's exclamation characters.
Other-session noise is ignored; duplicate/wrong checkpoints fail closed. A marker
only requests a fresh protected read; it is not owner authority. !O<tag>:OK! or
STOP terminates the pump. Partial or corrupted markers lead to bounded timeout.
At20-column width the marker fits while ordinary long display noise wraps.

The wrapper retains bounded A1R/A1E ASCII response chunks, session/sequence/hash,
64KiB response,1024-byte line,128 chunks and90-second per-checkpoint deadline.
Raw unbuffered stdin avoids a daemon BufferedReader lock during timeout shutdown.
Console echo suppression is restored in finally; marker separation stays safe with
echo enabled. Protected canonical response validation remains in the wrapper.
There is no hostile-process/cryptographic MCP attestation claim.

Host overhead: one pinned Python/PTY wrapper,23 bounded local Git source reads
(10s each/60s aggregate), up to seven90s exchanges, and protected tool calls in the
existing JavaScript parent. No additional helper processes for hashing/framing.
The source byte pins are prepared read-only before owner execution. Unchanged six
diagnostic children retain30s each, stdout/stderr limits and original ordinary CLI
cache/log/network effects. Capture remains<=32 files/16MiB outside webroot/live
state, owner/SYSTEM ACL. No worker/preparation/install/activation/other machine.

## Complete inert rehearsal

The actual exec/write_stdin path completed all seven checkpoints using fresh
actual-shaped protected results for historical11697/11698 in INERT mode only.
Long display text wrapped and cursor/terminal controls were present; compact
markers advanced without normalization. Result transport_complete,responses7;
14 protected reads, no digest helper, no A1 runner/probe/capture call.

A forced2-second timeout at checkpoint3 retained completed2; parent returned
STOP_NO_RETRY,responses2, four protected reads. A deliberate late write was rejected
as a closed process. Exit0; no successor. An earlier inert timeout had exposed a
BufferedReader-finalization fatal error; that result is not presented as clean.
The unbuffered-reader correction was then rehearsed successfully for BOTH seven-
checkpoint progression and timeout/late input. These are Developer-reported inert
observations, not native A1 acceptance or independent Assessor inspection.

Source tests cover SHA/Base64 equivalence, all seven transitions, wrapped/narrow
noise, extra/wrong/duplicate markers, timeout/late no successor, EOF/truncation,
CRLF, duplicate response keys, Git stalls and absolute acquisition budget. No native
retry is authorized. Review the exact parent+wrapper/payload identity and current
CI first, then ONE fresh owner decision with the new proposal and total overhead.


## Parent reparse mismatch11705: source correction pending new proposal

Owner event11705/request11704 is consumed by preflight STOP, not replay authority.
ZERO host Git reads/diagnostics/capture allocation occurred. Full Windows reparse
attributes rejected the proposed capture parent's two non-link reparse ancestors;
the earlier is_symlink/is_junction-only attestation did not cover these flags and
is withdrawn as sufficient. Both private proposals and all prior evidence remain
unchanged. Do not invoke the old51a21324 proposal under this changed source.

The runner now uses a shared check_nonreparse_chain guard over lstat plus direct GetFileAttributesW,
rejecting FILE_ATTRIBUTE_REPARSE_POINT for every tag and portable symlink mode,
with at most64 ancestors. It checks capture parent and runtime paths before writes
or byte hashing. Inert tests cover a reparse-flagged ordinary-directory mode at
root/ancestor even when it is not a junction or symlink. The first Python-lstat-only comparison did not reproduce the full Windows flag
and is not accepted as confirmation; a direct Win32 query was added as a
second all-tag guard. That later native read also did NOT reproduce the earlier
reparse flag. The snapshots are inconsistent; do not assert a proven normalization
cause or silently resume based on the later result. Existing LocalApplicationData
candidate checks are separate read-only observations. The existing LocalApplicationData
known-folder parent passes all ancestor checks; no directory was created there.
It is a candidate only. New parent/attempt/proposal source binding and review are
still required before a future single owner decision. Old51a21324 bytes must not
be overwritten, refreshed or replayed; source status remains non-executable.


## Coherent replacement parent/proposal (#11707)

Supersedes the earlier source-correction-only status without changing old evidence.
Payload/source head03432ed8253964ea828b62157b7d947c7f3bc6e5 implements the SAME
validate_environment for read-only admission and execution. It also verifies current
token SID and capture-parent OWNER SID (current user/SYSTEM) through bounded native
security-metadata queries, closing handles/allocations immediately. No helper child,
protected credential/config read or persistent handle. Inert owner/runtime/occupied
path tests accompany the all-tag native/stat checks. Seven runner tests pass.

The separately created LOCAL-parent proposal is canonical3713 bytes, SHA256
2d8b188e69e3469594af7883e0650ff3964642905d474dbe129dee0ed549972a.
It binds the existing LocalApplicationData directory and the still-unallocated
leaf140ab028ea4e4f00821e07a46b8f8260. No parent or capture creation occurred.
Actual Developer-local validate_environment passed all currently possible checks:
22 source/scope pins, four runtime hashes/paths, full ancestor flags/location,
current SID/parent owner and absent target. This is local attestation, not independent
Assessor inspection. The51a21324 and d31b0341 proposals remain byte-identical.

No ad-hoc PowerShell path eligibility preflight is used in the next implementation;
the exact reviewed Python validate_environment is used before owner request and
repeated inside execution. Native creation repeats its full ancestor guard before
the atomic owner/SYSTEM directory ACL. The host/parent compact-marker transport,
23 Git-read bounds, seven authorization exchanges and six diagnostic effects are
unchanged. Both transport digests plus this source/payload/proposal and exact local
parent must be reviewed and included in ONE future owner decision after green CI.
No native retry/setup/install/activation/merge is authorized by this source.
