export const USER_GUIDE_SECTIONS = [
  {
    id: "getting-started",
    title: "Getting started",
    articles: [
      {
        id: "workspace-projects",
        title: "Workspace and projects",
        summary: "Understand the workspace, project columns, and where shared work lives.",
        keywords: ["home", "navigation", "timeline", "team"],
        blocks: [
          { type: "p", text: "The workspace is your starting point. Select a project on the left to open its Timeline, Tasks, and Team." },
          { type: "list", items: [
            "Project cards show total activity and a badge when messages from other participants are still unread. Opening the full Timeline clears the badge after its messages load.",
            "Inactive project tabs show attention counters: unread Timeline messages, unacknowledged Responsibility Inbox requests, and pending AI Proposals when you can review them.",
            "Timeline is the project record for updates, decisions, and requests.",
            "Responsibility Inbox turns addressed action requests into a focused work view.",
            "Tasks track structured work with an owner, status, priority, due date, and acceptance criteria.",
            "Team shows the humans and agents participating in the selected project.",
          ] },
          { type: "note", text: "Select the Syndicatum logo to return to the workspace." },
        ],
      },
      {
        id: "create-project",
        title: "Create a project from a template",
        summary: "Choose a reusable starting point, review its context, and configure its team.",
        keywords: ["new project", "blank project", "template", "agent presets"],
        blocks: [
          { type: "steps", items: [
            "Open the project-list menu and choose New Project.",
            "Browse categories and select a template. Choose Blank Project when you do not want predefined project context or agents.",
            "Select Next, then enter the project name and review the copied project-specific context.",
            "Include only the agent presets you need and choose a provider for each one.",
            "Review the result and create the project. New agents receive fresh claims; provider credentials are never copied from the template.",
          ] },
        ],
      },
      {
        id: "project-plan",
        title: "Plan milestones and deliverables",
        summary: "Organize significant checkpoints, concrete outputs, and the tasks that produce them.",
        keywords: ["milestone", "deliverable", "artifact", "plan", "output", "task"],
        blocks: [
          { type: "steps", items: [
            "Open a project and choose Project plan from the project menu.",
            "Add a milestone for an important checkpoint or target date. Milestones are optional containers, not tasks.",
            "Add deliverables beneath a milestone or leave them standalone. Assign one accountable person or AI agent and optionally add the final artifact link.",
            "Select a milestone or deliverable title, status, date, or accountable owner to edit it in place. Save or cancel the field explicitly.",
            "Use a milestone drag handle to change checkpoint order. Use a deliverable drag handle to reorder it or move it to another milestone; keyboard users can pick up with Space or Enter and move with the arrow keys.",
            "When creating or editing a task, choose the deliverable that the task helps produce. Multiple participants may own separate tasks under the same deliverable.",
            "Use Project Status to review milestone readiness, deliverables in review, and blocked outputs.",
            "To let an executive-assistant agent keep the approved plan current, edit that agent and enable Maintain milestone and deliverable progress. The agent can then link or unlink existing tasks to deliverables and update milestone or deliverable statuses with evidence notes. It cannot change task ownership or lifecycle, plan names, owners, dates, hierarchy, or ordering.",
          ] },
          { type: "note", text: "Each drag is saved as one atomic change. If the network outcome is unknown, editing remains locked until you reconcile the current order with the server. Deliverable progress is calculated from linked non-cancelled tasks; a finished task list does not silently approve an output. Permissioned agent updates require the latest version and an evidence note. An incomplete task cannot be newly linked to an already approved or completed deliverable. Syndicatum prevents milestone completion while deliverables remain unready and prevents deliverable approval or completion while linked active tasks remain incomplete." },
        ],
      },
      {
        id: "project-shared-storage",
        title: "Set a shared project folder",
        summary: "Give people and agents one Google Drive folder for generated project files.",
        keywords: ["google drive", "shared storage", "files", "folder", "project instructions"],
        blocks: [
          { type: "steps", items: [
            "Create or choose the Google Drive folder and configure its access in Google Drive.",
            "Open the project menu, choose Edit Project, and paste the HTTPS drive.google.com folder link into Google Drive folder.",
            "Use Project-specific operating instructions to describe the project-wide folder structure, file naming, or handoff rules.",
            "Use an agent's role instructions when that agent needs more specific organization guidance.",
            "Open Project Info to confirm or open the configured shared folder.",
          ] },
          { type: "note", text: "Syndicatum stores and shares the folder link as project context; it does not manage Google Drive permissions or upload files itself. An agent can use the folder only when its current environment has authorized Google Drive access, and it must not report an upload as successful without confirmation." },
        ],
      },
      {
        id: "project-owner-status",
        title: "Review project status",
        summary: "Use the owner-only status view to spot progress, activity, and work needing attention.",
        keywords: ["owner", "status", "dashboard", "progress", "blocked", "overdue", "activity"],
        blocks: [
          { type: "steps", items: [
            "Open a project you own, open the project menu beside its title, and choose Project status.",
            "Review At a glance and Task progress first. Completed work is shown as a ratio and percentage, and the active team distinguishes people, AI agents, and connected systems.",
            "Change Project activity between 7, 14, and 30 days to compare work outcomes such as opened, completed, blocked, reviewed, and reassigned tasks. Message volume remains a secondary count.",
            "Use Needs attention to review blocked, in-review, and overdue tasks. Select an item to open its task details, or load another bounded page when more results are available.",
            "Review Team and Integrations for participant and connection readiness.",
          ] },
          { type: "note", text: "Sections request their data one at a time to keep database load predictable. Each section retains its own result, error, and retry state, so an unavailable section does not prevent the others from being used." },
        ],
      },
      {
        id: "ai-project-proposals",
        title: "Review AI project proposals",
        summary: "Let project agents suggest better project details, milestone plans, or agent roles while humans retain approval authority.",
        keywords: ["AI", "MCP", "proposal", "project details", "milestone", "deliverable", "agent setup", "role", "approve"],
        blocks: [
          { type: "steps", items: [
            "An authorized project agent submits a focused project-detail, milestone-and-deliverable plan, new-agent, or agent-profile proposal through MCP.",
            "As a project owner or administrator, open the AI Proposals tab in the timeline column. The project menu shortcut opens the same tab.",
            "Choose Review for a pending proposal, inspect the rationale, target agent when applicable, and every proposed field, then add an optional review note.",
            "Choose Approve and apply to commit the reviewed change, or Reject to preserve the current setup.",
            "Configure credentials or runtime activation separately when an approved proposal creates a new agent profile.",
          ] },
          { type: "list", items: [
            "Use project-detail proposals when an agent can clarify an incomplete brief after reading milestones and discussions.",
            "Use project-plan proposals when an agent can turn broad outcomes into a bounded hierarchy of milestones and deliverables. Approval creates the hierarchy atomically but does not create tasks.",
            "Use new-agent proposals when a growing plan needs a specialist, such as an accessibility reviewer or release coordinator.",
            "Use profile-update proposals when an existing agent's actual responsibilities or supervisor have changed.",
          ] },
          { type: "note", text: "The tab updates through Realtime when an agent submits a proposal or a reviewer makes a decision, so no browser refresh is needed. It also retains the proposal history: choose View details on an approved or rejected proposal to inspect its decision record. Proposals never contain credentials, tokens, scopes, webhooks, claim codes, activation settings, or working directories. Agents cannot approve their own suggestions." },
        ],
      },
      {
        id: "registration-profile-timezone",
        title: "Registration, activation, and timezone",
        summary: "Understand account activation and how Syndicatum chooses your displayed email timezone.",
        keywords: ["register", "activation", "welcome", "timezone", "profile", "system default"],
        blocks: [
          { type: "list", items: [
            "Native registration sends a single-use activation link. Registration does not create a signed-in session until that link is opened and confirmed.",
            "Google and PBB Account registrations are active immediately after the provider verifies the identity.",
            "A welcome message is sent once after successful activation or first-time SSO provisioning.",
            "Edit Profile lets you choose an IANA timezone or Use system default. Administrators set the global default in Settings → General.",
            "Syndicatum stores timestamps in UTC. Invitation expiry uses the recipient profile timezone when available, then the system default, then UTC.",
          ] },
        ],
      },
      {
        id: "notifications-invitations",
        title: "Notifications and project invitations",
        summary: "Review pending invitations without accepting them accidentally.",
        keywords: ["bell", "notifications", "invitation", "accept", "decline", "review"],
        blocks: [
          { type: "p", text: "The bell shows invitation notifications for your account. New notifications arrive through your private multipurpose Realtime user room when Realtime is available." },
          { type: "steps", items: [
            "Open the bell dropdown for recent items, or choose View all notifications to open /notifications.",
            "Choose Review to open the invitation details. Opening the email link or review modal does not join the project.",
            "Choose Accept to join, Decline to reject, or close the modal to leave the invitation pending.",
          ] },
          { type: "note", text: "Email and in-app notifications are attention channels. The invitation remains authoritative in Syndicatum, and only an explicit Accept changes membership." },
        ],
      },
    ],
  },
  {
    id: "connections-setup",
    title: "Connections and setup",
    articles: [
      {
        id: "setup-companion",
        title: "Install the Chrome Companion",
        summary: "Install and authorize browser delivery for ChatGPT and Gemini discussions.",
        keywords: ["chrome extension", "edge", "load unpacked", "device", "browser companion"],
        blocks: [
          { type: "p", text: "The Syndicatum Companion provides browser delivery for ChatGPT and Gemini. Codex uses its own local plugin and does not require the Companion." },
          { type: "link", label: "Download the latest Companion release ZIP", href: "https://github.com/jybanez/syndicatum/releases/latest" },
          { type: "steps", items: [
            "Download the latest syndicatum-companion release ZIP and its SHA-256 file from the official Syndicatum GitHub Releases page, then verify the archive before extracting it.",
            "Extract the ZIP to a permanent local folder. Chrome must continue to have access to this folder while the unpacked extension is installed.",
            "Open chrome://extensions in Chrome or Edge, enable Developer mode, choose Load unpacked, and select the extracted folder that contains manifest.json.",
            "Open Syndicatum Companion, enter the Syndicatum server URL supplied by your operator, and choose Connect device.",
            "Sign in to Syndicatum and approve the matching device code. Return to the Companion and confirm that server, authorization, Realtime, and delivery health are ready.",
            "Keep the browser signed in to the provider. Pending delivery resumes when the browser and bound discussion become available again.",
          ] },
          { type: "note", text: "Unpacked extensions do not update automatically. Keep the installation folder, install only verified official releases, and choose Reload on chrome://extensions after an update. Never enter an agent claim code or agent token in the Companion." },
        ],
      },
      {
        id: "setup-codex",
        title: "Set up Codex Desktop",
        summary: "Install the local plugin, authorize the device, claim an agent, and link a Codex task.",
        keywords: ["codex desktop", "plugin marketplace", "claim code", "copy deeplink", "background listener"],
        blocks: [
          { type: "p", text: "Codex uses the local Syndicatum for Codex plugin and a protected device-local agent profile. Device authorization, agent claiming, and proactive task routing are separate steps." },
          { type: "steps", items: [
            { text: "In a Codex terminal, add the official Syndicatum marketplace.", command: "codex plugin marketplace add jybanez/syndicatum --ref main" },
            { text: "Install the Syndicatum for Codex plugin.", command: "codex plugin add codex@syndicatum" },
            "Restart Codex Desktop and start a new task so the plugin and syndicatum-timeline skill are loaded.",
            "Ask Codex to connect this device to your Syndicatum server and give the device a recognizable name. Complete the one-time browser sign-in and approve the matching device code.",
            "In Syndicatum, a project owner or administrator creates or opens the Codex agent and chooses Credential actions → Generate new claim code.",
            { text: "In the Codex task that will own the identity, start the claim flow. Supply the single-use claim code only when Codex asks for it.", command: "syndicatum bind <project name> <agent identity>" },
            "After the first successful claim, Codex loads the agent's project role and posts one concise introduction to the team. Existing-profile reuse and credential replacement do not post duplicate introductions.",
            "Copy the Codex task deeplink. In Syndicatum, edit the agent, select Codex, paste the codex://threads/... value, and enable proactive activation when notifications should open that task.",
            "Ask Codex to check the connector and protected profile status. A healthy result should identify the intended server, project, and agent without exposing credentials.",
          ] },
          { type: "note", text: "Claim codes expire after 15 minutes, are single-use, and are shown only once. Do not paste them into ChatGPT, Gemini, the Companion, project messages, or source files. Generate a replacement claim only when the existing local profile is intentionally being replaced." },
        ],
      },
      {
        id: "setup-chatgpt",
        title: "Set up ChatGPT",
        summary: "Enable the Syndicatum MCP app, authorize OAuth, and bind one discussion through the Companion.",
        keywords: ["chatgpt", "developer mode", "mcp", "oauth", "bind discussion", "custom app"],
        blocks: [
          { type: "p", text: "ChatGPT uses the Syndicatum MCP app for authoritative project reads and writes. The Companion sends only a metadata wake-up to the exact bound discussion. Availability depends on the ChatGPT plan and workspace permissions." },
          { type: "steps", items: [
            "If Syndicatum is already published in your ChatGPT workspace, enable it under Settings → Apps and continue below. Otherwise, a workspace administrator or authorized developer must enable Developer mode and create a custom MCP app.",
            "For a custom app, use the deployment's HTTPS MCP endpoint ending in /mcp, scan its tools, complete the Syndicatum OAuth sign-in, and create or publish the app according to workspace policy.",
            { parts: [
              "Install and authorize the Syndicatum Companion in the browser using the ",
              { type: "article-link", articleId: "setup-companion", text: "Companion setup article" },
              ".",
            ] },
            "Open the ChatGPT discussion that should represent the agent. Invoke Syndicatum and enter: @Syndicatum bind <project name> <agent name>.",
            "Review the Companion confirmation showing the discussion, project, agent, and whether the identity will be reused or created. Choose Continue only when all details match.",
            "Allow the visible follow-up to diagnose the connection. A successful result identifies the project and agent and reports Discussion Binding: Successful.",
            "Keep the Syndicatum app available in that discussion. When a notification arrives, ChatGPT uses MCP to load the authoritative message, post its detailed response, and acknowledge it after handling.",
          ] },
          { type: "note", text: "Do not use a Codex claim code for ChatGPT. OAuth authorizes the human account; the confirmed per-discussion binding selects the project agent. Cancel an unexpected Companion confirmation rather than approving a mismatched discussion or identity." },
        ],
      },
      {
        id: "setup-gemini",
        title: "Set up Gemini",
        summary: "Bind a Gemini web discussion to an agent through the browser Companion.",
        keywords: ["gemini", "discussion url", "browser relay", "proactive activation"],
        blocks: [
          { type: "p", text: "Gemini currently uses the Companion's protected two-way browser relay. It is not a Gemini-native MCP or command-line integration." },
          { type: "steps", items: [
            { parts: [
              "Install and authorize the Syndicatum Companion using the ",
              { type: "article-link", articleId: "setup-companion", text: "Companion setup article" },
              ", and remain signed in to Gemini in the same browser profile.",
            ] },
            "Create or open the dedicated Gemini discussion for the project agent and copy its exact https://gemini.google.com/app/... URL.",
            "In Syndicatum, a project owner or administrator creates or edits the agent, selects Gemini as the provider, pastes the exact discussion URL, and enables proactive activation.",
            "Keep the bound discussion available. When addressed work arrives, the Companion inserts it into that discussion and waits for the matching assistant response to settle.",
            "Verify the connection with a non-sensitive request. The captured response should appear in the Syndicatum timeline under the configured Gemini agent, and the originating request is acknowledged only after the response is posted.",
          ] },
          { type: "note", text: "Do not put an agent token or claim code in Gemini or the Companion. If the browser is closed, signed out, busy, or the discussion is unavailable, delivery remains pending and retries when the required browser state returns." },
        ],
      },
    ],
  },
  {
    id: "communication-work",
    title: "Communication and work",
    articles: [
      {
        id: "updates-and-action-requests",
        title: "Update or action request?",
        summary: "Choose the message intent that matches what you expect from recipients.",
        keywords: ["message", "intent", "fyi", "request", "direct"],
        blocks: [
          { type: "terms", items: [
            ["Update / FYI", "Shares information. A direct recipient may acknowledge it, but no responsibility lifecycle is created."],
            ["Action request", "Asks one or more addressed participants to do or decide something. It appears in the Responsibility Inbox and has an accountable lifecycle."],
          ] },
          { type: "note", text: "Use an action request only when you can state the expected response or outcome. Use an update when awareness is enough." },
        ],
      },
      {
        id: "action-request-vs-task",
        title: "Action request versus task",
        summary: "Know when conversational responsibility should become structured project work.",
        keywords: ["convert", "source message", "structured work"],
        blocks: [
          { type: "terms", items: [
            ["Action request", "Conversation-first. It preserves who asked, who currently owns the response, acknowledgement, handoffs, blockers, and the proposed resolution."],
            ["Task", "Plan-first. It has a title, description, assignee, priority, due date, acceptance criteria, status, and immutable activity history."],
          ] },
          { type: "p", text: "Convert an action request to a task when the work needs planning, progress tracking, acceptance criteria, or a durable deliverable. The originating message remains linked as evidence, and the same request cannot be converted twice." },
        ],
      },
      {
        id: "addressing-and-acknowledgement",
        title: "Addressing, replies, and acknowledgement",
        summary: "Understand who is expected to respond and what acknowledgement means.",
        keywords: ["reply", "acknowledge", "broadcast", "recipient"],
        blocks: [
          { type: "list", items: [
            "Direct addressees are the people or agents expected to respond; mentions provide visibility without assigning responsibility.",
            "A broadcast notifies every active participant. Use it for project-wide information, not targeted work.",
            "Reply keeps the conversation connected to its parent message.",
            "Acknowledge means you have seen an addressed message. It does not complete an action request and does not close a task.",
          ] },
        ],
      },
      {
        id: "system-messages",
        title: "System messages",
        summary: "Recognize durable project events generated by Syndicatum.",
        keywords: ["system event", "automatic message", "task status", "timeline history"],
        blocks: [
          { type: "p", text: "Syndicatum adds a quiet system message to the project Timeline when selected important events commit successfully. Supported events include task creation, assignment, and status changes, plus authenticated events from configured external integrations." },
          { type: "list", items: [
            "System messages use the Syndicatum marker and identify the participant whose action triggered the event.",
            "A visible severity label distinguishes informational, successful, warning, error, and critical events without relying on color alone.",
            "Open View task to inspect the task represented by a task event.",
            "Use the Timeline's Message type filter to show all messages, only messages from people and agents, or only system messages.",
            "System messages are immutable and cannot be edited, removed, replied to, or converted into duplicate tasks.",
            "Routine reads, searches, refreshes, and ordinary task-detail edits do not create system messages.",
          ] },
          { type: "note", text: "System messages provide readable project history. The Administration Audit Log remains the detailed security and administrative record." },
        ],
      },
    ],
  },
  {
    id: "responsibility-inbox",
    title: "Responsibility Inbox",
    articles: [
      {
        id: "responsibility-inbox-overview",
        title: "What the Responsibility Inbox shows",
        summary: "Use the Inbox to find direct work and understand what needs attention.",
        keywords: ["my work", "direct work", "requests"],
        blocks: [
          { type: "p", text: "The Responsibility Inbox is a live projection of direct action requests in the current project. It does not replace the Timeline; it organizes the same authoritative messages by responsibility state." },
          { type: "p", text: "Each card identifies the request, requester, current responder, lifecycle state, and the actions available to you. View original message opens the source in a modal so you keep your Inbox position." },
        ],
      },
      {
        id: "responsibility-views",
        title: "Responsibility Inbox views",
        summary: "What each Show filter includes and when to use it.",
        keywords: ["unacknowledged", "waiting", "blocked", "handoffs", "decisions", "disputed", "unassigned", "resolved"],
        blocks: [
          { type: "terms", items: [
            ["My work", "Open requests where you are the current responder, plus decisions or handoffs that specifically need your action."],
            ["Unacknowledged", "Direct requests addressed to you that you have not acknowledged yet."],
            ["Waiting on others", "Requests you made or are watching where another participant currently owns the next action."],
            ["All direct work", "All responsibility records you are permitted to see in the project, regardless of current owner or state."],
            ["Blocked", "Requests whose current responder recorded a blocker."],
            ["Handoffs", "Requests with a proposed transfer of responsibility waiting for acceptance or decline."],
            ["Decisions needed", "A responder proposed a resolution and the requester or moderator must accept it or request changes."],
            ["Disputed", "The proposed resolution was not accepted and further work is expected."],
            ["Unassigned", "The responsible participant is no longer active or the request needs a new owner."],
            ["Resolved", "Requests completed by an accepted work resolution, approval decision, review decision, or withdrawal."],
            ["Historical / unknown", "Older records that predate the current responsibility model or cannot be classified safely."],
          ] },
        ],
      },
      {
        id: "responsibility-lifecycle",
        title: "Work an action request",
        summary: "Use the response workflow selected by the requester.",
        keywords: ["work request", "approval", "review", "start work", "approve", "deny", "accept", "revision"],
        blocks: [
          { type: "list", items: [
            "Work is for producing an outcome: acknowledge it, start or block the work, submit a proposed resolution, and wait for the requester to accept it or request changes.",
            "Approval is for a decision on a proposed action: the recipient chooses Approve or Deny.",
            "Review is for evaluating an existing result: the recipient chooses Accept or Request revision.",
          ] },
          { type: "steps", items: [
            "Acknowledge the request to confirm that you have seen it.",
            "Use the actions shown for the request type. Syndicatum does not offer work-production actions on Approval or Review requests.",
            "Offer handoff when another active participant should own the response. Responsibility changes only after they accept.",
            "Record a concrete reason or evidence note for the response. The resulting decision appears in the timeline and notifies the requester.",
          ] },
          { type: "note", text: "Each work update is recorded as immutable workflow evidence linked to the original request. Syndicatum directly addresses the requester and any participant who owns the next action, so they receive the update without broadcasting it to the whole project." },
        ],
      },
    ],
  },
  {
    id: "tasks",
    title: "Tasks",
    articles: [
      {
        id: "tasks-overview",
        title: "Create and track tasks",
        summary: "Use tasks for structured work with explicit ownership and completion criteria.",
        keywords: ["assignee", "priority", "due", "acceptance criteria", "status"],
        blocks: [
          { type: "list", items: [
            "Title names the outcome; Description supplies working context.",
            "Assignee owns the next task lifecycle action. The creator remains the immutable task giver.",
            "Acceptance criteria define what must be true before completion.",
            "Priority and due date communicate urgency; they do not change authorization.",
            "Activity records every lifecycle change and cannot be rewritten.",
          ] },
          { type: "p", text: "Typical flow: Open → In progress → In review → Completed. The assignee submits reviewable work as In review; a supervisor or manager returns revisions to In progress or marks accepted work Completed. Use Blocked when progress cannot continue, with a specific reason. Only authorized participants see the actions they may perform." },
        ],
      },
      {
        id: "convert-request-to-task",
        title: "Convert an action request to a task",
        summary: "Carry source evidence into structured tracking without duplicating work.",
        keywords: ["convert to task", "linked task", "duplicate"],
        blocks: [
          { type: "steps", items: [
            "Open the action request in the Responsibility Inbox.",
            "Choose Convert to task when the request does not already have a linked task.",
            "Review the prefilled title and description, then add an assignee, priority, due date, and acceptance criteria as needed.",
            "Create the task. The request card will expose the linked task, and the task will retain the source message as evidence.",
          ] },
        ],
      },
    ],
  },
  {
    id: "templates",
    title: "Templates",
    articles: [
      {
        id: "templates-overview",
        title: "Project templates",
        summary: "Reuse project context and provider-neutral agent teams.",
        keywords: ["built-in", "category", "preset", "blank project"],
        blocks: [
          { type: "p", text: "Templates provide reusable project descriptions, project-specific operating instructions, and optional agent-role presets. Categories make the library easier to browse." },
          { type: "list", items: [
            "Built-in templates are maintained by Syndicatum and cannot be edited or archived.",
            "Custom templates can be created, edited, and archived by authorized administrators.",
            "Blank Project intentionally supplies no description, project-specific instructions, or preset team.",
            "Every project also receives the shared read-only governance baseline; it is separate from editable project-specific instructions.",
          ] },
        ],
      },
    ],
  },
  {
    id: "agents",
    title: "Agents",
    articles: [
      {
        id: "agents-overview",
        title: "Agents and supervision",
        summary: "Understand agent roles, providers, claims, and reporting lines.",
        keywords: ["codex", "chatgpt", "gemini", "claim", "supervisor"],
        blocks: [
          { type: "list", items: [
            "An agent is a project participant with a defined role and authorization boundary.",
            "The provider identifies where the agent runs; the role describes what it is responsible for.",
            "A supervisor reviews or directs an agent where the project structure requires it.",
            "Claims and tokens are credentials. Share them only through the intended secure activation flow and never paste them into project messages.",
          ] },
        ],
      },
    ],
  },
  {
    id: "integrations",
    title: "External integrations",
    articles: [
      {
        id: "integrations-overview",
        title: "Connect an external system",
        summary: "Create a project-scoped integration and protect its callback URL.",
        keywords: ["webhook", "callback", "github", "external system", "rotate", "revoke"],
        blocks: [
          { type: "p", text: "Project owners and administrators can add an external system from the Team actions menu. The integration appears as a non-addressable participant and may publish only system events." },
          { type: "list", items: [
            "Choose Add integration, enter a display name and provider-neutral system identifier, and select the people or agents who should receive each incoming event as an FYI.",
            "Copy the callback URL immediately. Its embedded credential is shown only once and cannot be recovered later.",
            "Configure the external system to POST application/json to the complete callback URL over HTTPS. If it offers a separate webhook Secret field, leave that field blank.",
            "Open an integration-authored timeline message's menu and choose Show event payload to inspect the complete accepted JSON object. The payload is part of the project timeline and is visible to every project member, so do not send credentials or data that project members should not see.",
            "Select an integration participant in Team to disable or re-enable it, rotate or revoke its callback URL, or remove it permanently. Disabled integrations remain visible to project owners and administrators so their controls stay accessible.",
            "Edit the integration to change its FYI recipients. Each notified agent decides how to respond from its existing role instructions and reports through its configured supervisor when applicable.",
            "Rotating or revoking takes effect immediately. Existing timeline messages and audit history remain available.",
          ] },
        ],
      },
    ],
  },
  {
    id: "administration",
    title: "Administration",
    audience: "administrator",
    articles: [
      {
        id: "administration-overview",
        title: "Administration surfaces",
        summary: "Know which controls are global and why they may not appear for every user.",
        keywords: ["users", "audit", "settings", "backup", "delivery health", "permissions"],
        blocks: [
          { type: "p", text: "Administrator surfaces control system-wide behavior and appear only when your account has the required capability. Project permissions do not automatically grant global administration." },
          { type: "terms", items: [
            ["Users", "Manage system users and account access."],
            ["Audit", "Review security- and administration-relevant events."],
            ["Templates", "Manage custom reusable project foundations and inspect built-ins."],
            ["Settings", "Configure supported system-wide behavior and integrations."],
            ["Backup / Restore", "Create, verify, inspect, and restore protected recovery artifacts."],
            ["Delivery health", "Inspect release and operational readiness signals."],
          ] },
          { type: "note", text: "Encrypted restore inspection and staging require the production Linux/POSIX runtime and its private owner-only staging directory. Native Windows development environments can create and manage the application, but cannot perform this secure restore workflow directly; use the supported Docker/Linux deployment path." },
          { type: "list", items: [
            "A current portable full-clone backup includes the Linux Docker/Compose deployment scaffold, worker loop, PHP worker targets, schema baseline, and migrations. The Linux worker does not use the Windows PowerShell supervisor.",
            "The backup intentionally excludes the host .env file and recovery key. After Kickstart restores the files and isolated database, an operator must provide target-local protected settings, start the Compose app, database, and worker services, and verify both application and worker health.",
            "Backups created before this capability was deployed remain unchanged. Create a new backup and rehearse that exact artifact before relying on it for Linux recovery.",
          ] },
        ],
      },
    ],
  },
  {
    id: "reference",
    title: "Glossary and troubleshooting",
    articles: [
      {
        id: "glossary",
        title: "Glossary",
        summary: "Quick definitions for common Syndicatum terms.",
        keywords: ["definitions", "project sequence", "revision", "realtime"],
        blocks: [
          { type: "terms", items: [
            ["Participant", "A human, agent, or external integration identity that belongs to a project. Integration identities can contribute system events but cannot receive responsibility or task assignments."],
            ["Project sequence", "The durable order of authoritative project events."],
            ["Revision", "A preserved edit to a message; the current visible revision is shown by default."],
            ["Realtime", "Live delivery of committed updates to connected clients. A refresh or rejoin sync closes any gap."],
            ["Governance baseline", "Read-only rules applied to every project and included in agent bootstrap."],
          ] },
        ],
      },
      {
        id: "troubleshooting",
        title: "Troubleshooting",
        summary: "Resolve common display, access, and live-update questions.",
        keywords: ["cache", "missing", "refresh", "permission", "stale"],
        blocks: [
          { type: "list", items: [
            "If a control is missing, confirm that you are in the intended project and have the required project or system capability.",
            "If a live update has not appeared, use Refresh once. Realtime normally updates the view automatically, while refresh closes a possible connection gap.",
            "If the interface still shows an older release after deployment, reload without cache before reporting a persistent UI defect.",
            "If an action has an uncertain outcome after a network failure, inspect the current server state before trying it again.",
          ] },
        ],
      },
    ],
  },
];

export function visibleGuideSections({ administrator = false } = {}) {
  return USER_GUIDE_SECTIONS.filter((section) => section.audience !== "administrator" || administrator);
}

export function guideArticle(articleId, options = {}) {
  const sections = visibleGuideSections(options);
  for (const section of sections) {
    const article = section.articles.find((entry) => entry.id === articleId);
    if (article) return { section, article };
  }
  const section = sections[0];
  return { section, article: section.articles[0] };
}

export function searchGuide(query, options = {}) {
  const needle = String(query || "").trim().toLocaleLowerCase();
  return visibleGuideSections(options).map((section) => ({
    ...section,
    articles: section.articles.filter((article) => {
      if (!needle) return true;
      const searchable = [section.title, article.title, article.summary, ...(article.keywords || []),
        ...(article.blocks || []).flatMap((block) => [block.text || "", ...(block.items || []).flatMap((item) => {
          if (Array.isArray(item)) return item;
          if (item && typeof item === "object") return [
            item.text || "",
            item.command || "",
            ...(item.parts || []).map((part) => typeof part === "string" ? part : part?.text || ""),
          ];
          return [item];
        })])];
      return searchable.join(" ").toLocaleLowerCase().includes(needle);
    }),
  })).filter((section) => section.articles.length);
}
