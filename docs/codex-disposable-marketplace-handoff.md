# Disposable marketplace test handoff

Source review candidate only. Packaging is not permission to execute. Do not run
on Developer, HomeServer, S5, S6, or any production profile. Commercial must review
the exact runner bytes and the owner must designate the specific supported guest
with independently verified isolation before execution. See the companion test
plan for the complete eight-case matrix and stop conditions.

## Admission evidence

Retain a private UTF-8 JSON approval file with exactly these fields:

```json
{
  "guest_uuid": "REPLACE_WITH_DESIGNATED_GUEST_UUID",
  "isolation_evidence_sha256": "REPLACE_WITH_64_LOWERCASE_HEX_DIGEST",
  "network_disabled": true,
  "no_production_access": true,
  "owner_message_id": 0
}
```

The placeholders and zero are intentionally invalid. A reviewer must bind the
actual guest UUID, positive canonical owner-message ID, and digest of the private
independent isolation evidence. That evidence must establish administrative
outbound denial, no production credentials or host access, no shared folders,
clipboard or redirected drives, and the supported guest environment. The runner's
adapter/route checks supplement this evidence; they do not establish it.

Transfer the exact approved CLI privately and verify SHA-256
`f6dbf48cb70f62649c645f26c87dc9bf84cc36986202dfad8e12fb5b8ca70312`.
The review archive deliberately contains no binary, credentials, approvals, or
production state. Its manifest binds runner and plan bytes to a Git revision.
Compare these bytes with the exact Commercial review before any guest execution.

## Operator inputs after approval

Supply the runner's five mandatory parameters: `CliPath`, `GuestApprovalPath`,
`IsolationEvidencePath`, `GuestApprovalSha256`, and `NewEvidenceDirectory`.
Use absolute guest-local paths and a fresh evidence directory outside the inputs.
Do not use production paths, mounts, a real login, or an existing Codex profile.
Do not bypass a rejected admission check or rerun into a different directory to
hide a failed attempt. Preserve failure output and the complete attempt.

On timeout, preserve the reported PID and escalate; the runner does not kill,
retry, roll back, or clean up. A completed fixture run is evidence collection,
not a passing verdict: Commercial must review the eight cases, cache/source byte
comparisons, network evidence, and any unexpected effects. Retain all raw evidence
privately before guest destruction; share only reviewed sanitized findings.

No result authorizes live marketplace removal. The final exact live adapter/head
requires separate execution approval after disposable semantics review.
