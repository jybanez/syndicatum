# Separate managed-marketplace migration contract — design only

This is not a command or execution authority. Syndicatum 11507 accepts the
classification below and requires a separate owner decision. Request 11502 is
pending; Windows Run-value approval is not reused.

Expected HomeServer preimage, all fields required:

- Marketplace: `syndicatum`, source type Git.
- Source: `https://github.com/jybanez/syndicatum.git`.
- Ref: `codex-v0.2.6`.
- Revision: `c2ed0247fd61dc51bfa8515b1b3882f6a440aa1c`.
- Enabled installed plugin: `codex@syndicatum`, manifest version 0.2.6.

The sequence model now includes a separate decision gate, exact preimage
revalidation, marketplace migration adapter slot and unrelated-entry verification
before plugin installation. These slots have NO native implementation yet.

Future adapter review requires:

1. Explicit owner approval for this exact marketplace replacement, passing
   disposable semantics evidence, and final exact-source Commercial execution
   approval. Missing any gate => STOP before invoking lifecycle commands.
2. External verified backup of relevant config, marketplace metadata/snapshot and
   complete plugin cache before mutation, with identity/credential/binding/state
   preservation. Keep original failure outcomes and all partial evidence.
3. Compare anchored expected identity against fresh config + managed snapshot +
   installed manifest. Any mismatch, duplicate identity or uncertainty => STOP.
4. Official exact-target marketplace command only, with tested effects. No wildcard,
   broad upgrade, undocumented overwrite flag, or hand-edited configuration.
5. Independently verify only the approved entry was removed/replaced and unrelated
   marketplace/plugin registrations remain unchanged. Review any CLI cache removal
   or internal rollback behavior before use; do not assume removal is config-only.
6. Add the exact validated local source only after the separate removal phase is
   confirmed and safe local-path semantics are proven. Verify candidate bytes in
   source and managed cache; never fetch main or the nonexistent candidate tag.
7. An uncertain command result stops permanently for that attempt. No automatic
   retry, rollback, cleanup or alternative command. Preserve the original error.

The isolated Windows test host remains unresolved; do not use HomeServer as a
fixture. No live backup, marketplace/Task/Run mutation, stop/restart, install,
message send or state repair is authorized by this contract's creation or merge.
