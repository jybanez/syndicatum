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
