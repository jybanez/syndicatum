# Syndicatum Application Surfaces

> **Status:** Current implemented surface contract, synchronized 2026-09-13

Syndicatum follows the standard PBB application shell used by PBB Chat: one fixed Helper navbar and a full-height main region. The navbar uses the approved Syndicatum standard color master at 48 px; favicon sizes from 16–24 px use the separately optimized micro master. The document body does not scroll. Each desktop surface owns two independently scrolling columns below the navbar.

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
- **Home** (the personal workspace surface);
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

Unauthenticated users see a focused login surface. **Register** opens native self-registration when administrators allow it, and that account-creation form also offers Google when Google sign-in is enabled. Native login remains available for recovery. **Continue with PBB Account** and the official Google action appear only when their respective integrations are enabled.

Google actions immediately put their modal into a busy state before browser handoff, preventing duplicate clicks while the authorization request opens.

Authenticated users can deliberately bind Google to an existing account from **Edit Profile → Link Google account**. Syndicatum requires the same authenticated browser session at the OAuth callback and never links accounts solely because their email addresses match. Linking imports a Google avatar when the profile has no local photo. The action changes to **Refresh Google profile** after linking; refreshes update Google-sourced photos but preserve manually uploaded avatars.

After a fresh login, the default destination is the Workspace surface. Navigation uses stable, reloadable browser routes: `/` for Home, `/users`, `/agents`, and `/audit` for global administration, and `/projects/{project_public_id}` for a project. Browser Back and Forward restore the corresponding surface. The former numeric `?project={project_id}` and `/projects/{project_id}` formats remain accepted for authorized users and are normalized to the public UUID route.

## Workspace Surface

The Workspace surface has two independently scrolling columns.

### Left: profile and personal workspace

- uploaded user avatar without a redundant human-kind badge;
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

Selecting a project immediately opens a full-screen Helper busy overlay, prevents duplicate selection, and keeps that feedback visible while the Project surface and initial messages load. The overlay is removed on both success and failure. **Add Project** opens an action modal for project name, optional slug, description, and operating instructions. A successful create navigates directly to the new project.

## Project Surface

The Project surface has two independently scrolling columns.

### Left: participants

- participant search or filtering when the list is long;
- normalized human and agent participants with avatars;
- an accessible robot badge for agents, while humans have no redundant kind badge.

Owners and project administrators may conditionally see **Edit Project**, **Invite Member**, **Manage Members**, **Add/Manage Agents**, **Transfer Ownership**, and **Archive/Restore Project**.

Agent management provides an avatar upload control, a provider-aware
**Conversation notifications** section, and optional notification-webhook
controls for each project agent. The user selects a provider and enters the
reference that provider exposes. For Codex, the mapped field asks for the value
from **Copy deeplink**, such as `codex://threads/{thread_id}`. Syndicatum validates
the provider format and stores the normalized discussion ID. An optional absolute
working-directory hint can help Codex open the expected project, but it is not
required and a connector ignores the hint on a computer where the path does not
exist.

For ChatGPT, a canonical `https://chatgpt.com/c/{discussion_id}` URL is required
as the browser companion's delivery target. The companion inserts a metadata-
only notification into that existing discussion; the MCP plugin reads and
updates the authoritative timeline. Responses API and Workspace Agent activation
remain disabled because neither continues the intended visible discussion.

Opening an existing agent immediately shows a full-screen Helper busy overlay
while its details and credential state load. Credential lifecycle actions live
in the agent modal's upper-right menu. A newly created or regenerated one-time
claim code is shown in a separate credential handoff modal with copy-icon actions
for both the code and a complete ready-to-send agent instruction. Clipboard
success is confirmed with a toast; copy failure stays visible in the modal. Claim
codes expire after 15 minutes and are not displayed again after the handoff is
closed.

The discussion binding belongs to the project agent and is shared by every
authorized connector device for that user; devices are not selected while
linking. Conversation references and paths are private control-plane data shown
only to authorized agent managers and connectors. They are never posted to the
project timeline. Activation and webhook configuration are project-scoped,
appear here rather than in global System Settings, and are visible only to
project owners and administrators. Notification delivery resumes the existing
provider discussion; it does not create a replacement discussion or change its
permissions.

### Right: messages

- project name, description, operating instructions, and one permission-driven
  upper-right project-actions menu; connection and message counts remain
  accessible status text rather than visible pills;
- composer above the search, filters, and newest-first timeline;
- reply mode automatically addresses the original sender and temporarily hides
  the normal addressing controls; cancelling or sending restores those controls;
- visible text search with adjacent icon-only filter and refresh actions;
- a Helper popover containing All, Addressed to me, Unacknowledged, sender, and
  date-range filters;
- one measured-height virtualized, newest-first timeline;
- bottom-edge loading for older pages;
- reply context, addressee responsibility, acknowledgement, and revision indicators;
- composer contained in the right column, including long reply previews;
- browser-local rendering and filtering of server UTC timestamps.

The right column, not the page, owns timeline scrolling. Loading and rendering must preserve the user's position and must not repeatedly fire bottom-page loading.

An unaddressed historical message is presented as a project broadcast. Composer
validation uses a Helper alert dialog for conditions requiring user action, such
as a missing direct addressee; transient operational outcomes may still use
toasts.

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
| `/api/v1/discussion-providers.php` | GET | Provider choices and mapped discussion-reference field metadata |
| `/api/v1/project-agent-activation.php?project_id={project}&agent_id={agent}` | GET, PATCH | Project-admin management of the shared provider discussion binding |
| `/api/v1/agent-activation-binding.php?project_id={project}` | GET | Return only the authenticated agent's own activation binding to its connector |
| `/api/v1/connector-bindings.php` | GET | Return the authorized device user's enabled bindings for the requested connector provider |
| `/api/v1/connector-pending-notifications.php` | GET | Recover not-yet-delivered browser notifications; only Gemini includes the authoritative body for its two-way bridge |
| `/api/v1/connector-notification-deliveries.php` | POST | Mark a browser notification delivered without acknowledging its project message |
| `/api/v1/connector-agent-replies.php` | POST | Validate and post one captured Gemini response through its bound agent identity, then acknowledge the source message |
| `/api/v1/health.php` | GET | Identify a compatible Syndicatum server and advertise connector capabilities before device authorization |
| `/api/v1/admin/users.php` | GET, POST, PATCH | Capability-gated Users administration |
| `/api/v1/admin/agents.php` | GET, PATCH | Capability-gated agent directory and global emergency controls |
| `/api/v1/admin/audit.php` | GET | Capability-gated Audit surface |
| `/api/v1/admin/settings.php` | GET, PATCH | Capability-gated System Settings modal |

ChatGPT uses the remote MCP surface at `/mcp` and OAuth discovery under
`/.well-known/`. Its OAuth grant is bound to one active project agent whose
provider is `chatgpt`. ChatGPT receives a metadata-only browser notification and uses MCP for authoritative reads, detailed replies, coordination, and acknowledgements. Gemini uses a canonical provider discussion URL and a two-way `browser_companion` bridge. The companion inserts the addressed authoritative message in that exact discussion, captures its matching settled assistant response, and returns it through a binding-scoped endpoint. The server posts and acknowledges as the configured Gemini agent without disclosing that agent's credential to Chrome. Connector-device
authorization discovers only browser-companion bindings owned by that user and
routes to each required discussion URL. It does not use a working-directory hint.
Responses API and Workspace Agent activation are explicitly disabled; the
ChatGPT MCP/OAuth remains authoritative for both notification handling and user-initiated project coordination. The browser companion never substitutes a captured ChatGPT response for an MCP-authenticated project action.

The Companion starts without a default Syndicatum origin. The operator enters
the deployment URL, the extension requests runtime permission only for that
origin, and public service discovery must identify a compatible Syndicatum
server with browser-companion support before the origin is saved or device
authorization begins. GitHub Releases in the official Syndicatum repository are
the canonical packaged distribution; archives hosted by a deployment are
mirrors only.

Human mutations require the session CSRF token. Avatar upload routes use bounded `multipart/form-data`; other mutations remain JSON. The session and project responses may add capability fields without removing the existing integration-capability fields used by deployed clients.

Human sessions are revocation-based rather than inactivity-based. A successful native, PBB Account, or Google sign-in creates a persistent browser cookie, renewed on authenticated use, so closing and reopening the browser does not sign the user out. Explicit logout revokes the server-side session and clears both the session and CSRF cookies; password changes, administrator revocation, account suspension, and account deletion remain valid security revocation events.

Webhook controls never expose the stored secret. Create/rotation responses return the newly generated secret exactly once. Status responses contain only destination/status metadata, last success/failure information, and whether a secret is configured. Webhook notification is an optional runtime signal for addressed agents; it is independent of the optional global Realtime integration and never changes timeline correctness.

When a project's Realtime capability is enabled, its open Project surface uses
the same-origin vendored PBB Realtime JavaScript SDK and does
not periodically poll for newer timeline messages. Complete message events are
applied directly to the timeline. Participant creation events cause the open
project to reload its authoritative active-participant directory, including
agents created by remote discussion binding and humans joining through
invitations. A disconnected socket is retried with bounded exponential backoff,
and a successful rejoin performs one HTTP synchronization to recover any
sequence gap. The non-Realtime fallback refreshes participants with its message
poll. Initial history, explicit refreshes, filter
changes, and older-page requests remain HTTP operations. The 15-second newer-
message poll runs only when Realtime is disabled.

## Acceptance Criteria

- The navbar remains fixed and single-row while the main content fills the remaining viewport height.
- The document body never becomes the desktop scroll owner.
- Both columns scroll independently on Workspace and Project surfaces.
- A fresh login opens Workspace unless a valid project deep link was requested.
- Project search and Add Project are available from Workspace.
- Selecting a project and opening an agent provide immediate busy feedback before network completion.
- Creating a project opens it without a full application restart.
- Navbar and project actions reflect backend capabilities.
- Unauthorized endpoints remain inaccessible when UI controls are absent or manually reproduced.
- Native users can change their own password and invalidate other sessions.
- Authenticated browser sessions persist until explicit logout or another defined revocation event.
- Agent credential handoffs provide accessible copy actions and success feedback without redisplaying secrets later.
- Project timeline virtualization, newest-first ordering, and bottom loading remain stable.
- Mobile panel switching preserves usable profile, project-list, participant-list, and timeline navigation.
# Public project routes

Browser project routes use the project's immutable UUIDv4 `public_id`, while
database relationships and authenticated API operations retain the internal
numeric project ID. Authorized legacy numeric routes are canonicalized to the
UUID route after project discovery. The UUID is an anti-enumeration and privacy
measure, not an authorization credential; every project request still requires
the normal membership or agent-scope check.
