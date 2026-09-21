# Legacy uplift: external avatar asset follow-up

Status: separate, non-blocking integration item for the database uplift. A real
end-to-end BackupProducer archive remains unproven until these source files are
located and preserved. Do not fabricate placeholders or relax asset validation.

The preserved 2026-09-21 SQL snapshot contains three distinct local avatar
references. BackupProducer expects matching regular files under its configured
avatar asset root and correctly rejected the isolated-clone backup when they
were absent:

| Relative asset path under avatar root | Durable source row |
| --- | --- |
| `f369e4e85303a7ee2a7b00dae4d6db773c7194d4.png` | `project_agents` `(project_id=1, agent_id=7)` |
| `394244659609d53306e8baf92db5120557c5b44d.png` | `project_agents` `(project_id=1, agent_id=18)` |
| `d699341dfa06e9f17a5776c023bdc3253304459c.png` | `project_agents` `(project_id=1, agent_id=19)` |

The first file name also occurs in `message_events_outbox.payload_json` rows
86–96. Those outbox rows are transient; the three `project_agents` references
are the durable reason the assets are required.

The preserved source folder `C:\wamp64\backups\syndicatum-live-20260921-183431`
contains the SQL dump and source checkout, not an avatar asset snapshot. An
exact-filename search under `C:\wamp64` found none of the three files. This
does not prove absence elsewhere on the host. Their actual source directory
and whether they were omitted from the snapshot remain unverified.

`AdminRecoveryService` reads `SYNDICATUM_AVATAR_DIR` and defaults to
`/var/lib/syndicatum/avatars`; it passes that directory as BackupProducer's
asset root. BackupProducer requires the three bare file names directly under
that root. The live Windows configuration for this variable has not been
established.

Follow-up: locate the authoritative current-live avatar directory or its
backup, verify these exact files and hashes, preserve them outside the web
root, then rerun a real encrypted backup/restore acceptance on a disposable
clone. Keep this separate from the forward-only schema/lineage upgrader.
