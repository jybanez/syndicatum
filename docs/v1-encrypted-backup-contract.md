# V1 Encrypted Backup Contract

## Scope

V1 creates an authenticated, encrypted, data-only recovery envelope and restores
it only into a separately installed, empty MySQL 8.4 target. It does not contain
schema authority or executable application code, overwrite a live deployment,
or perform an automatic asset/database cutover. Backup and Restore UI actions
remain placeholders until this backend contract is accepted.

The trusted schema and table classifications come from the release baseline and
`schema/mysql84/backup-policy-v1.json`. The policy must cover every baseline
table exactly once; unknown, missing, duplicate, or unordered table names fail
closed. No table receives a default classification.

## Exact 48-table policy

### Durable — 28 tables

| Table | Recovery rationale |
|---|---|
| `administrative_audit_events` | Preserve administrative accountability history. |
| `agent_activation_bindings` | Preserve explicitly configured agent integrations; encrypted values require the exported master key. |
| `agent_credential_scopes` | Preserve the authorization scope assigned to durable agent identities. |
| `agent_notification_webhooks` | Preserve webhook configuration and encrypted signing material; queued deliveries are reset separately. |
| `agents` | Preserve canonical Project API agent identities. |
| `chat_agents` | Preserve canonical legacy/agent identity records. |
| `chat_entries` | Preserve historical chat content. |
| `chat_entry_recipients` | Preserve historical addressing. |
| `chat_entry_revisions` | Preserve edit history. |
| `chat_topics` | Preserve discussion organization. |
| `chat_write_audit` | Preserve write/audit history. |
| `legacy_api_usage_daily` | Preserve aggregated commercial/operational usage history, not transient requests. |
| `message_addressees` | Preserve delivery/seen/acknowledgement state attached to canonical messages. |
| `message_revisions` | Preserve canonical message revision history. |
| `messages` | Preserve the authoritative project timeline. |
| `oauth_clients` | Preserve OAuth application configuration without preserving issued codes or tokens. |
| `pinned_messages` | Preserve user-selected project state. |
| `project_agents` | Preserve project membership/configuration for agents. |
| `project_invitations` | Preserve business workflow state and its audit trail. |
| `project_members` | Preserve project membership and roles. |
| `project_message_sequences` | Preserve monotonic project sequence continuity. |
| `project_participants` | Preserve stable participant identities referenced by messages. |
| `projects` | Preserve canonical projects. |
| `responsibility_events` | Preserve assignment/responsibility history. |
| `system_settings` | Preserve configured integration settings and encrypted values; decryptability requires the exported master key. |
| `user_system_roles` | Preserve user role assignments; role IDs are reconciled against exact trusted target seeds. |
| `users` | Preserve canonical accounts and profile/avatar references. |
| `workspaces` | Preserve canonical workspace ownership. |

### Reset — 17 tables

| Table | Strategy | Recovery rationale |
|---|---|---|
| `account_oauth_attempts` | `truncate` | Expiring login attempts/nonces must not be revived. |
| `agent_webhook_deliveries` | `truncate` | Prevent delivery of pre-backup queued/retry work. |
| `connector_device_activation_routes` | `rebind_after_reauthorization` | Device-local paths and conversation routes are rebuilt only after a device is authorized again. |
| `connector_device_authorizations` | `truncate` | Pending/consumed device codes are ephemeral. |
| `connector_devices` | `reauthorize` | Device bearer-token verifiers are not portable; devices must authorize again. |
| `connector_discussion_binding_intents` | `truncate` | Short-lived binding intents and token references must not be replayed. |
| `delivery_worker_heartbeats` | `truncate` | Worker liveness is target-runtime state. |
| `google_oauth_attempts` | `truncate` | Expiring state, nonce, and PKCE verifier material must not be revived. |
| `mcp_service_tokens` | `reissue_credentials` | Resetting token hashes prevents snapshot rollback from resurrecting a revoked service token. |
| `message_events_outbox` | `truncate` | Prevent old realtime events from being emitted after recovery. |
| `oauth_access_tokens` | `truncate` | Issued bearer-token hashes are ephemeral credentials. |
| `oauth_authorization_codes` | `truncate` | One-time codes must never be restored. |
| `oauth_refresh_tokens` | `truncate` | Refresh-token hashes must not regain authority after recovery. |
| `responses_api_deliveries` | `truncate` | Prevent retries or duplicate external Responses API actions. |
| `security_rate_limits` | `truncate` | Rate-window counters are runtime-local and safely restart. |
| `syndicatum_sessions` | `truncate` | Browser sessions and CSRF state must not be revived. |
| `workspace_agent_trigger_deliveries` | `truncate` | Prevent replay of old workspace-agent triggers. |

### Excluded / target-local — 3 tables

| Table | Target expectation | Recovery rationale |
|---|---|---|
| `syndicatum_installation_identity` | `locally_initialized` | Must describe the trusted target release/package, never the source installation. |
| `syndicatum_schema_migrations` | `locally_initialized` | Must reflect only migrations applied by the trusted target release. V1 baseline currently requires zero rows. |
| `system_roles` | `locally_initialized` | Fixed roles are seeded by the trusted baseline. V1 requires exact IDs and codes: `1/user`, `2/administrator`. |

## Foreign-key and reconciliation proof

There is no durable-to-reset foreign key in the reviewed schema. The only
durable-to-excluded edge is `user_system_roles.role_id -> system_roles.id`.
Staged restore accepts the target only when its fixed role catalog is exactly
`(1,user,User)` and `(2,administrator,Administrator)`, so restored assignments
cannot silently attach to a different target-local role. The target installation
identity must also match the trusted baseline/application/schema head, and the
migration ledger must be empty while the baseline declares no forward migrations.

Reset tables may reference durable tables, but they are not exported or
inserted. Clearing them cannot violate restored durable rows because the
dependency direction is from reset state to durable state.

## Credential and replay boundaries

OAuth client configuration is durable; OAuth attempts, device codes,
authorization codes, access tokens, refresh tokens, sessions, and binding
intents are reset. Integration settings whose encrypted values must survive are
durable only alongside the exported `SYNDICATUM_MASTER_KEY` recovery secret.

MCP service-token hashes and connector-device token hashes are reset. Operators
must reissue/reclaim service credentials, reauthorize devices, and then rebuild
device activation routes. This intentionally prefers revocation safety over
seamless credential continuity.

All four delivery queues/outboxes are reset. Workers therefore have no pre-backup
work to claim after restore. Durable message/addressee acknowledgement state is
retained, and no startup routine reconstructs delivery rows from message history.
Only a new post-recovery action can create new outbound work.

## Envelope and secret contract

- AES-256-GCM is applied in independently authenticated chunks with a canonical,
  authenticated header and unique nonce material per frame.
- A raw 32-byte operator-managed key is read from
  `SYNDICATUM_BACKUP_KEY_FILE`; it is never stored in the backup.
- The envelope exposes archive and manifest hashes only after every frame and
  the final content identities authenticate successfully.
- Frame counts are bounded to the authenticated 32-bit nonce/AAD counter, and
  the final envelope is committed with a no-replacement filesystem operation.
- Temporary ZIP, manifest, logical data, assets, and portable secrets exist only
  in private staging and are removed on success or failure.
- The portable recovery-secret inventory is closed: application HMAC and master
  encryption keys are exported where required; the database password and backup
  key are operator supplied; optional previous HMAC state is exported when present
  and otherwise regenerated/omitted.

## Restore boundary

The target must be a clean baseline installation. All durable and reset tables
must be empty; excluded tables must satisfy their exact target-local invariants.
Both source and target must match every table's exact trusted ordered-column
inventory; a same-table-name schema drift is rejected before backup or restore.
Authenticated next `AUTO_INCREMENT` values (including values above `MAX(id)`)
are restored and verified so deleted or previously issued identifiers are not
reused. Persistent assets are fully staged and hashed before the database
transaction begins, so an asset failure cannot leave committed durable rows.
The ordinary archive reader and controlled private extractor are used without a
producer bypass. Durable rows are inserted in trusted restore order, assets are
returned in a private stage, and the result states `cutover_performed: false`.
