# Syndicatum public OpenAI plugin candidate

This directory is the portable Agent Plugins review candidate for the hosted
Syndicatum MCP service. It is distinct from `plugins/codex`, which owns the
local Codex connector and background runtime.

The package contains public listing metadata, the production MCP URL, the
approved square brand asset, five positive review cases, and three negative
review cases. Reviewer credentials must be entered only in the OpenAI portal.
Do not add them to this directory.

This source is not a published plugin. Before upload, the owner must:

1. provision the dedicated `Review Sandbox` and `Review Agent` fixtures named
   by the cases, with sample data and no production credentials;
2. run all cases against the exact package and service revision;
3. record an accessible demo and add its URL through the portal or a reviewed
   manifest update;
4. create the portal draft, complete publisher and domain verification, scan
   the live MCP tools, resolve every blocking finding, and submit for review;
5. record the approved directory identifier and exact published version in
   `release/integration-distribution-policy-v1.json` only after approval.

Follow the complete gate in
[`docs/integration-distribution-production-gate.md`](../../docs/integration-distribution-production-gate.md).
