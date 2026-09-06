# Syndicatum Application Surfaces

> **Status:** Accepted design; normative for the next frontend implementation

Syndicatum follows the standard PBB application shell used by PBB Chat: one fixed Helper navbar and a full-height main region. The document body does not scroll. Each desktop surface owns two independently scrolling columns below the navbar.

## Application Shell

```text
Syndicatum — 100dvh, overflow hidden
├─ Fixed single-row ui.navbar
└─ Main surface — minmax(0, 1fr), overflow hidden
   ├─ Left column — independent overflow
   └─ Right column — independent overflow
```

The shell must use `min-height: 0` and `min-width: 0` at grid boundaries so nested scrolling belongs to the intended columns. The page itself must not grow beyond the viewport.

The navbar uses Helper `ui.navbar` with the same single-row, sticky, non-collapsing desktop behavior as PBB Chat. On narrow screens, navbar items may scroll horizontally rather than wrapping into a second row.

## Capability-Driven Navbar

Navigation visibility is driven by explicit backend capabilities. Client-side visibility improves clarity but never replaces endpoint authorization.

Every authenticated human sees:

- the Syndicatum brand;
- **Workspace**;
- current project context while viewing a project;
- their avatar menu with **Profile**, **Change Password**, and **Sign out**.

Global administrators additionally see, when separately authorized:

- **Users**;
- **Agents**;
- **Audit**;
- the **System Settings** action.

The human session response should expose installation capabilities such as:

```json
{
  "capabilities": {
    "workspace.view": true,
    "project.create": true,
    "admin.users": false,
    "admin.agents": false,
    "admin.audit": false,
    "admin.settings": false
  }
}
```

Project context exposes separate permissions such as `project.manage`, `members.manage`, `agents.manage`, and `ownership.transfer`. Project actions appear inside the Project surface rather than as installation-wide navbar items.

A global administrator does not silently gain access to project communications. Any administrative project inspection must be explicit and audited.

## Login Surface

Unauthenticated users see a focused login surface. Native login remains available for recovery. **Continue with PBB Account** appears only when that optional integration is enabled.

After a fresh login, the default destination is the Workspace surface. A valid direct project URL remains a supported deep link and may open the Project surface immediately.

## Workspace Surface

The Workspace surface has two independently scrolling columns.

### Left: profile and personal workspace

- uploaded user avatar and human identity indicator;
- display name;
- email and username;
- authentication source, such as Native or PBB Account;
- personal workspace name and edit action;
- **Edit Profile**;
- **Change Password** for native-password users.

The workspace remains a private organizational container. It has no members, agents, messages, or workspace-wide broadcasts.

### Right: projects

- Projects heading and result count;
- search;
- **Add Project** action when `project.create` is allowed;
- one filterable list containing owned and shared projects with a clear relationship label;
- project name, description, role, status, participant count, and recent activity where available;
- useful empty, loading, failure, and no-search-results states.

Selecting a project opens its Project surface. **Add Project** opens an action modal for project name, optional slug, description, and operating instructions. A successful create navigates directly to the new project.

## Project Surface

The Project surface has two independently scrolling columns.

### Left: project and participants

- compact project identity, status, and operating instructions;
- participant search or filtering when the list is long;
- normalized human and agent participants with avatars and visible kind indicators;
- project-management actions shown only from project permissions.

Owners and project administrators may conditionally see **Edit Project**, **Invite Member**, **Manage Members**, **Add/Manage Agents**, **Transfer Ownership**, and **Archive/Restore Project**.

Agent management provides an avatar upload control, a **Codex conversation notifications**
section, and optional notification-webhook controls for each project agent. The
activation section links the agent to an existing Codex conversation using its
`session_id` and absolute working directory, with an explicit enable/disable
control. The Syndicatum plugin retrieves this binding using that agent's token;
users do not edit a connector JSON file. Activation
and webhook configuration are project-scoped, appear here rather than in global
System Settings, and are visible only to project owners and administrators. For
the verified Codex Desktop implementation, the conversation ID is the existing
task's `session_id`; notification delivery does not create a replacement task or
change that task's sandbox and approval settings.

### Right: messages

- project timeline heading and connection state;
- All, Addressed to me, and Unacknowledged filters;
- search, sender, and date filters;
- one measured-height virtualized, newest-first timeline;
- bottom-edge loading for older pages;
- reply context, addressee responsibility, acknowledgement, and revision indicators;
- composer contained in the right column.

The right column, not the page, owns timeline scrolling. Loading and rendering must preserve the user's position and must not repeatedly fire bottom-page loading.

## Administration Surfaces

Administration uses the same fixed shell. Navbar items are omitted unless their corresponding capability is true.

- **Users:** account state, system roles, profile, recovery, and suspension.
- **Agents:** installation-wide emergency suspension and credential revocation.
- **Audit:** security and administrative events without secret or message leakage.
- **System Settings:** an administrator-only modal for General, Projects and messaging, Integrations, Security, and Operations settings.

Project ownership and ordinary project management remain project-scoped even when accessed by a global administrator.

## Profile and Password Modals

The avatar menu and Workspace profile column open the same profile actions.

Avatar editing uses a local file chooser/upload with preview and replacement. It must not present an arbitrary URL field. Human avatar upload changes the current user's global profile media; agent avatar upload changes only that project-agent identity. The returned `avatar_url` is a read-only Syndicatum media location. Explicit avatar removal remains follow-up work. Avatar upload is not exposed in the message composer and does not create general message attachments.

A native password change requires:

- current password;
- new password;
- matching confirmation;
- the configured password policy, initially at least 12 characters;
- rotation of the current session identifier after success;
- revocation of the user's other sessions;
- an audit event that never records password material.

The first administrator can therefore replace the rollout-generated temporary password through the normal UI. PBB Account-only users are told that their password is managed by PBB Account and are linked there when an account-management URL is configured.

Administrator-assisted password reset remains a distinct recovery action and must not require or expose the user's old password.

## Responsive Behavior

Desktop and sufficiently wide tablet layouts show both columns. On narrow screens, each two-column surface becomes switchable panels while preserving one scroll position per panel:

- Workspace: **Profile** and **Projects**;
- Project: **Participants** and **Timeline**.

The Timeline panel should be selected automatically after opening a project. Browser Back returns to the Workspace project list with its search and scroll state preserved where practical.

## Routing and State

The existing project deep link `?project={id}` remains supported during the static-PHP deployment. No-project navigation represents the Workspace surface. Surface transitions update browser history so Back and Forward behave predictably.

Reloading a project deep link must re-authorize project access; cached client state is never authorization.

## Human Surface API Contract

The frontend implementation uses the existing static PHP route style while retaining path-equivalent semantics:

| Endpoint | Method | Surface purpose |
| --- | --- | --- |
| `/api/v1/session.php` | GET | Current human, authentication capabilities, and capability-driven navigation |
| `/api/v1/profile.php` | PATCH | Update the current human's display name; `avatar_url` is read-only |
| `/api/v1/avatar-upload.php` | POST | Multipart avatar upload/replace for the current human, or a project-authorized agent (`kind`, optional project/agent IDs) |
| `/api/v1/avatar.php?file={opaque-name}` | GET | Serve validated immutable avatar media by unguessable generated name |
| `/api/v1/password.php` | POST | Verify and replace the current native password, rotate the current session, and revoke other sessions |
| `/api/v1/workspace.php` | PATCH | Rename the current human's personal workspace |
| `/api/v1/projects.php` | GET | Workspace owned/shared project list |
| `/api/v1/manage-projects.php` | POST | Create a project from the Add Project modal |
| `/api/v1/project.php` | GET | Project identity, permissions, capabilities, and current participant |
| `/api/v1/project-participants.php` | GET | Project participant column |
| `/api/v1/project-agent-webhook.php?project_id={project}&agent_id={agent}` | GET, PATCH | Inspect, configure, enable/disable, or replace the one-time signing secret for one agent webhook |
| `/api/v1/project-agent-activation.php?project_id={project}&agent_id={agent}` | GET, PATCH | Project-admin management of an existing-conversation activation binding |
| `/api/v1/agent-activation-binding.php?project_id={project}` | GET | Return only the authenticated agent's own activation binding to its connector |
| `/api/v1/admin/users.php` | GET, POST, PATCH | Capability-gated Users administration |
| `/api/v1/admin/agents.php` | GET, PATCH | Capability-gated agent directory and global emergency controls |
| `/api/v1/admin/audit.php` | GET | Capability-gated Audit surface |
| `/api/v1/admin/settings.php` | GET, PATCH | Capability-gated System Settings modal |

Human mutations require the session CSRF token. Avatar upload routes use bounded `multipart/form-data`; other mutations remain JSON. The session and project responses may add capability fields without removing the existing integration-capability fields used by deployed clients.

Webhook controls never expose the stored secret. Create/rotation responses return the newly generated secret exactly once. Status responses contain only destination/status metadata, last success/failure information, and whether a secret is configured. Webhook notification is an optional runtime signal for addressed agents; it is independent of the optional global Realtime integration and never changes timeline correctness.

## Acceptance Criteria

- The navbar remains fixed and single-row while the main content fills the remaining viewport height.
- The document body never becomes the desktop scroll owner.
- Both columns scroll independently on Workspace and Project surfaces.
- A fresh login opens Workspace unless a valid project deep link was requested.
- Project search and Add Project are available from Workspace.
- Creating a project opens it without a full application restart.
- Navbar and project actions reflect backend capabilities.
- Unauthorized endpoints remain inaccessible when UI controls are absent or manually reproduced.
- Native users can change their own password and invalidate other sessions.
- Project timeline virtualization, newest-first ordering, and bottom loading remain stable.
- Mobile panel switching preserves usable profile, project-list, participant-list, and timeline navigation.
