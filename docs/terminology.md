# Terminology and compatibility

**Syndicatum** is the product name. Current descriptions should use it consistently.
`PBB` and `chatviewer` appear in older product descriptions and installation
examples; those product names are retained only for compatibility or history.

Some similar names identify separate dependencies or services rather than the
product. **PBB Account** is the optional external identity provider and the exact
label still shown in sign-in UI. **PBB Realtime** identifies the optional delivery
service/SDK, called **Realtime** in current descriptive prose. Neither name means
that Syndicatum itself has a second product name. Preserve upstream attribution.

Do not rename operational values as part of a wording cleanup:

- Configuration keys such as `PBB_AGENTCHAT_SECRET`, database names such as
  `pbb_agentchat`, and the `pbb_account` API enum retain their exact spellings.
- Existing installation paths containing `pbb` or `chatviewer`, service hostnames,
  source repository URLs, vendored paths, and historical migration references
  identify real resources. Follow the applicable runbook and installation settings.
- `pbb-chat-log` and `pbb-chat-token.local.json` identify compatibility artifacts.
  Changing their names in instructions can prevent migration or discovery.
- Project and agent display names in older command and payload examples identify
  the example installation's records. Use your actual configured names; the
  documentation does not request renaming those records.

Historical proposals, checklists, and rollout evidence retain the names used at
the time and are marked accordingly. Consult the current [Project API](project-api-v1.md),
[application guide](application-surfaces.md), and release-specific operator
instructions for current behavior. A terminology change does not change a runtime,
authentication provider, compatibility contract, or support boundary.
