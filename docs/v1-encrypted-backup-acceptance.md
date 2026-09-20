# V1 Encrypted Backup Acceptance

## Automated evidence

The source contract runs:

- `tests/package-contract.php` — exact 48-table closure, reviewed counts,
  explicit recovery strategies, target-local expectations, and a deterministic
  policy-mutation hash check;
- `tests/backup-envelope.php` — multi-frame authenticated round trip, wrong-key,
  ciphertext mutation, truncation, key validation, and cleanup failures;
- `tests/backup-producer.php` — encrypted-only output, ordinary reader round
  trip, persistent asset inclusion, portable-secret classification, empty-target
  staged restore, no cutover, and fail-closed cleanup;
- `tests/backup-mysql84-roundtrip.php` — real MySQL 8.4 source and target baseline
  installs, durable data/configuration fidelity, OAuth/service/session/outbox
  reset, exact target-local role and installation identity preservation, and
  encrypted archive/manifest identities.

The `mysql84-baseline-drift` job regenerates `schema.sql` and `baseline.json`
from the exact reviewed schema and policy and byte-compares both outputs. It
records the resulting baseline metadata SHA-256. A missing or extra live table,
unknown policy, policy-only semantic change, schema drift, or nondeterministic
output fails CI.

## Required MySQL 8.4 result

The retained round-trip report must state:

- MySQL version within `>=8.4,<9.0`;
- baseline ID and exact metadata SHA-256;
- 48 tables classified as 28 durable, 17 reset, and 3 excluded;
- authenticated envelope, archive, manifest, and content-tree identities;
- durable row/configuration fidelity;
- zero restored rows in seeded reset fixtures, including sessions, OAuth access
  tokens, MCP service tokens, connector devices/routes, and all four delivery
  queue/outbox tables;
- a pre-backup MCP bearer token is rejected after restore, while an explicitly
  reissued replacement token authenticates successfully;
- exact target-local role seeds and target installation identity preserved;
- zero historical migration rows;
- no automatic cutover.

## Remaining protected evidence

The backend gate is not closed by local tests alone. The exact implementation
commit must pass the complete CI matrix, and retained CI artifacts must be given
to Commercial Assessor for review. UI wiring remains deferred until that review
accepts the producer and staged-restore contract.
