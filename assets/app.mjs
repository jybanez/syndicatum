import { uiLoader } from "../vendor/pbb-helper/js/ui/ui.loader.js";

const API = {
  session: "api/v1/session.php",
  projects: "api/v1/projects.php",
  context: "api/v1/project.php",
  participants: "api/v1/project-participants.php",
  messages: "api/v1/project-messages.php",
  acknowledge: "api/v1/project-message-acknowledge.php",
  settings: "api/v1/admin/settings.php",
  integrationTest: "api/v1/admin/integration-test.php",
  profile: "api/v1/profile.php",
  password: "api/v1/password.php",
  workspace: "api/v1/workspace.php",
  manageProjects: "api/v1/manage-projects.php",
  projectInvitations: "api/v1/project-invitations.php",
  projectAgents: "api/v1/project-agents.php",
  projectAgentWebhook: "api/v1/project-agent-webhook.php",
  projectAgentActivation: "api/v1/project-agent-activation.php",
  discussionProviders: "api/v1/discussion-providers.php",
  avatarUpload: "api/v1/avatar-upload.php",
  adminUsers: "api/v1/admin/users.php",
  adminAgents: "api/v1/admin/agents.php",
  adminAudit: "api/v1/admin/audit.php",
};

const state = {
  mode: "boot",
  surface: "workspace",
  projectSearch: "",
  participantSearch: "",
  adminKind: "",
  session: null,
  projects: [],
  project: null,
  participants: [],
  messages: [],
  filters: { primary: "all", q: "", sender: "", from: "", to: "" },
  draft: { mode: "direct", addressees: [], replyTo: null, idempotencyKey: "" },
  oldestCursor: "",
  newestCursor: "",
  hasOlder: false,
  loading: false,
  generation: 0,
  messageGeneration: 0,
  filterTimer: null,
  pollingTimer: null,
  realtimeSocket: null,
  realtimeRetryTimer: null,
  realtimeRetryCount: 0,
  realtimeGeneration: 0,
  abortController: null,
  mobilePanel: "left",
  factories: {},
  components: {},
};

const el = Object.fromEntries([
  "app-shell", "navbar-host", "mobile-panel-switcher", "workspace-surface", "project-surface", "admin-surface",
  "workspace-profile-avatar", "workspace-profile-name", "workspace-profile-details", "workspace-name", "edit-profile-button", "change-password-button", "rename-workspace-button",
  "workspace-project-count", "project-search-mount", "workspace-project-list", "add-project-button",
  "status-badge", "project-title", "project-description", "project-instructions", "participant-count", "participant-list",
  "participant-search", "project-management-actions", "connection-label",
  "timeline-count", "refresh-button", "primary-filter", "search-mount", "sender-filter", "date-from", "date-to", "clear-filters",
  "timeline-notice", "timeline-host", "composer-shell", "reply-context", "address-mode", "addressee-select", "broadcast-warning", "composer-host",
  "admin-title", "admin-list", "admin-refresh-button",
].map((id) => [id.replaceAll("-", "_"), document.getElementById(id)]));

const panelButtons = Array.from(document.querySelectorAll("[data-panel-button]"));
const panels = Array.from(document.querySelectorAll("[data-panel]"));

function unwrap(payload) {
  return payload && Object.prototype.hasOwnProperty.call(payload, "data") ? payload.data : payload;
}

function id(value) {
  return value == null ? "" : String(value);
}

function csrfHeaders(extra = {}) {
  const token = state.session?.csrf_token || state.session?.csrfToken || "";
  return { "Content-Type": "application/json", ...(token ? { "X-CSRF-Token": token } : {}), ...extra };
}

async function request(url, options = {}) {
  const response = await fetch(url, { credentials: "same-origin", cache: "no-store", ...options });
  let payload = null;
  try { payload = await response.json(); } catch (_error) { payload = null; }
  if (!response.ok) {
    const error = new Error(payload?.message || payload?.error?.message || `Request failed with status ${response.status}`);
    error.status = response.status;
    error.payload = payload;
    throw error;
  }
  return payload;
}

function makeIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  return `web-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function participantFrom(source = {}, fallbackKind = "agent") {
  const participantId = id(source.participant_id ?? source.id ?? source.user_id ?? source.agent_id ?? source.name);
  return {
    id: participantId,
    kind: String(source.kind || source.type || fallbackKind).toLowerCase() === "human" ? "human" : "agent",
    display_name: String(source.display_name || source.name || source.project_name || "Unknown participant"),
    avatar_url: source.avatar_url || null,
    status: String(source.status || (source.is_active === false ? "inactive" : "active")),
    role: String(source.role || ""),
    color_seed: String(source.color_seed || hashColor(participantId)),
    identity_id: id(source.identity_id || source.agent_id || source.user_id),
    provider: String(source.provider || ""),
    runtime_name: String(source.runtime_name || source.runtime || ""),
    capabilities: source.capabilities || {},
    webhook: source.webhook || source.notification_webhook || null,
  };
}

function hashColor(value) {
  let hash = 0;
  for (const char of String(value || "participant")) hash = ((hash << 5) - hash + char.charCodeAt(0)) | 0;
  return [0, 8, 16].map((shift) => (96 + ((hash >>> shift) & 95)).toString(16).padStart(2, "0")).join("");
}

function normalizeMessage(source = {}) {
  const senderSource = source.sender && typeof source.sender === "object"
    ? source.sender
    : { id: source.sender_id || source.sender_agent_id || source.sender, display_name: source.sender };
  const targets = Array.isArray(source.addressees)
    ? source.addressees
    : (Array.isArray(source.targets) ? source.targets : (source.target ? [source.target] : []));
  const addressees = targets.map((target) => {
    if (typeof target === "string") {
      const found = state.participants.find((entry) => entry.display_name === target);
      return { participant_id: found?.id || target, display_name: target, reason: "direct", acknowledged_at: null };
    }
    const targetParticipant = target.participant || target;
    return {
      participant_id: id(target.participant_id ?? targetParticipant.id),
      display_name: String(target.display_name || targetParticipant.display_name || targetParticipant.name || "Participant"),
      reason: String(target.reason || "direct"),
      acknowledged_at: target.acknowledged_at || null,
    };
  });
  const created = source.created_at || source.timestamp || source.message_timestamp || new Date().toISOString();
  const currentParticipantId = id(state.project?.current_participant?.id || state.session?.participant?.id);
  const ownAddress = addressees.find((entry) => entry.participant_id === currentParticipantId);
  return {
    ...source,
    id: id(source.id ?? source.entry_uuid ?? source.db_id),
    sequence: Number(source.sequence ?? source.project_sequence ?? source.index ?? source.db_id ?? 0),
    body: String(source.body || ""),
    created_at: created.includes("T") ? created : created.replace(" ", "T"),
    updated_at: source.updated_at || created,
    sender: participantFrom(senderSource),
    addressees,
    reply_to: source.reply_to || source.reply || null,
    reply_to_message_id: id(source.reply_to_message_id || source.reply_to?.id || ""),
    revision_count: Number(source.revision_count || 0),
    current_participant_state: source.current_participant_state || {
      is_addressee: Boolean(ownAddress),
      acknowledged_at: ownAddress?.acknowledged_at || null,
    },
    permissions: source.permissions || {},
    deleted_at: source.deleted_at || null,
  };
}

function isBroadcastMessage(message = {}) {
  return Array.isArray(message.addressees)
    && message.addressees.length > 0
    && message.addressees.every((entry) => String(entry.reason || "").toLowerCase() === "broadcast");
}

function sortAndDedupe(messages) {
  const unique = new Map(messages.map((message) => [id(message.id), message]));
  return Array.from(unique.values()).sort((left, right) => {
    const sequenceDelta = Number(right.sequence || 0) - Number(left.sequence || 0);
    return sequenceDelta || String(right.created_at).localeCompare(String(left.created_at)) || id(right.id).localeCompare(id(left.id));
  });
}

function selectedProjectId() {
  return id(state.project?.id || state.project?.project_id);
}

function can(permission) {
  const permissions = state.project?.permissions || {};
  return Boolean(permissions[permission] ?? permissions[permission.replace(".", "_")]);
}

function isAdministrator() {
  const roles = state.session?.system_roles || state.session?.roles || state.session?.user?.system_roles || [];
  return roles.some((role) => String(typeof role === "object" ? role.name : role).toLowerCase() === "administrator");
}

function capability(name, fallback = false) {
  const capabilities = state.session?.capabilities || {};
  if (Object.prototype.hasOwnProperty.call(capabilities, name)) return Boolean(capabilities[name]);
  return fallback;
}

function accountUsesNativePassword() {
  const user = state.session?.user || {};
  return user.authentication_source !== "pbb_account" && user.auth_source !== "pbb_account" && user.has_native_password !== false;
}

function openAccountProfile() {
  const url = state.session?.capabilities?.account_profile_url;
  if (url) { location.assign(url); return; }
  state.components.toast.info("Your password and account profile are managed by PBB Account.", { title: "PBB Account" });
}

function navbarAvatarHtml() {
  const user = participantFrom({ ...(state.session?.user || {}), kind: "human" }, "human");
  return makeAvatar(user, "sm").outerHTML;
}

function mountNavbar() {
  if (!state.factories.createNavbar) return;
  const items = [];
  if (state.mode === "expanded" && capability("workspace.view", true)) items.push({ id: "workspace", label: "Workspace" });
  if (state.surface === "project" && state.project) items.push({ id: "project", label: state.project.name });
  if (state.mode === "expanded" && capability("admin.users")) items.push({ id: "users", label: "Users" });
  if (state.mode === "expanded" && capability("admin.agents")) items.push({ id: "agents", label: "Agents" });
  if (state.mode === "expanded" && capability("admin.audit")) items.push({ id: "audit", label: "Audit" });
  const actions = [];
  if (state.mode === "expanded" && capability("admin.settings", isAdministrator())) actions.push({ id: "settings", label: "System Settings" });
  if (state.mode === "expanded") actions.push({
    id: "account",
    label: state.session?.user?.display_name || "Account",
    icon: navbarAvatarHtml(),
    menuItems: [
      { id: "profile", label: "Profile" },
      ...(accountUsesNativePassword() ? [{ id: "password", label: "Change Password" }] : []),
      ...(!accountUsesNativePassword() ? [{ id: "account-profile", label: "Manage PBB Account" }] : []),
      { id: "signout", label: "Sign out", danger: true },
    ],
  });
  state.components.navbar?.destroy();
  state.components.navbar = state.factories.createNavbar(el.navbar_host, {}, {
    brandText: "Syndicatum",
    brandSubtitle: state.surface === "project" && state.project ? state.project.name : "Human + agent collaboration",
    className: "syndicatum-navbar-single-row",
    activeId: state.surface,
    items,
    actions,
    sticky: true,
    mobileCollapse: false,
    mobileLayout: "scroll",
    onNavigate(item) {
      if (item?.id === "brand" || item?.id === "workspace") showWorkspaceSurface();
      else if (["users", "agents", "audit"].includes(item?.id)) void showAdminSurface(item.id);
    },
    onAction(action) { if (action?.id === "settings") void openSettings(); },
    onActionMenuSelect(_action, item) {
      if (item?.id === "profile") openProfileModal();
      else if (item?.id === "password") openPasswordModal();
      else if (item?.id === "account-profile") openAccountProfile();
      else if (item?.id === "signout") void logout();
    },
  });
}

function makeAvatar(participant, size = "md") {
  const wrap = document.createElement("span");
  wrap.className = `participant-avatar is-${size} is-${participant.kind}`;
  const initials = participant.display_name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join("").toUpperCase() || "?";
  const fallback = document.createElement("span");
  fallback.className = "participant-avatar-fallback";
  fallback.textContent = initials;
  fallback.style.background = `#${participant.color_seed}`;
  wrap.appendChild(fallback);
  if (participant.avatar_url) {
    const image = document.createElement("img");
    image.src = participant.avatar_url;
    image.alt = "";
    image.loading = "lazy";
    image.referrerPolicy = "no-referrer";
    image.addEventListener("error", () => image.remove(), { once: true });
    wrap.appendChild(image);
  }
  const badge = document.createElement("span");
  badge.className = "participant-kind-mark";
  badge.textContent = participant.kind === "agent" ? "A" : "H";
  badge.title = participant.kind === "agent" ? "Agent" : "Human";
  wrap.appendChild(badge);
  return wrap;
}

function appendLinkedText(host, text) {
  const pattern = /https?:\/\/[^\s<>]+/gi;
  let offset = 0;
  for (const match of String(text).matchAll(pattern)) {
    if (match.index > offset) host.appendChild(document.createTextNode(text.slice(offset, match.index)));
    let url = match[0];
    let suffix = "";
    while (/[),.;!?]$/.test(url)) { suffix = url.slice(-1) + suffix; url = url.slice(0, -1); }
    const link = document.createElement("a");
    link.href = url;
    link.target = "_blank";
    link.rel = "noopener noreferrer";
    link.textContent = url;
    host.append(link, document.createTextNode(suffix));
    offset = match.index + match[0].length;
  }
  if (offset < text.length) host.appendChild(document.createTextNode(text.slice(offset)));
}

function setMobilePanel(name) {
  state.mobilePanel = name;
  panelButtons.forEach((button) => button.classList.toggle("is-active", button.dataset.panelButton === name));
  panels.forEach((panel) => panel.classList.toggle("is-mobile-active", panel.dataset.panel === name));
  if (name === "right" && state.surface === "project") requestAnimationFrame(() => state.components.timeline?.resetReachEnd());
}

function showLogin(message = "") {
  state.mode = "login";
  el.app_shell.hidden = true;
  const returnPath = requestedReturnPath();
  const accountEnabled = Boolean(state.session?.capabilities?.account_sso || state.session?.capabilities?.pbb_account);
  const options = {
    title: "Welcome to Syndicatum",
    message: message || "Sign in to collaborate with the humans and agents in your projects.",
    identifierKind: "username",
    identifierLabel: "Email or username",
    identifierPlaceholder: "Enter email or username",
    fields: { identifier: "identity", password: "password" },
    submitLabel: "Sign in",
    busyMessage: "Signing in...",
    closeOnBackdrop: false,
    closeOnEscape: false,
    showCloseButton: false,
    extraActionsPlacement: "start",
    extraActions: accountEnabled ? [{
      id: "pbb-account",
      label: "Continue with PBB Account",
      variant: "ghost",
      closeOnClick: false,
      onClick() {
        location.assign(returnPath ? `auth/account.php?return=${encodeURIComponent(returnPath)}` : "auth/account.php");
        return false;
      },
    }] : [],
    onSubmit: submitLogin,
  };
  if (state.components.login) state.components.login.update(options);
  else state.components.login = state.factories.createLoginFormModal(options);
  if (!state.components.login.getState().open) state.components.login.open();
}

function requestedReturnPath() {
  const value = new URLSearchParams(location.search).get("return") || "";
  return value.startsWith("/") && !value.startsWith("//") && !value.includes("\\") ? value : "";
}

function showApplication() {
  el.app_shell.hidden = false;
  document.body.classList.toggle("expanded-mode", state.mode === "expanded");
  document.body.classList.toggle("legacy-mode", state.mode === "legacy");
}

function renderIdentity() {
  mountNavbar();
}

function renderProjectHeader() {
  const project = state.project || {};
  el.project_title.textContent = project.name || "PBB Coordination";
  el.project_description.textContent = project.description || (state.mode === "legacy" ? "The existing shared Syndicatum coordination timeline." : "No project description has been added yet.");
  const instructions = project.instructions || project.operating_instructions || "";
  el.project_instructions.textContent = instructions;
  el.project_instructions.hidden = !instructions;
  el.project_management_actions.replaceChildren();
  if (state.mode === "expanded" && (can("project.manage") || can("project.admin"))) {
    const edit = actionButton("Edit project", () => openEditProjectModal());
    edit.className = "ui-button ui-button-quiet";
    el.project_management_actions.append(edit);
  }
  if (state.mode === "expanded" && can("members.manage")) {
    const invite = actionButton("Invite member", () => openInviteMemberModal()); invite.className = "ui-button ui-button-quiet"; el.project_management_actions.append(invite);
  }
  if (state.mode === "expanded" && can("agents.manage")) {
    const addAgent = actionButton("Add agent", () => openAddAgentModal()); addAgent.className = "ui-button ui-button-quiet"; el.project_management_actions.append(addAgent);
  }
  renderIdentity();
}

function renderParticipants() {
  el.participant_count.textContent = String(state.participants.length);
  el.participant_list.replaceChildren();
  const query = state.participantSearch.toLocaleLowerCase();
  for (const participant of state.participants.filter((entry) => !query || `${entry.display_name} ${entry.kind} ${entry.role}`.toLocaleLowerCase().includes(query))) {
    const row = document.createElement("div");
    row.className = "participant-row";
    const button = document.createElement("button");
    button.type = "button";
    button.className = `participant-card${state.filters.sender === participant.id ? " is-active" : ""}`;
    button.appendChild(makeAvatar(participant));
    const copy = document.createElement("span");
    copy.className = "participant-card-copy";
    const name = document.createElement("strong");
    name.textContent = participant.display_name;
    const meta = document.createElement("span");
    meta.textContent = `${participant.kind === "agent" ? "Agent" : "Human"}${participant.role ? ` · ${participant.role}` : ""}`;
    copy.append(name, meta);
    button.appendChild(copy);
    button.addEventListener("click", () => {
      state.filters.sender = state.filters.sender === participant.id ? "" : participant.id;
      state.components.senderSelect?.setValue(state.filters.sender || null);
      renderParticipants();
      void reloadForFilters();
      if (matchMedia("(max-width: 980px)").matches) setMobilePanel("right");
    });
    row.appendChild(button);
    if (participant.kind === "agent" && can("agents.manage")) {
      const edit = document.createElement("button");
      edit.type = "button";
      edit.className = "ui-button ui-button-borderless participant-edit";
      edit.textContent = "Edit";
      edit.setAttribute("aria-label", `Edit ${participant.display_name}`);
      edit.addEventListener("click", () => openEditAgentModal(participant));
      row.appendChild(edit);
    }
    el.participant_list.appendChild(row);
  }
}

function renderFilters() {
  const hasFilters = state.filters.primary !== "all" || state.filters.q || state.filters.sender || state.filters.from || state.filters.to;
  el.clear_filters.hidden = !hasFilters;
  renderParticipants();
}

function formatDate(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value || "") : new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(date);
}

function replyPreview(message) {
  const reply = message.reply_to;
  if (!reply) return null;
  return {
    id: id(reply.id || message.reply_to_message_id),
    sender: reply.sender?.display_name || reply.sender_name || "Participant",
    body: String(reply.body || reply.excerpt || "Original message"),
  };
}

function renderAddresseeChips(message) {
  const chips = document.createElement("div");
  chips.className = "addressee-chips";
  if (isBroadcastMessage(message)) {
    const chip = document.createElement("span");
    chip.className = "addressee-chip is-broadcast";
    chip.textContent = "Project broadcast";
    chip.title = "Broadcast to the project";
    chips.appendChild(chip);
  } else if (!message.addressees.length) {
    const chip = document.createElement("span");
    chip.className = "addressee-chip";
    chip.textContent = "Project timeline";
    chips.appendChild(chip);
  } else {
    message.addressees.slice(0, 5).forEach((entry) => {
      const chip = document.createElement("span");
      chip.className = `addressee-chip${entry.acknowledged_at ? " is-acknowledged" : ""}`;
      const participant = state.participants.find((candidate) => candidate.id === entry.participant_id);
      chip.textContent = `@${participant?.display_name || entry.display_name}`;
      chip.title = entry.acknowledged_at ? "Acknowledged" : "Expected to respond";
      chips.appendChild(chip);
    });
    if (message.addressees.length > 5) {
      const more = document.createElement("span");
      more.className = "addressee-chip";
      more.textContent = `+${message.addressees.length - 5}`;
      chips.appendChild(more);
    }
  }
  return chips;
}

function mountMessageCard(host, item) {
  const message = item.raw;
  function paint(nextItem = item) {
    const current = nextItem.raw;
    host.replaceChildren();
    const header = document.createElement("header");
    header.className = "message-card-header";
    header.appendChild(makeAvatar(current.sender));
    const identity = document.createElement("div");
    identity.className = "message-identity";
    const identityLine = document.createElement("div");
    identityLine.className = "message-identity-line";
    const name = document.createElement("strong");
    name.textContent = current.sender.display_name;
    const kind = document.createElement("span");
    kind.className = `participant-kind is-${current.sender.kind}`;
    kind.textContent = current.sender.kind === "agent" ? "Agent" : "Human";
    identityLine.append(name, kind);
    identity.append(identityLine, renderAddresseeChips(current));
    const time = document.createElement("time");
    time.dateTime = current.created_at;
    time.textContent = formatDate(current.created_at);
    header.append(identity, time);
    host.appendChild(header);

    const reply = replyPreview(current);
    if (reply) {
      const preview = document.createElement("button");
      preview.type = "button";
      preview.className = "message-reply-preview";
      preview.textContent = `Replying to ${reply.sender}: ${reply.body.slice(0, 140)}`;
      preview.addEventListener("click", () => jumpToMessage(reply.id));
      host.appendChild(preview);
    }

    const body = document.createElement("p");
    body.className = "message-card-body";
    if (current.deleted_at) body.textContent = "This message was removed.";
    else appendLinkedText(body, current.body);
    host.appendChild(body);

    const footer = document.createElement("footer");
    footer.className = "message-card-footer";
    const actions = document.createElement("div");
    actions.className = "message-actions";
    if (state.mode === "expanded" && can("messages.write")) actions.appendChild(actionButton("Reply", () => setReply(current)));
    if (state.mode === "expanded" && current.current_participant_state?.is_addressee && !current.current_participant_state?.acknowledged_at && (can("messages.acknowledge") || current.permissions?.acknowledge)) {
      actions.appendChild(actionButton("Acknowledge", () => acknowledgeMessage(current)));
    }
    if (current.revision_count) {
      const revisions = document.createElement("span");
      revisions.className = "revision-label";
      revisions.textContent = `${current.revision_count} revision${current.revision_count === 1 ? "" : "s"}`;
      actions.appendChild(revisions);
    }
    footer.appendChild(actions);
    host.appendChild(footer);
  }
  paint(item);
  return { update: paint };
}

function actionButton(label, handler) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "ui-button ui-button-borderless message-action";
  button.textContent = label;
  button.addEventListener("click", handler);
  return button;
}

function timelineItems(messages) {
  return messages.map((message) => ({
    id: message.id,
    className: `syndicatum-message${message.current_participant_state?.is_addressee ? " is-addressed" : ""}`,
    title: message.sender.display_name,
    description: message.body,
    timestamp: message.created_at,
    status: message.current_participant_state?.acknowledged_at ? "completed" : (message.current_participant_state?.is_addressee ? "requested" : "accepted"),
    raw: message,
    contentKey: `${message.updated_at}|${message.current_participant_state?.acknowledged_at || ""}|${message.revision_count}`,
  }));
}

function renderTimeline(mode = "replace", changed = state.messages) {
  const items = timelineItems(changed);
  const options = {
    ariaLabel: "Project timeline",
    groupByDate: true,
    enableVirtualization: true,
    virtualThreshold: 1,
    virtualOverscan: 600,
    endThreshold: 160,
    isLoading: state.loading,
    hasMore: state.hasOlder,
    emptyText: "No messages match these filters.",
    mountItemContent: mountMessageCard,
    onReachEnd() {
      const viewport = el.timeline_host.querySelector(".ui-timeline-viewport");
      if (viewport?.clientHeight > 0) void loadMessages("older").catch(handleLoadError);
    },
  };
  if (!state.components.timeline) state.components.timeline = state.factories.createTimeline(el.timeline_host, items, options);
  else if (mode === "append") state.components.timeline.append(items);
  else if (mode === "prepend") state.components.timeline.prepend(items);
  else state.components.timeline.update(items, options);
  el.timeline_count.textContent = `${state.messages.length} loaded${state.hasOlder ? " · more available" : ""}`;
}

function messageQuery({ before = "", after = "", order = "desc" } = {}) {
  const params = new URLSearchParams({ limit: "200", order });
  if (state.mode === "expanded") params.set("project_id", selectedProjectId());
  if (before) params.set("before", before);
  if (after) params.set("after", after);
  if (state.filters.q) params.set("q", state.filters.q);
  if (state.filters.sender) params.set(state.mode === "legacy" ? "participant" : "sender", state.filters.sender);
  if (state.filters.from) params.set("from", state.filters.from);
  if (state.filters.to) params.set("to", state.filters.to);
  if (state.filters.primary === "addressed") {
    if (state.mode === "legacy") params.set("direct", "1"); else params.set("addressed_to", "me");
  }
  if (state.filters.primary === "unacknowledged") {
    if (state.mode === "legacy") params.set("direct", "1");
    else { params.set("addressed_to", "me"); params.set("acknowledged", "false"); }
  }
  return params;
}

async function loadMessages(mode = "initial", generation = state.generation, messageGeneration = state.messageGeneration) {
  if (state.loading && mode !== "initial") return 0;
  if (mode === "older" && (!state.hasOlder || !state.oldestCursor)) return 0;
  state.loading = true;
  const showTimelineLoading = mode !== "newer";
  if (showTimelineLoading) state.components.timeline?.update(undefined, { isLoading: true, hasMore: state.hasOlder });
  try {
    const before = mode === "older" ? state.oldestCursor : "";
    const after = mode === "newer" ? state.newestCursor : "";
    if (mode === "newer" && !after) return 0;
    const endpoint = state.mode === "legacy" ? "api/chat-entries.php" : API.messages;
    const payload = await request(`${endpoint}?${messageQuery({ before, after, order: mode === "newer" ? "asc" : "desc" })}`);
    if (generation !== state.generation || messageGeneration !== state.messageGeneration) return 0;
    const source = unwrap(payload);
    const rows = Array.isArray(source) ? source : (source?.messages || payload?.data || []);
    const incoming = rows.map(normalizeMessage);
    const existing = new Set(state.messages.map((message) => message.id));
    const fresh = sortAndDedupe(incoming.filter((message) => !existing.has(message.id)));
    const page = payload?.page || source?.page || payload?.meta?.page || {};
    if (mode === "initial") state.messages = sortAndDedupe(incoming);
    else state.messages = sortAndDedupe([...state.messages, ...incoming]);
    state.oldestCursor = page.older_cursor || (mode === "initial" ? "" : state.oldestCursor);
    state.newestCursor = page.newer_cursor || state.newestCursor;
    if (mode !== "newer") state.hasOlder = Boolean(page.has_more ?? page.has_older);
    if (mode === "initial") {
      renderTimeline("replace", state.messages);
    } else if (fresh.length) {
      renderTimeline(mode === "older" ? "append" : "prepend", fresh);
    } else {
      el.timeline_count.textContent = `${state.messages.length} loaded${state.hasOlder ? " · more available" : ""}`;
    }
    if (mode === "newer" && fresh.length) state.components.toast.info(`${fresh.length} new message${fresh.length === 1 ? "" : "s"}`, { title: "Timeline updated" });
    return fresh.length;
  } finally {
    state.loading = false;
    if (showTimelineLoading) state.components.timeline?.update(undefined, { isLoading: false, hasMore: state.hasOlder });
  }
}

function handleLoadError(error) {
  state.components.timeline?.resetReachEnd({ check: false });
  state.components.toast.warn(error.message || "Unable to update the timeline.", { title: "Timeline error" });
}

async function reloadForFilters() {
  const generation = state.generation;
  const messageGeneration = ++state.messageGeneration;
  renderFilters();
  el.status_badge.textContent = "Filtering";
  try {
    await loadMessages("initial", generation, messageGeneration);
    el.status_badge.textContent = state.mode === "legacy" ? "Legacy" : "Live";
  } catch (error) {
    el.status_badge.textContent = "Filter error";
    handleLoadError(error);
  }
}

function scheduleFilterReload() {
  clearTimeout(state.filterTimer);
  state.filterTimer = setTimeout(() => void reloadForFilters(), 250);
}

function setSurface(name) {
  state.surface = name;
  el.workspace_surface.hidden = name !== "workspace";
  el.project_surface.hidden = name !== "project";
  el.admin_surface.hidden = !["users", "agents", "audit"].includes(name);
  el.mobile_panel_switcher.hidden = !["workspace", "project"].includes(name);
  const labels = name === "project" ? ["Participants", "Timeline"] : ["Profile", "Projects"];
  panelButtons.forEach((button, index) => { button.textContent = labels[index] || button.textContent; });
  mountNavbar();
}

function showWorkspaceSurface({ historyMode = "push" } = {}) {
  closeRealtime();
  clearTimeout(state.pollingTimer);
  setSurface("workspace");
  renderWorkspace();
  setMobilePanel("left");
  if (historyMode === "push") history.pushState(null, "", location.pathname);
  else if (historyMode === "replace") history.replaceState(null, "", location.pathname);
}

function renderWorkspace() {
  const user = state.session?.user || {};
  const human = participantFrom({ ...user, kind: "human" }, "human");
  el.workspace_profile_avatar.replaceChildren(makeAvatar(human));
  el.workspace_profile_name.textContent = human.display_name;
  el.workspace_profile_details.replaceChildren();
  [["Email", user.email], ["Username", user.username], ["Authentication", user.authentication_source || user.auth_source || (user.pbb_user_id ? "PBB Account" : "Native")]].forEach(([term, value]) => {
    if (!value) return;
    const dt = document.createElement("dt"); dt.textContent = term;
    const dd = document.createElement("dd"); dd.textContent = value;
    el.workspace_profile_details.append(dt, dd);
  });
  el.workspace_name.textContent = user.workspace?.name || state.session?.workspace?.name || "My workspace";
  el.change_password_button.hidden = false;
  el.change_password_button.textContent = accountUsesNativePassword() ? "Change Password" : "Manage PBB Account";
  el.add_project_button.hidden = !capability("project.create");
  const query = state.projectSearch.toLocaleLowerCase();
  const projects = state.projects.filter((project) => !query || `${project.name} ${project.description || ""} ${project.role || ""}`.toLocaleLowerCase().includes(query));
  el.workspace_project_count.textContent = String(projects.length);
  el.workspace_project_list.replaceChildren();
  if (!projects.length) {
    const empty = document.createElement("div"); empty.className = "empty-state ui-panel";
    empty.textContent = state.projects.length ? "No projects match your search." : "No projects yet. Create one to start collaborating.";
    el.workspace_project_list.append(empty); return;
  }
  projects.forEach((project) => {
    const card = document.createElement("button"); card.type = "button"; card.className = "project-card ui-panel";
    const top = document.createElement("span"); top.className = "project-card-top";
    const title = document.createElement("strong"); title.textContent = project.name;
    const relationship = document.createElement("span"); relationship.className = "ui-badge"; relationship.textContent = project.collection === "Shared" ? "Shared" : "Owned";
    top.append(title, relationship);
    const description = document.createElement("span"); description.className = "project-card-description"; description.textContent = project.description || "No description";
    const meta = document.createElement("span"); meta.className = "project-card-meta";
    meta.textContent = [project.role, project.status, project.participant_count != null ? `${project.participant_count} participants` : "", project.last_activity_at ? `Active ${formatDate(project.last_activity_at)}` : ""].filter(Boolean).join(" · ");
    card.append(top, description, meta);
    card.addEventListener("click", () => void switchProject(project.id).catch(handleLoadError));
    el.workspace_project_list.append(card);
  });
}

function modalTextField(name, label, options = {}) { return { type: "input", name, label, ...options }; }

async function uploadAvatar(file, { kind, projectId = "", agentId = "" } = {}) {
  if (!(file instanceof File)) return "";
  if (!/^image\/(jpeg|png|webp)$/i.test(file.type) || file.size < 1 || file.size > 2 * 1024 * 1024) {
    throw new Error("Choose a JPEG, PNG, or WebP avatar no larger than 2 MB.");
  }
  const body = new FormData();
  body.append("avatar", file, file.name);
  body.append("kind", kind);
  if (projectId) body.append("project_id", projectId);
  if (agentId) body.append("agent_id", agentId);
  const token = state.session?.csrf_token || "";
  const result = unwrap(await request(API.avatarUpload, { method: "POST", headers: token ? { "X-CSRF-Token": token } : {}, body }));
  const avatarUrl = result?.avatar_url || result?.url || "";
  if (!avatarUrl) throw new Error("The avatar upload did not return an avatar URL.");
  return avatarUrl;
}

function openProfileModal() {
  const user = state.session?.user || {};
  state.factories.createFormModal({
    title: "Edit Profile", submitLabel: "Save profile", initialValues: { display_name: user.display_name || "", avatar: null },
    rows: [[{ type: "avatar", name: "avatar", label: "Profile photo", accept: "image/jpeg,image/png,image/webp", previewUrl: user.avatar_url || "", help: "JPEG, PNG, or WebP; up to 2 MB." }], [modalTextField("display_name", "Display name", { required: true })]],
    async onSubmit(values, context) {
      try { const avatarUrl = values.avatar instanceof File ? await uploadAvatar(values.avatar, { kind: "human" }) : user.avatar_url; const result = unwrap(await request(API.profile, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ display_name: values.display_name, avatar_url: avatarUrl || null }) })); state.session.user = result.user || result; renderWorkspace(); mountNavbar(); state.components.toast.success("Profile updated."); return true; }
      catch (error) { context.setFormError(error.message); return false; }
    },
  }).open();
}

function openPasswordModal() {
  if (!accountUsesNativePassword()) return;
  state.factories.createFormModal({
    title: "Change Password", submitLabel: "Change password", rows: [
      [modalTextField("current_password", "Current password", { input: "password", required: true })],
      [modalTextField("new_password", "New password", { input: "password", required: true, minlength: 12 })],
      [modalTextField("new_password_confirmation", "Confirm new password", { input: "password", required: true, minlength: 12 })],
    ],
    async onSubmit(values, context) {
      if (values.new_password !== values.new_password_confirmation) { context.setFormError("The new passwords do not match."); return false; }
      try { const result = unwrap(await request(API.password, { method: "POST", headers: csrfHeaders(), body: JSON.stringify(values) })); if (result.csrf_token) state.session.csrf_token = result.csrf_token; state.components.toast.success("Password changed. Other sessions were signed out."); return true; }
      catch (error) { context.setFormError(error.message); return false; }
    },
  }).open();
}

function openRenameWorkspaceModal() {
  const name = state.session?.user?.workspace?.name || state.session?.workspace?.name || "My workspace";
  state.factories.createFormModal({ title: "Rename Workspace", submitLabel: "Save name", initialValues: { name }, rows: [[modalTextField("name", "Workspace name", { required: true })]], async onSubmit(values, context) {
    try { const result = unwrap(await request(API.workspace, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ name: values.name }) })); const workspace = result.workspace || result; state.session.workspace = workspace; state.session.user.workspace = workspace; renderWorkspace(); state.components.toast.success("Workspace renamed."); return true; }
    catch (error) { context.setFormError(error.message); return false; }
  }}).open();
}

function openAddProjectModal() {
  state.factories.createFormModal({ title: "Add Project", size: "lg", submitLabel: "Create project", rows: [
    [modalTextField("name", "Project name", { required: true }), modalTextField("slug", "Slug (optional)")],
    [{ type: "textarea", name: "description", label: "Description" }], [{ type: "textarea", name: "instructions", label: "Operating instructions" }],
  ], async onSubmit(values, context) {
    try { const result = unwrap(await request(API.manageProjects, { method: "POST", headers: csrfHeaders(), body: JSON.stringify(values) })); const project = result.project || result; project.id = id(project.id || project.project_id); project.collection = "My"; state.projects.unshift(project); state.components.toast.success("Project created."); void switchProject(project.id); return true; }
    catch (error) { context.setFormError(error.message); return false; }
  }}).open();
}

function openEditProjectModal() {
  const project = state.project || {};
  state.factories.createFormModal({ title: "Edit Project", submitLabel: "Save project", initialValues: { name: project.name || "", description: project.description || "", instructions: project.instructions || "" }, rows: [
    [modalTextField("name", "Project name", { required: true })], [{ type: "textarea", name: "description", label: "Description" }], [{ type: "textarea", name: "instructions", label: "Operating instructions" }],
  ], async onSubmit(values, context) {
    try { const result = unwrap(await request(API.manageProjects, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ project_id: selectedProjectId(), ...values }) })); state.project = { ...state.project, ...(result.project || result) }; state.projects = state.projects.map((entry) => entry.id === selectedProjectId() ? { ...entry, ...state.project } : entry); renderProjectHeader(); state.components.toast.success("Project updated."); return true; }
    catch (error) { context.setFormError(error.message); return false; }
  }}).open();
}

function showCredentialResult(title, label, value, expiresAt) {
  state.factories.createFormModal({ title, submitLabel: "Done", context: { badge: "Shown once", summary: "Copy this value now and share it through a trusted channel." }, rows: [
    [{ type: "text", content: `${label}: ${value}` }], [{ type: "text", content: expiresAt ? `Expires ${formatDate(expiresAt)}` : "" }],
  ], async onSubmit() { return true; } }).open();
}

function showAgentCredentialResult(result) {
  const rows = [];
  if (result.claim_code) rows.push([{ type: "text", content: `Claim code: ${result.claim_code}` }]);
  const webhookSecret = result.webhook_signing_secret || result.signing_secret;
  if (webhookSecret) rows.push([{ type: "text", content: `Webhook signing secret: ${webhookSecret}` }]);
  if (result.claim_expires_at) rows.push([{ type: "text", content: `Claim expires ${formatDate(result.claim_expires_at)}` }]);
  if (!rows.length) return;
  state.factories.createFormModal({ title: "Agent credentials", submitLabel: "Done", context: { badge: "Shown once", summary: "Copy these credentials now. Syndicatum will not show the secrets again." }, rows, async onSubmit() { return true; } }).open();
}

function openInviteMemberModal() {
  state.factories.createFormModal({ title: "Invite Member", submitLabel: "Create invitation", initialValues: { role: "member" }, rows: [
    [modalTextField("email", "Email address", { input: "email", required: true })],
    [{ type: "select", name: "role", label: "Project role", required: true, options: [{ value: "member", label: "Member" }, { value: "viewer", label: "Viewer" }, { value: "admin", label: "Administrator" }] }],
  ], async onSubmit(values, context) {
    try { const result = unwrap(await request(API.projectInvitations, { method: "POST", headers: csrfHeaders(), body: JSON.stringify({ project_id: selectedProjectId(), email: values.email, role: values.role }) })); setTimeout(() => showCredentialResult("Invitation created", "Invitation token", result.invitation_token, result.expires_at), 0); return true; }
    catch (error) { context.setFormError(error.message); return false; }
  }}).open();
}

async function loadDiscussionProviders() {
  const providers = unwrap(await request(API.discussionProviders));
  if (!Array.isArray(providers) || !providers.length) throw new Error("No discussion providers are currently available.");
  return providers;
}

async function openAddAgentModal() {
  let providers;
  try { providers = await loadDiscussionProviders(); }
  catch (error) { state.components.toast.warn(error.message, { title: "Discussion providers unavailable" }); return; }
  const provider = providers[0];
  state.factories.createFormModal({ title: "Add Agent", size: "lg", submitLabel: "Create agent", initialValues: { avatar: null, provider: provider.code, activation_enabled: false, webhook_enabled: false }, rows: [
    [{ type: "avatar", name: "avatar", label: "Agent avatar", accept: "image/jpeg,image/png,image/webp", help: "JPEG, PNG, or WebP; up to 2 MB." }],
    [modalTextField("display_name", "Agent display name", { required: true })],
    [{ type: "textarea", name: "description", label: "Description" }],
    [{ type: "divider" }], [{ type: "text", content: "Activation connector" }],
    [{ type: "select", name: "provider", label: "Provider", required: true, options: providers.map(item => ({ value: item.code, label: item.display_name })) }],
    [{ type: "checkbox", name: "activation_enabled", label: "Enable conversation notifications" }],
    [modalTextField("discussion_reference", provider.reference_label, { placeholder: provider.reference_placeholder, help: provider.reference_help })],
    [modalTextField("working_directory", provider.working_directory_label, { placeholder: "C:\\path\\to\\project", help: provider.working_directory_help })],
    [{ type: "divider" }], [{ type: "text", content: "Optional notification webhook" }],
    [{ type: "checkbox", name: "webhook_enabled", label: "Enable webhook notifications" }], [modalTextField("webhook_url", "Webhook URL", { input: "url", placeholder: "https://agent.example/hooks/syndicatum" })],
  ], async onSubmit(values, context) {
    try { if (values.activation_enabled && !String(values.discussion_reference || "").trim()) throw new Error("A discussion reference is required when conversation notifications are enabled."); if (values.webhook_enabled && !String(values.webhook_url || "").trim()) throw new Error("A webhook URL is required when webhook notifications are enabled."); const avatarUrl = values.avatar instanceof File ? await uploadAvatar(values.avatar, { kind: "agent", projectId: selectedProjectId() }) : ""; const body = { ...values, avatar_url: avatarUrl || null, project_id: selectedProjectId() }; delete body.avatar; const result = unwrap(await request(API.projectAgents, { method: "POST", headers: csrfHeaders(), body: JSON.stringify(body) })); setTimeout(() => showAgentCredentialResult(result), 0); const participants = unwrap(await request(`${API.participants}?${new URLSearchParams({ project_id: selectedProjectId(), status: "active" })}`)); state.participants = (participants || []).map((entry) => participantFrom(entry, entry.kind)); rebuildParticipantControls(); return true; }
    catch (error) { context.setFormError(error.message); return false; }
  }}).open();
}

function confirmSigningSecretRotation(agentId, values, currentWebhook) {
  const endpointUrl = String(values.webhook_url || "").trim();
  const confirmation = state.factories.createFormModal({
    title: "Generate new signing secret?",
    size: "sm",
    submitLabel: "Generate new secret",
    submitVariant: "danger",
    context: { badge: "Security action", summary: "The current signing secret will stop working immediately." },
    rows: [
      [{ type: "text", content: "Update the receiving agent with the new secret as soon as it is generated. The replacement secret will be shown only once." }],
    ],
    async onSubmit(_confirmationValues, context) {
      try {
        const result = unwrap(await request(API.projectAgentWebhook, {
          method: "PATCH",
          headers: csrfHeaders(),
          body: JSON.stringify({
            project_id: selectedProjectId(),
            agent_id: agentId,
            endpoint_url: endpointUrl,
            enabled: Boolean(values.webhook_enabled),
            replace_secret: true,
          }),
        })) || {};
        Object.assign(currentWebhook, result);
        setTimeout(() => showAgentCredentialResult(result), 0);
        state.components.toast.success("A new webhook signing secret was generated.");
        return true;
      } catch (error) {
        context.setFormError(error.message);
        return false;
      }
    },
  });
  confirmation.open();
}

async function openEditAgentModal(agent) {
  const agentId = agent.identity_id;
  let webhook = agent.webhook || {};
  let activation = agent.activation || {};
  let providers = [];
  try { webhook = unwrap(await request(`${API.projectAgentWebhook}?${new URLSearchParams({ project_id: selectedProjectId(), agent_id: agentId })}`)) || {}; }
  catch (error) { if (error.status !== 404) { state.components.toast.warn(error.message, { title: "Webhook settings unavailable" }); return; } }
  try { activation = unwrap(await request(`${API.projectAgentActivation}?${new URLSearchParams({ project_id: selectedProjectId(), agent_id: agentId })}`)) || {}; }
  catch (error) { if (error.status !== 404) { state.components.toast.warn(error.message, { title: "Activation settings unavailable" }); return; } }
  try { providers = await loadDiscussionProviders(); }
  catch (error) { state.components.toast.warn(error.message, { title: "Discussion providers unavailable" }); return; }
  const provider = providers.find(item => item.code === activation.provider) || providers[0];
  state.factories.createFormModal({ title: `Edit ${agent.display_name}`, size: "lg", submitLabel: "Save agent", initialValues: {
    display_name: agent.display_name, avatar: null,
    provider: provider.code, activation_enabled: Boolean(activation.enabled), discussion_reference: activation.discussion_reference || "", working_directory: activation.working_directory || "",
    webhook_enabled: Boolean(webhook.enabled), webhook_url: webhook.endpoint_url || webhook.url || "",
  }, extraActionsPlacement: "start", extraActions: [{
    id: "rotate-webhook-secret",
    label: "Generate new signing secret",
    variant: "default",
    className: "ui-button-warning agent-secret-warning-action",
    onClick(values, context) {
      if (!String(values.webhook_url || "").trim()) {
        context.setFormError("Enter a webhook URL before generating a signing secret.");
        return false;
      }
      setTimeout(() => confirmSigningSecretRotation(agentId, values, webhook), 0);
      return false;
    },
  }], rows: [
    [{ type: "avatar", name: "avatar", label: "Agent avatar", accept: "image/jpeg,image/png,image/webp", previewUrl: agent.avatar_url || "", help: "JPEG, PNG, or WebP; up to 2 MB." }],
    [modalTextField("display_name", "Agent display name", { required: true })],
    [{ type: "divider" }], [{ type: "text", content: "Activation connector" }],
    [{ type: "select", name: "provider", label: "Provider", required: true, options: providers.map(item => ({ value: item.code, label: item.display_name })) }],
    [{ type: "checkbox", name: "activation_enabled", label: "Enable conversation notifications" }],
    [modalTextField("discussion_reference", provider.reference_label, { placeholder: provider.reference_placeholder, help: provider.reference_help })],
    [modalTextField("working_directory", provider.working_directory_label, { placeholder: "C:\\path\\to\\project", help: provider.working_directory_help })],
    [{ type: "divider" }], [{ type: "checkbox", name: "webhook_enabled", label: "Enable webhook notifications" }],
    [modalTextField("webhook_url", "Webhook URL", { input: "url" })],
  ], async onSubmit(values, context) {
    try {
      if (values.activation_enabled && !String(values.discussion_reference || "").trim()) throw new Error("A discussion reference is required when conversation notifications are enabled.");
      if (values.webhook_enabled && !String(values.webhook_url || "").trim()) throw new Error("A webhook URL is required when webhook notifications are enabled.");
      const avatarUrl = values.avatar instanceof File ? await uploadAvatar(values.avatar, { kind: "agent", projectId: selectedProjectId(), agentId }) : agent.avatar_url;
      await request(API.projectAgents, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ project_id: selectedProjectId(), agent_id: agentId, display_name: values.display_name, avatar_url: avatarUrl || null }) });
      await request(API.projectAgentActivation, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ project_id: selectedProjectId(), agent_id: agentId, enabled: Boolean(values.activation_enabled), provider: values.provider, discussion_reference: values.discussion_reference, working_directory: values.working_directory }) });
      let webhookResult = {};
      if (values.webhook_enabled || String(values.webhook_url || "").trim() || webhook.endpoint_url) webhookResult = unwrap(await request(API.projectAgentWebhook, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ project_id: selectedProjectId(), agent_id: agentId, endpoint_url: values.webhook_url, enabled: Boolean(values.webhook_enabled) }) })) || {};
      setTimeout(() => showAgentCredentialResult(webhookResult), 0);
      const participants = unwrap(await request(`${API.participants}?${new URLSearchParams({ project_id: selectedProjectId(), status: "active" })}`)); state.participants = (participants || []).map((entry) => participantFrom(entry, entry.kind)); rebuildParticipantControls(); state.components.toast.success("Agent updated."); return true;
    }
    catch (error) { context.setFormError(error.message); return false; }
  }}).open();
}

function adminRows(payload, kind) {
  const source = unwrap(payload);
  const rows = Array.isArray(source) ? source : (source?.[kind] || source?.events || []);
  return Array.isArray(rows) ? rows : [];
}

async function showAdminSurface(kind) {
  if (!capability(`admin.${kind}`)) return;
  closeRealtime(); clearTimeout(state.pollingTimer); state.adminKind = kind; setSurface(kind);
  el.admin_title.textContent = kind[0].toUpperCase() + kind.slice(1);
  el.admin_list.replaceChildren(); const loading = document.createElement("p"); loading.textContent = "Loading…"; el.admin_list.append(loading);
  const endpoint = { users: API.adminUsers, agents: API.adminAgents, audit: API.adminAudit }[kind];
  try {
    const rows = adminRows(await request(endpoint), kind); el.admin_list.replaceChildren();
    if (!rows.length) { const empty = document.createElement("p"); empty.className = "empty-state ui-panel"; empty.textContent = `No ${kind} to show.`; el.admin_list.append(empty); return; }
    rows.forEach((row) => {
      const card = document.createElement("article"); card.className = "admin-card ui-panel";
      const title = document.createElement("strong"); title.textContent = row.display_name || row.name || row.action || `${kind.slice(0, -1)} ${row.id || ""}`;
      const summary = document.createElement("p"); summary.textContent = kind === "audit" ? [row.actor_display_name || "System", row.subject_type, row.subject_id, formatDate(row.created_at)].filter(Boolean).join(" · ") : [row.email, row.username, row.status, row.kind, ...(row.system_roles || row.roles || [])].filter(Boolean).join(" · ");
      card.append(title, summary); el.admin_list.append(card);
    });
  } catch (error) { el.admin_list.replaceChildren(); const failure = document.createElement("p"); failure.className = "empty-state ui-panel"; failure.textContent = error.message; el.admin_list.append(failure); }
}

async function switchProject(projectId, { initial = false, historyMode = "push" } = {}) {
  const nextId = id(projectId);
  if (!initial && state.surface === "project" && nextId === selectedProjectId()) return;
  state.abortController?.abort();
  closeRealtime();
  state.abortController = new AbortController();
  const generation = ++state.generation;
  const messageGeneration = ++state.messageGeneration;
  state.messages = [];
  state.oldestCursor = "";
  state.newestCursor = "";
  state.hasOlder = false;
  state.draft = { mode: "direct", addressees: [], replyTo: null, idempotencyKey: "" };
  state.components.timeline?.destroy();
  state.components.timeline = null;
  el.timeline_host.replaceChildren();
  el.status_badge.textContent = "Loading";
  const [payload, participantsPayload] = await Promise.all([
    request(`${API.context}?${new URLSearchParams({ project_id: nextId })}`, { signal: state.abortController.signal }),
    request(`${API.participants}?${new URLSearchParams({ project_id: nextId, status: "active" })}`, { signal: state.abortController.signal }),
  ]);
  if (generation !== state.generation) return;
  const context = unwrap(payload) || {};
  state.project = { ...(state.projects.find((project) => project.id === nextId) || {}), ...(context.project || context) };
  state.project.id = nextId;
  state.project.capabilities = context.capabilities || state.project.capabilities || {};
  state.participants = (unwrap(participantsPayload) || context.participants || state.project.participants || []).map((participant) => participantFrom(participant, participant.kind));
  const currentParticipantId = id(context.current_participant_id || context.current_participant?.id || state.project.participant_id);
  state.project.current_participant = state.participants.find((participant) => participant.id === currentParticipantId)
    || (context.current_participant ? participantFrom(context.current_participant, "human") : null);
  const role = String(context.current_role || state.project.role || "viewer");
  state.project.permissions = context.permissions || {
    "messages.read": true,
    "messages.write": ["owner", "admin", "member", "agent"].includes(role),
    "messages.acknowledge": true,
    "project.admin": ["owner", "admin"].includes(role),
  };
  state.filters.sender = "";
  setSurface("project");
  setMobilePanel("right");
  if (historyMode === "push") history.pushState(null, "", `${location.pathname}?project=${encodeURIComponent(nextId)}`);
  else if (historyMode === "replace") history.replaceState(null, "", `${location.pathname}?project=${encodeURIComponent(nextId)}`);
  renderProjectHeader();
  rebuildParticipantControls();
  renderComposerControls();
  await loadMessages("initial", generation, messageGeneration);
  el.status_badge.textContent = "Live";
  void connectRealtime(generation);
}

function rebuildParticipantControls() {
  const items = state.participants.map((participant) => ({ value: participant.id, label: `${participant.display_name} · ${participant.kind}` }));
  state.components.senderSelect?.destroy();
  state.components.senderSelect = state.factories.createSelect(el.sender_filter, items, {
    placeholder: "Any sender", ariaLabel: "Filter by sender", searchable: true, clearable: true,
    selected: state.filters.sender || null,
    onChange(value) { state.filters.sender = id(value); void reloadForFilters(); },
  });
  state.components.addresseeSelect?.destroy();
  const currentId = id(state.project?.current_participant?.id);
  state.components.addresseeSelect = state.factories.createSelect(el.addressee_select, items.filter((item) => item.value !== currentId), {
    placeholder: "Choose people or agents", ariaLabel: "Expected responders", searchable: true, multiple: true, closeOnSelect: false,
    selected: state.draft.addressees,
    onChange(values) { state.draft.addressees = values.map(id); },
  });
  renderParticipants();
}

function renderComposerControls() {
  const writable = state.mode === "expanded" && can("messages.write");
  el.composer_shell.hidden = !writable;
  if (!writable) return;
  state.components.addressMode?.destroy();
  state.components.addressMode = state.factories.createSelect(el.address_mode, [
    { value: "direct", label: "Direct addressees" },
    { value: "broadcast", label: "Broadcast to project" },
  ], {
    searchable: false, clearable: false, ariaLabel: "Addressing mode", selected: state.draft.mode,
    onChange(value) {
      state.draft.mode = value === "broadcast" ? "broadcast" : "direct";
      const broadcast = state.draft.mode === "broadcast";
      el.addressee_select.hidden = broadcast;
      el.broadcast_warning.hidden = !broadcast;
    },
  });
  state.components.composer?.destroy();
  state.components.composer = state.factories.createChatComposer(el.composer_host, { value: "" }, {
    placeholder: "Write to the project timeline…",
    helperText: "All project communications are visible to every project participant. Shift+Enter adds a new line.",
    showAttachmentButton: false,
    maxLength: Number(state.project?.message_max_length || 24000),
    onSend: sendMessage,
  });
  renderReplyContext();
}

function setReply(message) {
  state.draft.replyTo = message;
  renderReplyContext();
  state.components.composer?.focus();
}

function renderReplyContext() {
  el.reply_context.replaceChildren();
  if (!state.draft.replyTo) { el.reply_context.hidden = true; return; }
  el.reply_context.hidden = false;
  const copy = document.createElement("span");
  copy.textContent = `Replying to ${state.draft.replyTo.sender.display_name}: ${state.draft.replyTo.body.slice(0, 160)}`;
  const cancel = actionButton("Cancel reply", () => { state.draft.replyTo = null; renderReplyContext(); });
  el.reply_context.append(copy, cancel);
}

async function sendMessage({ text }) {
  if (state.draft.mode === "direct" && !state.draft.addressees.length) {
    state.components.toast.warn("Select at least one expected responder, or choose Broadcast.", { title: "Addressees required" });
    return;
  }
  if (!state.draft.idempotencyKey) state.draft.idempotencyKey = makeIdempotencyKey();
  state.components.composer.setBusy(true);
  try {
    const body = {
      body: text.trim(),
      reply_to_message_id: state.draft.replyTo?.id || null,
      direct_participant_ids: state.draft.mode === "broadcast" ? [] : state.draft.addressees,
      mention_participant_ids: [],
      broadcast: state.draft.mode === "broadcast",
      idempotency_key: state.draft.idempotencyKey,
    };
    const payload = await request(`${API.messages}?${new URLSearchParams({ project_id: selectedProjectId() })}`, {
      method: "POST", headers: csrfHeaders({ "Idempotency-Key": state.draft.idempotencyKey }), body: JSON.stringify(body),
    });
    const message = normalizeMessage(unwrap(payload)?.message || unwrap(payload));
    if (message.id && !state.messages.some((entry) => entry.id === message.id)) {
      state.messages = sortAndDedupe([message, ...state.messages]);
      renderTimeline("prepend", [message]);
    }
    state.components.composer.clear();
    state.draft.replyTo = null;
    state.draft.idempotencyKey = "";
    renderReplyContext();
  } catch (error) {
    state.components.toast.warn(error.message, { title: "Message not sent" });
  } finally {
    state.components.composer.setBusy(false);
    state.components.composer.focus();
  }
}

async function acknowledgeMessage(message) {
  try {
    const payload = await request(`${API.acknowledge}?${new URLSearchParams({ project_id: selectedProjectId(), id: message.id })}`, {
      method: "POST", headers: csrfHeaders(), body: JSON.stringify({}),
    });
    const responseMessage = unwrap(payload);
    const updated = responseMessage?.id ? normalizeMessage(responseMessage) : {
        ...message,
        updated_at: new Date().toISOString(),
        current_participant_state: { ...message.current_participant_state, is_addressee: true, acknowledged_at: responseMessage?.acknowledged_at || new Date().toISOString() },
      };
    state.messages = state.messages.map((entry) => entry.id === message.id ? updated : entry);
    renderTimeline();
    state.components.toast.success("Message acknowledged.");
  } catch (error) {
    state.components.toast.warn(error.message, { title: "Acknowledgement failed" });
  }
}

function jumpToMessage(messageId) {
  const row = el.timeline_host.querySelector(`[data-item-id="${CSS.escape(id(messageId))}"]`);
  if (row) row.scrollIntoView({ block: "center", behavior: "smooth" });
  else state.components.toast.info("That message is outside the currently loaded timeline. Use search to locate it.", { title: "Message not loaded" });
}

async function openSettings() {
  if (!capability("admin.settings", isAdministrator())) return;
  let settings;
  try { settings = unwrap(await request(API.settings))?.settings || {}; }
  catch (error) { state.components.toast.warn(error.message, { title: "Settings unavailable" }); return; }
  const value = (key, fallback = "") => settings[key]?.value ?? settings[key] ?? fallback;
  const configured = (key) => Boolean(settings[key]?.configured);
  const locked = (key) => Boolean(settings[key]?.locked);
  const modal = state.factories.createFormModal({
    title: "System Settings",
    size: "lg",
    submitLabel: "Save settings",
    busyMessage: "Saving settings…",
    context: { badge: "Global administration", summary: "Integration settings are optional. Blank secrets keep their current value." },
    extraActionsPlacement: "start",
    extraActions: [{
      id: "test-realtime",
      label: "Test Realtime connection",
      variant: "default",
      async onClick(values, context) {
        try {
          const result = unwrap(await request(API.integrationTest, {
            method: "POST",
            headers: csrfHeaders(),
            body: JSON.stringify({ integration: "realtime", base_url: values.realtime_base_url }),
          })) || {};
          if (!result.reachable) throw new Error(result.message || "Realtime is not reachable.");
          const suffix = Number.isFinite(Number(result.latency_ms)) ? ` (${Number(result.latency_ms)} ms)` : "";
          if (result.credentials_configured) state.components.toast.success(`Realtime server is reachable${suffix}.`);
          else state.components.toast.warn(`Realtime server is reachable${suffix}, but the required credentials are incomplete.`, { title: "Configuration incomplete" });
        } catch (error) {
          context.setFormError(error.message);
        }
        return false;
      },
    }],
    initialValues: {
      site_name: value("general.installation_name", "Syndicatum"),
      message_max_length: value("messaging.max_message_bytes", 24000),
      realtime_enabled: Boolean(value("realtime.enabled", false)),
      realtime_base_url: value("realtime.base_url"),
      realtime_client_code: value("realtime.client_code"),
      realtime_project_code: value("realtime.project_code"),
      realtime_connector_authorization_project_code: value("realtime.connector_authorization_project_code"),
      realtime_issuer: value("realtime.issuer", "syndicatum@pbb.ph"),
      realtime_audience: value("realtime.audience", "pbb-realtime"),
      realtime_signing_secret: "",
      realtime_backend_ingress_secret: "",
      account_enabled: Boolean(value("account.enabled", false)),
      account_base_url: value("account.base_url"),
      account_client_id: value("account.client_id", "pbb-syndicatum"),
      account_profile_url: value("account.profile_url"),
      account_client_secret: "",
      native_login_enabled: Boolean(value("account.native_login_enabled", true)),
    },
    rows: [
      [{ type: "text", content: "General and messaging" }],
      [{ type: "input", name: "site_name", label: "Installation name", required: true, disabled: locked("general.installation_name") }, { type: "input", input: "number", name: "message_max_length", label: "Maximum message length", min: 1000, required: true, disabled: locked("messaging.max_message_bytes") }],
      [{ type: "divider" }],
      [{ type: "text", content: "Optional PBB Realtime integration" }],
      [{ type: "checkbox", name: "realtime_enabled", label: "Enable Realtime", disabled: locked("realtime.enabled") }],
      [{ type: "input", input: "url", name: "realtime_base_url", label: "Realtime URL", placeholder: "https://realtime.pbb.ph", disabled: locked("realtime.base_url") }],
      [{ type: "input", name: "realtime_client_code", label: "Realtime client code", disabled: locked("realtime.client_code") }, { type: "input", name: "realtime_project_code", label: "Project scope code", disabled: locked("realtime.project_code") }],
      [{ type: "input", name: "realtime_connector_authorization_project_code", label: "Connector authorization project code", disabled: locked("realtime.connector_authorization_project_code") }],
      [{ type: "input", input: "password", name: "realtime_signing_secret", label: "Replace token-signing secret", disabled: locked("realtime.signing_secret"), placeholder: configured("realtime.signing_secret") ? "Configured — leave blank to keep" : "Not configured" }],
      [{ type: "input", input: "password", name: "realtime_backend_ingress_secret", label: "Replace backend-ingress secret", disabled: locked("realtime.backend_ingress_secret"), placeholder: configured("realtime.backend_ingress_secret") ? "Configured — leave blank to keep" : "Not configured" }],
      [{ type: "text", content: "Advanced token identity" }],
      [{ type: "input", name: "realtime_issuer", label: "Issuer", disabled: locked("realtime.issuer") }, { type: "input", name: "realtime_audience", label: "Audience", disabled: locked("realtime.audience") }],
      [{ type: "divider" }],
      [{ type: "text", content: "Optional PBB Account integration" }],
      [{ type: "checkbox", name: "account_enabled", label: "Enable PBB Account", disabled: locked("account.enabled") }, { type: "checkbox", name: "native_login_enabled", label: "Keep native login available", disabled: locked("account.native_login_enabled") }],
      [{ type: "input", input: "url", name: "account_base_url", label: "PBB Account base URL", disabled: locked("account.base_url") }, { type: "input", name: "account_client_id", label: "OAuth client ID", disabled: locked("account.client_id") }],
      [{ type: "input", input: "url", name: "account_profile_url", label: "Account management URL", disabled: locked("account.profile_url") }],
      [{ type: "input", input: "password", name: "account_client_secret", label: "Replace OAuth client secret", disabled: locked("account.client_secret"), placeholder: configured("account.client_secret") ? "Configured — leave blank to keep" : "Not configured" }],
    ],
    async onSubmit(values, context) {
      const updates = {
        "general.installation_name": values.site_name,
        "messaging.max_message_bytes": Number(values.message_max_length),
        "realtime.enabled": Boolean(values.realtime_enabled),
        "realtime.base_url": values.realtime_base_url,
        "realtime.client_code": values.realtime_client_code,
        "realtime.project_code": values.realtime_project_code,
        "realtime.connector_authorization_project_code": values.realtime_connector_authorization_project_code,
        "realtime.issuer": values.realtime_issuer,
        "realtime.audience": values.realtime_audience,
        "account.enabled": Boolean(values.account_enabled),
        "account.base_url": values.account_base_url,
        "account.client_id": values.account_client_id,
        "account.profile_url": values.account_profile_url,
        "account.native_login_enabled": Boolean(values.native_login_enabled),
      };
      Object.keys(updates).forEach((key) => { if (locked(key)) delete updates[key]; });
      if (values.realtime_signing_secret) updates["realtime.signing_secret"] = { operation: "replace", value: values.realtime_signing_secret };
      if (values.realtime_backend_ingress_secret) updates["realtime.backend_ingress_secret"] = { operation: "replace", value: values.realtime_backend_ingress_secret };
      if (values.account_client_secret) updates["account.client_secret"] = { operation: "replace", value: values.account_client_secret };
      try {
        await request(API.settings, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ settings: updates }) });
        state.components.toast.success("System settings saved.");
        return true;
      } catch (error) {
        context.setFormError(error.message);
        return false;
      }
    },
  });
  modal.open();
}

async function loadExpanded() {
  const projectsPayload = await request(API.projects);
  const source = unwrap(projectsPayload) || {};
  const owned = Array.isArray(source) ? source : (source.owned || source.projects || []);
  const shared = Array.isArray(source.shared) ? source.shared : [];
  state.projects = [...owned.map((project) => ({
    ...project,
    id: id(project.id || project.project_id),
    collection: project.relationship === "shared" ? "Shared" : "My",
  })), ...shared.map((project) => ({ ...project, id: id(project.id || project.project_id), collection: "Shared" }))];
  if (!state.projects.length) {
    showApplication(); showWorkspaceSurface({ historyMode: "replace" });
    return;
  }
  const requested = new URLSearchParams(location.search).get("project");
  showApplication();
  if (requested && state.projects.some((project) => project.id === requested)) await switchProject(requested, { initial: true, historyMode: "replace" });
  else showWorkspaceSurface({ historyMode: "replace" });
}

async function loadLegacy() {
  state.mode = "legacy";
  state.session = null;
  state.project = { id: "legacy", name: "PBB Coordination", description: "The existing shared Syndicatum coordination timeline.", permissions: {} };
  const context = await request("api/chat-context.php");
  const source = unwrap(context) || context;
  state.participants = (source.participants || []).map((participant) => participantFrom({ ...participant, id: participant.name }, "agent"));
  state.projects = [];
  setSurface("project");
  renderProjectHeader();
  rebuildParticipantControls();
  renderComposerControls();
  await loadMessages("initial");
  el.status_badge.textContent = "Legacy";
  setMobilePanel("right");
  showApplication();
}

async function checkSession() {
  try {
    const payload = await request(API.session);
    const session = { ...(unwrap(payload) || {}), capabilities: payload?.capabilities || unwrap(payload)?.capabilities || {} };
    if (session.setup_required) return loadLegacy();
    state.session = session;
    if (session.authenticated === false || !session.user) {
      const ssoFailed = new URLSearchParams(location.search).get("account_sso_error") === "1";
      showLogin(ssoFailed ? "PBB Account sign in could not be completed. You can try again or use native sign in." : "");
      return;
    }
    const returnPath = requestedReturnPath();
    if (returnPath) { location.replace(returnPath); return; }
    state.mode = "expanded";
    await loadExpanded();
  } catch (error) {
    if (error.status === 401) { state.session = error.payload?.data || error.payload || {}; showLogin(); return; }
    if (error.status === 404 || error.status === 503 || error.payload?.setup_required) { await loadLegacy(); return; }
    throw error;
  }
}

async function submitLogin(values, context) {
  try {
    const payload = await request(API.session, {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "login", identity: String(values.identity || "").trim(), password: String(values.password || "") }),
    });
    state.session = unwrap(payload) || {};
    const refreshed = await request(API.session);
    state.session = { ...(unwrap(refreshed) || {}), capabilities: refreshed?.capabilities || unwrap(refreshed)?.capabilities || {} };
    const returnPath = requestedReturnPath();
    if (returnPath) { location.replace(returnPath); return true; }
    state.mode = "expanded";
    await loadExpanded();
    return true;
  } catch (error) {
    context.setFormError(error.message);
    return false;
  }
}

async function logout() {
  clearTimeout(state.pollingTimer);
  closeRealtime();
  state.components.timeline?.destroy();
  state.components.timeline = null;
  location.assign("auth/logout.php");
}

function closeRealtime() {
  state.realtimeGeneration += 1;
  clearTimeout(state.realtimeRetryTimer);
  state.realtimeRetryTimer = null;
  state.realtimeRetryCount = 0;
  const socket = state.realtimeSocket;
  state.realtimeSocket = null;
  socket?.close?.();
}

function messageMatchesFilters(message) {
  const ownId = id(state.project?.current_participant?.id);
  if (state.filters.sender && message.sender.id !== state.filters.sender) return false;
  if (state.filters.q && !message.body.toLocaleLowerCase().includes(state.filters.q.toLocaleLowerCase())) return false;
  const day = String(message.created_at || "").slice(0, 10);
  if (state.filters.from && day < state.filters.from) return false;
  if (state.filters.to && day > state.filters.to) return false;
  const addressed = message.addressees.some((entry) => entry.participant_id === ownId);
  if (state.filters.primary === "addressed" && !addressed) return false;
  if (state.filters.primary === "unacknowledged" && (!addressed || message.current_participant_state?.acknowledged_at)) return false;
  return true;
}

function receiveRealtimeMessage(source) {
  const message = normalizeMessage(source);
  if (!message.id || state.messages.some((entry) => entry.id === message.id)) return;
  const highest = state.messages.reduce((value, entry) => Math.max(value, Number(entry.sequence || 0)), 0);
  if (highest && message.sequence > highest + 1) {
    void loadMessages("newer").catch(handleLoadError);
    return;
  }
  if (!messageMatchesFilters(message)) return;
  state.messages = sortAndDedupe([...state.messages, message]);
  renderTimeline("prepend", [message]);
  state.components.toast.info("1 new message", { title: "Timeline updated" });
}

async function connectRealtime(projectGeneration = state.generation) {
  if (state.mode !== "expanded" || projectGeneration !== state.generation) return;
  if (!state.project?.capabilities?.realtime?.enabled) {
    startPolling();
    return;
  }
  clearTimeout(state.pollingTimer);
  state.pollingTimer = null;
  const attempt = ++state.realtimeGeneration;
  try {
    const admissionPath = state.project.capabilities.realtime.admission_url || `api/v1/realtime-admission.php?project_id=${encodeURIComponent(selectedProjectId())}`;
    const admission = unwrap(await request(admissionPath));
    if (!admission?.enabled) { startPolling(); return; }
    if (!admission.token || !admission.websocket_url || !admission.room) throw new Error("Realtime admission is incomplete.");
    const websocketUrl = new URL(admission.websocket_url, window.location.href);
    if (window.location.protocol === "https:" && websocketUrl.protocol !== "wss:") {
      const error = new Error("Realtime is configured with an insecure WebSocket endpoint. An HTTPS page requires wss://.");
      error.realtimeConfiguration = true;
      throw error;
    }
    const sdkUrl = state.project.capabilities.realtime.sdk_module_url || "/vendor/pbb-realtime/js/sdk/index.js";
    const sdk = await import(sdkUrl);
    let joinRequested = false;
    let joined = false;
    let client;
    const joinTimeout = setTimeout(() => {
      if (!joined) client?.close?.();
    }, 15000);
    const handleMessage = (raw) => {
      let envelope;
      try { envelope = sdk.parseRealtimeEnvelope(raw); } catch (_error) { return; }
      if (envelope?.phase === "ack" && envelope?.type === "session.auth.request") {
        if (!joinRequested) {
          joinRequested = true;
          client.sendRequest("room.join.request", admission.room, sdk.buildRoomJoinPayload());
        }
        return;
      }
      if (envelope?.phase === "ack" && envelope?.type === "room.join.request") {
        joined = true;
        clearTimeout(joinTimeout);
        state.realtimeRetryTimer = null;
        state.realtimeRetryCount = 0;
        clearTimeout(state.pollingTimer);
        state.pollingTimer = null;
        el.status_badge.textContent = "Realtime";
        void loadMessages("newer", projectGeneration).catch(handleLoadError);
        return;
      }
      if (envelope?.phase === "error") {
        client.close();
        return;
      }
      if (envelope?.phase === "event" && envelope.type === "syndicatum.message.created" && envelope.payload?.message) {
        receiveRealtimeMessage(envelope.payload.message);
      }
    };
    client = new sdk.RealtimeSocketClient({
      websocketUrl: admission.websocket_url,
      token: admission.token,
      requestPrefix: "syndicatum",
      onMessage: handleMessage,
      onError() { el.status_badge.textContent = "Realtime reconnecting"; },
      onClose() {
        clearTimeout(joinTimeout);
        if (attempt !== state.realtimeGeneration || projectGeneration !== state.generation) return;
        state.realtimeSocket = null;
        el.status_badge.textContent = "Realtime reconnecting";
        scheduleRealtimeReconnect(projectGeneration);
      },
    });
    state.realtimeSocket = client;
    client.connect();
  } catch (error) {
    if (attempt !== state.realtimeGeneration || projectGeneration !== state.generation) return;
    console.warn("[Syndicatum] Realtime connection attempt failed.", error);
    if (error?.realtimeConfiguration) {
      el.status_badge.textContent = "Realtime configuration error";
      state.components.toast.warn(error.message, { title: "Realtime unavailable" });
      return;
    }
    el.status_badge.textContent = "Realtime reconnecting";
    scheduleRealtimeReconnect(projectGeneration);
  }
}

function scheduleRealtimeReconnect(projectGeneration) {
  clearTimeout(state.realtimeRetryTimer);
  const delay = Math.min(60000, 5000 * (2 ** Math.min(state.realtimeRetryCount, 4)));
  state.realtimeRetryCount += 1;
  state.realtimeRetryTimer = setTimeout(() => void connectRealtime(projectGeneration), delay);
}

function startPolling() {
  clearTimeout(state.pollingTimer);
  const tick = async () => {
    try { await loadMessages("newer"); } catch (_error) { el.status_badge.textContent = "Reconnect needed"; }
    finally { state.pollingTimer = setTimeout(tick, 15000); }
  };
  state.pollingTimer = setTimeout(tick, 15000);
}

async function bootstrap() {
  uiLoader.setPreferBundles(true);
  const options = { css: false };
  const names = ["ui.navbar", "ui.search", "ui.timeline", "ui.toast", "ui.icons", "ui.select", "ui.toggle.group", "ui.chat.composer", "ui.form.modal", "ui.form.modal.login"];
  await uiLoader.loadMany(names, options);
  state.factories = {
    createNavbar: await uiLoader.get("ui.navbar", options),
    createSearchField: await uiLoader.get("ui.search", options),
    createTimeline: await uiLoader.get("ui.timeline", options),
    createToastStack: await uiLoader.get("ui.toast", options),
    createSelect: await uiLoader.get("ui.select", options),
    createToggleGroup: await uiLoader.get("ui.toggle.group", options),
    createChatComposer: await uiLoader.get("ui.chat.composer", options),
    createFormModal: await uiLoader.get("ui.form.modal", options),
    createLoginFormModal: await uiLoader.get("ui.form.modal.login", options),
  };
  state.components.toast = state.factories.createToastStack({ position: "bottom-right", defaultDuration: 3200, max: 4 });
  const search = state.factories.createSearchField({
    classPrefix: "ui-search", placeholder: "Search this project", clearText: "Clear", inputClass: "ui-input",
    onChange(value) { state.filters.q = value.trim(); scheduleFilterReload(); },
  });
  el.search_mount.appendChild(search.wrap);
  search.bind({ on(target, eventName, handler) { target.addEventListener(eventName, handler); } });
  const projectSearch = state.factories.createSearchField({
    classPrefix: "ui-search", placeholder: "Search projects", clearText: "Clear", inputClass: "ui-input",
    onChange(value) { state.projectSearch = value.trim(); renderWorkspace(); },
  });
  el.project_search_mount.appendChild(projectSearch.wrap);
  projectSearch.bind({ on(target, eventName, handler) { target.addEventListener(eventName, handler); } });
  state.components.primaryFilter = state.factories.createToggleGroup(el.primary_filter, {
    name: "Timeline view", multi: false, allowNone: false, size: "sm", items: [
      { id: "all", label: "All", pressed: true },
      { id: "addressed", label: "Addressed to me" },
      { id: "unacknowledged", label: "Unacknowledged" },
    ],
    onChange(payload) { state.filters.primary = payload.value || "all"; void reloadForFilters(); },
  });
  el.date_from.addEventListener("change", () => { state.filters.from = el.date_from.value; void reloadForFilters(); });
  el.date_to.addEventListener("change", () => { state.filters.to = el.date_to.value; void reloadForFilters(); });
  el.clear_filters.addEventListener("click", () => {
    state.filters = { primary: "all", q: "", sender: "", from: "", to: "" };
    el.date_from.value = ""; el.date_to.value = "";
    state.components.primaryFilter.setPressed("all", true);
    state.components.senderSelect?.setValue(null);
    search.setValue("");
    void reloadForFilters();
  });
  el.refresh_button.addEventListener("click", () => void reloadForFilters());
  el.edit_profile_button.addEventListener("click", openProfileModal);
  el.change_password_button.addEventListener("click", () => accountUsesNativePassword() ? openPasswordModal() : openAccountProfile());
  el.rename_workspace_button.addEventListener("click", openRenameWorkspaceModal);
  el.add_project_button.addEventListener("click", openAddProjectModal);
  el.participant_search.addEventListener("input", () => { state.participantSearch = el.participant_search.value.trim(); renderParticipants(); });
  el.admin_refresh_button.addEventListener("click", () => void showAdminSurface(state.adminKind));
  panelButtons.forEach((button) => button.addEventListener("click", () => setMobilePanel(button.dataset.panelButton || "left")));
  addEventListener("popstate", () => {
    if (state.mode !== "expanded") return;
    const project = new URLSearchParams(location.search).get("project");
    if (project) void switchProject(project, { initial: true, historyMode: "none" }).catch(handleLoadError);
    else showWorkspaceSurface({ historyMode: "none" });
  });
  await checkSession();
  if (state.mode === "legacy" && !state.realtimeSocket) startPolling();
}

bootstrap().catch((error) => {
  document.body.innerHTML = `<main class="fatal-error"><h1>Syndicatum could not start</h1><p>${String(error.message || error).replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char])}</p></main>`;
});
