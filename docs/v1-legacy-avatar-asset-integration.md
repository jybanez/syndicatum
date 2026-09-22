# Legacy uplift: external avatar asset follow-up

Status: all three historical files were found outside the webroot. A real
end-to-end BackupProducer archive and separate restore-stage acceptance remain
unproven. Do not fabricate placeholders or relax asset validation.

The preserved 2026-09-21 SQL snapshot contains three distinct local avatar
references. BackupProducer expects matching regular files under its configured
avatar asset root and correctly rejected the isolated-clone backup when they
were absent:

| Relative asset path under avatar root | Durable source row | Bytes | SHA-256 |
| --- | --- | ---: | --- |
| `f369e4e85303a7ee2a7b00dae4d6db773c7194d4.png` | `project_agents` `(project_id=1, agent_id=7)` | 5,051 | `ACE61EAC3EEE0AE729C3F3F1F009FA3DC7C07C021BF0E6E400FCD9F700AE8199` |
| `394244659609d53306e8baf92db5120557c5b44d.png` | `project_agents` `(project_id=1, agent_id=18)` | 1,255,607 | `EF7C2CDFB4EA9F5D74E825245A9651F3C060BB7A2EE1598FF034BF5ECE222A7F` |
| `d699341dfa06e9f17a5776c023bdc3253304459c.png` | `project_agents` `(project_id=1, agent_id=19)` | 213,571 | `09FABA3C3112F360670BD5692DB7F555435D891A1DE8E2EB49421BEC9B392DD6` |

The first file name also occurs in `message_events_outbox.payload_json` rows
86–96. Those outbox rows are transient; the three `project_agents` references
are the durable reason the assets are required.

The preserved source folder `C:\wamp64\backups\syndicatum-live-20260921-183431`
contains the SQL dump and source checkout, not an avatar asset snapshot. The
live webroot also contains none of the three files. A later exact-filename
search found all three in `C:\wamp64\private\syndicatum-avatars`, the live
AvatarService default private runtime directory outside the webroot when
`SYNDICATUM_AVATAR_DIR` is unset. Their sizes and hashes above were checked
twice against those private files. The isolated upgraded clone's
`project_agents` rows (1,7), (1,18), and (1,19) refer to the corresponding
`api/v1/avatar.php?file=...` filenames.

`AdminRecoveryService` reads `SYNDICATUM_AVATAR_DIR` and defaults to
`/var/lib/syndicatum/avatars`; it passes that directory as BackupProducer's
asset root. BackupProducer requires the three bare file names directly under
that root. The Docker default differs from the live WAMP AvatarService
default; the assets must be copied into an isolated clone's private avatar
root before testing backup eligibility.

Follow-up: copy only these hash-verified files to a disposable upgraded clone,
then rerun real encrypted backup and separate restore-stage acceptance. Keep
this separate from the forward-only schema/lineage upgrader and live deployment.
