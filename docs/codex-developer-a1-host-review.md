# Narrow A1 tool-host correction — source review only

The accepted runner/scope remains6593116a9d8f94cede63a3004a818acb5f349306.
Existing canonical3717-byte private proposal remains
 d31b0341272b8dd602f4ad1c316eb0127d87e264bc6c35aaf4af43c9b52b899a.
Do not regenerate it. Stopped request11683/approval11684 is consumed and rejected
by the new host's run entrypoint. No native A1 retry occurred.

The new source tools/release/codex_developer_a1_tool_host.py replaces only the
ad-hoc tool-host wrapper. It is separately pinned in the companion review manifest;
it is NOT substituted into the accepted22-file A1 payload or private packet.
A new owner decision must bind this wrapper digest in addition to the accepted
runner head, unchanged packet and unchanged six-command/capture effects.

The trusted Codex parent materializes the exact Git-blob wrapper in memory and
launches the pinned Python interpreter through exec_command with tty=true, shell
profile loading disabled where supported. This host interpreter/PTY is separate
from the six diagnostic children. No visible app window or persistent listener,
service, transport endpoint or storage setup is added. All existing worker/install/
activation gates remain unchanged. The parent alone calls the exact protected
get_message tool under profile1f397680dd1cddc0.2.33 for the new request and owner
positive event on every authorization challenge; no packet-supplied authority.

Use --mode inert --session <48hex> for transport tests only. It never calls the
runner, resolves/reads the proposal, imports native probe bodies, creates capture
or launches diagnostic children. --mode run additionally requires the NEW request
ID, NEW approval ID and reviewed wrapper SHA. Actual runner code and22 sources are
loaded from accepted Git blobs after verifying pins; checkout CR/LF is not executed.
The existing proposal is resolved with Windows SHGetFolderPathW(CSIDL_PERSONAL),
not a guessed USERPROFILE/Documents concatenation. A separate read-only check
confirmed the resolver matches Windows Documents, proposal hash is unchanged, and
reserved target remains absent; no paths are shared here.

PTY response framing uses ASCII A1R chunks (base64,<=768 chars each), sequential
part numbers, exact48hex session/sequence and A1E end marker with raw SHA256.
Maximum raw response64KiB, line1024 bytes,128 chunks and one absolute90-second
authorization deadline. CR/LF framing conversion is accepted; embedded controls,
truncation, EOF, wrong context/count/hash, duplicate JSON keys and noncanonical JSON
fail closed. No reconnect/retry or extra authority channel. Echo suppression is
scoped to the host console and restored in finally; framing stays safe if echo is
on. Echoed responses begin A1R/A1E, while parent challenges require a clean A1Q
marker and exact session/sequence; terminal control output is never stripped into
authority. The trusted tool parent may read the rendered terminal challenge but
must match its exact known session/sequence before returning protected results.

The parent sends one response via write_stdin, then waits for the next challenge
or terminal A1O result. It must not feed terminal echo back as an authorization
response. Parent and child context checks do not provide hostile-process or
cryptographic MCP attestation; they preserve the existing cooperating-host trust.
No old request/profile substitution, PID/name reattachment, kill or replay.

Inert evidence: one initial invalid-length test session was rejected before any
read/runner action. The successful PTY run transported one actual-shaped response
containing fresh protected-tool results for the stopped historical request/event,
solely in inert mode. Canonical4949 bytes in9 chunks; response SHA256
6d188ac9de2dc0b1bb03016ff65fd72ba7fc5f2a36a8da8ea0277c232d34721d.
Returned transport_ok and exited0. These bytes are NOT new owner authority or A1
provenance. Five inert source tests cover LF/CRLF, EOF/truncation, wrong identity,
echo/control rejection, deadline, duplicate keys and injected Documents resolution.
No diagnostic command, capture allocation or packet mutation occurred.

On failure retain the original error and STOP; do not reopen the consumed attempt.
A future successful run may retain the new authority/result in one capture file
within the original32-file/16MiB bound. Full private paths/raw output stay local.
Production callback/quiescence/OS image limitations remain unchanged.
