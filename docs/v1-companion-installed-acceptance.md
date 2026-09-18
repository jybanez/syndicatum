# V1 Companion installed-client binding acceptance

Status: test procedure, not a passing acceptance record. This procedure uses a
disposable project and a new ChatGPT discussion. Do not reuse or replace an
existing production agent identity.

## Preconditions and evidence boundary

1. Record the exact Companion source commit or published ZIP SHA-256, installed
   extension version and ID, browser version, server revision, and test time.
   A source-tree run and a published-release run are separate evidence.
2. Use an authenticated project owner/admin, a disposable active project, and
   a Companion device authorized for that server. Record only non-secret
   project/agent IDs and observed outcomes. Never copy an OAuth token, device
   token, claim code, or `binding_context_id` into the acceptance record.
3. Keep a second project outside that account's authorized owner/admin set to
   test confidentiality. For ambiguity cases, create two disposable authorized
   projects or agents whose names differ only after Unicode casefolding and
   whitespace collapse; do not alter existing team identities.
4. Verify the installed extension is the intended build, its background worker
   starts without errors, and the operator-selected server is shown before
   starting a binding request.

## Discussion binding matrix

| Case | Action | Required observation |
| --- | --- | --- |
| Unauthorized or unknown project | Ask `prepare_discussion_binding` for a project the account cannot administer, then an unknown name. | Both fail without listing accessible or inaccessible projects, agents, or alternative names. No pending Companion confirmation or agent creation. |
| Normalized authorized project and existing agent | Use a project and agent name with changed case and repeated whitespace. | Preparation resolves uniquely to canonical names. The Companion confirmation shows the selected server, discussion, canonical project and agent, their IDs, and **Use existing** action before Continue. |
| Ambiguous normalized name | Request a name that collides in the authorized fixture set. | Preparation fails closed as ambiguous; it selects neither candidate and shows no unauthorized alternatives in the AI discussion. No binding is created. |
| Cancel | Prepare a valid disposable binding and press Cancel. | The intent is cancelled; no activation binding or new agent is created. Retrying that intent fails. |
| Expired request | Prepare a valid binding, wait past its 15-minute expiry, then attempt Continue. | The request is rejected generically; no binding or new agent is created. |
| Existing-agent success | Prepare in a new ChatGPT discussion and press Continue. | The server binds the original stable project/agent IDs once. The Companion keeps the canonical server, project, agent, and IDs visible after success. Its status-check submission is labelled as a submission, not proof of MCP health. |
| Authoritative status | Let the submitted ChatGPT follow-up call `diagnose_connection` with the returned context. | The authoritative tool reports successful discussion binding and the same project and agent IDs shown by the Companion. A missing/unusable context must not be called healthy. |
| Protected operation | In the bound discussion, call one permitted protected project MCP operation under the returned context. | The result is scoped to the displayed project and agent identity; it does not fall back to another globally connected identity. |
| New-agent success | Prepare a unique new agent name in another disposable discussion and press Continue. | Exactly one ChatGPT agent is created only after Continue, the bound IDs are shown, and `diagnose_connection` returns those same IDs. A retry must not create a second agent. |
| Refresh/recovery failure | After server confirmation, make Companion binding refresh temporarily unavailable in a controlled test. | The UI still reports the binding as successful, shows a refresh warning, and does not claim successful notification delivery. Recovery refresh later discovers the binding. |
| Reconnect/reopen | Reopen the bound discussion and reconnect the Companion after a controlled browser or worker restart. | The confirmed identity remains visible or is correctly re-established from authoritative state without silently changing the server, project, or agent. A status check is pending or unknown until the protected tool returns its result. |

For each case, compare the browser observation with the authoritative Project
API/MCP result and the server audit/participant/binding rows using an
authorized operator view. A visible ChatGPT prompt alone proves only that a
prompt was submitted; it does not prove tool execution or a healthy binding.
Record discrepancies and the exact IDs involved without copying message text
or credentials into operational diagnostics.

## Exit rule

Do not check off P0.5 installed-client acceptance until the matrix passes on
the current installed Companion and target server build, a participant without
repository/database knowledge completes the normalized/no-match/ambiguous
flow, and the exact published release artifact is verified separately after
the RC path becomes available. Source tests and PR CI are prerequisites, not
substitutes for these observations.
