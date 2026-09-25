import { uiLoader, AI_ICONS } from "../vendor/pbb-helper/dist/helpers.ui.bundle.min.js?v=0.21.205";
import { createResponsibilityInbox } from "./responsibility-inbox.mjs";
import { evidenceDetails } from "./responsibility-evidence.mjs?v=20260925160000";
import { guideArticle, searchGuide } from "./user-guide-content.mjs?v=20260925215000";
import { mountCurrentBackup } from "./current-backup-ui.mjs?v=202609240004";
import { mountCurrentRestore } from "./current-restore-ui.mjs?v=202609232355";

const GOOGLE_SIGN_IN_ICON = '<img class="syndicatum-google-button-image" src="assets/google-signin-dark.svg" alt="">';
const SYNDICATUM_BRAND_ICON = '<img class="syndicatum-brand-icon" src="assets/brand/svg/syndicatum-standard-color.svg?v=20260907115852" alt="" aria-hidden="true">';
const MORE_ACTIONS_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.75" fill="currentColor"></circle><circle cx="12" cy="12" r="1.75" fill="currentColor"></circle><circle cx="19" cy="12" r="1.75" fill="currentColor"></circle></svg>';
const CLAIM_CODE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 14a4.5 4.5 0 1 1 3.9-6.75l7.35.01v3h-2v2h-3v2H11.4A4.48 4.48 0 0 1 7.5 14Zm0-3a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" fill="currentColor"></path></svg>';
const REMOVE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M7 7l1 12h8l1-12M10 11v5M14 11v5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
const COLLAPSE_ALL_ICON = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M4 4h16M4 20h16M12 7v10m-3-7 3-3 3 3m-6 4 3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
const EXPAND_ALL_ICON = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M4 4h16M4 20h16M12 7v10m-3-3 3 3 3-3m-6-4 3-3 3 3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
const APP_BASE_PATH = new URL(document.baseURI).pathname.replace(/\/$/, "");
const WORKSPACE_MOBILE_QUERY = "(max-width: 980px)";
const TEMPLATE_MOBILE_QUERY = "(max-width: 680px)";
const TIMELINE_MARKER_ICONS = new Map();

const API = {
  session: "api/v1/session.php",
  projects: "api/v1/projects.php",
  context: "api/v1/project.php",
  participants: "api/v1/project-participants.php",
  messages: "api/v1/project-messages.php",
  tasks: "api/v1/project-tasks.php",
  task: "api/v1/project-task.php",
  acknowledge: "api/v1/project-message-acknowledge.php",
  responsibilityInbox: "api/v1/project-responsibility-inbox.php",
  message: "api/v1/project-message.php",
  settings: "api/v1/admin/settings.php",
  projectTemplates: "api/v1/admin/project-templates.php",
  projectTemplateLibrary: "api/v1/project-templates.php",
  integrationTest: "api/v1/admin/integration-test.php",
  profile: "api/v1/profile.php",
  googleLink: "api/v1/google-link.php",
  password: "api/v1/password.php",
  workspace: "api/v1/workspace.php",
  manageProjects: "api/v1/manage-projects.php",
  projectInvitations: "api/v1/project-invitations.php",
  projectMembers: "api/v1/project-members.php",
  projectAgents: "api/v1/project-agents.php",
  projectAgentWebhook: "api/v1/project-agent-webhook.php",
  projectAgentActivation: "api/v1/project-agent-activation.php",
  discussionProviders: "api/v1/discussion-providers.php",
  avatarUpload: "api/v1/avatar-upload.php",
  adminUsers: "api/v1/admin/users.php",
  adminAgents: "api/v1/admin/agents.php",
  adminAudit: "api/v1/admin/audit.php",
  adminDeliveryHealth: "api/v1/admin/delivery-health.php",
  recoveryStatus: "api/v1/admin/recovery-status.php",
  releasePackage: "api/v1/admin/release-package.php",
  backups: "api/v1/admin/backups.php",
  restores: "api/v1/admin/restores.php",
  backupDownloads: "api/v1/admin/backup-downloads.php",
  backupRecoveryKey: "api/v1/admin/backup-recovery-key.php",
  kickstartDownload: "api/v1/admin/kickstart-download.php",
  restoreInspections: "api/v1/admin/restore-inspections.php",
  stagedRestores: "api/v1/admin/staged-restores.php",
  artifactDownload: "api/v1/admin/artifact-download.php",
};

const state = {
  mode: "boot",
  surface: "workspace",
  projectSearch: "",
  participantSearch: "",
  adminKind: "",
  recoveryStatus: null,
  backupGridColumnWidths: null,
  backupPage: null,
  session: null,
  projects: [],
  project: null,
  participants: [],
  messages: [],
  tasks: [],
  templates: [],
  templateCategories: [],
  selectedTemplateId: "",
  collapsedTemplateCategoryIds: new Set(),
  templateMobileView: "library",
  selectedGuideArticleId: "workspace-projects",
  guideQuery: "",
  taskFilter: "active",
  projectView: "timeline",
  aiIconPackAvailable: false,
  timelineDefaultCollapsed: false,
  filters: { primary: "all", q: "", sender: "", from: "", to: "" },
  draft: { mode: "direct", intent: "update", addressees: [], replyTo: null, preReplyAddressing: null, idempotencyKey: "" },
  oldestCursor: "",
  newestCursor: "",
  hasOlder: false,
  loading: false,
  generation: 0,
  messageGeneration: 0,
  filterTimer: null,
  pollingTimer: null,
  foregroundSyncTimer: null,
  realtimeSocket: null,
  realtimeRetryTimer: null,
  realtimeRetryCount: 0,
  realtimeGeneration: 0,
  realtimeRooms: new Set(),
  realtimeError: null,
  abortController: null,
  mobilePanel: "projects",
  teamVisible: true,
  factories: {},
  components: {},
  recoveryStatus: null,
};

const el = Object.fromEntries([
  "app-shell", "navbar-host", "workspace-surface", "admin-surface",
  "workspace-splitter-host", "workspace-inner-splitter-host", "workspace-work-splitter-host", "project-navigation-column", "project-messages-column", "project-tasks-column", "project-participants-column",
  "project-search-mount", "workspace-project-list", "project-list-actions-trigger", "project-list-actions-icon",
  "status-badge", "project-title", "participant-list", "task-list", "task-count", "task-status-filter", "new-task-trigger", "task-guide-trigger",
  "participant-search", "new-message-trigger", "new-message-icon", "project-actions-trigger", "project-actions-icon", "team-actions-trigger", "team-actions-icon", "connection-label",
  "timeline-count", "refresh-button", "timeline-collapse-toggle", "timeline-collapse-icon", "primary-filter", "search-mount", "sender-filter", "date-from", "date-to", "clear-filters",
  "filter-popover-trigger", "filter-popover-content", "filter-count", "filter-icon", "refresh-icon",
  "timeline-notice", "timeline-host", "composer-shell", "reply-context", "addressing-row", "address-mode", "message-intent", "addressee-select", "broadcast-warning", "composer-host",
  "project-view-switch", "show-timeline", "show-responsibility", "responsibility-host", "timeline-filter-bar", "timeline-scroll",
  "admin-eyebrow", "admin-title", "admin-list", "admin-refresh-button", "public-policy-links",
].map((id) => [id.replaceAll("-", "_"), document.getElementById(id)]));

const panels = Array.from(document.querySelectorAll("[data-panel]"));

function unwrap(payload) {
  return payload && Object.prototype.hasOwnProperty.call(payload, "data") ? payload.data : payload;
}

function id(value) {
  return value == null ? "" : String(value);
}

function readLocalPreference(key, fallback = "") {
  try {
    const value = localStorage.getItem(key);
    return value == null ? fallback : value;
  } catch (_error) {
    return fallback;
  }
}

function writeLocalPreference(key, value) {
  try { localStorage.setItem(key, String(value)); } catch (_error) { /* Storage is optional. */ }
}

function storedSplitterRatio(key, fallback) {
  const value = Number(readLocalPreference(key, String(fallback)));
  return Number.isFinite(value) ? value : fallback;
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

function redirectWithBusy(context, url, message = "Opening Google sign in...") {
  context?.clearFormError?.();
  context?.setBusy?.(true, { message });
  requestAnimationFrame(() => requestAnimationFrame(() => location.assign(url)));
  return false;
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
    role_title: source.role_title || "",
    role_summary: source.role_summary || "",
    role_instructions: source.role_instructions || "",
    role_version: source.role_version === null || source.role_version === undefined ? null : Number(source.role_version),
    supervisor: source.supervisor || null,
    webhook: source.webhook || source.notification_webhook || null,
    joined_at: source.joined_at || source.created_at || null,
    last_message_at: source.last_message_at || null,
    message_count: source.message_count === null || source.message_count === undefined ? null : Number(source.message_count),
    email: source.email || null,
    authentication_source: String(source.authentication_source || ""),
  };
}

function hashColor(value) {
  let hash = 0;
  for (const char of String(value || "participant")) hash = ((hash << 5) - hash + char.charCodeAt(0)) | 0;
  return [0, 8, 16].map((shift) => (96 + ((hash >>> shift) & 95)).toString(16).padStart(2, "0")).join("");
}

function normalizeUtcTimestamp(value) {
  const timestamp = String(value || "").trim();
  if (!timestamp) return "";
  const normalized = timestamp.includes("T") ? timestamp : timestamp.replace(" ", "T");
  const timezoneLessDateTime = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/;
  return timezoneLessDateTime.test(normalized) ? `${normalized}Z` : normalized;
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
  const created = normalizeUtcTimestamp(source.created_at || source.timestamp || source.message_timestamp) || new Date().toISOString();
  const currentParticipantId = id(state.project?.current_participant?.id || state.session?.participant?.id);
  const ownAddress = addressees.find((entry) => entry.participant_id === currentParticipantId);
  return {
    ...source,
    id: id(source.id ?? source.entry_uuid ?? source.db_id),
    sequence: Number(source.sequence ?? source.project_sequence ?? source.index ?? source.db_id ?? 0),
    body: String(source.body || ""),
    created_at: created,
    updated_at: normalizeUtcTimestamp(source.updated_at) || created,
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
    && (message.addressees.length === 0
      || message.addressees.every((entry) => String(entry.reason || "").toLowerCase() === "broadcast"));
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

function applicationPath(path = "") {
  const suffix = String(path || "").replace(/^\/+/, "");
  return `${APP_BASE_PATH}/${suffix}`;
}

function applicationResourceUrl(path) {
  const source = String(path || "").trim();
  if (/^[a-z][a-z0-9+.-]*:/i.test(source) || source.startsWith("//")) return source;
  return new URL(source.replace(/^\/+/, ""), document.baseURI).href;
}

function routeForSurface(surface, projectId = "") {
  if (surface === "project" && projectId) return applicationPath(`projects/${encodeURIComponent(projectId)}`);
  if (surface === "backup-restore") return `${applicationPath()}?admin=backup-restore`;
  if (surface === "guide") return `${applicationPath("guide")}#${encodeURIComponent(state.selectedGuideArticleId || "workspace-projects")}`;
  if (["users", "agents", "audit", "templates", "delivery-health"].includes(surface)) return applicationPath(surface);
  return applicationPath();
}

function currentApplicationRoute() {
  if (new URLSearchParams(location.search).get("admin") === "backup-restore") return { surface: "backup-restore", projectId: "" };
  const pathname = location.pathname.startsWith(`${APP_BASE_PATH}/`)
    ? location.pathname.slice(APP_BASE_PATH.length)
    : location.pathname;
  const parts = pathname.split("/").filter(Boolean);
  if (parts[0] === "projects" && parts[1]) {
    try { return { surface: "project", projectId: decodeURIComponent(parts[1]) }; }
    catch (_error) { return { surface: "workspace", projectId: "" }; }
  }
  if (parts[0] === "guide") {
    try { return { surface: "guide", projectId: "", articleId: decodeURIComponent(location.hash.replace(/^#/, "")) || "workspace-projects" }; }
    catch (_error) { return { surface: "guide", projectId: "", articleId: "workspace-projects" }; }
  }
  if (["users", "agents", "audit", "templates", "delivery-health", "backup-restore"].includes(parts[0])) return { surface: parts[0], projectId: "" };
  const legacyProjectId = new URLSearchParams(location.search).get("project") || "";
  return legacyProjectId ? { surface: "project", projectId: legacyProjectId } : { surface: "workspace", projectId: "" };
}

function updateApplicationRoute(surface, projectId = "", historyMode = "push") {
  if (historyMode === "none") return;
  const path = routeForSurface(surface, projectId);
  if (`${location.pathname}${location.search}${location.hash}` === path) return;
  history[historyMode === "replace" ? "replaceState" : "pushState"]({ surface, projectId }, "", path);
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
  return user.has_native_password !== false;
}

function usesPbbAccount() {
  const user = state.session?.user || {};
  return ["account", "pbb_account"].includes(String(user.authentication_source || user.auth_source || "").toLowerCase());
}

function openAccountProfile() {
  const url = state.session?.capabilities?.account_profile_url;
  if (url) { location.assign(url); return; }
  state.components.toast.info("Your password and account profile are managed by PBB Account.", { title: "PBB Account" });
}

function navbarAvatarHtml() {
  const user = participantFrom({ ...(state.session?.user || {}), kind: "human" }, "human");
  const name = user.display_name || "Account";
  if (user.avatar_url) {
    const image = document.createElement("img");
    image.className = "navbar-account-avatar";
    image.src = user.avatar_url;
    image.alt = `${name} profile photo`;
    image.referrerPolicy = "no-referrer";
    return image.outerHTML;
  }

  const fallback = document.createElement("span");
  fallback.className = "navbar-account-avatar is-initials";
  fallback.setAttribute("aria-hidden", "true");
  fallback.textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join("").toUpperCase() || "?";
  fallback.style.background = `#${user.color_seed}`;
  return fallback.outerHTML;
}

function helperIconHtml(name, size = 14) {
  if (!state.factories.createIcon) return "";
  try {
    return state.factories.createIcon(name, { size, decorative: true })?.outerHTML || "";
  } catch (_error) {
    return "";
  }
}

function mountNavbar() {
  if (!state.factories.createNavbar) return;
  const items = [];
  const workspaceVisible = ["workspace", "project"].includes(state.surface);
  const mobileWorkspace = workspaceVisible && matchMedia(WORKSPACE_MOBILE_QUERY).matches;
  if (state.mode === "expanded" && capability("workspace.view", true)) {
    items.push({ id: "mobile-timeline", label: "Timeline", icon: helperIconHtml("data.list"), className: "ui-button-borderless mobile-workspace-nav", disabled: !selectedProjectId() });
    items.push({ id: "mobile-tasks", label: "Tasks", icon: helperIconHtml("actions.check"), className: "ui-button-borderless mobile-workspace-nav", disabled: !selectedProjectId() });
    if (state.teamVisible) items.push({ id: "mobile-team", label: "Team", icon: helperIconHtml("people.users"), className: "ui-button-borderless mobile-workspace-nav", disabled: !selectedProjectId() });
  }
  const actions = [];
  const administratorItems = state.mode === "expanded" && isAdministrator() ? [
    ...(capability("admin.users") ? [{ id: "users", label: "Users", icon: helperIconHtml("people.users") }] : []),
    ...(capability("admin.audit") ? [{ id: "audit", label: "Audit", icon: helperIconHtml("time.history") }] : []),
    ...(capability("admin.settings") ? [{ id: "templates", label: "Templates", icon: helperIconHtml("assets.document") }] : []),
    ...(capability("admin.settings") ? [{ id: "settings", label: "Settings", icon: helperIconHtml("actions.settings") }] : []),
    ...(capability("admin.settings") ? [{ id: "backup-restore", label: "Backup / Restore", icon: helperIconHtml("actions.download") }] : []),
    ...(capability("admin.settings") ? [{ id: "delivery-health", label: "Delivery health", icon: helperIconHtml("actions.settings") }] : []),
  ] : [];
  if (administratorItems.length) actions.push({
    id: "administrator",
    label: "Administrator",
    icon: helperIconHtml("actions.settings"),
    iconOnly: true,
    className: "ui-button-borderless",
    menuItems: administratorItems,
  });
  if (state.mode === "expanded") actions.push({
    id: "account",
    label: state.session?.user?.display_name || "Account",
    icon: navbarAvatarHtml(),
    iconOnly: true,
    className: "ui-button-borderless",
    menuGroups: [
      {
        id: "account",
        label: "Account",
        className: "syndicatum-account-menu-group",
        items: [
          { id: "guide", label: "User Guide", icon: helperIconHtml("assets.document") },
          { id: "profile", label: "Profile", icon: helperIconHtml("people.profile") },
          ...(accountUsesNativePassword() ? [{ id: "password", label: "Change Password", icon: helperIconHtml("actions.lock") }] : []),
          ...(usesPbbAccount() ? [{ id: "account-profile", label: "Manage PBB Account", icon: helperIconHtml("people.account") }] : []),
        ],
      },
      {
        id: "legal",
        label: "Legal",
        className: "syndicatum-account-menu-group",
        items: [
          { id: "support", label: "Support", icon: helperIconHtml("comms.message") },
          { id: "privacy", label: "Privacy Policy", icon: helperIconHtml("actions.lock") },
          { id: "terms", label: "Terms of Service", icon: helperIconHtml("assets.document") },
          { id: "license", label: "Source & License", icon: helperIconHtml("assets.document") },
        ],
      },
      {
        id: "session",
        label: "Session",
        className: "syndicatum-account-menu-group",
        items: [{ id: "signout", label: "Logout", icon: helperIconHtml("navigation.arrow-right"), danger: true }],
      },
    ],
  });
  state.components.navbar?.destroy();
  state.components.navbar = state.factories.createNavbar(el.navbar_host, {}, {
    brandText: "Syndicatum",
    brandSubtitle: state.surface === "project" && state.project ? state.project.name : "Human + agent collaboration",
    brandMedia: SYNDICATUM_BRAND_ICON,
    className: "syndicatum-navbar-single-row",
    activeId: mobileWorkspace ? `mobile-${state.mobilePanel}` : (["workspace", "project"].includes(state.surface) ? "" : state.surface),
    items,
    actions,
    sticky: true,
    mobileCollapse: false,
    mobileLayout: "scroll",
    onNavigate(item) {
      if (item?.id === "brand") showWorkspaceSurface();
      else if (item?.id === "mobile-timeline") showMobileWorkspacePanel("timeline");
      else if (item?.id === "mobile-tasks") showMobileWorkspacePanel("tasks");
      else if (item?.id === "mobile-team") showMobileWorkspacePanel("team");
      else if (["users", "agents", "audit", "templates", "delivery-health", "backup-restore"].includes(item?.id)) void showAdminSurface(item.id);
    },
    onActionMenuSelect(_action, item) {
      if (["users", "audit", "templates", "delivery-health", "backup-restore"].includes(item?.id)) void showAdminSurface(item.id);
      else if (item?.id === "guide") showGuideSurface();
      else if (item?.id === "settings") void openSettings();
      else if (item?.id === "profile") openProfileModal();
      else if (item?.id === "password") openPasswordModal();
      else if (item?.id === "account-profile") openAccountProfile();
      else if (item?.id === "support") location.assign("support");
      else if (item?.id === "privacy") location.assign("privacy");
      else if (item?.id === "terms") location.assign("terms");
      else if (item?.id === "license") location.assign("license");
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
  if (participant.avatar_url) {
    const image = document.createElement("img");
    image.src = participant.avatar_url;
    image.alt = "";
    image.loading = "lazy";
    image.referrerPolicy = "no-referrer";
    image.addEventListener("error", () => image.replaceWith(fallback), { once: true });
    wrap.appendChild(image);
  } else {
    wrap.appendChild(fallback);
  }
  if (participant.kind === "agent") {
    const badge = document.createElement("span");
    badge.className = "participant-kind-mark is-agent-icon";
    const agentIcon = helperIconHtml("people.agent", 11);
    if (agentIcon) badge.innerHTML = agentIcon;
    else badge.textContent = "A";
    badge.title = "Agent";
    badge.setAttribute("aria-label", "Agent");
    wrap.appendChild(badge);
  }
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
  if (name === "team" && !state.teamVisible) name = "timeline";
  state.mobilePanel = name;
  panels.forEach((panel) => panel.classList.toggle("is-mobile-active", panel.dataset.panel === name));
  if (name === "timeline" && state.project) requestAnimationFrame(() => state.components.timeline?.resetReachEnd());
  if (state.components.navbar) mountNavbar();
}

function showMobileWorkspacePanel(name) {
  if (!selectedProjectId()) {
    showWorkspaceSurface();
    return;
  }
  const needsProjectRoute = state.surface !== "project";
  setSurface("project");
  setMobilePanel(name);
  if (needsProjectRoute) {
    updateApplicationRoute("project", state.project.public_id || selectedProjectId(), "push");
  }
}

function mountWorkspaceSplitters() {
  state.teamVisible = readLocalPreference("syndicatum.workspace.team", "shown") !== "hidden";
  state.components.workspaceWorkSplitter = state.factories.createSplitter(el.workspace_work_splitter_host, {
    className: "workspace-work-splitter",
    orientation: "horizontal",
    panePadding: 0,
    chrome: false,
    initialRatio: storedSplitterRatio("syndicatum.workspace.tasksRatio", 0.52),
    minRatio: 0.42,
    maxRatio: 0.68,
    paneA: el.project_tasks_column,
    paneB: el.project_participants_column,
    onResize(ratio) { writeLocalPreference("syndicatum.workspace.tasksRatio", ratio); },
  });
  state.components.workspaceInnerSplitter = state.factories.createSplitter(el.workspace_inner_splitter_host, {
    className: "workspace-inner-splitter",
    orientation: "horizontal",
    panePadding: 0,
    chrome: false,
    initialRatio: storedSplitterRatio("syndicatum.workspace.timelineRatio", 0.64),
    minRatio: 0.48,
    maxRatio: 0.76,
    paneA: el.project_messages_column,
    paneB: el.workspace_work_splitter_host,
    onResize(ratio) { writeLocalPreference("syndicatum.workspace.timelineRatio", ratio); },
  });
  state.components.workspaceOuterSplitter = state.factories.createSplitter(el.workspace_splitter_host, {
    className: "workspace-outer-splitter",
    orientation: "horizontal",
    panePadding: 0,
    initialRatio: storedSplitterRatio("syndicatum.workspace.projectsRatio", 0.23),
    minRatio: 0.16,
    maxRatio: 0.36,
    paneA: el.project_navigation_column,
    paneB: el.workspace_inner_splitter_host,
    onResize(ratio) { writeLocalPreference("syndicatum.workspace.projectsRatio", ratio); },
  });
  syncTeamVisibility();
}

function syncTeamVisibility() {
  el.workspace_surface.classList.toggle("is-team-hidden", !state.teamVisible);
  if (!state.teamVisible && state.mobilePanel === "team") setMobilePanel("timeline");
  else if (state.components.navbar) mountNavbar();
}

function setTeamVisible(visible) {
  state.teamVisible = Boolean(visible);
  writeLocalPreference("syndicatum.workspace.team", state.teamVisible ? "shown" : "hidden");
  syncTeamVisibility();
  renderProjectHeader();
  if (state.teamVisible && matchMedia("(max-width: 980px)").matches) setMobilePanel("team");
}

function showLogin(message = "") {
  state.mode = "login";
  el.app_shell.hidden = true;
  el.public_policy_links.hidden = false;
  const returnPath = requestedReturnPath();
  const accountEnabled = Boolean(state.session?.capabilities?.account_sso || state.session?.capabilities?.pbb_account);
  const googleEnabled = Boolean(state.session?.capabilities?.google_sso);
  const extraActions = [];
  if (googleEnabled) extraActions.push({
    id: "google",
    label: "Sign in with Google",
    ariaLabel: "Sign in with Google",
    icon: GOOGLE_SIGN_IN_ICON,
    className: "syndicatum-google-button",
    variant: "ghost",
    closeOnClick: false,
    onClick(_values, context) {
      const url = returnPath ? `auth/google.php?return=${encodeURIComponent(returnPath)}` : "auth/google.php";
      return redirectWithBusy(context, url);
    },
  });
  if (accountEnabled) extraActions.push({
    id: "pbb-account",
    label: "Continue with PBB Account",
    variant: "ghost",
    closeOnClick: false,
    onClick() {
      location.assign(returnPath ? `auth/account.php?return=${encodeURIComponent(returnPath)}` : "auth/account.php");
      return false;
    },
  });
  const options = {
    title: "Welcome to Syndicatum",
    className: "syndicatum-login-modal",
    size: accountEnabled && googleEnabled ? "md" : "sm",
    message: message || "Sign in to collaborate with the humans and agents in your projects.",
    mediaUrl: "assets/brand/svg/syndicatum-standard-color.svg?v=20260907115852",
    mediaAlt: "Syndicatum",
    backgroundTone: "none",
    identifierKind: "email",
    identifierLabel: "Email address",
    identifierPlaceholder: "Enter your email address",
    fields: { identifier: "identity", password: "password" },
    submitLabel: "Sign in",
    cancelLabel: state.session?.capabilities?.self_registration === false ? "Cancel" : "Register",
    busyMessage: "Signing in...",
    closeOnBackdrop: false,
    closeOnEscape: false,
    showCloseButton: false,
    extraActionsPlacement: "start",
    extraActions,
    onSubmit: submitLogin,
    onClose(event = {}) {
      const shouldRegister = (event.reason === "cancel" || event.actionId === "cancel") && state.session?.capabilities?.self_registration !== false;
      state.components.login = null;
      if (shouldRegister) openRegistrationModal();
    },
  };
  if (state.components.login) state.components.login.update(options);
  else state.components.login = state.factories.createLoginFormModal(options);
  if (!state.components.login.getState().open) state.components.login.open();
}

function openRegistrationModal() {
  const googleEnabled = Boolean(state.session?.capabilities?.google_sso);
  const returnPath = requestedReturnPath();
  const extraActions = googleEnabled ? [{
    id: "google",
    label: "Continue with Google",
    ariaLabel: "Continue with Google",
    icon: GOOGLE_SIGN_IN_ICON,
    className: "syndicatum-google-button",
    variant: "ghost",
    closeOnClick: false,
    onClick(_values, context) {
      const url = returnPath ? `auth/google.php?return=${encodeURIComponent(returnPath)}` : "auth/google.php";
      return redirectWithBusy(context, url);
    },
  }] : [];
  const modal = state.factories.createFormModal({
    title: "Create your Syndicatum account",
    size: googleEnabled ? "md" : "sm",
    submitLabel: "Register",
    cancelLabel: "Back to sign in",
    busyMessage: "Creating your account...",
    extraActionsPlacement: "start",
    extraActions,
    rows: [
      [{ type: "text", content: "Create a standard human account and your personal workspace." }],
      [{ type: "input", name: "display_name", label: "Display name", autocomplete: "name", required: true }],
      [{ type: "input", input: "email", name: "email", label: "Email address", autocomplete: "email", required: true }],
      [{ type: "input", input: "password", name: "password", label: "Password", autocomplete: "new-password", required: true, help: "Use at least 12 characters." }],
      [{ type: "input", input: "password", name: "password_confirmation", label: "Confirm password", autocomplete: "new-password", required: true }],
    ],
    async onSubmit(values, context) {
      try {
        const payload = await request(API.session, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ action: "register", ...values }),
        });
        state.session = unwrap(payload) || {};
        const refreshed = await request(API.session);
        state.session = { ...(unwrap(refreshed) || {}), capabilities: refreshed?.capabilities || unwrap(refreshed)?.capabilities || {} };
        state.mode = "expanded";
        await loadExpanded();
        return true;
      } catch (error) {
        context.setFormError(error.message);
        return false;
      }
    },
    onClose(event = {}) {
      if (event.reason === "cancel" || event.actionId === "cancel") showLogin();
    },
  });
  modal.open();
}

function requestedReturnPath() {
  const value = new URLSearchParams(location.search).get("return") || "";
  return value.startsWith("/") && !value.startsWith("//") && !value.includes("\\") ? value : "";
}

function showApplication() {
  el.app_shell.hidden = false;
  el.public_policy_links.hidden = state.mode === "expanded";
  document.body.classList.toggle("expanded-mode", state.mode === "expanded");
  document.body.classList.toggle("legacy-mode", state.mode === "legacy");
}

function renderIdentity() {
  mountNavbar();
}

function renderProjectHeader() {
  const project = state.project || {};
  const hasProject = Boolean(selectedProjectId());
  el.project_view_switch.hidden = !hasProject || state.mode !== "expanded";
  el.project_title.textContent = project.name || (state.mode === "legacy" ? "PBB Coordination" : "Select a project");
  el.new_message_trigger.hidden = !hasProject || state.mode !== "expanded" || !can("messages.write") || state.projectView === "responsibility";
  state.components.projectActions?.destroy?.();
  state.components.projectActions = null;
  const actions = [];
  if (hasProject) actions.push({ id: "info", label: "Project Info", icon: helperIconHtml("status.info") });
  if (hasProject && state.mode === "expanded" && (can("project.manage") || can("project.admin"))) {
    actions.push({ id: "edit", label: "Edit project", icon: helperIconHtml("actions.edit") });
  }
  if (hasProject) actions.push({
    id: "toggle-team",
    label: state.teamVisible ? "Hide team" : "Show team",
    icon: helperIconHtml(state.teamVisible ? "actions.hide" : "actions.view"),
  });
  el.project_actions_trigger.hidden = !hasProject || actions.length === 0;
  if (actions.length) {
    state.components.projectActions = state.factories.createDropdown(el.project_actions_trigger, actions, {
      align: "right",
      ariaLabel: "Project actions",
      onSelect(item) {
        if (item.id === "info") openProjectInfoModal();
        if (item.id === "edit") openEditProjectModal();
        if (item.id === "toggle-team") setTeamVisible(!state.teamVisible);
      },
    });
  }
  state.components.teamActions?.destroy?.();
  state.components.teamActions = null;
  const teamActions = [];
  if (hasProject && state.mode === "expanded" && can("members.manage")) {
    teamActions.push({ id: "invite", label: "Invite member", icon: helperIconHtml("people.users") });
  }
  if (hasProject && state.mode === "expanded" && can("agents.manage")) {
    teamActions.push({ id: "add-agent", label: "Add agent", icon: helperIconHtml("people.agent") });
  }
  el.team_actions_trigger.hidden = teamActions.length === 0;
  if (teamActions.length) {
    state.components.teamActions = state.factories.createDropdown(el.team_actions_trigger, teamActions, {
      align: "right",
      ariaLabel: "Team actions",
      onSelect(item) {
        if (item.id === "invite") openInviteMemberModal();
        if (item.id === "add-agent") openAddAgentModal();
      },
    });
  }
  renderIdentity();
}

function participantProviderLabel(provider) {
  const normalized = String(provider || "").trim().toLowerCase();
  const labels = { chatgpt: "ChatGPT", codex: "Codex", gemini: "Gemini", openai: "OpenAI" };
  if (labels[normalized]) return labels[normalized];
  return normalized ? normalized.replace(/(^|[-_\s])\w/g, (match) => match.toUpperCase()).replaceAll("_", " ").replaceAll("-", " ") : "Unspecified";
}

function participantCapabilityLabels(capabilities) {
  if (Array.isArray(capabilities)) return capabilities.map(String).map((value) => value.trim()).filter(Boolean);
  if (!capabilities || typeof capabilities !== "object") return [];
  return Object.entries(capabilities)
    .filter(([, value]) => value !== false && value != null && value !== "")
    .map(([key, value]) => projectInfoLabel(value === true ? key : `${key}: ${String(value)}`));
}

function canEditParticipant(participant) {
  if (participant.kind === "agent") return can("agents.manage");
  if (participant.id === id(state.project?.current_participant?.id)) return true;
  return participant.role !== "owner" && can("members.manage");
}

function openParticipantInfoModal(participant) {
  const isAgent = participant.kind === "agent";
  const editable = canEditParticipant(participant);
  const canRemoveHuman = !isAgent && participant.role !== "owner"
    && participant.id !== id(state.project?.current_participant?.id) && can("members.manage");
  const hasProfileActions = (isAgent && can("agents.manage")) || canRemoveHuman;
  const content = projectInfoElement("div", "participant-profile-content");
  const layout = projectInfoElement("div", "participant-profile-layout");
  const identity = projectInfoElement("aside", "participant-profile-identity");
  const avatar = makeAvatar(participant, "profile");
  avatar.querySelector(".participant-kind-mark")?.remove();
  const avatarWrap = projectInfoElement("div", "participant-profile-avatar");
  avatarWrap.append(avatar);
  identity.append(avatarWrap, projectInfoElement("h2", "participant-profile-name", participant.display_name));
  if (isAgent && participant.role_title) {
    identity.append(projectInfoElement("p", "participant-profile-role-title", participant.role_title));
  }
  if (isAgent && participant.role_summary) {
    identity.append(projectInfoElement("p", "participant-profile-role-summary", participant.role_summary));
  }
  const badges = projectInfoElement("div", "participant-profile-badges");
  const typeBadge = projectInfoElement("span", `participant-profile-badge is-${participant.kind}`);
  const typeIcon = projectInfoElement("span", "participant-profile-badge-icon");
  typeIcon.innerHTML = helperIconHtml(isAgent ? "people.agent" : "people.user", 17);
  typeBadge.append(typeIcon, document.createTextNode(isAgent ? "Agent" : "Human"));
  const status = String(participant.status || "active").toLowerCase();
  const statusBadge = projectInfoElement("span", `participant-profile-badge participant-profile-membership-status is-${status}`);
  statusBadge.append(projectInfoElement("span", "participant-profile-status-dot"), document.createTextNode(participantMembershipStatusLabel(status)));
  if (!isAgent) badges.append(typeBadge);
  badges.append(statusBadge);
  identity.append(badges);

  const details = projectInfoElement("div", "participant-profile-details");
  const membership = participantProfileSection("people.users", "Project membership");
  const membershipList = projectInfoElement("dl", "participant-profile-definition-list");
  participantProfileDefinition(membershipList, "Project role", isAgent
    ? (participant.role_title || "Agent")
    : projectInfoLabel(participant.role || "member"));
  if (isAgent) {
    if (participant.supervisor) {
      const supervisor = state.participants.find((entry) => entry.id === id(participant.supervisor.participant_id));
      if (supervisor) {
        const supervisorButton = projectInfoElement("button", "participant-profile-supervisor-link",
          `${participant.supervisor.display_name || supervisor.display_name}${participant.supervisor.active === false ? " (inactive)" : ""}`);
        supervisorButton.type = "button";
        supervisorButton.addEventListener("click", async () => {
          await modal.close({ reason: "open-supervisor" });
          openParticipantInfoModal(supervisor);
        });
        participantProfileDefinition(membershipList, "Reports to", supervisorButton);
      } else {
        participantProfileDefinition(membershipList, "Reports to",
          `${participant.supervisor.display_name || "Unavailable participant"}${participant.supervisor.active === false ? " (inactive)" : ""}`);
      }
    } else {
      participantProfileDefinition(membershipList, "Reports to", "No supervisor assigned");
    }
  }
  if (participant.joined_at) participantProfileDefinition(membershipList, "Date added", participantProfileDate(participant.joined_at));
  if (participant.last_message_at) participantProfileDefinition(membershipList, "Last message", participantProfileDate(participant.last_message_at));
  if (participant.message_count !== null && Number.isFinite(participant.message_count)) participantProfileDefinition(membershipList, "Messages sent", participant.message_count);
  membership.append(membershipList);
  details.append(membership);

  if (isAgent) {
    const capabilities = participantCapabilityLabels(participant.capabilities);
    const projectInstructions = String(state.project?.instructions || state.project?.operating_instructions || "").trim();
    if (participant.role_instructions || projectInstructions) {
      const responsibilities = participantProfileSection("assets.document", "Responsibilities");
      if (participant.role_instructions) responsibilities.append(
        participantProfileInstructionBlock("Role instructions", participant.role_instructions));
      if (projectInstructions) responsibilities.append(
        participantProfileInstructionBlock("Project instructions", projectInstructions));
      details.append(responsibilities);
    }
    if (participant.provider || participant.runtime_name || capabilities.length) {
      const agentDetails = participantProfileSection("actions.settings", "Connection");
      const agentList = projectInfoElement("dl", "participant-profile-definition-list");
      if (participant.provider) participantProfileDefinition(agentList, "Provider", participantProviderLabel(participant.provider));
      if (participant.runtime_name) participantProfileDefinition(agentList, "Runtime", participant.runtime_name);
      participantProfileDefinition(agentList, "Assignment status", participantMembershipStatusLabel(status));
      agentDetails.append(agentList);
      if (capabilities.length) {
        const capabilityBlock = projectInfoElement("div", "participant-profile-capabilities");
        capabilityBlock.append(projectInfoElement("span", "participant-profile-subheading", "Capabilities"));
        const chips = projectInfoElement("div", "participant-profile-capability-list");
        capabilities.forEach((capability) => chips.append(projectInfoElement("span", "ui-badge participant-profile-capability", capability)));
        capabilityBlock.append(chips);
        agentDetails.append(capabilityBlock);
      }
      details.append(agentDetails);
    }
  } else if (participant.email || participant.authentication_source) {
    const account = participantProfileSection("people.account", "Human account");
    const accountList = projectInfoElement("dl", "participant-profile-definition-list");
    if (participant.email) participantProfileDefinition(accountList, "Email", participant.email);
    if (participant.authentication_source) participantProfileDefinition(accountList, "Sign-in method", participantAuthenticationLabel(participant.authentication_source));
    account.append(accountList);
    details.append(account);
  }

  if (can("project.admin") && (participant.id || participant.identity_id)) {
    const technical = projectInfoElement("details", "participant-profile-technical");
    const technicalSummary = projectInfoElement("summary", "participant-profile-technical-summary");
    const technicalIcon = projectInfoElement("span", "participant-profile-section-icon");
    technicalIcon.innerHTML = helperIconHtml("assets.document", 20);
    technicalSummary.append(technicalIcon, projectInfoElement("strong", "", "Technical details"), projectInfoElement("span", "participant-profile-technical-hint", "Authorized administrators only"));
    const technicalList = projectInfoElement("dl", "participant-profile-definition-list participant-profile-technical-list");
    if (participant.id) participantProfileDefinition(technicalList, "Participant ID", participant.id);
    if (participant.identity_id) participantProfileDefinition(technicalList, isAgent ? "Agent ID" : "User ID", participant.identity_id);
    if (isAgent && participant.role_version !== null) participantProfileDefinition(technicalList, "Role version", participant.role_version);
    if (state.project?.context_version !== null && state.project?.context_version !== undefined) {
      participantProfileDefinition(technicalList, "Project context version", state.project.context_version);
    }
    technical.append(technicalSummary, technicalList);
    details.append(technical);
  }

  layout.append(identity, details);
  content.append(layout);
  const actions = [];
  if (editable) actions.push({
    id: "edit-participant",
    label: `Edit ${isAgent ? "agent" : "human"}`,
    icon: helperIconHtml("actions.edit"),
    closeOnClick: false,
    async onClick({ modal: participantModal }) {
      await participantModal.close({ reason: "edit-participant" });
      if (isAgent) void openEditAgentModal(participant);
      else if (participant.id === id(state.project?.current_participant?.id)) openProfileModal();
      else openManageMemberModal(participant);
      return false;
    },
  });
  actions.push({ id: "done", label: "Done", variant: "primary", autoFocus: true });
  let profileMenu = null;
  const modal = state.factories.createActionModal({
    title: "Participant profile",
    size: "lg",
    className: "participant-profile-modal",
    content,
    actions,
    headerActions: hasProfileActions ? [{
      id: "participant-actions",
      label: "Participant actions",
      icon: MORE_ACTIONS_ICON,
      iconOnly: true,
      ariaLabel: "Participant actions",
      variant: "ghost",
      closeOnClick: false,
      onClick() { return false; },
    }] : [],
    onOpen({ headerActions }) {
      const trigger = headerActions?.querySelector('[aria-label="Participant actions"]');
      if (!trigger) return;
      const profileActions = isAgent ? [
        { id: "generate-claim-code", label: "Generate claim code", icon: CLAIM_CODE_ICON },
        { id: "remove-agent", label: "Remove from project", icon: REMOVE_ICON },
      ] : [{ id: "remove-member", label: "Remove from project", icon: REMOVE_ICON }];
      profileMenu = state.factories.createDropdown(trigger, profileActions, {
        align: "right",
        ariaLabel: "Participant actions",
        async onSelect(item) {
          if (item.id === "remove-agent") {
            setTimeout(() => confirmAgentRemoval(participant, modal), 0);
            return;
          }
          if (item.id === "remove-member") {
            setTimeout(() => confirmMemberRemoval(participant, modal), 0);
            return;
          }
          try {
            const agentId = participant.identity_id;
            if (item.id === "generate-claim-code") {
              const credential = unwrap(await request(`${API.projectAgents}?${new URLSearchParams({ project_id: selectedProjectId(), agent_id: agentId })}`)) || {};
              setTimeout(() => confirmAgentClaimGeneration(agentId, credential, null, participant.provider), 0);
            }
          } catch (error) {
            state.components.toast.warn(error.message, { title: "Participant action unavailable" });
          }
        },
      });
    },
    onClose() {
      profileMenu?.destroy?.();
      profileMenu = null;
    },
  });
  modal.open();
}

function participantMembershipStatusLabel(status) {
  if (status === "active") return "Active in project";
  if (["inactive", "suspended"].includes(status)) return "Inactive in project";
  if (status === "removed") return "Removed from project";
  return projectInfoLabel(status);
}

function participantAuthenticationLabel(source) {
  const labels = { google: "Google", pbb_account: "PBB Account", account: "PBB Account", password: "Email and password", native: "Email and password" };
  return labels[String(source || "").toLowerCase()] || projectInfoLabel(source);
}

function participantProfileDate(value) {
  const date = new Date(normalizeUtcTimestamp(value));
  return Number.isNaN(date.getTime()) ? String(value || "") : new Intl.DateTimeFormat(undefined, { dateStyle: "medium" }).format(date);
}

function participantProfileSection(icon, title) {
  const section = projectInfoElement("section", "participant-profile-section");
  const heading = projectInfoElement("header", "participant-profile-section-heading");
  const iconWrap = projectInfoElement("span", "participant-profile-section-icon");
  iconWrap.innerHTML = helperIconHtml(icon, 20);
  heading.append(iconWrap, projectInfoElement("h3", "", title));
  section.append(heading);
  return section;
}

function participantProfileInstructionBlock(label, value) {
  const text = String(value || "").trim();
  const block = projectInfoElement("details", "participant-profile-instructions");
  if (text.length <= 240) block.open = true;
  const summary = projectInfoElement("summary", "participant-profile-instructions-summary", label);
  block.append(summary, projectInfoElement("p", "participant-profile-instructions-copy", text));
  return block;
}

function participantProfileDefinition(host, label, value) {
  const row = projectInfoElement("div", "participant-profile-definition");
  const description = projectInfoElement("dd");
  if (value instanceof Node) description.append(value);
  else description.textContent = String(value ?? "");
  row.append(projectInfoElement("dt", "", label), description);
  host.append(row);
}

function renderParticipants() {
  el.participant_list.replaceChildren();
  const query = state.participantSearch.toLocaleLowerCase();
  for (const participant of state.participants.filter((entry) => !query || `${entry.display_name} ${entry.kind} ${entry.role} ${entry.provider}`.toLocaleLowerCase().includes(query))) {
    const row = document.createElement("div");
    row.className = "participant-row";
    const button = document.createElement("button");
    button.type = "button";
    button.className = "participant-card";
    button.appendChild(makeAvatar(participant));
    const copy = document.createElement("span");
    copy.className = "participant-card-copy";
    const name = document.createElement("strong");
    name.textContent = participant.display_name;
    const meta = document.createElement("span");
    meta.textContent = participant.kind === "agent"
      ? `Agent · ${participantProviderLabel(participant.provider)}`
      : `Human${participant.role ? ` · ${participant.role}` : ""}`;
    copy.append(name, meta);
    button.appendChild(copy);
    button.addEventListener("click", () => openParticipantInfoModal(participant));
    row.appendChild(button);
    el.participant_list.appendChild(row);
  }
}

function renderFilters() {
  const activeCount = [
    state.filters.primary !== "all",
    Boolean(state.filters.sender),
    Boolean(state.filters.from),
    Boolean(state.filters.to),
  ].filter(Boolean).length;
  el.clear_filters.hidden = activeCount === 0;
  el.filter_count.hidden = activeCount === 0;
  el.filter_count.textContent = String(activeCount);
  el.filter_popover_trigger.classList.toggle("is-active", activeCount > 0);
  el.filter_popover_trigger.setAttribute("aria-label", activeCount
    ? `Timeline filters, ${activeCount} active`
    : "Timeline filters");
  renderParticipants();
}

function formatDate(value) {
  const date = new Date(normalizeUtcTimestamp(value));
  return Number.isNaN(date.getTime()) ? String(value || "") : new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(date);
}

function localDateKey(value) {
  const date = new Date(normalizeUtcTimestamp(value));
  if (Number.isNaN(date.getTime())) return String(value || "").slice(0, 10);
  const year = String(date.getFullYear());
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
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

function messageAddresseesLabel(message) {
  if (isBroadcastMessage(message)) return "Project broadcast";
  const names = message.addressees.slice(0, 5).map((entry) => {
    const participant = state.participants.find((candidate) => id(candidate.id) === id(entry.participant_id));
    return `@${participant?.display_name || entry.display_name}`;
  });
  if (message.addressees.length > 5) names.push(`+${message.addressees.length - 5}`);
  const recipients = names.join("  ");
  return message.action_requested ? `Action requested from ${recipients}` : recipients;
}

function updateTimelineCollapseButton() {
  const label = state.timelineDefaultCollapsed ? "Expand all messages" : "Collapse all messages";
  el.timeline_collapse_toggle.setAttribute("aria-label", label);
  el.timeline_collapse_toggle.setAttribute("title", label);
  el.timeline_collapse_toggle.classList.toggle("is-active", state.timelineDefaultCollapsed);
  el.timeline_collapse_icon.innerHTML = state.timelineDefaultCollapsed ? EXPAND_ALL_ICON : COLLAPSE_ALL_ICON;
}

function setAllMessagesCollapsed(collapsed) {
  state.timelineDefaultCollapsed = collapsed;
  state.components.timeline?.update(undefined, { defaultCollapsed: collapsed });
  if (collapsed) state.components.timeline?.collapseAll();
  else state.components.timeline?.expandAll();
  updateTimelineCollapseButton();
}

function messageCardPreview(message) {
  if (message.deleted_at) return "This message was removed.";
  return String(message.body || "").replace(/\s+/g, " ").trim() || "Empty message";
}

function canAcknowledgeMessage(message) {
  return state.mode === "expanded"
    && message.current_participant_state?.is_addressee
    && !message.current_participant_state?.acknowledged_at
    && (can("messages.acknowledge") || message.permissions?.acknowledge);
}

function mountMessageCard(host, item) {
  let renderedMessage = null;
  let renderedContentKey = null;
  function paint(nextItem = item) {
    const current = nextItem.raw;
    if (renderedMessage === current && renderedContentKey === nextItem.contentKey) return;
    renderedMessage = current;
    renderedContentKey = nextItem.contentKey;
    host.replaceChildren();
    const details = document.createElement("div");
    details.className = "message-card-details";
    const reply = replyPreview(current);
    if (reply) {
      const preview = document.createElement("button");
      preview.type = "button";
      preview.className = "message-reply-preview";
      preview.textContent = `Replying to ${reply.sender}: ${reply.body.slice(0, 140)}`;
      preview.addEventListener("click", () => jumpToMessage(reply.id));
      details.appendChild(preview);
    }
    const body = document.createElement("p");
    body.className = "message-card-body";
    if (current.deleted_at) body.textContent = "This message was removed.";
    else appendLinkedText(body, current.body);
    details.appendChild(body);
    const footer = document.createElement("footer");
    footer.className = "message-card-footer";
    const actions = document.createElement("div");
    actions.className = "message-actions";
    if (state.mode === "expanded" && can("messages.write")) actions.appendChild(actionButton("Reply", () => setReply(current)));
    if (canAcknowledgeMessage(current)) {
      const acknowledge = actionButton("Acknowledge", async () => {
        acknowledge.disabled = true;
        try { await acknowledgeMessage(current); }
        finally { if (acknowledge.isConnected) acknowledge.disabled = false; }
      });
      actions.appendChild(acknowledge);
    }
    if (current.revision_count) {
      const revisions = document.createElement("span");
      revisions.className = "revision-label";
      revisions.textContent = `${current.revision_count} revision${current.revision_count === 1 ? "" : "s"}`;
      actions.appendChild(revisions);
    }
    const messageId = document.createElement("span");
    messageId.className = "message-card-id";
    messageId.textContent = `#${current.id}`;
    messageId.title = `Message ID ${current.id}`;
    footer.append(actions, messageId);
    details.appendChild(footer);
    host.appendChild(details);
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

function messageLinkedTasks(messageId) {
  return state.tasks.filter((task) => id(task.source_message_id) === id(messageId));
}

function messageContextMenu(message) {
  const items = [];
  const linkedTasks = messageLinkedTasks(message.id);
  if (linkedTasks.length) items.push({
    id: "linked-tasks",
    label: linkedTasks.length === 1 ? "View linked task" : `View linked tasks (${linkedTasks.length})`,
    icon: "actions.view",
  });
  if (state.mode === "expanded" && can("messages.write") && !message.deleted_at
      && (!message.action_requested || !linkedTasks.length)) items.push({
    id: "create-task", label: message.action_requested ? "Convert to task" : "Create task", icon: "actions.check",
  });
  return items.length ? { ariaLabel: `Message actions for #${message.id}`, items } : null;
}

function openLinkedMessageTasks(message) {
  const linkedTasks = messageLinkedTasks(message.id);
  if (linkedTasks.length === 1) {
    void openTaskDetails(linkedTasks[0].id);
    return;
  }
  const content = document.createElement("div");
  content.className = "linked-message-task-list";
  linkedTasks.forEach((task) => {
    const button = document.createElement("button");
    button.type = "button";
    button.className = `ui-panel linked-message-task is-${task.status}`;
    const title = document.createElement("strong"); title.textContent = task.title;
    const meta = document.createElement("span"); meta.textContent = `${taskStatusLabel(task.status)} · ${task.priority} priority`;
    button.append(title, meta);
    button.addEventListener("click", async () => {
      await modal.close({ reason: "open-task" });
      void openTaskDetails(task.id);
    });
    content.append(button);
  });
  const modal = state.factories.createActionModal({
    title: `Tasks linked to message #${message.id}`,
    size: "md",
    content,
    actions: [{ id: "close", label: "Close", variant: "primary" }],
  });
  modal.open();
}

function timelineMarkerName(sender) {
  if (sender.kind === "human") return "people.user";
  if (!state.aiIconPackAvailable) return "people.agent";
  const participant = state.participants.find((entry) => entry.id === sender.id);
  const provider = String(participant?.provider || sender.provider || "").trim().toLowerCase();
  return {
    openai: "ai.openai", chatgpt: "ai.openai", codex: "ai.openai",
    anthropic: "ai.anthropic", claude: "ai.claude",
    gemini: "ai.gemini", deepseek: "ai.deepseek", ollama: "ai.ollama",
    copilot: "ai.copilot", mistral: "ai.mistral",
  }[provider] || "ai.generic";
}

function timelineMarkerHtml(sender) {
  const name = timelineMarkerName(sender);
  if (!TIMELINE_MARKER_ICONS.has(name)) {
    TIMELINE_MARKER_ICONS.set(name, state.factories.createIcon(name, {
      size: 14, fallback: sender.kind === "human" ? "people.user" : (state.aiIconPackAvailable ? "ai.generic" : "people.agent"),
    }).outerHTML);
  }
  return TIMELINE_MARKER_ICONS.get(name);
}

function timelineItems(messages) {
  return messages.map((message) => ({
    id: message.id,
    className: `syndicatum-message${message.current_participant_state?.is_addressee ? " is-addressed" : ""}`,
    title: message.sender.display_name,
    subtitle: messageAddresseesLabel(message),
    preview: messageCardPreview(message),
    timestamp: message.created_at,
    status: message.current_participant_state?.acknowledged_at ? "completed" : (message.current_participant_state?.is_addressee ? "requested" : "accepted"),
    iconHtml: timelineMarkerHtml(message.sender),
    contextMenu: messageContextMenu(message),
    raw: message,
    contentKey: `${message.updated_at}|${message.current_participant_state?.acknowledged_at || ""}|${message.revision_count}|tasks:${messageLinkedTasks(message.id).map((task) => task.id).join(",")}`,
  }));
}

function renderTimeline(mode = "replace", changed = state.messages) {
  const items = timelineItems(changed);
  const options = {
    ariaLabel: "Project timeline",
    groupByDate: true,
    collapsible: true,
    defaultCollapsed: state.timelineDefaultCollapsed,
    enableVirtualization: true,
    virtualThreshold: 1,
    virtualOverscan: 600,
    estimateItemHeight(item, { startsGroup }) {
      const bodyLength = String(item.raw?.body || "").length;
      return (item.collapsed ? 95 : 135 + Math.min(1200, Math.ceil(bodyLength / 80) * 24)) + (startsGroup ? 40 : 0);
    },
    endThreshold: 160,
    isLoading: state.loading,
    hasMore: state.hasOlder,
    emptyText: "No messages match these filters.",
    mountItemContent: mountMessageCard,
    async onContextMenuAction(action, item) {
      const message = item.raw;
      if (action.id === "create-task") {
        setTimeout(() => openCreateTaskModal(message), 0);
        return;
      }
      if (action.id === "linked-tasks") {
        setTimeout(() => openLinkedMessageTasks(message), 0);
        return;
      }
    },
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
  const params = new URLSearchParams({ limit: "50", order });
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
    if (mode === "newer" && fresh.length) {
      fresh.forEach((message) => state.components.responsibilityInbox?.refreshFromRealtime(message.id));
      state.components.toast.info(`${fresh.length} new message${fresh.length === 1 ? "" : "s"}`, { title: "Timeline updated" });
    }
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
  if (name !== "backup-restore") { state.backupPage?.destroy(); state.backupPage = null; }
  state.surface = name;
  const workspaceVisible = ["workspace", "project"].includes(name);
  el.workspace_surface.hidden = !workspaceVisible;
  el.admin_surface.hidden = !["users", "agents", "audit", "templates", "delivery-health", "backup-restore", "guide"].includes(name);
  mountNavbar();
}

function showWorkspaceSurface({ historyMode = "push" } = {}) {
  setSurface("workspace");
  renderWorkspace();
  renderProjectHeader();
  setMobilePanel("projects");
  updateApplicationRoute("workspace", "", historyMode);
  if (state.project && !state.realtimeSocket) void connectRealtime(state.generation);
}

function renderWorkspaceActions() {
  state.components.workspaceActions?.destroy?.();
  state.components.workspaceActions = null;
  const actions = [];
  if (state.mode !== "expanded") {
    el.project_list_actions_trigger.hidden = true;
    return;
  }
  if (capability("project.create")) actions.push({ id: "new-project", label: "New Project", icon: helperIconHtml("actions.add") });
  actions.push({ id: "rename-workspace", label: "Rename workspace", icon: helperIconHtml("actions.edit") });
  el.project_list_actions_trigger.hidden = actions.length === 0;
  if (!actions.length) return;
  state.components.workspaceActions = state.factories.createDropdown(el.project_list_actions_trigger, actions, {
    align: "right",
    ariaLabel: "Project list actions",
    onSelect(item) {
      if (item.id === "new-project") openAddProjectModal();
      if (item.id === "rename-workspace") openRenameWorkspaceModal();
    },
  });
}

function renderWorkspace() {
  renderWorkspaceActions();
  const query = state.projectSearch.toLocaleLowerCase();
  const projects = state.projects.filter((project) => !query || `${project.name} ${project.description || ""} ${project.role || ""}`.toLocaleLowerCase().includes(query));
  el.workspace_project_list.replaceChildren();
  if (!projects.length) {
    const empty = document.createElement("div"); empty.className = "empty-state ui-panel";
    empty.textContent = state.projects.length ? "No projects match your search." : "No projects yet. Create one to start collaborating.";
    el.workspace_project_list.append(empty); return;
  }
  projects.forEach((project) => {
    const card = document.createElement("button");
    card.type = "button";
    card.className = `project-card ui-panel${project.id === selectedProjectId() ? " is-active" : ""}`;
    if (project.id === selectedProjectId()) card.setAttribute("aria-current", "page");
    const top = document.createElement("span"); top.className = "project-card-top";
    const title = document.createElement("strong"); title.textContent = project.name;
    top.appendChild(title);
    const stats = document.createElement("span");
    stats.className = "project-card-stats";
    [
      ["people.user", Number(project.human_count || 0), "human"],
      ["people.agent", Number(project.agent_count || 0), "agent"],
      ["comms.message", Number(project.message_count || 0), "message"],
    ].forEach(([icon, count, label]) => {
      const stat = document.createElement("span");
      stat.className = "project-card-stat";
      stat.setAttribute("aria-label", `${count} ${label}${count === 1 ? "" : "s"}`);
      stat.title = stat.getAttribute("aria-label");
      const iconHost = document.createElement("span");
      iconHost.className = "project-card-stat-icon";
      iconHost.innerHTML = helperIconHtml(icon, 16);
      const value = document.createElement("span");
      value.textContent = String(count);
      stat.append(iconHost, value);
      stats.appendChild(stat);
    });
    card.append(top, stats);
    card.addEventListener("click", () => void openWorkspaceProject(project, card));
    el.workspace_project_list.append(card);
  });
}

async function openWorkspaceProject(project, trigger) {
  if (trigger.disabled) return;
  trigger.disabled = true;
  const loadingOverlay = state.factories.createBusyOverlay({
    text: `Opening ${project.name}...`,
    visible: true,
    fullscreen: true,
    ariaLabel: `Opening ${project.name}`,
  });
  try {
    await switchProject(project.id);
  } catch (error) {
    handleLoadError(error);
  } finally {
    trigger.disabled = false;
    loadingOverlay.destroy();
  }
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
  const googleEnabled = capability("google_sso");
  const googleLinked = Boolean(user.google_linked);
  const googleActions = googleEnabled ? [{
    id: "google-link",
    label: googleLinked ? "Refresh Google profile" : "Link Google account",
    variant: "ghost",
    closeOnClick: false,
    async onClick(_values, context) {
      context.setBusy(true, { message: googleLinked ? "Refreshing Google profile..." : "Opening Google account linking..." });
      try {
        const returnPath = `${location.pathname}${location.search}`;
        const result = unwrap(await request(API.googleLink, {
          method: "POST",
          headers: csrfHeaders(),
          body: JSON.stringify({ return_path: returnPath }),
        }));
        if (!result?.authorization_url) throw new Error("Google did not return an authorization URL.");
        return redirectWithBusy(context, result.authorization_url, googleLinked ? "Refreshing Google profile..." : "Opening Google account linking...");
      } catch (error) {
        context.setBusy(false);
        context.setFormError(error.message);
      }
      return false;
    },
  }] : [];
  state.factories.createFormModal({
    title: "Edit Profile", size: googleEnabled ? "md" : "sm", submitLabel: "Save profile", initialValues: { display_name: user.display_name || "", avatar: null },
    extraActionsPlacement: "start", extraActions: googleActions,
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

function workflowField(name, label, value = "", { textarea = false, required = false, help = "", maxlength = null } = {}) {
  const field = document.createElement("div"); field.className = "project-workflow-field"; field.dataset.field = name;
  const caption = document.createElement("label"); caption.htmlFor = `project-workflow-${name}`; caption.textContent = label;
  if (required) { const marker = document.createElement("span"); marker.textContent = " Required"; caption.append(marker); }
  const control = document.createElement(textarea ? "textarea" : "input"); control.id = caption.htmlFor; control.name = name; control.value = value || "";
  control.className = textarea ? "ui-textarea" : "ui-input"; if (required) control.required = true; if (maxlength) control.maxLength = maxlength;
  const error = document.createElement("p"); error.className = "project-workflow-field-error"; error.id = `${control.id}-error`; error.hidden = true;
  field.append(caption, control);
  if (help) { const note = document.createElement("p"); note.className = "project-workflow-help"; note.textContent = help; field.append(note); }
  field.append(error); return { field, control, error };
}

function clearWorkflowErrors(root) {
  root.querySelector(".project-workflow-errors")?.remove();
  root.querySelectorAll("[aria-invalid='true']").forEach((control) => { control.removeAttribute("aria-invalid"); control.removeAttribute("aria-describedby"); });
  root.querySelectorAll(".project-workflow-field-error").forEach((error) => { error.hidden = true; error.textContent = ""; });
}

function showWorkflowErrors(root, issues) {
  clearWorkflowErrors(root);
  const unique = issues.filter((issue, index) => issues.findIndex((entry) => entry.name === issue.name) === index);
  const summary = document.createElement("div"); summary.className = "project-workflow-errors ui-alert ui-alert-danger"; summary.setAttribute("role", "alert");
  const lead = document.createElement("p"); lead.textContent = "Please address the following issues before continuing:";
  const list = document.createElement("ul"); unique.forEach((issue) => { const item = document.createElement("li"); item.textContent = issue.summary; list.append(item); });
  summary.append(lead, list); root.prepend(summary);
  unique.forEach((issue) => {
    const field = root.querySelector(`[data-field="${issue.name}"]`); const control = field?.querySelector("input, select, textarea"); const error = field?.querySelector(".project-workflow-field-error");
    if (!control || !error) return; control.setAttribute("aria-invalid", "true"); control.setAttribute("aria-describedby", error.id); error.textContent = issue.detail; error.hidden = false;
    control.addEventListener("input", () => { control.removeAttribute("aria-invalid"); control.removeAttribute("aria-describedby"); error.hidden = true; }, { once: true });
  });
  root.querySelector("[aria-invalid='true']")?.focus();
}

function mountTemplateMobileNavigation(browser, tree, detail, { initialView = "library", onChange = null } = {}) {
  const navigation = document.createElement("div"); navigation.className = "template-mobile-navigation"; navigation.setAttribute("aria-label", "Template browser view");
  const libraryButton = document.createElement("button"); libraryButton.type = "button"; libraryButton.className = "ui-button"; libraryButton.textContent = "Library";
  const previewButton = document.createElement("button"); previewButton.type = "button"; previewButton.className = "ui-button"; previewButton.textContent = "Preview";
  const buttons = { library: libraryButton, preview: previewButton };
  const setView = (view, { focus = false } = {}) => {
    const next = view === "preview" ? "preview" : "library";
    browser.dataset.mobileView = next;
    Object.entries(buttons).forEach(([key, button]) => {
      const active = key === next;
      button.classList.toggle("ui-button-primary", active);
      button.setAttribute("aria-pressed", active ? "true" : "false");
    });
    onChange?.(next);
    if (focus && matchMedia(TEMPLATE_MOBILE_QUERY).matches) {
      const pane = next === "library" ? tree : detail.querySelector(".template-main") || detail;
      pane.tabIndex = -1;
      pane.scrollTop = 0;
      pane.focus({ preventScroll: true });
    }
  };
  Object.entries(buttons).forEach(([view, button]) => button.addEventListener("click", () => setView(view, { focus: true })));
  navigation.append(libraryButton, previewButton);
  tree.tabIndex = -1;
  browser.append(navigation, tree, detail);
  setView(initialView);
  return { setView };
}

function renderTemplatePicker(workflow) {
  const browser = document.createElement("div"); browser.className = "template-browser project-template-picker";
  const tree = document.createElement("aside"); tree.className = "template-tree ui-panel"; tree.setAttribute("aria-label", "Template library");
  const heading = document.createElement("div"); heading.className = "template-tree-heading";
  const title = document.createElement("h2"); title.textContent = "Template library";
  const count = document.createElement("span"); count.textContent = String(state.templates.length); heading.append(title, count); tree.append(heading);
  const detailHost = document.createElement("div"); detailHost.className = "project-template-detail-host";
  const renderDetail = () => detailHost.replaceChildren(renderTemplateDetailPanel(templateById(workflow.selectedTemplateId), { readOnly: true }));
  let mobileNavigation = null;
  state.templateCategories.forEach((category) => {
    const templates = state.templates.filter((template) => id(template.category_id) === id(category.id)); if (!templates.length) return;
    const categoryId = id(category.id); const branch = document.createElement("details"); branch.className = "template-tree-category"; branch.open = !workflow.collapsedCategoryIds.has(categoryId);
    branch.addEventListener("toggle", () => branch.open ? workflow.collapsedCategoryIds.delete(categoryId) : workflow.collapsedCategoryIds.add(categoryId));
    const summary = document.createElement("summary"); summary.title = category.description || category.name;
    const categoryTitle = document.createElement("span"); categoryTitle.textContent = category.name; const categoryCount = document.createElement("span"); categoryCount.textContent = String(templates.length); summary.append(categoryTitle, categoryCount); branch.append(summary);
    const list = document.createElement("ul"); templates.forEach((template) => {
      const item = document.createElement("li"); const button = document.createElement("button"); button.type = "button"; button.className = "template-tree-item";
      const selected = id(template.id) === id(workflow.selectedTemplateId); button.classList.toggle("is-active", selected); button.setAttribute("aria-current", selected ? "true" : "false");
      const name = document.createElement("span"); name.textContent = template.name; button.append(name);
      button.addEventListener("click", () => { workflow.selectedTemplateId = id(template.id); tree.querySelectorAll(".template-tree-item").forEach((entry) => { const active = entry === button; entry.classList.toggle("is-active", active); entry.setAttribute("aria-current", active ? "true" : "false"); }); renderDetail(); if (matchMedia(TEMPLATE_MOBILE_QUERY).matches) mobileNavigation?.setView("preview", { focus: true }); });
      item.append(button); list.append(item);
    }); branch.append(list); tree.append(branch);
  });
  renderDetail();
  mobileNavigation = mountTemplateMobileNavigation(browser, tree, detailHost, { initialView: workflow.mobileView, onChange(view) { workflow.mobileView = view; } });
  return browser;
}

function openAddProjectModal() {
  const host = document.createElement("div"); host.className = "project-template-workflow";
  const loading = document.createElement("div"); loading.className = "project-workflow-loading"; loading.textContent = "Loading project templates…"; host.append(loading);
  const workflow = { selectedTemplateId: "", selectedTemplate: null, collapsedCategoryIds: new Set(), mobileView: "library", project: { name: "", slug: "", description: "", instructions: "" }, agents: [], createdProject: null };
  let stack = null; const abortController = new AbortController(); let dismissed = false;
  let modal = state.factories.createActionModal({ title: "Create project", size: "xl", className: "project-template-modal", content: host, actions: [{ id: "cancel", label: "Cancel" }], onClose() { dismissed = true; abortController.abort(); stack?.destroy(); } });
  modal.open(); modal.setBusy(true, { message: "Loading project templates…", cancelBusy: { label: "Cancel", onCancel: () => modal.close({ reason: "cancelled" }) } });

  const setPickerActions = () => modal.setActions([
    { id: "cancel", label: "Cancel" },
    { id: "use", label: "Next", variant: "primary", closeOnClick: false, onClick() { workflow.selectedTemplate = templateById(workflow.selectedTemplateId); if (!workflow.selectedTemplate) return false; workflow.project.description = workflow.selectedTemplate.description || ""; workflow.project.instructions = workflow.selectedTemplate.instructions || ""; workflow.agents = (workflow.selectedTemplate.agents || []).map((agent) => ({ ...agent, included: true, provider: agent.provider === "unassigned" ? "" : agent.provider })); showProjectPage(); return false; } },
  ]);

  const showPickerPage = () => {
    modal.setTitle("Create project — choose template");
    if (!workflow.selectedTemplateId || !templateById(workflow.selectedTemplateId)) workflow.selectedTemplateId = id(state.templates[0]?.id);
    const page = { id: "template", title: "Choose a project template", content: renderTemplatePicker(workflow) };
    if (!stack) stack = state.factories.createNavigationStack(host, { chrome: false, ariaLabel: "Create project", transition: "slide", initialPages: [page] }); else stack.reset([page], { animate: false });
    setPickerActions();
  };

  const projectPageContent = () => {
    const root = document.createElement("div"); root.className = "project-workflow-page";
    const heading = document.createElement("div"); heading.className = "project-workflow-heading"; const h2 = document.createElement("h2"); h2.textContent = "Configure project";
    const intro = document.createElement("p"); intro.textContent = workflow.selectedTemplate ? `Review the project context copied from “${workflow.selectedTemplate.name}”.` : "Set the project name and starting context."; heading.append(h2, intro);
    const name = workflowField("name", "Project name", workflow.project.name, { required: true, maxlength: 160 });
    const slug = workflowField("slug", "Slug (optional)", workflow.project.slug, { maxlength: 160, help: "Leave blank to generate a unique slug from the project name." });
    const description = workflowField("description", "Description", workflow.project.description, { textarea: true, maxlength: 10000 });
    const instructions = workflowField("instructions", "Project-specific operating instructions", workflow.project.instructions, { textarea: true, maxlength: 50000, help: "Optional guidance unique to this project. The shared governance baseline is applied automatically." });
    const row = document.createElement("div"); row.className = "project-workflow-row"; row.append(name.field, slug.field); root.append(heading, row, description.field, instructions.field);
    root.readValues = () => ({ name: name.control.value.trim(), slug: slug.control.value.trim(), description: description.control.value.trim(), instructions: instructions.control.value.trim() }); return root;
  };

  const showProjectPage = () => {
    const content = projectPageContent(); modal.setTitle("Create project — project details"); stack.push({ id: "project", title: "Configure project", content });
    modal.setActions([
      { id: "back", label: "Back", closeOnClick: false, onClick() { workflow.project = content.readValues(); stack.pop(); modal.setTitle("Create project — choose template"); setPickerActions(); return false; } },
      { id: "continue", label: workflow.agents.length ? "Continue" : "Create project", variant: "primary", closeOnClick: false, async onClick() { const values = content.readValues(); const issues = []; if (!values.name) issues.push({ name: "name", summary: "Project name — required", detail: "Enter a project name before continuing." }); if (values.name.length > 160) issues.push({ name: "name", summary: "Project name — use 160 characters or fewer", detail: "Shorten the project name to 160 characters or fewer." }); if (issues.length) { showWorkflowErrors(content, issues); return false; } workflow.project = values; if (workflow.agents.length) showAgentsPage(); else await createProject(content); return false; } },
    ]);
  };

  const agentPageContent = () => {
    const root = document.createElement("div"); root.className = "project-workflow-page project-agent-config";
    const heading = document.createElement("div"); heading.className = "project-workflow-heading"; const h2 = document.createElement("h2"); h2.textContent = "Configure preset agents";
    const intro = document.createElement("p"); intro.textContent = "Include only the identities you want now and choose each provider explicitly. New one-time claims are generated; no provider credential is copied."; heading.append(h2, intro); root.append(heading);
    workflow.agents.forEach((agent, index) => {
      const card = document.createElement("section"); card.className = "project-agent-config-card"; card.dataset.agentIndex = String(index);
      const includeLabel = document.createElement("label"); includeLabel.className = "project-agent-include"; const include = document.createElement("input"); include.type = "checkbox"; include.checked = agent.included !== false; const includeText = document.createElement("strong"); includeText.textContent = agent.display_name; includeLabel.append(include, includeText); card.append(includeLabel);
      const fields = document.createElement("div"); fields.className = "project-agent-fields";
      const name = workflowField(`agent-${index}-name`, "Agent display name", agent.display_name, { required: true, maxlength: 120 }); const role = workflowField(`agent-${index}-role`, "Role title", agent.role_title, { required: true, maxlength: 120 });
      const providerField = document.createElement("div"); providerField.className = "project-workflow-field"; providerField.dataset.field = `agent-${index}-provider`;
      const providerLabel = document.createElement("label"); providerLabel.htmlFor = `project-workflow-agent-${index}-provider`; providerLabel.textContent = "Provider Required";
      const provider = document.createElement("select"); provider.id = providerLabel.htmlFor; provider.className = "ui-select"; [["", "Choose provider"], ["codex", "Codex"], ["chatgpt", "ChatGPT"], ["gemini", "Gemini"]].forEach(([value, label]) => { const option = document.createElement("option"); option.value = value; option.textContent = label; option.selected = value === agent.provider; provider.append(option); });
      const providerError = document.createElement("p"); providerError.className = "project-workflow-field-error"; providerError.id = `${provider.id}-error`; providerError.hidden = true; providerField.append(providerLabel, provider, providerError);
      const summary = workflowField(`agent-${index}-summary`, "Role summary", agent.role_summary, { textarea: true, maxlength: 4000 }); const instructions = workflowField(`agent-${index}-instructions`, "Role instructions", agent.role_instructions, { textarea: true, maxlength: 20000 });
      fields.append(name.field, role.field, providerField, summary.field, instructions.field); card.append(fields); include.addEventListener("change", () => { fields.hidden = !include.checked; }); fields.hidden = !include.checked; root.append(card);
      agent.readValues = () => ({ template_agent_id: agent.id, included: include.checked, display_name: name.control.value.trim(), role_title: role.control.value.trim(), provider: provider.value, role_summary: summary.control.value.trim(), role_instructions: instructions.control.value.trim() });
    }); return root;
  };

  const setProjectActions = (content) => modal.setActions([
    { id: "back", label: "Back", closeOnClick: false, onClick() { workflow.project = content.readValues(); stack.pop(); modal.setTitle("Create project — choose template"); setPickerActions(); return false; } },
    { id: "continue", label: "Continue", variant: "primary", closeOnClick: false, onClick() { const values = content.readValues(); if (!values.name) { showWorkflowErrors(content, [{ name: "name", summary: "Project name — required", detail: "Enter a project name before continuing." }]); return false; } workflow.project = values; showAgentsPage(); return false; } },
  ]);

  const showAgentsPage = () => {
    const content = agentPageContent(); modal.setTitle("Create project — preset agents"); stack.push({ id: "agents", title: "Configure preset agents", content });
    modal.setActions([
      { id: "back", label: "Back", closeOnClick: false, onClick() { workflow.agents = workflow.agents.map((agent) => ({ ...agent, ...agent.readValues?.() })); stack.pop(); modal.setTitle("Create project — project details"); const projectContent = stack.getState().currentPage?.content; if (projectContent) setProjectActions(projectContent); return false; } },
      { id: "create", label: "Create project", variant: "primary", closeOnClick: false, async onClick() { const values = workflow.agents.map((agent) => agent.readValues()); const included = values.filter((agent) => agent.included); const issues = []; values.forEach((agent, index) => { if (!agent.included) return; if (!agent.display_name) issues.push({ name: `agent-${index}-name`, summary: `${workflow.agents[index].display_name} — display name required`, detail: "Enter a display name for this included agent." }); if (!agent.role_title) issues.push({ name: `agent-${index}-role`, summary: `${agent.display_name || workflow.agents[index].display_name} — role title required`, detail: "Enter a role title for this included agent." }); if (!agent.provider) issues.push({ name: `agent-${index}-provider`, summary: `${agent.display_name || workflow.agents[index].display_name} — choose a provider`, detail: "Choose Codex, ChatGPT, or Gemini, or exclude this preset." }); }); if (issues.length) { showWorkflowErrors(content, issues); return false; } workflow.agents = workflow.agents.map((agent, index) => ({ ...agent, ...values[index] })); await createProject(content, included); return false; } },
    ]);
  };

  const createProject = async (content, includedAgents = []) => {
    clearWorkflowErrors(content); modal.setBusy(true, { message: "Creating project…" });
    try {
      const body = { ...workflow.project }; if (workflow.selectedTemplate) Object.assign(body, { template_id: workflow.selectedTemplate.id, template_version: workflow.selectedTemplate.version, template_agents: includedAgents.map(({ included, readValues, ...agent }) => agent) });
      const result = unwrap(await request(API.manageProjects, { method: "POST", headers: csrfHeaders(), body: JSON.stringify(body) })); if (dismissed) return;
      const project = result.project || result; const claims = Array.isArray(project.agent_claims) ? project.agent_claims : []; delete project.agent_claims; project.id = id(project.id || project.project_id); project.collection = "My"; project.human_count = Number(project.human_count ?? 1); project.agent_count = Number(project.agent_count ?? includedAgents.length); project.message_count = Number(project.message_count ?? 0); workflow.createdProject = project; state.projects.unshift(project);
      const success = document.createElement("div"); success.className = "project-workflow-success"; const h2 = document.createElement("h2"); h2.textContent = `${project.name} was created`; const note = document.createElement("p"); note.textContent = claims.length ? "Save these one-time claims now. They expire in 15 minutes and are not shown again." : "The project is ready to use."; success.append(h2, note);
      if (claims.length) { const list = document.createElement("div"); list.className = "project-claim-list"; claims.forEach((claim) => { const card = document.createElement("div"); const name = document.createElement("strong"); name.textContent = claim.display_name; const code = document.createElement("code"); code.textContent = claim.claim_code; card.append(name, code); list.append(card); }); success.append(list); }
      stack.push({ id: "complete", title: "Project created", content: success }); modal.setTitle("Project created"); modal.setActions([{ id: "close", label: "Close" }, { id: "open", label: "Open project", variant: "primary", closeOnClick: false, async onClick() { await modal.close({ reason: "open-project" }); void switchProject(project.id); return false; } }]); state.components.toast.success("Project created.");
    } catch (error) { if (dismissed || error.name === "AbortError") return; modal.setBusy(false); const requestError = document.createElement("div"); requestError.className = "project-workflow-errors ui-alert ui-alert-danger"; requestError.setAttribute("role", "alert"); requestError.textContent = error.message; content.prepend(requestError); state.components.toast.error(error.message); return; }
    modal.setBusy(false);
  };

  request(API.projectTemplateLibrary, { signal: abortController.signal }).then((payload) => { if (dismissed) return; const data = unwrap(payload); state.templateCategories = data.categories || []; state.templates = data.templates || []; host.replaceChildren(); modal.setBusy(false); showPickerPage(); }).catch((error) => { if (dismissed || error.name === "AbortError") return; modal.setBusy(false); const failure = document.createElement("div"); failure.className = "project-workflow-load-error"; failure.textContent = `Unable to load project templates. ${error.message}`; host.replaceChildren(failure); modal.setActions([{ id: "cancel", label: "Close" }, { id: "retry", label: "Retry", variant: "primary", closeOnClick: false, onClick() { modal.close({ reason: "retry" }); openAddProjectModal(); return false; } }]); });
}

function templateById(templateId) {
  return state.templates.find((template) => id(template.id) === id(templateId));
}

function replaceTemplate(template) {
  state.templates = state.templates.some((entry) => id(entry.id) === id(template.id))
    ? state.templates.map((entry) => id(entry.id) === id(template.id) ? template : entry)
    : [...state.templates, template];
  state.templates.sort((left, right) => String(left.name).localeCompare(String(right.name)));
  state.selectedTemplateId = id(template.id);
  renderTemplatesSurface();
}

function openTemplateModal(template = null) {
  const editing = Boolean(template);
  const categoryOptions = state.templateCategories.map((category) => ({ value: String(category.id), label: category.name }));
  state.factories.createFormModal({
    title: editing ? "Edit project template" : "Create project template",
    size: "lg", submitLabel: editing ? "Save template" : "Create template",
    initialValues: { category_id: template?.category_id ? String(template.category_id) : "", name: template?.name || "", description: template?.description || "", instructions: template?.instructions || "" },
    rows: [
      [modalTextField("name", "Template name", { required: true, maxlength: 160 }), { type: "select", name: "category_id", label: "Category", required: true, options: [{ value: "", label: "Select category" }, ...categoryOptions] }],
      [{ type: "textarea", name: "description", label: "Project description", maxlength: 10000, help: "Reusable purpose and scope for projects created from this template." }],
      [{ type: "textarea", name: "instructions", label: "Operating instructions", maxlength: 50000, help: "Reusable authority, workflow, safety, and reporting guidance." }],
    ],
    async onSubmit(values, context) {
      try {
        const body = editing ? { template_id: template.id, version: template.version, ...values } : values;
        const result = unwrap(await request(API.projectTemplates, { method: editing ? "PATCH" : "POST", headers: csrfHeaders(), body: JSON.stringify(body) }));
        replaceTemplate(result.template || result);
        state.components.toast.success(editing ? "Template updated." : "Template created. Add agent presets when ready.");
        return true;
      } catch (error) { context.setFormError(error.message); return false; }
    },
  }).open();
}

function templateSupervisorOptions(template, agent = null) {
  return [{ value: "", label: "No supervising agent preset" }, ...(template.agents || [])
    .filter((entry) => id(entry.id) !== id(agent?.id))
    .map((entry) => ({ value: String(entry.id), label: `${entry.display_name} — ${entry.role_title}` }))];
}

function openTemplateAgentModal(templateId, agent = null) {
  const template = templateById(templateId);
  if (!template) return;
  const editing = Boolean(agent);
  state.factories.createFormModal({
    title: editing ? "Edit agent preset" : "Add agent preset",
    size: "lg", submitLabel: editing ? "Save agent preset" : "Add agent preset",
    initialValues: {
      display_name: agent?.display_name || "", role_title: agent?.role_title || "", role_summary: agent?.role_summary || "",
      role_instructions: agent?.role_instructions || "", provider: agent?.provider || "unassigned",
      supervising_agent_id: agent?.supervising_agent_id ? String(agent.supervising_agent_id) : "",
    },
    rows: [
      [modalTextField("display_name", "Agent display name", { required: true, maxlength: 120 }), modalTextField("role_title", "Role title", { required: true, maxlength: 160 })],
      [{ type: "textarea", name: "role_summary", label: "Role summary", maxlength: 10000 }, { type: "textarea", name: "role_instructions", label: "Role instructions", maxlength: 50000, help: "Project-scoped responsibilities, boundaries, and escalation guidance." }],
      [{ type: "select", name: "provider", label: "Provider", required: true, options: [
        { value: "unassigned", label: "Choose during project setup" }, { value: "codex", label: "Codex" }, { value: "chatgpt", label: "ChatGPT" }, { value: "gemini", label: "Gemini" },
      ] }],
      [{ type: "select", name: "supervising_agent_id", label: "Reports to", options: templateSupervisorOptions(template, agent) }],
    ],
    async onSubmit(values, context) {
      try {
        const body = { template_id: template.id, version: template.version, ...(editing ? { agent_id: agent.id } : {}), ...values };
        const result = unwrap(await request(API.projectTemplates, { method: editing ? "PATCH" : "POST", headers: csrfHeaders(), body: JSON.stringify(body) }));
        replaceTemplate(result.template || result);
        state.components.toast.success(editing ? "Agent preset updated." : "Agent preset added.");
        return true;
      } catch (error) { context.setFormError(error.message); return false; }
    },
  }).open();
}

function confirmTemplateAction({ title, message, confirmLabel, onConfirm }) {
  const body = document.createElement("p"); body.className = "ui-dialog-message"; body.textContent = message;
  let modal;
  modal = state.factories.createActionModal({
    title, size: "sm", className: "ui-dialog ui-dialog--warning", content: body,
    actions: [
      { id: "cancel", label: "Cancel" },
      { id: "confirm", label: confirmLabel, variant: "danger", closeOnClick: false, async onClick() {
        try { modal.setBusy(true, { message: `${confirmLabel}…` }); await onConfirm(); await modal.close({ reason: "confirmed" }); }
        catch (error) { modal.setBusy(false); state.components.toast.error(error.message); }
        return false;
      } },
    ],
  });
  modal.open();
}

function archiveTemplate(template) {
  confirmTemplateAction({
    title: "Archive project template?", confirmLabel: "Archive template",
    message: `Archive “${template.name}”? Existing projects are unchanged, and this template will no longer be offered for new projects.`,
    async onConfirm() {
      await request(API.projectTemplates, { method: "DELETE", headers: csrfHeaders(), body: JSON.stringify({ template_id: template.id, version: template.version }) });
      state.templates = state.templates.filter((entry) => id(entry.id) !== id(template.id));
      if (id(state.selectedTemplateId) === id(template.id)) state.selectedTemplateId = id(state.templates[0]?.id);
      renderTemplatesSurface();
      state.components.toast.success("Template archived.");
    },
  });
}

function deleteTemplateAgent(template, agent) {
  confirmTemplateAction({
    title: "Remove agent preset?", confirmLabel: "Remove agent preset",
    message: `Remove ${agent.display_name} from “${template.name}”? Other presets that reported to this agent will become unsupervised.`,
    async onConfirm() {
      const result = unwrap(await request(API.projectTemplates, { method: "DELETE", headers: csrfHeaders(), body: JSON.stringify({ template_id: template.id, agent_id: agent.id, version: template.version }) }));
      replaceTemplate(result.template || result); state.components.toast.success("Agent preset removed.");
    },
  });
}

function templateActionButton(label, onClick, variant = "") {
  const button = document.createElement("button"); button.type = "button"; button.className = `ui-button ${variant}`.trim(); button.textContent = label; button.addEventListener("click", onClick); return button;
}

function templateProviderLabel(provider) {
  return provider === "unassigned" ? "Provider selected during setup" : ({ codex: "Codex", chatgpt: "ChatGPT", gemini: "Gemini" })[provider] || provider;
}

function renderTemplateDetailPanel(template, options = {}) {
  const panel = document.createElement("main"); panel.className = "template-main ui-panel";
  if (!template) {
    const empty = document.createElement("div"); empty.className = "template-detail-empty";
    const title = document.createElement("h2"); title.textContent = "Select a template";
    const copy = document.createElement("p"); copy.textContent = "Choose a template from the library to view its project context and preset team.";
    empty.append(title, copy); panel.append(empty); return panel;
  }

  const header = document.createElement("header"); header.className = "template-detail-heading";
  const headingCopy = document.createElement("div");
  const context = document.createElement("div"); context.className = "template-detail-context";
  const category = document.createElement("span"); category.textContent = template.category?.name || "General"; context.append(category);
  if (template.origin === "system") { const badge = document.createElement("span"); badge.className = "ui-badge template-origin-badge"; badge.textContent = "Built-in"; context.append(badge); }
  const title = document.createElement("h2"); title.textContent = template.name;
  const meta = document.createElement("p"); meta.textContent = `${template.agents?.length || 0} agent preset${template.agents?.length === 1 ? "" : "s"} · Updated ${formatDate(template.updated_at)}`;
  headingCopy.append(context, title, meta);
  const actions = document.createElement("div"); actions.className = "template-actions template-detail-actions";
  if (!options.readOnly && template.origin !== "system") actions.append(
    templateActionButton("Edit template", () => openTemplateModal(template)),
    templateActionButton("Add agent preset", () => openTemplateAgentModal(template.id), "ui-button-primary"),
    templateActionButton("Archive", () => archiveTemplate(template), "ui-button-danger"),
  );
  header.append(headingCopy, actions); panel.append(header);

  const detailBody = document.createElement("div"); detailBody.className = "template-detail-body";
  const contextStack = document.createElement("div"); contextStack.className = "template-context-stack";
  [["Project description", template.description], ["Operating instructions", template.instructions]].forEach(([label, copy]) => {
    const section = document.createElement("section"); section.className = "template-detail-section";
    const heading = document.createElement("h3"); heading.textContent = label;
    const body = document.createElement("p"); body.textContent = copy || "Not provided.";
    section.append(heading, body); contextStack.append(section);
  });

  const agentsSection = document.createElement("section"); agentsSection.className = "template-presets-section";
  const agentsHeading = document.createElement("div"); agentsHeading.className = "template-section-heading";
  const agentsTitle = document.createElement("h3"); agentsTitle.textContent = "Agent presets";
  const agentsCount = document.createElement("span"); agentsCount.textContent = String(template.agents?.length || 0);
  agentsHeading.append(agentsTitle, agentsCount); agentsSection.append(agentsHeading);
  if (!template.agents?.length) {
    const empty = document.createElement("p"); empty.className = "template-presets-empty"; empty.textContent = "No agent presets. Projects created from this template begin without a preset agent team."; agentsSection.append(empty);
  } else {
    const list = document.createElement("div"); list.className = "template-detail-agents";
    template.agents.forEach((agent) => {
      const card = document.createElement("article"); card.className = "template-agent-card";
      const cardHeading = document.createElement("div"); cardHeading.className = "template-agent-card-heading";
      const identity = document.createElement("div");
      const name = document.createElement("strong"); name.textContent = agent.display_name;
      const role = document.createElement("span"); role.textContent = `${agent.role_title} · ${templateProviderLabel(agent.provider)}${agent.supervising_agent_name ? ` · Reports to ${agent.supervising_agent_name}` : ""}`;
      identity.append(name, role); cardHeading.append(identity);
      if (!options.readOnly && template.origin !== "system") {
        const agentActions = document.createElement("div"); agentActions.className = "template-actions";
        agentActions.append(templateActionButton("Edit", () => openTemplateAgentModal(template.id, agent)), templateActionButton("Remove", () => deleteTemplateAgent(template, agent), "ui-button-danger"));
        cardHeading.append(agentActions);
      }
      const summary = document.createElement("p"); summary.textContent = agent.role_summary || "No role summary provided.";
      const instructions = document.createElement("div"); instructions.className = "template-agent-instructions";
      const instructionsLabel = document.createElement("strong"); instructionsLabel.textContent = "Role instructions";
      const instructionsCopy = document.createElement("p"); instructionsCopy.textContent = agent.role_instructions || "No role instructions provided.";
      instructions.append(instructionsLabel, instructionsCopy); card.append(cardHeading, summary, instructions); list.append(card);
    });
    agentsSection.append(list);
  }
  detailBody.append(contextStack, agentsSection); panel.append(detailBody); return panel;
}

function renderTemplatesSurface(error = "") {
  el.admin_list.replaceChildren();
  const toolbar = document.createElement("div"); toolbar.className = "template-toolbar";
  const intro = document.createElement("p"); intro.textContent = "Reusable project context and preset agent teams for consistent onboarding.";
  toolbar.append(intro, templateActionButton("New template", () => openTemplateModal(), "ui-button-primary"));
  el.admin_list.append(toolbar);
  if (error) { const failure = document.createElement("p"); failure.className = "empty-state ui-panel"; failure.textContent = error; el.admin_list.append(failure); return; }
  if (!state.templates.length) { const empty = document.createElement("p"); empty.className = "empty-state ui-panel"; empty.textContent = "No project templates have been created yet."; el.admin_list.append(empty); return; }
  if (!templateById(state.selectedTemplateId)) state.selectedTemplateId = id(state.templates[0]?.id);
  const selectedTemplate = templateById(state.selectedTemplateId);
  const browser = document.createElement("div"); browser.className = "template-browser";
  const tree = document.createElement("aside"); tree.className = "template-tree ui-panel"; tree.setAttribute("aria-label", "Template library");
  const detailHost = document.createElement("div"); detailHost.className = "template-detail-host";
  const treeHeading = document.createElement("div"); treeHeading.className = "template-tree-heading";
  const treeTitle = document.createElement("h2"); treeTitle.textContent = "Template library";
  const treeCount = document.createElement("span"); treeCount.textContent = String(state.templates.length);
  treeHeading.append(treeTitle, treeCount); tree.append(treeHeading);
  let mobileNavigation = null;
  state.templateCategories.forEach((category) => {
    const templates = state.templates.filter((template) => id(template.category_id) === id(category.id));
    if (!templates.length) return;
    const categoryId = id(category.id);
    const branch = document.createElement("details"); branch.className = "template-tree-category"; branch.open = !state.collapsedTemplateCategoryIds.has(categoryId);
    branch.addEventListener("toggle", () => {
      if (branch.open) state.collapsedTemplateCategoryIds.delete(categoryId);
      else state.collapsedTemplateCategoryIds.add(categoryId);
    });
    const summary = document.createElement("summary"); summary.title = category.description || category.name;
    const categoryTitle = document.createElement("span"); categoryTitle.textContent = category.name;
    const categoryCount = document.createElement("span"); categoryCount.textContent = String(templates.length);
    summary.append(categoryTitle, categoryCount); branch.append(summary);
    const list = document.createElement("ul");
    templates.forEach((template) => {
      const item = document.createElement("li");
      const button = document.createElement("button"); button.type = "button"; button.className = "template-tree-item";
      const selected = id(template.id) === id(state.selectedTemplateId); button.classList.toggle("is-active", selected); button.setAttribute("aria-current", selected ? "true" : "false");
      const name = document.createElement("span"); name.textContent = template.name; button.append(name);
      button.addEventListener("click", () => {
        state.selectedTemplateId = id(template.id);
        tree.querySelectorAll(".template-tree-item").forEach((item) => {
          const active = item === button;
          item.classList.toggle("is-active", active);
          item.setAttribute("aria-current", active ? "true" : "false");
        });
        detailHost.replaceChildren(renderTemplateDetailPanel(template));
        if (matchMedia(TEMPLATE_MOBILE_QUERY).matches) mobileNavigation?.setView("preview", { focus: true });
      });
      item.append(button); list.append(item);
    });
    branch.append(list); tree.append(branch);
  });
  const detail = renderTemplateDetailPanel(selectedTemplate);
  detailHost.append(detail);
  mobileNavigation = mountTemplateMobileNavigation(browser, tree, detailHost, { initialView: state.templateMobileView, onChange(view) { state.templateMobileView = view; } });
  el.admin_list.append(browser);
}

async function loadTemplatesSurface() {
  el.admin_list.replaceChildren();
  const loading = document.createElement("p"); loading.className = "empty-state ui-panel"; loading.textContent = "Loading project templates…"; el.admin_list.append(loading);
  try {
    const payload = unwrap(await request(API.projectTemplates));
    state.templateCategories = payload.categories || [];
    state.templates = payload.templates || [];
    renderTemplatesSurface();
  } catch (error) { state.templateCategories = []; state.templates = []; renderTemplatesSurface(`Unable to load project templates. ${error.message}`); }
}

function openEditProjectModal() {
  const project = state.project || {};
  state.factories.createFormModal({ title: "Edit Project", submitLabel: "Save project", initialValues: { name: project.name || "", description: project.description || "", instructions: project.instructions || "" }, rows: [
    [modalTextField("name", "Project name", { required: true })], [{ type: "textarea", name: "description", label: "Description" }], [{ type: "textarea", name: "instructions", label: "Project-specific operating instructions", help: "Optional guidance unique to this project. The shared governance baseline is applied automatically." }],
  ], async onSubmit(values, context) {
    try { const result = unwrap(await request(API.manageProjects, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ project_id: selectedProjectId(), ...values }) })); state.project = { ...state.project, ...(result.project || result) }; state.projects = state.projects.map((entry) => entry.id === selectedProjectId() ? { ...entry, ...state.project } : entry); renderWorkspace(); renderProjectHeader(); state.components.toast.success("Project updated."); return true; }
    catch (error) { context.setFormError(error.message); return false; }
  }}).open();
}

function openProjectInfoModal() {
  const project = state.project || {};
  const editable = state.mode === "expanded" && (can("project.manage") || can("project.admin"));
  const humans = state.participants.filter((participant) => participant.kind === "human").length;
  const agents = state.participants.filter((participant) => participant.kind === "agent").length;
  const messageCount = project.message_count !== null && project.message_count !== undefined && Number.isFinite(Number(project.message_count))
    ? Number(project.message_count)
    : null;
  const status = String(project.status || "active").toLowerCase();
  const owner = state.participants.find((participant) => participant.kind === "human" && participant.role === "owner");
  const content = projectInfoElement("div", "project-info-content");

  const hero = projectInfoElement("header", "project-info-hero");
  const titleRow = projectInfoElement("div", "project-info-title-row");
  titleRow.append(projectInfoElement("h2", "project-info-title", project.name || "Untitled project"));
  const statusBadge = projectInfoElement("span", `ui-badge project-info-status is-${status}`);
  statusBadge.append(projectInfoElement("span", "project-info-status-dot"), document.createTextNode(projectInfoLabel(status)));
  titleRow.append(statusBadge);
  hero.append(titleRow, projectInfoElement("p", "project-info-lead", "Key information and details about this project."));
  content.append(hero);

  const summary = projectInfoElement("section", "project-info-summary");
  [
    ["people.user", "Humans", humans, "Team members", "humans"],
    ["people.agent", "Agents", agents, "AI agents", "agents"],
    ["comms.message", "Messages", messageCount === null ? "—" : messageCount, messageCount === null ? "Count unavailable" : "Total messages", "messages"],
  ].forEach(([icon, label, value, hint, kind]) => {
    const card = projectInfoElement("article", `project-info-stat is-${kind}`);
    const iconWrap = projectInfoElement("span", "project-info-stat-icon");
    iconWrap.innerHTML = helperIconHtml(icon, 30);
    const copy = projectInfoElement("span", "project-info-stat-copy");
    copy.append(projectInfoElement("span", "project-info-stat-label", label), projectInfoElement("strong", "project-info-stat-value", value), projectInfoElement("span", "project-info-stat-hint", hint));
    card.append(iconWrap, copy);
    summary.append(card);
  });
  content.append(summary);

  const detailGrid = projectInfoElement("section", "project-info-detail-grid");
  const about = projectInfoElement("article", "project-info-card project-info-about");
  about.append(projectInfoSectionHeading("assets.document", "About", "Project description, ownership, and purpose."));
  const description = String(project.description || "").trim();
  const descriptionBox = projectInfoElement("div", `project-info-description${description ? " has-description" : " is-empty"}`);
  if (description) {
    descriptionBox.append(projectInfoElement("p", "project-info-description-copy", description));
  } else {
    const emptyIcon = projectInfoElement("span", "project-info-empty-icon");
    emptyIcon.innerHTML = helperIconHtml("assets.document", 26);
    descriptionBox.append(emptyIcon, projectInfoElement("strong", "", "No description yet"), projectInfoElement("p", "", "Add a description to help your team understand this project."));
    if (editable) {
      const addDescription = projectInfoElement("button", "project-info-inline-action", "Add a description");
      addDescription.type = "button";
      addDescription.addEventListener("click", async () => {
        await modal.close({ reason: "add-description" });
        openEditProjectModal();
      });
      descriptionBox.append(addDescription);
    }
  }
  about.append(descriptionBox);
  const ownerBlock = projectInfoElement("div", "project-info-owner");
  ownerBlock.append(projectInfoElement("span", "project-info-owner-label", "Project owner"));
  const ownerRow = projectInfoElement("div", "project-info-owner-row");
  const ownerIdentity = owner || { kind: "human", display_name: project.owner_display_name || "Unavailable", color_seed: hashColor(project.owner_display_name || "owner"), avatar_url: null };
  ownerRow.append(makeAvatar(ownerIdentity));
  const ownerCopy = projectInfoElement("span", "project-info-owner-copy");
  ownerCopy.append(projectInfoElement("strong", "", ownerIdentity.display_name), projectInfoElement("span", "", "Project owner"));
  ownerRow.append(ownerCopy);
  ownerBlock.append(ownerRow);
  about.append(ownerBlock);

  const details = projectInfoElement("article", "project-info-card project-info-metadata");
  details.append(projectInfoSectionHeading("actions.settings", "Project Details", "Access and project history."));
  const metadata = projectInfoElement("dl", "project-info-metadata-list");
  projectInfoMetadataRow(metadata, "Your role", projectInfoLabel(project.role || project.current_participant?.role || "Unavailable"));
  projectInfoMetadataRow(metadata, "Created", projectInfoDate(project.created_at));
  projectInfoMetadataRow(metadata, "Last updated", projectInfoDate(project.updated_at));
  details.append(metadata);
  const operating = projectInfoElement("article", "project-info-card project-info-operating");
  operating.append(projectInfoSectionHeading("assets.document", "Operating instructions", "Shared governance and optional project-specific guidance."));
  const governance = state.project?.governance;
  if (governance?.instructions) {
    const baseline = projectInfoElement("details", "participant-profile-instructions");
    const baselineSummary = projectInfoElement("summary", "participant-profile-instructions-summary", `Governance baseline · version ${governance.version}`);
    baseline.append(baselineSummary, projectInfoElement("p", "participant-profile-instructions-copy", governance.instructions));
    operating.append(baseline);
  }
  const instructions = String(project.instructions || project.operating_instructions || "").trim();
  const instructionsBox = projectInfoElement("div", `project-info-instructions${instructions ? " has-instructions" : " is-empty"}`);
  if (instructions) {
    instructionsBox.append(projectInfoElement("strong", "", "Project-specific instructions"),
      projectInfoElement("p", "project-info-instructions-copy", instructions));
  } else {
    instructionsBox.append(projectInfoElement("strong", "", "No project-specific instructions"),
      projectInfoElement("p", "", "The shared governance baseline still applies. Add only guidance unique to this project."));
    if (editable) {
      const addInstructions = projectInfoElement("button", "project-info-inline-action", "Add project-specific instructions");
      addInstructions.type = "button";
      addInstructions.addEventListener("click", async () => {
        await modal.close({ reason: "add-operating-instructions" });
        openEditProjectModal();
      });
      instructionsBox.append(addInstructions);
    }
  }
  operating.append(instructionsBox);
  detailGrid.append(about, details, operating);
  content.append(detailGrid);

  const actions = [];
  if (editable) actions.push({
    id: "edit",
    label: "Edit project",
    closeOnClick: false,
    async onClick({ modal: infoModal }) {
      await infoModal.close({ reason: "edit-project" });
      openEditProjectModal();
      return false;
    },
  });
  actions.push({ id: "done", label: "Done", variant: "primary", autoFocus: true });
  const modal = state.factories.createActionModal({
    title: "Project Info",
    size: "xl",
    className: "project-info-modal",
    content,
    actions,
  });
  modal.open();
}

function projectInfoElement(tagName, className = "", text = null) {
  const node = document.createElement(tagName);
  if (className) node.className = className;
  if (text !== null) node.textContent = String(text);
  return node;
}

function projectInfoLabel(value) {
  const label = String(value || "Unavailable").replace(/[_-]+/g, " ");
  return label.charAt(0).toUpperCase() + label.slice(1);
}

function projectInfoSectionHeading(icon, title, subtitle) {
  const heading = projectInfoElement("header", "project-info-section-heading");
  const iconWrap = projectInfoElement("span", "project-info-section-icon");
  iconWrap.innerHTML = helperIconHtml(icon, 22);
  const copy = projectInfoElement("span", "project-info-section-copy");
  copy.append(projectInfoElement("strong", "", title), projectInfoElement("span", "", subtitle));
  heading.append(iconWrap, copy);
  return heading;
}

function projectInfoDate(value) {
  if (!value) return { primary: "Unavailable", secondary: "" };
  const date = new Date(normalizeUtcTimestamp(value));
  if (Number.isNaN(date.getTime())) return { primary: String(value), secondary: "" };
  const primary = new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(date);
  const secondary = Intl.DateTimeFormat().resolvedOptions().timeZone || "Local time";
  return { primary, secondary };
}

function projectInfoMetadataRow(host, label, value) {
  const row = projectInfoElement("div", "project-info-metadata-row");
  row.append(projectInfoElement("dt", "", label));
  const definition = projectInfoElement("dd");
  if (value && typeof value === "object") {
    definition.append(projectInfoElement("span", "project-info-metadata-primary", value.primary));
    if (value.secondary) definition.append(projectInfoElement("span", "project-info-metadata-secondary", value.secondary));
  } else {
    definition.textContent = String(value || "Unavailable");
  }
  row.append(definition);
  host.append(row);
}

function showCredentialResult(title, label, value, expiresAt) {
  state.factories.createFormModal({ title, submitLabel: "Done", context: { badge: "Shown once", summary: "Copy this value now and share it through a trusted channel." }, rows: [
    [{ type: "text", content: `${label}: ${value}` }], [{ type: "text", content: expiresAt ? `Expires ${formatDate(expiresAt)}` : "" }],
  ], async onSubmit() { return true; } }).open();
}

function showAgentCredentialResult(result) {
  const rows = [];
  let claimCode = "";
  let agentInstruction = "";
  if (result.claim_code) {
    const projectName = result.project_name || state.project?.name || "";
    const agent = state.participants.find((entry) => entry.kind === "agent" && id(entry.agent_id || entry.id) === id(result.agent_id));
    const identity = result.display_name || agent?.display_name || "";
    const provider = String(result.provider || agent?.provider || "codex").toLowerCase();
    claimCode = String(result.claim_code);
    if (provider === "codex") {
      agentInstruction = `In Codex Desktop, use the installed local Syndicatum plugin tool claim_agent_profile to claim the “${identity}” identity in “${projectName}” at ${window.location.origin} with this one-time claim code: ${claimCode}. Do not use the ChatGPT OAuth-connected Syndicatum app for this claim.`;
    } else if (provider === "chatgpt") {
      agentInstruction = "This ChatGPT agent uses Syndicatum OAuth for project identity and the browser companion for delivery. Do not enter this claim code in ChatGPT or the Companion; browser delivery does not use it. Keep it only for a separate direct API integration that explicitly supports Syndicatum agent claiming.";
    } else if (provider === "gemini") {
      agentInstruction = "This Gemini agent uses the browser companion for delivery. Do not enter this claim code in Gemini or the Companion; browser delivery does not use it. Keep it only for a separate Syndicatum integration that explicitly supports agent-profile claiming. Gemini still needs that integration to load and respond to the authoritative project timeline.";
    } else {
      agentInstruction = `Use this one-time claim code only with a Syndicatum integration that explicitly supports agent-profile claiming for the “${identity}” identity in “${projectName}”: ${claimCode}.`;
    }
    rows.push([{ type: "text", content: `Project: ${projectName}` }]);
    rows.push([{ type: "text", content: `Project ID: ${result.project_id || selectedProjectId()}` }]);
    rows.push([{ type: "text", content: `Identity: ${identity}` }]);
    rows.push([{ type: "text", content: `Agent ID: ${result.agent_id}` }]);
    rows.push([{ type: "text", className: "agent-credential-copy-row claim-code-copy-row", content: `Claim code: ${claimCode}` }]);
    rows.push([{ type: "text", className: "agent-credential-copy-row agent-message-copy-row", content: `${provider === "codex" ? "Ask the agent" : "Provider setup"}: ${agentInstruction}` }]);
  }
  const webhookSecret = result.webhook_signing_secret || result.signing_secret;
  if (webhookSecret) rows.push([{ type: "text", content: `Webhook signing secret: ${webhookSecret}` }]);
  if (result.claim_expires_at) rows.push([{ type: "text", content: `Claim expires ${formatDate(result.claim_expires_at)}` }]);
  if (!rows.length) return;
  const modal = state.factories.createFormModal({ title: "Agent credentials", size: "lg", submitLabel: "Done", context: { badge: "Shown once", summary: "Copy this handoff now. The claim code expires after 15 minutes and will not be shown again." }, rows, async onSubmit() { return true; } });
  if (claimCode) {
    mountCredentialCopyAction(modal, ".claim-code-copy-row", claimCode, "Copy claim code", "Claim code copied.");
    mountCredentialCopyAction(modal, ".agent-message-copy-row", agentInstruction, "Copy agent message", "Agent message copied.");
  }
  modal.open();
}

function mountCredentialCopyAction(modal, selector, value, label, successMessage) {
  const row = modal.refs.rows.querySelector(selector);
  if (!row) return;
  const text = document.createElement("span");
  text.className = "agent-credential-copy-text";
  text.textContent = row.textContent;
  const button = document.createElement("button");
  button.type = "button";
  button.className = "ui-button ui-button-borderless agent-credential-copy-action";
  button.setAttribute("aria-label", label);
  button.title = label;
  button.innerHTML = helperIconHtml("actions.copy", 18);
  button.addEventListener("click", async () => {
    button.disabled = true;
    modal.clearFormError();
    try {
      if (!navigator.clipboard?.writeText) throw new Error("Clipboard access is unavailable.");
      await navigator.clipboard.writeText(value);
      state.components.toast.success(successMessage);
    } catch (_error) {
      modal.setFormError("Copy failed. Select the text and copy it manually.");
    } finally {
      button.disabled = false;
    }
  });
  row.replaceChildren(text, button);
}

function agentCredentialStatusText(credential = {}, provider = "") {
  const expires = credential.claim_expires_at ? formatDate(credential.claim_expires_at) : "";
  if (credential.claim_status === "pending") {
    if (isBrowserCompanionProvider(provider) && !credential.has_active_token) {
      return `Optional direct API credential unclaimed · claim code expires ${expires}`;
    }
    return credential.has_active_token
      ? `Connected · replacement claim code expires ${expires}`
      : `Awaiting claim · claim code expires ${expires}`;
  }
  if (credential.claim_status === "expired") {
    return credential.has_active_token
      ? `Connected · replacement claim code expired ${expires}`
      : `Unclaimed · claim code expired ${expires}`;
  }
  return credential.has_active_token
    ? `Connected${credential.claimed_at ? ` · claimed ${formatDate(credential.claimed_at)}` : ""}`
    : "Unclaimed · no active claim code";
}

function confirmAgentClaimGeneration(agentId, credential, onGenerated = null, provider = "codex") {
  const replacement = Boolean(credential.has_active_token);
  const confirmation = state.factories.createFormModal({
    title: replacement ? "Generate replacement claim code?" : "Generate new claim code?",
    size: "sm",
    submitLabel: replacement ? "Generate replacement" : "Generate claim code",
    submitVariant: "danger",
    context: { badge: "Security action", summary: replacement ? "The current agent token remains valid until the replacement code is claimed." : "Any previously issued claim code will stop working immediately." },
    rows: [[{ type: "text", content: "The new claim code expires after 15 minutes and will be shown only once." }]],
    async onSubmit(_values, context) {
      try {
        const result = unwrap(await request(API.projectAgents, {
          method: "PATCH",
          headers: csrfHeaders(),
          body: JSON.stringify({ project_id: selectedProjectId(), agent_id: agentId, rotate: true }),
        })) || {};
        credential.claim_status = "pending";
        credential.claim_expires_at = result.claim_expires_at || null;
        onGenerated?.(credential);
        setTimeout(() => showAgentCredentialResult({ ...result, provider }), 0);
        state.components.toast.success("A new agent claim code was generated.");
        return true;
      } catch (error) {
        context.setFormError(error.message);
        return false;
      }
    },
  });
  confirmation.open();
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

async function refreshActiveParticipants(removedParticipantId = "") {
  if (removedParticipantId && state.filters.sender === id(removedParticipantId)) {
    state.filters.sender = "";
  }
  const participants = unwrap(await request(`${API.participants}?${new URLSearchParams({ project_id: selectedProjectId(), status: "active" })}`));
  state.participants = (participants || []).map((entry) => participantFrom(entry, entry.kind));
  rebuildParticipantControls();
  if (removedParticipantId) await reloadForFilters();
}

function confirmMemberRemoval(participant, managerModal) {
  const confirmation = state.factories.createFormModal({
    title: `Remove ${participant.display_name}?`,
    size: "sm",
    submitLabel: "Remove from project",
    submitVariant: "danger",
    context: { badge: "Access removal", summary: "Their existing timeline messages will remain visible." },
    rows: [[{ type: "text", content: "They will immediately lose access to this project and stop receiving project notifications." }]],
    async onSubmit(_values, context) {
      try {
        await request(API.projectMembers, {
          method: "DELETE",
          headers: csrfHeaders(),
          body: JSON.stringify({ project_id: selectedProjectId(), user_id: participant.identity_id }),
        });
        await refreshActiveParticipants(participant.id);
        managerModal?.destroy?.();
        state.components.toast.success(`${participant.display_name} was removed from the project.`);
        return true;
      } catch (error) {
        context.setFormError(error.message);
        return false;
      }
    },
  });
  confirmation.open();
}

function openManageMemberModal(participant) {
  const modal = state.factories.createFormModal({
    title: `Manage ${participant.display_name}`,
    size: "sm",
    submitLabel: "Save member",
    initialValues: { role: participant.role || "member" },
    rows: [[{ type: "select", name: "role", label: "Project role", required: true, options: [
      { value: "member", label: "Member" },
      { value: "viewer", label: "Viewer" },
      { value: "admin", label: "Administrator" },
    ] }]],
    async onSubmit(values, context) {
      try {
        await request(API.projectMembers, {
          method: "PATCH",
          headers: csrfHeaders(),
          body: JSON.stringify({ project_id: selectedProjectId(), user_id: participant.identity_id, role: values.role }),
        });
        await refreshActiveParticipants();
        state.components.toast.success("Project member updated.");
        return true;
      } catch (error) {
        context.setFormError(error.message);
        return false;
      }
    },
  });
  modal.open();
}

function confirmAgentRemoval(agent, editModal) {
  const confirmation = state.factories.createFormModal({
    title: `Remove ${agent.display_name}?`,
    size: "sm",
    submitLabel: "Remove agent",
    submitVariant: "danger",
    context: { badge: "Permanent access removal", summary: "Existing timeline messages will remain visible." },
    rows: [[{ type: "text", content: "The agent will stop receiving notifications. Its tokens, pending claim codes, browser routes, activation, and webhook will be revoked or disabled." }]],
    async onSubmit(_values, context) {
      try {
        await request(API.projectAgents, {
          method: "DELETE",
          headers: csrfHeaders(),
          body: JSON.stringify({ project_id: selectedProjectId(), agent_id: agent.identity_id }),
        });
        await refreshActiveParticipants(agent.id);
        editModal?.destroy?.();
        state.components.toast.success(`${agent.display_name} was removed from the project.`);
        return true;
      } catch (error) {
        context.setFormError(error.message);
        return false;
      }
    },
  });
  confirmation.open();
}

async function loadDiscussionProviders() {
  const providers = unwrap(await request(API.discussionProviders));
  if (!Array.isArray(providers) || !providers.length) throw new Error("No discussion providers are currently available.");
  return providers;
}

function normalizeAgentProviderValues(values, providers) {
  const provider = providers.find(item => item.code === values.provider);
  if (provider && !provider.proactive_activation) {
    values.activation_enabled = false;
    values.discussion_reference = "";
    values.working_directory = "";
  }
  return values;
}

function isBrowserCompanionProvider(provider) {
  return ["chatgpt", "gemini"].includes(String(provider || ""));
}

function agentDiscussionReference(values) {
  if (values.provider === "chatgpt") return values.chatgpt_discussion_reference;
  if (values.provider === "gemini") return values.gemini_discussion_reference;
  return values.discussion_reference;
}

function requireBrowserDiscussionReference(values, reference) {
  if (!isBrowserCompanionProvider(values.provider) || String(reference || "").trim()) return;
  throw new Error(`A ${values.provider === "gemini" ? "Gemini" : "ChatGPT"} discussion URL is required.`);
}

function supervisingParticipantOptions(agent = null) {
  const excludedId = id(agent?.id);
  const options = [{ value: "", label: "No supervisor" }];
  state.participants
    .filter((participant) => participant.status === "active" && participant.id !== excludedId)
    .forEach((participant) => options.push({ value: String(participant.id), label: `${participant.display_name} (${participant.kind})` }));
  const current = agent?.supervisor;
  if (current && !options.some((option) => option.value === String(current.participant_id))) {
    options.push({ value: String(current.participant_id), label: `${current.display_name || "Unavailable participant"} (inactive)` });
  }
  return options;
}

async function openAddAgentModal() {
  let providers = [];
  let provider = null;
  const modal = state.factories.createFormModal({
    title: "Add Agent",
    size: "lg",
    submitLabel: "Create agent",
    rows: [[{ type: "text", content: "Loading agent configuration..." }]],
    async onSubmit(_values, context) {
      context.setFormError("Agent configuration is not available yet.");
      return false;
    },
  });
  modal.open();
  modal.setBusy(true, { message: "Loading agent configuration..." });
  try {
    providers = await loadDiscussionProviders();
    provider = providers[0];
    const initialValues = { avatar: null, provider: provider.code, supervising_participant_id: "", activation_enabled: false, responses_model: "gpt-5.6-terra" };
    modal.update({ initialValues, rows: [
    [{ type: "avatar", name: "avatar", label: "Agent avatar", accept: "image/jpeg,image/png,image/webp", help: "JPEG, PNG, or WebP; up to 2 MB." }],
    [modalTextField("display_name", "Agent display name", { required: true }), modalTextField("role_title", "Role title", { required: true, placeholder: "Commercial Assessor" })],
    [{ type: "textarea", name: "role_summary", label: "Role summary", required: true }, { type: "textarea", name: "role_instructions", label: "Role instructions", help: "Project-scoped responsibilities, boundaries, and escalation guidance." }],
    [{ type: "select", name: "supervising_participant_id", label: "Reports to", options: supervisingParticipantOptions() }],
    [{ type: "divider" }], [{ type: "text", content: "Provider connection" }],
    [{ type: "select", name: "provider", label: "Provider", required: true, options: providers.map(item => ({ value: item.code, label: item.display_name })) }],
    [{ type: "checkbox", name: "activation_enabled", label: "Enable proactive agent activation" }],
    [modalTextField("discussion_reference", "Codex discussion deeplink", { placeholder: "codex://threads/01abc...", help: "In Codex, use Copy deeplink.", visibleWhen: { provider: "codex" } })],
    [modalTextField("chatgpt_discussion_reference", "ChatGPT discussion URL", { input: "url", placeholder: "https://chatgpt.com/c/...", help: "Required. The browser companion delivers notifications to this existing discussion.", visibleWhen: { provider: "chatgpt" } })],
    [modalTextField("gemini_discussion_reference", "Gemini discussion URL", { input: "url", placeholder: "https://gemini.google.com/app/...", help: "Required. The browser companion delivers notifications to this existing discussion.", visibleWhen: { provider: "gemini" } })],
    [modalTextField("working_directory", provider.working_directory_label, { placeholder: "C:\\path\\to\\project", help: provider.working_directory_help, visibleWhen: { provider: "codex" } })],
    [{ type: "text", content: "ChatGPT activation uses the Syndicatum browser companion. Responses API and Workspace Agent activation remain disabled.", visibleWhen: { provider: "chatgpt" } }],
    [{ type: "text", content: "Gemini activation uses the Syndicatum browser companion. The Gemini discussion must have access to the Syndicatum integration to handle the notification.", visibleWhen: { provider: "gemini" } }],
    ], async onSubmit(values, context) {
    try { normalizeAgentProviderValues(values, providers); const reference = agentDiscussionReference(values); if (values.activation_enabled && !String(reference || "").trim()) throw new Error("An activation reference is required when proactive activation is enabled."); requireBrowserDiscussionReference(values, reference); const avatarUrl = values.avatar instanceof File ? await uploadAvatar(values.avatar, { kind: "agent", projectId: selectedProjectId() }) : ""; const body = { ...values, discussion_reference: reference, avatar_url: avatarUrl || null, project_id: selectedProjectId() }; delete body.avatar; delete body.chatgpt_discussion_reference; delete body.gemini_discussion_reference; const result = unwrap(await request(API.projectAgents, { method: "POST", headers: csrfHeaders(), body: JSON.stringify(body) })); if (values.activation_enabled || isBrowserCompanionProvider(values.provider)) await request(API.projectAgentActivation, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ project_id: selectedProjectId(), agent_id: result.agent_id, enabled: Boolean(values.activation_enabled), provider: values.provider, activation_driver: isBrowserCompanionProvider(values.provider) ? "browser_companion" : "connector", discussion_reference: reference, working_directory: values.working_directory }) }); setTimeout(() => showAgentCredentialResult({ ...result, provider: values.provider }), 0); const participants = unwrap(await request(`${API.participants}?${new URLSearchParams({ project_id: selectedProjectId(), status: "active" })}`)); state.participants = (participants || []).map((entry) => participantFrom(entry, entry.kind)); rebuildParticipantControls(); return true; }
    catch (error) { context.setFormError(error.message); return false; }
    }});
    modal.setValues(initialValues);
    modal.setBusy(false);
  } catch (error) {
    modal.setBusy(false);
    modal.setFormError(`Unable to load agent configuration. ${error.message}`);
  }
}

async function openEditAgentModal(agent) {
  const agentId = agent.identity_id;
  let activation = agent.activation || {};
  let credential = {};
  let providers = [];
  let provider = null;
  const editModal = state.factories.createFormModal({
    title: `Edit ${agent.display_name}`,
    size: "lg",
    submitLabel: "Save agent",
    rows: [[{ type: "text", content: `Loading ${agent.display_name}...` }]],
    async onSubmit(_values, context) {
      context.setFormError("Agent configuration is not available yet.");
      return false;
    },
  });
  editModal.open();
  editModal.setBusy(true, { message: `Loading ${agent.display_name}...` });
  try {
  try { activation = unwrap(await request(`${API.projectAgentActivation}?${new URLSearchParams({ project_id: selectedProjectId(), agent_id: agentId })}`)) || {}; }
  catch (error) { if (error.status !== 404) throw new Error(`Activation settings unavailable. ${error.message}`); }
  try { credential = unwrap(await request(`${API.projectAgents}?${new URLSearchParams({ project_id: selectedProjectId(), agent_id: agentId })}`)) || {}; }
  catch (error) { throw new Error(`Credential status unavailable. ${error.message}`); }
  try { providers = await loadDiscussionProviders(); }
  catch (error) { throw new Error(`Discussion providers unavailable. ${error.message}`); }
  provider = providers.find(item => item.code === activation.provider) || providers[0];
  const initialValues = {
    display_name: agent.display_name, avatar: null, role_title: agent.role_title || "",
    role_summary: agent.role_summary || "", role_instructions: agent.role_instructions || "",
    supervising_participant_id: agent.supervisor ? String(agent.supervisor.participant_id) : "",
    provider: provider.code, activation_enabled: Boolean(activation.enabled), discussion_reference: provider.code === "codex" ? (activation.discussion_reference || "") : "", chatgpt_discussion_reference: provider.code === "chatgpt" ? (activation.discussion_reference || "") : "", gemini_discussion_reference: provider.code === "gemini" ? (activation.discussion_reference || "") : "", working_directory: activation.working_directory || "",
    responses_api_key: "", responses_model: activation.responses_model || "gpt-5.6-terra",
  };
  editModal.update({ initialValues, rows: [
    [{ type: "avatar", name: "avatar", label: "Agent avatar", accept: "image/jpeg,image/png,image/webp", previewUrl: agent.avatar_url || "", help: "JPEG, PNG, or WebP; up to 2 MB." }],
    [modalTextField("display_name", "Agent display name", { required: true }), modalTextField("role_title", "Role title", { required: true })],
    [{ type: "textarea", name: "role_summary", label: "Role summary", required: true }, { type: "textarea", name: "role_instructions", label: "Role instructions", help: "Project-scoped responsibilities, boundaries, and escalation guidance." }],
    [{ type: "select", name: "supervising_participant_id", label: "Reports to", options: supervisingParticipantOptions(agent) }],
    [{ type: "divider" }], [{ type: "text", content: "Agent credentials" }],
    [{ type: "text", className: "agent-credential-status", content: agentCredentialStatusText(credential, provider.code) }],
    [{ type: "text", content: "ChatGPT uses MCP/OAuth for its project identity and device authorization for browser delivery. Claiming the separate direct API credential is optional.", visibleWhen: { provider: "chatgpt" } }],
    [{ type: "divider" }], [{ type: "text", content: "Provider connection" }],
    [{ type: "select", name: "provider", label: "Provider", required: true, options: providers.map(item => ({ value: item.code, label: item.display_name })) }],
    [{ type: "checkbox", name: "activation_enabled", label: "Enable proactive agent activation" }],
    [modalTextField("discussion_reference", "Codex discussion deeplink", { placeholder: "codex://threads/01abc...", help: "In Codex, use Copy deeplink.", visibleWhen: { provider: "codex" } })],
    [modalTextField("chatgpt_discussion_reference", "ChatGPT discussion URL", { input: "url", placeholder: "https://chatgpt.com/c/...", help: "Required. The browser companion delivers notifications to this existing discussion.", visibleWhen: { provider: "chatgpt" } })],
    [modalTextField("gemini_discussion_reference", "Gemini discussion URL", { input: "url", placeholder: "https://gemini.google.com/app/...", help: "Required. The browser companion delivers notifications to this existing discussion.", visibleWhen: { provider: "gemini" } })],
    [modalTextField("working_directory", provider.working_directory_label, { placeholder: "C:\\path\\to\\project", help: provider.working_directory_help, visibleWhen: { provider: "codex" } })],
    [{ type: "text", content: "ChatGPT activation uses the Syndicatum browser companion. Responses API and Workspace Agent activation remain disabled.", visibleWhen: { provider: "chatgpt" } }],
    [{ type: "text", content: "Gemini activation uses the Syndicatum browser companion. The Gemini discussion must have access to the Syndicatum integration to handle the notification.", visibleWhen: { provider: "gemini" } }],
  ], async onSubmit(values, context) {
    try {
      normalizeAgentProviderValues(values, providers);
      const reference = agentDiscussionReference(values);
      if (values.activation_enabled && !String(reference || "").trim()) throw new Error("An activation reference is required when proactive activation is enabled.");
      requireBrowserDiscussionReference(values, reference);
      const avatarUrl = values.avatar instanceof File ? await uploadAvatar(values.avatar, { kind: "agent", projectId: selectedProjectId(), agentId }) : agent.avatar_url;
      await request(API.projectAgents, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({
        project_id: selectedProjectId(), agent_id: agentId, display_name: values.display_name,
        role_title: values.role_title, role_summary: values.role_summary,
        role_instructions: values.role_instructions,
        supervising_participant_id: values.supervising_participant_id || null,
        provider: values.provider, avatar_url: avatarUrl || null,
      }) });
      await request(API.projectAgentActivation, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ project_id: selectedProjectId(), agent_id: agentId, enabled: Boolean(values.activation_enabled), provider: values.provider, activation_driver: isBrowserCompanionProvider(values.provider) ? "browser_companion" : "connector", discussion_reference: reference, working_directory: values.working_directory }) });
      const participants = unwrap(await request(`${API.participants}?${new URLSearchParams({ project_id: selectedProjectId(), status: "active" })}`)); state.participants = (participants || []).map((entry) => participantFrom(entry, entry.kind)); rebuildParticipantControls(); state.components.toast.success("Agent updated."); return true;
    }
    catch (error) { context.setFormError(error.message); return false; }
  }});
  editModal.setValues(initialValues);
  editModal.setBusy(false);
  } catch (error) {
    editModal.setBusy(false);
    editModal.setFormError(`Unable to load agent configuration. ${error.message}`);
  }
}

function adminRows(payload, kind) {
  const source = unwrap(payload);
  const rows = Array.isArray(source) ? source : (source?.[kind] || source?.events || []);
  return Array.isArray(rows) ? rows : [];
}

function recoveryOperationPanel(titleText, descriptionText, statusText, details = [], action = null) {
  const panel = document.createElement("section");
  panel.className = "backup-restore-operation ui-panel";
  const heading = document.createElement("div");
  heading.className = "backup-restore-operation-heading";
  const copy = document.createElement("div");
  const title = document.createElement("h2");
  title.textContent = titleText;
  const description = document.createElement("p");
  description.textContent = descriptionText;
  copy.append(title, description);
  const stateLabel = document.createElement("span");
  stateLabel.className = `ui-badge ${statusText === "Ready" || statusText === "Verified" ? "backup-restore-preview-status" : "backup-restore-unavailable"}`;
  stateLabel.textContent = statusText;
  heading.append(copy, stateLabel);
  panel.appendChild(heading);
  if (details.length) {
    const list = document.createElement("dl");
    list.className = "backup-restore-details";
    details.forEach(([label, value = "Unavailable"]) => {
      const term = document.createElement("dt"); term.textContent = label;
      const detail = document.createElement("dd"); detail.textContent = value;
      list.append(term, detail);
    });
    panel.appendChild(list);
  }
  if (action) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "ui-button ui-button-primary";
    button.disabled = Boolean(action.disabled);
    button.textContent = action.label;
    button.addEventListener("click", action.onClick);
    panel.appendChild(button);
  }
  return panel;
}

function recoveryDownload(token) {
  const link = document.createElement("a");
  link.href = `${API.artifactDownload}?${new URLSearchParams({ ticket: token })}`;
  link.hidden = true;
  document.body.appendChild(link);
  link.click();
  setTimeout(() => link.remove(), 1000);
}

async function idempotentRecoveryPost(url, body, idempotencyKey) {
  const send = () => request(url, {
    method: "POST",
    headers: csrfHeaders({ "Idempotency-Key": idempotencyKey }),
    body: JSON.stringify(body),
  });
  let operation;
  try { operation = unwrap(await send()); }
  catch (error) {
    const receipt = unwrap(error.payload);
    if (receipt?.operation_id) throw new Error(receipt.error_message || "The recovery operation did not complete.");
    if (error.status) throw error;
    state.components.toast.warn("The outcome is unknown. Checking the same operation without creating a duplicate…", { title: "Connection interrupted" });
    operation = unwrap(await send());
  }
  if (operation?.status !== "started") return operation;
  const statusUrl = `${url}?${new URLSearchParams({ operation_id: operation.operation_id })}`;
  for (let attempt = 0; attempt < 20; attempt += 1) {
    await new Promise((resolve) => setTimeout(resolve, 1500));
    try {
      operation = unwrap(await request(statusUrl));
      if (operation?.status !== "started") return operation;
    } catch (error) {
      if (error.status) throw error;
    }
  }
  return {
    ...operation,
    status: "uncertain",
    error_message: "The operation is still running or its terminal receipt was interrupted. Do not submit a different request. Retry this same confirmation or have an administrator inspect the staging target.",
  };
}

async function reauthorizeBackupDownload(operationId) {
  const result = unwrap(await request(API.backupDownloads, {
    method: "POST", headers: csrfHeaders(), body: JSON.stringify({ operation_id: operationId }),
  }));
  recoveryDownload(result.download.token);
  state.components.toast.success("A new one-time backup download was authorized.");
}

function showRecoveryReceipt(titleText, receipt, download = null, operation = null) {
  const content = document.createElement("div");
  content.className = "backup-restore-receipt";
  const summary = document.createElement("p");
  summary.textContent = receipt?.cutover_performed === false
    ? "Verified. No live overwrite or automatic cutover was performed."
    : "Verified operation receipt.";
  const inspectorHost = document.createElement("div");
  content.append(summary, inspectorHost);
  const inspector = state.factories.createDataInspector(inspectorHost, receipt, { ariaLabel: `${titleText} details` });
  const actions = [{ id: "close", label: "Close", autoFocus: !download }];
  if (download?.token) actions.unshift({
    id: "download", label: "Download encrypted backup", variant: "primary", autoFocus: true,
    onClick() { recoveryDownload(download.token); },
  });
  if (operation?.kind === "backup" && operation?.operation_id) actions.unshift({
    id: "reauthorize", label: "Authorize another download", variant: "secondary",
    async onClick() {
      try { await reauthorizeBackupDownload(operation.operation_id); }
      catch (error) { state.components.toast.error(error.message, { title: "Download authorization failed" }); }
    },
  });
  const modal = state.factories.createActionModal({ title: titleText, size: "lg", content, actions, onClose() { inspector.destroy?.(); } });
  modal.open();
}


function openBuildBackup() {
  const idempotencyKey = makeIdempotencyKey();
  let pendingReceipt = null;
  const modal = state.factories.createFormModal({
    title: "Build encrypted backup", submitLabel: "Build encrypted backup", size: "lg",
    initialValues: { understand: false },
    rows: [
      [{ type: "text", content: "This creates a non-executable, authenticated encrypted backup in private storage. The destination is server-selected and an existing artifact is never replaced." }],
      [{ type: "text", content: "Sessions, ephemeral OAuth state, delivery queues, and service credentials are reset or reissued during restore. No restore or cutover occurs while building a backup." }],
      [{ type: "checkbox", name: "understand", label: "I understand the backup and credential-reset boundaries.", required: true }],
    ],
    async onSubmit(values, context) {
      try {
        if (!values.understand) throw new Error("Confirm the backup boundaries before continuing.");
        const operation = await idempotentRecoveryPost(API.backups, {}, idempotencyKey);
        if (operation.status !== "succeeded") throw new Error(operation.error_message || "Encrypted backup creation failed.");
        pendingReceipt = operation;
        state.components.toast.success("Encrypted backup created and verified.");
        return true;
      } catch (error) { context.setFormError(error.message); return false; }
    },
    onClose() {
      if (pendingReceipt) showRecoveryReceipt("Encrypted backup created", pendingReceipt.result, pendingReceipt.result.download, pendingReceipt);
    },
  });
  modal.open();
}

function uploadBackupInspection(item, controls) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", API.restoreInspections);
    xhr.responseType = "json";
    const csrf = state.session?.csrf_token || state.session?.csrfToken || "";
    if (csrf) xhr.setRequestHeader("X-CSRF-Token", csrf);
    xhr.upload.addEventListener("progress", (event) => {
      if (event.lengthComputable) controls.report(Math.min(85, (event.loaded / event.total) * 85));
    });
    xhr.addEventListener("load", () => {
      const payload = xhr.response;
      if (xhr.status >= 200 && xhr.status < 300) { controls.report(100); resolve(unwrap(payload)); return; }
      reject(new Error(payload?.message || `Inspection failed with status ${xhr.status}`));
    });
    xhr.addEventListener("error", () => reject(new Error("Upload outcome is unknown. Retry inspection; inspection never mutates a database.")));
    xhr.addEventListener("abort", () => reject(new Error("Upload cancelled before restore staging began.")));
    controls.signal.addEventListener("abort", () => xhr.abort(), { once: true });
    const form = new FormData();
    form.append("backup", item.file, item.name);
    xhr.send(form);
  });
}

function openStageRestoreConfirmation(inspection) {
  const metadata = inspection.metadata;
  const idempotencyKey = makeIdempotencyKey();
  const durableRows = Object.values(metadata.durable_row_counts || {}).reduce((sum, value) => sum + Number(value || 0), 0);
  let pendingReceipt = null;
  const modal = state.factories.createFormModal({
    title: "Stage verified restore", submitLabel: "Stage verified restore", size: "lg",
    className: "recovery-stage-restore-modal",
    initialFocus: '[name="confirmation"]',
    initialValues: { confirmation: "", no_cutover: false, reset_ack: false },
    rows: [
      [{ type: "text", content: "The encrypted package authenticated successfully and matches the trusted baseline. The server will revalidate it immediately before staging." }],
      [{ type: "display", name: "digest", label: "Envelope SHA-256", value: metadata.envelope_sha256 }],
      [{ type: "display", name: "contents", label: "Durable contents", value: `${durableRows} rows across ${metadata.backup_policy?.durable?.count || 0} durable tables` }],
      [{ type: "display", name: "policy", label: "Reset / target-local policy", value: `${metadata.backup_policy?.reset?.count || 0} reset tables · ${metadata.backup_policy?.excluded?.count || 0} target-local tables` }],
      [{ type: "text", content: "Sessions, ephemeral OAuth tokens/codes, delivery queues, webhook deliveries, and service credentials will not be carried into the staged target. Credentials marked for reissue must be replaced." }],
      [{ type: "checkbox", name: "no_cutover", label: "I understand this writes only to the separate empty staging target and never overwrites the live database or cuts traffic over.", required: true }],
      [{ type: "checkbox", name: "reset_ack", label: "I understand reset/reissue data is intentionally omitted and must be re-established after an approved cutover.", required: true }],
      [modalTextField("confirmation", "Type STAGE RESTORE to continue", {
        required: true,
        autocomplete: "off",
        pattern: "STAGE RESTORE",
        help: "Enter exactly STAGE RESTORE (uppercase, with one space).",
      })],
    ],
    async onSubmit(values, context) {
      try {
        if (!values.no_cutover || !values.reset_ack) throw new Error("Confirm both staged-restore boundaries.");
        if (String(values.confirmation || "") !== "STAGE RESTORE") throw new Error("Type STAGE RESTORE exactly.");
        const operation = await idempotentRecoveryPost(API.stagedRestores, {
          inspection_id: inspection.inspection_id,
          envelope_sha256: metadata.envelope_sha256,
          confirmation: values.confirmation,
        }, idempotencyKey);
        if (operation.status !== "succeeded") throw new Error(operation.error_message || "Staged restore failed. Reprovision the staging target before retrying.");
        pendingReceipt = operation;
        state.components.toast.success("Restore staged. No live overwrite or cutover occurred.");
        return true;
      } catch (error) { context.setFormError(error.message); return false; }
    },
    onClose() {
      if (pendingReceipt) showRecoveryReceipt("Restore staged", pendingReceipt.result);
    },
  });
  modal.open();
}

function openRestoreUploader() {
  const content = document.createElement("div");
  content.className = "backup-restore-uploader";
  const guidance = document.createElement("p");
  guidance.textContent = "Choose one encrypted .syndicatum-backup file. Inspection authenticates and validates it without changing either database.";
  const mount = document.createElement("div");
  content.append(guidance, mount);
  let inspection = null;
  let modal = null;
  let uploader = null;
  let contextGeneration = 1;
  let contextActive = true;
  let pendingTransition = null;
  const invalidateInspectionContext = () => {
    contextActive = false;
    contextGeneration += 1;
    inspection = null;
    pendingTransition = null;
    uploader?.destroy();
  };
  uploader = state.factories.createFileUploader(mount, {
    ariaLabel: "Encrypted backup inspection",
    dropzoneAriaLabel: "Choose encrypted Syndicatum backup",
    accept: ".syndicatum-backup",
    allowedTypes: [".syndicatum-backup"],
    multiple: false,
    maxFiles: 1,
    maxFileSize: 256 * 1024 * 1024,
    startText: "Authenticate and inspect",
    dropText: "Drop one encrypted backup here or choose Browse.",
    async onUpload(item, controls) { inspection = await uploadBackupInspection(item, controls); },
    onComplete(stateValue) {
      if (!contextActive || pendingTransition || !inspection || !stateValue.items.some((entry) => entry.status === "success")) return;
      pendingTransition = { inspection, generation: contextGeneration };
      modal.close({ reason: "inspected" }).then((closed) => {
        if (!closed && pendingTransition?.generation === contextGeneration) pendingTransition = null;
      });
    },
  });
  modal = state.factories.createActionModal({
    title: "Inspect encrypted backup", size: "lg", content,
    actions: [{ id: "close", label: "Close" }],
    onBeforeClose(meta) {
      if (meta?.reason !== "inspected") invalidateInspectionContext();
      return true;
    },
    onClose(meta) {
      const transition = meta?.reason === "inspected" ? pendingTransition : null;
      const shouldOpenConfirmation = Boolean(transition && contextActive && transition.generation === contextGeneration);
      const completedInspection = shouldOpenConfirmation ? transition.inspection : null;
      invalidateInspectionContext();
      if (completedInspection) openStageRestoreConfirmation(completedInspection);
    },
  });
  modal.open();
}

function recoveryProgressSteps(labels) {
  const list = document.createElement("ol");
  list.className = "recovery-progress";
  list.setAttribute("aria-label", "Operation progress");
  const items = [];
  const controls = [];
  labels.forEach((label) => {
    const item = document.createElement("li");
    item.dataset.state = "pending";
    const barHost = document.createElement("div");
    barHost.className = "recovery-progress-bar";
    const bar = state.factories.createProgress(barHost, { value: 0, label }, {
      style: "striped", size: "sm", rounded: true,
      showLabel: true, showPercent: true, ariaLabel: `${label} progress`,
    });
    item.appendChild(barHost);
    list.appendChild(item);
    items.push(item);
    controls.push(bar);
  });
  return { list, items, controls, labels, destroy() { controls.forEach((control) => control.destroy()); } };
}

function previewRecoveryProgress(steps, status, onOverallProgress, onComplete) {
  const { items, controls, labels } = steps;
  let index = 0;
  let percent = 0;
  const showStage = () => {
    items[index].dataset.state = "in-progress";
    status.textContent = `Preview only — no backup is running. Stage ${index + 1} of ${items.length}: ${labels[index]}.`;
  };
  showStage();
  const timer = setInterval(() => {
    percent = Math.min(percent + 10, 100);
    controls[index].setValue(percent);
    onOverallProgress(Math.round((index * 100 + percent) / items.length));
    if (percent < 100) return;
    controls[index].update({}, { style: "gradient", animate: false });
    items[index].dataset.state = "complete";
    index += 1;
    if (index === items.length) {
      clearInterval(timer);
      status.textContent = "Preview complete. Recovery service is unavailable; no backup was created.";
      onComplete();
      return;
    }
    percent = 0;
    showStage();
  }, 90);
  return () => clearInterval(timer);
}

function recoverySize(bytes) {
  if (bytes === null || bytes === undefined || bytes === "") return "Unavailable";
  const value = Number(bytes);
  if (!Number.isFinite(value) || value < 0) return "Unavailable";
  if (value < 1024) return value + " B";
  const unit = value < 1048576 ? "KB" : value < 1073741824 ? "MB" : "GB";
  const divisor = unit === "KB" ? 1024 : unit === "MB" ? 1048576 : 1073741824;
  return (value / divisor).toFixed(1) + " " + unit;
}

function backupOverallProgress(backup) {
  const raw = backup.overall_progress_percent ?? backup.overall_progress ?? backup.progress_percent;
  if (raw === null || raw === undefined || raw === "") return null;
  const value = Number(raw);
  return Number.isFinite(value) && value >= 0 && value <= 100 ? Math.round(value) : null;
}

function backupSizeOrProgress(backup) {
  const status = String(backup.status || "").toLowerCase();
  if (status !== "running" && !(backup.preview === true && ["preview complete", "preview cancelled"].includes(status))) {
    return recoverySize(backup.size_bytes);
  }
  const progress = backupOverallProgress(backup);
  return progress === null ? "" : `${progress}%`;
}

function backupInitiator(backup) {
  const candidates = [
    backup.initiated_by_name,
    backup.initiated_by_display_name,
    backup.initiated_by?.display_name,
    backup.created_by_display_name,
    backup.created_by?.display_name,
    backup.created_by_name,
    backup.initiated_by,
  ];
  return candidates.find((value) => typeof value === "string" && value.trim())?.trim() || "Unavailable";
}

function backupIncludesData(backup) {
  const value = backup.includes_data ?? backup.include_existing_data ?? null;
  if (value === true || value === 1 || value === "1") return true;
  if (value === false || value === 0 || value === "0") return false;
  return null;
}

function backupInitiatedAt(backup) {
  return backup.initiated_at || backup.started_at || backup.created_at || null;
}

function backupFinishedAt(backup) {
  return backup.finished_at || backup.completed_at || null;
}

function backupCreatedAt(backup) {
  if (!["ready", "succeeded"].includes(String(backup.status || "").toLowerCase())) return null;
  return backup.artifact_created_at || backupFinishedAt(backup);
}

function backupIndeterminateProgress(label) {
  const host = document.createElement("div");
  host.className = "recovery-backup-indeterminate";
  state.factories.createProgress(host, { label }, {
    style: "indeterminate", size: "sm", rounded: true,
    showLabel: false, showPercent: false, ariaLabel: label,
  });
  return host;
}

function backupDuration(backup) {
  const initiated = Date.parse(backupInitiatedAt(backup) || "");
  const finished = Date.parse(backupFinishedAt(backup) || "");
  if (!Number.isFinite(initiated) || !Number.isFinite(finished) || finished < initiated) return "Unavailable";
  const totalSeconds = Math.round((finished - initiated) / 1000);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  if (hours) return `${hours}h ${minutes}m ${seconds}s`;
  if (minutes) return `${minutes}m ${seconds}s`;
  return `${seconds}s`;
}

let openBackupDetails = null;

function openBackupMetadata(backup) {
  if (openBackupDetails?.id === String(backup.id) && openBackupDetails.modal.getState().open) {
    openBackupDetails.update(backup);
    return;
  }
  const content = document.createElement("div");
  content.className = "recovery-backup-metadata";
  let modal = null;
  let actionState = "";
  const actionsFor = (current) => {
    const ready = ["ready", "succeeded"].includes(String(current.status || "").toLowerCase());
    const canDownload = ready && current.preview !== true && Boolean(current.download?.token);
    return [
      { id: "close", label: "Close" },
      ...(ready ? [{
        id: "download", label: "Download", variant: "primary", disabled: !canDownload,
        onClick() { recoveryDownload(current.download.token); },
      }] : []),
    ];
  };
  const update = (current) => {
    const preview = current.preview === true;
    const status = String(current.status || "Unknown");
    const normalizedStatus = status.toLowerCase();
    const running = normalizedStatus === "running";
    const previewFinished = preview && ["preview complete", "preview cancelled"].includes(normalizedStatus);
    const dataSelection = backupIncludesData(current);
    const initiatedAt = backupInitiatedAt(current);
    const finishedAt = backupFinishedAt(current);
    const timingFields = [
      ["Initiated", initiatedAt ? formatDate(initiatedAt) : "Unavailable"],
      ["Finished", finishedAt ? formatDate(finishedAt) : running ? "In progress" : "Unavailable"],
      ["Duration", finishedAt ? backupDuration(current) : running ? "In progress" : "Unavailable"],
    ];
    content.replaceChildren();
    if (preview || running) {
      const notice = document.createElement("p");
      notice.className = "recovery-workflow-notice";
      notice.textContent = preview
        ? previewFinished ? `${status}. No backup job or file was created.`
          : running ? "Simulated running backup for layout preview only. No real job or backup file exists."
            : "Sample data for layout preview only. No backup file exists to download."
        : "Backup is running. A file and download will be available only after it is ready.";
      content.appendChild(notice);
    }
    const details = document.createElement("dl");
    details.className = "recovery-backup-metadata-list";
    const fields = running || previewFinished ? [
      ...timingFields,
      ["Status", status],
      ["Overall progress", backupOverallProgress(current) === null ? "" : `${backupOverallProgress(current)}%`],
      ["Package type", dataSelection === null ? "" : dataSelection ? "Full clone" : "Clean installation"],
      ["Initiated by", backupInitiator(current)],
    ] : [
      ["Filename", current.filename || current.file_name || current.name || "Unavailable"],
      ...timingFields,
      ["Size", backupSizeOrProgress(current)],
      ["Status", status],
      ["Package type", dataSelection === null ? "" : dataSelection ? "Full clone" : "Clean installation"],
      ["Initiated by", backupInitiator(current)],
    ];
    if (normalizedStatus === "failed") fields.push(["Details", current.error_message || current.message || "This backup did not complete."]);
    fields.forEach(([label, value]) => {
      const term = document.createElement("dt");
      term.textContent = label;
      const description = document.createElement("dd");
      description.textContent = value;
      details.append(term, description);
    });
    content.appendChild(details);
    const nextActionState = `${["ready", "succeeded"].includes(normalizedStatus)}:${preview}:${Boolean(current.download?.token)}`;
    if (modal && nextActionState !== actionState) modal.setActions(actionsFor(current));
    actionState = nextActionState;
  };
  update(backup);
  modal = state.factories.createActionModal({
    title: backup.preview === true ? "Backup details · Preview" : "Backup details", size: "md", content,
    actions: actionsFor(backup),
    onClose() {
      if (openBackupDetails?.modal === modal) openBackupDetails = null;
      modal.destroy();
    },
  });
  openBackupDetails = { id: String(backup.id), modal, update };
  modal.open();
}

const PREVIEW_BACKUPS = [
  { id: "preview-running", preview: true, created_at: "2026-09-22T10:20:00+08:00", size_bytes: null, status: "Running", overall_progress_percent: 42, includes_data: true, initiated_by_name: "Sample Admin" },
  { id: "preview-001", preview: true, filename: "sample-backup-2026-09-22.syndicatum-backup", created_at: "2026-09-22T09:15:00+08:00", finished_at: "2026-09-22T09:18:24+08:00", size_bytes: 38168166, status: "Ready", includes_data: true, initiated_by_name: "Sample Admin" },
  { id: "preview-002", preview: true, filename: "sample-backup-2026-09-21.syndicatum-backup", created_at: "2026-09-21T22:40:00+08:00", finished_at: "2026-09-21T22:42:11+08:00", size_bytes: 19608371, status: "Ready", includes_data: false, initiated_by_name: "Sample Operator" },
  { id: "preview-003", preview: true, filename: "sample-backup-2026-09-20.syndicatum-backup", created_at: "2026-09-20T08:30:00+08:00", finished_at: "2026-09-20T08:31:47+08:00", size_bytes: null, status: "Failed", initiated_by_name: "Sample Admin", error_message: "Sample failure shown for layout preview." },
];

let backupConfirmationPending = false;
let backupProgressModal = null;
let previewRunningBackup = null;

function previewBackupRows() {
  return previewRunningBackup
    ? [previewRunningBackup, ...PREVIEW_BACKUPS.filter((backup) => backup.id !== previewRunningBackup.id)]
    : PREVIEW_BACKUPS;
}

function refreshPreviewBackupGrid() {
  if (state.backupGridPreview) state.components.backupGrid?.setRows?.(previewBackupRows());
  if (previewRunningBackup && openBackupDetails?.id === String(previewRunningBackup.id)) {
    openBackupDetails.update(previewRunningBackup);
  }
}

function confirmBackupStart() {
  if (backupConfirmationPending || backupProgressModal) return;
  backupConfirmationPending = true;
  const body = document.createElement("div");
  body.className = "ui-dialog-body";
  const icon = document.createElement("div");
  icon.className = "ui-dialog-variant-icon";
  icon.setAttribute("aria-hidden", "true");
  icon.innerHTML = helperIconHtml("status.warning", 44);
  const text = document.createElement("div");
  text.className = "ui-dialog-text";
  const message = document.createElement("p");
  message.className = "ui-dialog-message";
  message.textContent = "Show a simulated portable-package backup preview? The recovery service is unavailable, so no backup will be created.";
  const option = document.createElement("label");
  option.className = "recovery-backup-data-option";
  const includeData = document.createElement("input");
  includeData.type = "checkbox";
  includeData.checked = true;
  const label = document.createElement("span");
  label.textContent = "Include user-generated data";
  option.append(includeData, label);
  const explanation = document.createElement("p");
  explanation.className = "recovery-backup-data-help";
  explanation.textContent = "Uncheck for a clean installation package. The current database baseline, system settings, presets, persistent files, and protected configuration remain included.";
  text.append(message, option, explanation);
  body.append(icon, text);
  let startSelected = false;
  const modal = state.factories.createActionModal({
    title: "Backup preview", size: "sm", className: "ui-dialog ui-dialog--warning recovery-backup-warning-dialog", content: body,
    actions: [
      { id: "cancel", label: "Cancel" },
      { id: "start", label: "Show preview", variant: "primary", onClick() { startSelected = true; } },
    ],
    onClose() {
      const includeExistingData = includeData.checked;
      backupConfirmationPending = false;
      modal.destroy();
      if (startSelected) openBackupProgress({ includeExistingData });
    },
  });
  modal.open();
}

function openBackupProgress({ includeExistingData = true } = {}) {
  if (backupProgressModal) return;
  previewRunningBackup = {
    id: "preview-running", preview: true,
    created_at: new Date().toISOString(), size_bytes: null, status: "Running",
    overall_progress_percent: 0, includes_data: includeExistingData,
    initiated_by_name: state.session?.user?.display_name || "Sample Admin",
  };
  refreshPreviewBackupGrid();
  const content = document.createElement("div");
  content.className = "recovery-workflow-modal";
  const status = document.createElement("p");
  status.className = "recovery-workflow-notice";
  status.setAttribute("role", "status");
  status.textContent = `Preview only — ${includeExistingData ? "full clone with user-generated data" : "clean installation without user-generated data"}. Recovery service is unavailable; no backup will be created.`;
  const steps = recoveryProgressSteps([
    "Preparing backup", "Collecting production runtime", "Exporting database",
    "Collecting persistent files", "Securing configuration",
    "Building encrypted package", "Verifying package", "Complete",
  ]);
  content.append(status, steps.list);
  const overall = document.createElement("span");
  overall.className = "recovery-overall-progress";
  overall.textContent = "Overall progress: 0%";
  let stopPreview = null;
  let cancelConfirmationPending = false;
  let cancelConfirmationModal = null;
  let settleCancelConfirmation = null;
  let previewComplete = false;
  const focusCompletedClose = () => {
    if (backupProgressModal !== modal || !modal.getState().open) return;
    modal.refs.footer.querySelector('[data-action-autofocus="true"]')?.focus();
  };
  const confirmCancellation = () => new Promise((resolve) => {
    let cancelSelected = false;
    let settled = false;
    const settle = (value) => {
      if (settled) return;
      settled = true;
      resolve(value);
    };
    settleCancelConfirmation = settle;
    const body = document.createElement("div");
    body.className = "ui-dialog-body ui-dialog-body--compact";
    const icon = document.createElement("div");
    icon.className = "ui-dialog-variant-icon";
    icon.setAttribute("aria-hidden", "true");
    icon.innerHTML = helperIconHtml("status.warning", 44);
    const text = document.createElement("div");
    text.className = "ui-dialog-text";
    const message = document.createElement("p");
    message.className = "ui-dialog-message";
    message.textContent = "Stop the backup progress preview? No backup has been created.";
    text.appendChild(message);
    body.append(icon, text);
    const confirmation = state.factories.createActionModal({
      title: "Cancel backup preview?", size: "sm",
      className: "ui-dialog ui-dialog--warning recovery-backup-warning-dialog", content: body,
      showCloseButton: false, closeOnBackdrop: false,
      actions: [
        { id: "keep", label: "Keep preview" },
        { id: "cancel", label: "Cancel preview", variant: "danger", onClick() { cancelSelected = true; } },
      ],
      onClose() {
        if (cancelConfirmationModal === confirmation) cancelConfirmationModal = null;
        if (settleCancelConfirmation === settle) settleCancelConfirmation = null;
        settle(cancelSelected && !previewComplete);
        confirmation.destroy();
        if (previewComplete) requestAnimationFrame(focusCompletedClose);
      },
    });
    cancelConfirmationModal = confirmation;
    confirmation.open();
  });
  const modal = state.factories.createActionModal({
    title: "Backup Now · Preview", size: "lg", className: "recovery-backup-preview-modal", content,
    actions: [{ id: "cancel", label: "Cancel" }],
    onOpen({ footer }) { footer.prepend(overall); },
    async onBeforeClose() {
      if (cancelConfirmationPending) return false;
      if (previewComplete) return true;
      cancelConfirmationPending = true;
      try {
        const confirmed = await confirmCancellation();
        if (confirmed) stopPreview?.();
        return confirmed;
      } finally {
        cancelConfirmationPending = false;
      }
    },
    onClose() {
      settleCancelConfirmation?.(false);
      settleCancelConfirmation = null;
      cancelConfirmationModal?.destroy();
      cancelConfirmationModal = null;
      stopPreview?.();
      steps.destroy();
      if (previewRunningBackup && !previewComplete) {
        previewRunningBackup = { ...previewRunningBackup, status: "Preview cancelled", finished_at: new Date().toISOString() };
      }
      refreshPreviewBackupGrid();
      if (backupProgressModal === modal) backupProgressModal = null;
      modal.destroy();
    },
  });
  backupProgressModal = modal;
  modal.open();
  stopPreview = previewRecoveryProgress(steps, status, (value) => {
    overall.textContent = `Overall progress: ${value}%`;
    if (previewRunningBackup) {
      previewRunningBackup.overall_progress_percent = value;
      refreshPreviewBackupGrid();
    }
  }, () => {
    previewComplete = true;
    if (previewRunningBackup) {
      previewRunningBackup = { ...previewRunningBackup, status: "Preview complete", overall_progress_percent: 100, finished_at: new Date().toISOString() };
    }
    refreshPreviewBackupGrid();
    modal.setActions([{ id: "close", label: "Close", autoFocus: true }]);
    modal.refs.footer.prepend(overall);
    if (cancelConfirmationModal) {
      if (cancelConfirmationModal.getState().open) void cancelConfirmationModal.close({ reason: "preview-complete" });
    } else requestAnimationFrame(focusCompletedClose);
  });
}

function openRestoreProgress(file) {
  const content = document.createElement("div");
  content.className = "recovery-workflow-modal";
  const selected = document.createElement("p");
  selected.className = "recovery-selected-file";
  selected.textContent = "Selected file: " + file.name;
  const status = document.createElement("p");
  status.className = "recovery-workflow-notice";
  status.setAttribute("role", "alert");
  const validName = file.name.toLowerCase().endsWith(".syndicatum-backup");
  if (!validName) status.textContent = "Select a .syndicatum-backup file. Nothing was uploaded.";
  else if (file.size < 1 || file.size > 256 * 1024 * 1024) status.textContent = "Select a backup file between 1 byte and 256 MB. Nothing was uploaded.";
  else status.textContent = "Restore service unavailable. Nothing was uploaded or changed.";
  const steps = recoveryProgressSteps([
    "Uploading", "Validating backup", "Checking contents", "Preparing restore",
    "Restoring database", "Restoring files", "Verifying system", "Complete",
  ]);
  content.append(selected, status, steps.list);
  const modal = state.factories.createActionModal({
    title: "Restore backup", size: "lg", content,
    actions: [{ id: "close", label: "Close" }],
    onClose() { steps.destroy(); modal.destroy(); },
  });
  modal.open();
}

function renderBackupGrid(host, backups, unavailable) {
  state.components.backupGrid?.destroy?.();
  state.backupGridPreview = unavailable;
  const gridHost = document.createElement("div");
  gridHost.className = "recovery-backup-grid";
  host.appendChild(gridHost);
  const width = Math.max(800, el.admin_list.clientWidth - 16);
  const columnWidths = state.backupGridColumnWidths || {
    initiated_at: Math.round(width * .21),
    created_at: Math.round(width * .21),
    size_bytes: Math.round(width * .12),
    status: Math.round(width * .12),
    includes_data: Math.round(width * .12),
    initiated_by_name: Math.round(width * .22),
  };
  const rows = unavailable ? previewBackupRows() : backups;
  state.components.backupGrid = state.factories.createGrid(gridHost, rows, {
    className: "recovery-backups-grid",
    chrome: false,
    toolbarStart: unavailable ? "Preview data — sample backups only; no files exist. Select a row to view details." : null,
    columns: [
      { key: "initiated_at", label: "Initiated", format: (_value, backup) => backupInitiatedAt(backup) ? formatDate(backupInitiatedAt(backup)) : "" },
      {
        key: "created_at", label: "Created", sortable: false,
        renderCell({ row: backup }) {
          if (String(backup.status || "").toLowerCase() === "running") return backupIndeterminateProgress("Backup file being created");
          const createdAt = backupCreatedAt(backup);
          return createdAt ? formatDate(createdAt) : "";
        },
      },
      { key: "size_bytes", label: "Size", format: (_value, backup) => backupSizeOrProgress(backup) },
      { key: "status", label: "Status", format: (value) => String(value || "Unknown") },
      {
        key: "includes_data", label: "Data", sortable: false,
        renderCell({ row: backup }) {
          const status = String(backup.status || "").toLowerCase();
          if (backup.preview === true && ["preview complete", "preview cancelled"].includes(status)) return "";
          if (status === "running") return backupIndeterminateProgress("Backup in progress");
          const included = backupIncludesData(backup);
          if (included === null) return "";
          const marker = document.createElement("span");
          marker.className = `recovery-backup-data-marker ${included ? "is-included" : "is-excluded"}`;
          marker.setAttribute("role", "img");
          marker.setAttribute("aria-label", included ? "Includes existing data" : "Does not include existing data");
          marker.title = included ? "Includes existing data" : "Does not include existing data";
          marker.innerHTML = helperIconHtml(included ? "status.success" : "status.error", 18);
          return marker;
        },
      },
      { key: "initiated_by_name", label: "Initiated by", format: (_value, backup) => backupInitiator(backup) },
    ],
    columnWidths,
    enableColumnResize: true,
    minColumnWidth: 100,
    enableSort: false,
    enableSearch: false,
    enablePagination: false,
    emptyText: unavailable ? "Backup service unavailable." : "No backups yet.",
    onRowClick(backup) { openBackupMetadata(backup); },
    onColumnResize({ columnWidths: resized }) { state.backupGridColumnWidths = resized; },
  });
}

function renderBackupRestoreSurface(recovery = null, error = null) {
  state.backupPage?.destroy();
  state.backupPage = null;
  state.components.backupGrid?.destroy?.();
  state.components.backupGrid = null;
  state.components.adminTabs?.destroy?.();
  state.components.adminTabs = null;
  el.admin_list.replaceChildren();
  const unavailable = error || "Recovery services are not installed on this server yet.";
  const tabsHost = document.createElement("div");
  tabsHost.className = "backup-restore-tabs";
  el.admin_list.appendChild(tabsHost);
  state.components.adminTabs = state.factories.createTabs(tabsHost, {
    ariaLabel: "Backup and restore workflows",
    activeId: "backup",
    onChange(_tab, activeId) {
      requestAnimationFrame(() => {
        const activeTab = Array.from(tabsHost.querySelectorAll('[role="tab"]')).find((entry) => entry.dataset.tabId === String(activeId));
        activeTab?.focus({ preventScroll: true });
      });
    },
    tabs: [
      {
        id: "overview",
        label: "Overview",
        render(host) {
          state.backupPage?.destroy();
          state.backupPage = null;
          state.components.backupGrid?.destroy?.();
          state.components.backupGrid = null;
          const grid = document.createElement("div");
          grid.className = "backup-restore-overview-grid";
          grid.append(
            recoveryOperationPanel(
              "Installation identity",
              recovery?.installation?.message || "The immutable installed release identity anchors package and backup compatibility.",
              recovery?.installation?.available ? "Verified" : "Unavailable",
              [["Installation ID", recovery?.installation?.installation_id], ["Installed version", recovery?.installation?.application_version], ["Package baseline", recovery?.installation?.schema_baseline]],
            ),
            recoveryOperationPanel(
              "Backup contract",
              "Backups are authenticated, encrypted, non-executable, and written with no-replacement semantics.",
              recovery?.backup?.available ? "Ready" : "Unavailable",
              [["Encryption", recovery?.backup?.encrypted ? "Required" : "Unavailable"], ["Executable code", recovery?.backup?.executable ? "Included" : "Excluded"], ["Existing destinations", "Never replaced"]],
            ),
            recoveryOperationPanel(
              "Restore boundary",
              "The serving database is never a restore target.",
              recovery?.restore_target?.ready ? "Ready" : "Unavailable",
              [["Separate target", recovery?.restore_target?.configured ? "Configured" : "Not configured"], ["Empty-target check", recovery?.restore_target?.ready ? "Passed" : "Not ready"], ["Automatic cutover", "Never"]],
            ),
          );
          host.appendChild(grid);
        },
      },
      {
        id: "backup",
        label: "Backup",
        render(host) {
          state.backupPage?.destroy();
          state.backupPage = null;
          const panel = document.createElement("section");
          panel.className = "recovery-workflow-panel recovery-backup-panel";
          host.appendChild(panel);
          state.backupPage = mountCurrentBackup({ host: panel, factories: state.factories,
            api: API, request, csrfHeaders, toast: state.components.toast, formatDate,
            realtime: {
              room: "syndicatum.backups.global",
              state() {
                return { joined: state.realtimeRooms.has("syndicatum.backups.global"), error: state.realtimeError };
              },
            },
          });
        },
      },
      {
        id: "restore",
        label: "Restore",
        render(host) {
          state.backupPage?.destroy();
          state.backupPage = null;
          state.components.backupGrid?.destroy?.();
          state.components.backupGrid = null;
          const panel = document.createElement("section");
          panel.className = "recovery-workflow-panel";
          host.appendChild(panel);
          state.backupPage = mountCurrentRestore({ host: panel, factories: state.factories,
            api: API, request, csrfHeaders, toast: state.components.toast, formatDate,
            realtime: {
              room: "syndicatum.backups.global",
              state() { return { joined: state.realtimeRooms.has("syndicatum.backups.global"), error: state.realtimeError }; },
            },
          });
        },
      },
      {
        id: "release-package",
        label: "Release package",
        render(host) {
          state.backupPage?.destroy();
          state.backupPage = null;
          state.components.backupGrid?.destroy?.();
          state.components.backupGrid = null;
          host.appendChild(recoveryOperationPanel(
            "Get the canonical clean installation package",
            error || "Retrieve the mounted immutable CI-built release after checking its pinned SHA-256. The serving instance never builds or mints it.",
            recovery?.release?.available ? "Verified" : "Unavailable",
            [["Source", "CI-built immutable release"], ["SHA-256", recovery?.release?.sha256],
              ["Source commit", recovery?.release?.source_commit]],
            { label: "Get clean package", disabled: !recovery?.release?.available, onClick: () => openReleasePackage(recovery) },
          ));
        },
      },
    ],
  });
}

async function loadBackupRestoreSurface() {
  renderBackupRestoreSurface();
  try {
    state.recoveryStatus = unwrap(await request(API.recoveryStatus));
    renderBackupRestoreSurface(state.recoveryStatus);
  } catch (error) {
    state.recoveryStatus = null;
    renderBackupRestoreSurface(null, error.message);
  }
}

function openReleasePackage(status) {
  const modal = state.factories.createFormModal({
    title: "Get clean package", submitLabel: "Authorize download",
    initialValues: { verified_source: false },
    rows: [
      [{ type: "text", content: "Syndicatum will only download the pinned CI-built release. This running instance cannot build or mint canonical executable code." }],
      [{ type: "display", name: "sha", label: "Pinned SHA-256", value: status.release.sha256 || "Unavailable" }],
      [{ type: "display", name: "commit", label: "Source commit", value: status.release.source_commit || "Unavailable" }],
      [{ type: "checkbox", name: "verified_source", label: "I understand this retrieves the verified CI artifact and does not build a package locally.", required: true }],
    ],
    async onSubmit(values, context) {
      try {
        if (!values.verified_source) throw new Error("Confirm the CI-built release boundary.");
        const result = unwrap(await request(API.releasePackage, { method: "POST", headers: csrfHeaders(), body: "{}" }));
        recoveryDownload(result.download.token);
        state.components.toast.success("Verified CI-built package download authorized.");
        return true;
      } catch (error) { context.setFormError(error.message); return false; }
    },
  });
  modal.open();
}


function guideNode(tag, className = "", text = "") {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
}

function guideCommandBlock(command) {
  const block = guideNode("div", "guide-command");
  const pre = guideNode("pre", "guide-command-text");
  const code = guideNode("code", "", command);
  const button = guideNode("button", "ui-button ui-button-borderless guide-command-copy");
  const label = guideNode("span", "", "Copy");
  button.type = "button";
  button.title = "Copy command";
  button.setAttribute("aria-label", "Copy command");
  button.innerHTML = helperIconHtml("actions.copy", 16);
  button.append(label);
  button.addEventListener("click", async () => {
    button.disabled = true;
    try {
      if (!navigator.clipboard?.writeText) throw new Error("Clipboard access is unavailable.");
      await navigator.clipboard.writeText(command);
      label.textContent = "Copied";
      state.components.toast.success("Command copied.");
      window.setTimeout(() => { label.textContent = "Copy"; }, 1600);
    } catch (_error) {
      state.components.toast.error("Copy failed. Select the command and copy it manually.");
    } finally {
      button.disabled = false;
    }
  });
  pre.append(code);
  block.append(pre, button);
  return block;
}

function appendGuideRichText(container, parts = []) {
  for (const part of parts) {
    if (typeof part === "string") {
      container.append(document.createTextNode(part));
      continue;
    }
    if (part?.type !== "article-link" || !part.articleId) continue;
    const target = guideArticle(part.articleId, { administrator: isAdministrator() }).article;
    if (target.id !== part.articleId) {
      container.append(document.createTextNode(part.text || "User Guide article"));
      continue;
    }
    const link = guideNode("a", "guide-article-link", part.text || target.title);
    link.href = `${applicationPath("guide")}#${encodeURIComponent(target.id)}`;
    link.addEventListener("click", (event) => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      selectGuideArticle(target.id);
    });
    container.append(link);
  }
}

function renderGuideArticle(articleId) {
  const { section, article } = guideArticle(articleId, { administrator: isAdministrator() });
  state.selectedGuideArticleId = article.id;
  const panel = guideNode("article", "guide-article ui-panel");
  panel.id = "guide-article";
  panel.tabIndex = -1;
  const eyebrow = guideNode("p", "ui-eyebrow", section.title);
  const title = guideNode("h2", "guide-article-title", article.title);
  const summary = guideNode("p", "guide-article-summary", article.summary);
  const header = guideNode("header", "guide-article-header");
  header.append(eyebrow, title, summary);
  const body = guideNode("div", "guide-article-body");
  for (const block of article.blocks || []) {
    if (block.type === "p") body.append(guideNode("p", "", block.text));
    if (block.type === "note") {
      const note = guideNode("aside", "guide-note", block.text);
      note.setAttribute("aria-label", "Note");
      body.append(note);
    }
    if (block.type === "link") {
      const link = guideNode("a", "guide-resource-link", `${block.label} ↗`);
      link.href = block.href;
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.setAttribute("aria-label", `${block.label} (opens in a new tab)`);
      body.append(link);
    }
    if (["list", "steps"].includes(block.type)) {
      const list = guideNode(block.type === "steps" ? "ol" : "ul", "guide-list");
      for (const item of block.items || []) {
        const listItem = guideNode("li");
        if (item && typeof item === "object" && !Array.isArray(item)) {
          if (item.parts?.length) {
            const copy = guideNode("span", "guide-step-text");
            appendGuideRichText(copy, item.parts);
            listItem.append(copy);
          } else if (item.text) {
            listItem.append(guideNode("span", "guide-step-text", item.text));
          }
          if (item.command) listItem.append(guideCommandBlock(item.command));
        } else {
          listItem.textContent = item;
        }
        list.append(listItem);
      }
      body.append(list);
    }
    if (block.type === "terms") {
      const terms = guideNode("dl", "guide-terms");
      for (const [term, definition] of block.items || []) {
        terms.append(guideNode("dt", "", term), guideNode("dd", "", definition));
      }
      body.append(terms);
    }
  }
  panel.append(header, body);
  return panel;
}

function selectGuideArticle(articleId, { historyMode = "push", focus = true } = {}) {
  const browser = el.admin_list.querySelector(".guide-browser");
  if (!browser) return;
  const next = guideArticle(articleId, { administrator: isAdministrator() }).article;
  state.selectedGuideArticleId = next.id;
  browser.querySelector(".guide-article")?.replaceWith(renderGuideArticle(next.id));
  browser.querySelectorAll(".guide-tree-item").forEach((button) => {
    const selected = button.dataset.articleId === next.id;
    button.classList.toggle("is-active", selected);
    button.setAttribute("aria-current", selected ? "page" : "false");
  });
  updateApplicationRoute("guide", "", historyMode);
  if (focus) browser.querySelector(".guide-article")?.focus({ preventScroll: true });
}

function renderGuideSurface() {
  el.admin_list.replaceChildren();
  const intro = guideNode("p", "guide-intro", isAdministrator()
    ? "Search plain-language guidance for projects, connections, messages, requests, tasks, templates, agents, and administration."
    : "Search plain-language guidance for projects, connections, messages, requests, tasks, templates, and agents.");
  const browser = guideNode("div", "guide-browser");
  const navigation = guideNode("aside", "guide-tree ui-panel");
  navigation.setAttribute("aria-label", "User Guide topics");
  const searchLabel = guideNode("label", "guide-search-label", "Search the guide");
  const search = guideNode("input", "ui-input");
  search.type = "search";
  search.placeholder = "Search topics";
  search.value = state.guideQuery;
  searchLabel.append(search);
  const results = guideNode("nav", "guide-tree-results");
  results.setAttribute("aria-label", "Guide sections");
  navigation.append(searchLabel, results);

  const renderNavigation = () => {
    const sections = searchGuide(state.guideQuery, { administrator: isAdministrator() });
    results.replaceChildren();
    if (!sections.length) {
      results.append(guideNode("p", "guide-no-results", `No guide topics match “${state.guideQuery}”.`));
      return;
    }
    for (const section of sections) {
      const group = guideNode("section", "guide-tree-section");
      const heading = guideNode("h3", "guide-tree-section-title", section.title);
      const list = guideNode("ul", "guide-tree-list");
      for (const article of section.articles) {
        const item = guideNode("li");
        const button = guideNode("button", "guide-tree-item", article.title);
        button.type = "button";
        button.dataset.articleId = article.id;
        const selected = article.id === state.selectedGuideArticleId;
        button.classList.toggle("is-active", selected);
        button.setAttribute("aria-current", selected ? "page" : "false");
        button.addEventListener("click", () => selectGuideArticle(article.id));
        item.append(button); list.append(item);
      }
      group.append(heading, list); results.append(group);
    }
  };
  search.addEventListener("input", () => {
    state.guideQuery = search.value.trim();
    renderNavigation();
  });
  renderNavigation();
  browser.append(navigation, renderGuideArticle(state.selectedGuideArticleId));
  el.admin_list.append(intro, browser);
}

function showGuideSurface(articleId = state.selectedGuideArticleId, { historyMode = "push" } = {}) {
  closeRealtime();
  clearTimeout(state.pollingTimer);
  state.selectedGuideArticleId = guideArticle(articleId, { administrator: isAdministrator() }).article.id;
  state.adminKind = "";
  setSurface("guide");
  el.admin_surface.classList.remove("is-backup-restore", "is-templates");
  el.admin_surface.classList.add("is-guide");
  el.admin_eyebrow.textContent = "Help and reference";
  el.admin_title.textContent = "User Guide";
  el.admin_refresh_button.hidden = true;
  state.components.backupGrid?.destroy?.();
  state.components.backupGrid = null;
  state.components.adminTabs?.destroy?.();
  state.components.adminTabs = null;
  renderGuideSurface();
  updateApplicationRoute("guide", "", historyMode);
}

async function showAdminSurface(kind, { historyMode = "push" } = {}) {
  const settingsSurface = ["templates", "delivery-health", "backup-restore"].includes(kind);
  if (settingsSurface ? !capability("admin.settings", isAdministrator()) : !capability(`admin.${kind}`)) return;
  closeRealtime(); clearTimeout(state.pollingTimer); state.adminKind = kind; setSurface(kind);
  el.admin_surface.classList.toggle("is-backup-restore", kind === "backup-restore");
  el.admin_surface.classList.toggle("is-templates", kind === "templates");
  el.admin_surface.classList.remove("is-guide");
  el.admin_eyebrow.textContent = "Global administration";
  updateApplicationRoute(kind, "", historyMode);
  state.components.backupGrid?.destroy?.();
  state.components.backupGrid = null;
  state.components.adminTabs?.destroy();
  state.components.adminTabs = null;
  el.admin_refresh_button.hidden = ["templates", "backup-restore"].includes(kind);
  el.admin_title.textContent = kind === "delivery-health" ? "Delivery health" : (kind === "backup-restore" ? "Backup / Restore" : kind[0].toUpperCase() + kind.slice(1));
  if (kind === "backup-restore") {
    void loadBackupRestoreSurface();
    if (!state.realtimeSocket) void connectRealtime(state.generation);
    return;
  }
  if (kind === "templates") {
    await loadTemplatesSurface();
    if (!state.realtimeSocket) void connectRealtime(state.generation);
    return;
  }
  el.admin_list.replaceChildren(); const loading = document.createElement("p"); loading.textContent = "Loading…"; el.admin_list.append(loading);
  const endpoint = { users: API.adminUsers, agents: API.adminAgents, audit: API.adminAudit, "delivery-health": API.adminDeliveryHealth }[kind];
  try {
    const payload = await request(endpoint);
    if (kind === "delivery-health") {
      renderAdminDeliveryHealth(unwrap(payload));
      return;
    }
    const rows = adminRows(payload, kind); el.admin_list.replaceChildren();
    if (!rows.length) { const empty = document.createElement("p"); empty.className = "empty-state ui-panel"; empty.textContent = `No ${kind} to show.`; el.admin_list.append(empty); return; }
    rows.forEach((row) => {
      const card = document.createElement("article"); card.className = "admin-card ui-panel";
      const title = document.createElement("strong"); title.textContent = row.display_name || row.name || row.action || `${kind.slice(0, -1)} ${row.id || ""}`;
      const summary = document.createElement("p"); summary.textContent = kind === "audit" ? [row.actor_display_name || "System", row.subject_type, row.subject_id, formatDate(row.created_at)].filter(Boolean).join(" · ") : [row.email, row.status, row.kind, ...(row.system_roles || row.roles || [])].filter(Boolean).join(" · ");
      card.append(title, summary); el.admin_list.append(card);
    });
  } catch (error) { el.admin_list.replaceChildren(); const failure = document.createElement("p"); failure.className = "empty-state ui-panel"; failure.textContent = error.message; el.admin_list.append(failure); }
  if (!state.realtimeSocket) void connectRealtime(state.generation);
}

function renderAdminDeliveryHealth(report) {
  el.admin_list.replaceChildren();
  const addCard = (titleText, lines) => {
    const card = document.createElement("article"); card.className = "admin-card ui-panel";
    const title = document.createElement("strong"); title.textContent = titleText;
    card.append(title);
    lines.forEach((line) => { const detail = document.createElement("p"); detail.textContent = line; card.append(detail); });
    el.admin_list.append(card);
  };
  const value = (item) => item === null || item === undefined ? "unavailable" : String(item);
  const seconds = (item) => item === null || item === undefined ? "unavailable" : `${item} seconds`;
  addCard("Overall", [`State: ${value(report?.state)}`, `Checked: ${value(report?.checked_at)}`]);
  const worker = report?.worker || {};
  addCard("Delivery worker", [
    `State: ${value(worker.state)}`, `Heartbeat age: ${seconds(worker.age_seconds)}`,
    `Last successful cycle: ${value(worker.last_success_at)}`,
    ...(worker.unavailable_reason ? [`Telemetry: ${worker.unavailable_reason}`] : []),
  ]);
  const names = { realtime: "Realtime", webhook: "Agent webhooks", workspace_agent: "Workspace Agent", responses_api: "Responses API" };
  Object.entries(names).forEach(([key, label]) => {
    const path = report?.paths?.[key] || {};
    const failure = path.diagnostic_sample?.latest_failed_attempt;
    addCard(label, [
      `Queue state: ${value(path.state)}`,
      `Pending: ${value(path.pending)} · Retrying: ${value(path.retrying)} · Waiting: ${value(path.waiting)} · Terminal: ${value(path.terminal)}`,
      `Oldest pending: ${seconds(path.oldest_pending_seconds)}`,
      `Last attempt: ${value(path.last_attempt_at)} · Last success: ${value(path.last_success_at)}`,
      ...(path.last_success_at === null && path.state !== "unknown" ? ["Delivery acceptance: not yet observed"] : []),
      `Activation dependency: ${path.activation_dependency === "disabled_in_v1" ? "Disabled in V1" : "Delivery worker (see state above)"}`,
      failure ? `Last failed attempt in newest 50 rows: ${value(failure.failure_code)}; queue ${value(failure.queue_state)}; HTTP ${value(failure.http_status)}; provider state ${value(failure.provider_state)}; at ${value(failure.at)}`
        : `Last failed attempt in newest 50 rows: ${path.diagnostic_sample?.scope === "unavailable" ? "unavailable" : "none sampled"}`,
      ...(path.unavailable_reason ? [`Telemetry: ${path.unavailable_reason}`] : []),
    ]);
  });
}

function visibleTasks() {
  const terminal = new Set(["completed", "cancelled"]);
  const current = id(state.project?.current_participant?.id);
  if (state.taskFilter === "active") return state.tasks.filter((task) => !terminal.has(task.status));
  if (state.taskFilter === "mine") return state.tasks.filter((task) => id(task.assignee_participant_id) === current && !terminal.has(task.status));
  if (state.taskFilter === "all") return state.tasks;
  return state.tasks.filter((task) => task.status === state.taskFilter);
}

function taskStatusLabel(status) {
  return ({ open: "Open", in_progress: "In progress", in_review: "In review", blocked: "Blocked", completed: "Completed", cancelled: "Cancelled" })[status] || status;
}

function renderTasks() {
  if (!el.task_list) return;
  el.new_task_trigger.hidden = !state.project || !can("messages.write");
  const tasks = visibleTasks();
  el.task_count.textContent = String(tasks.length);
  el.task_list.replaceChildren();
  if (!tasks.length) {
    const empty = document.createElement("p");
    empty.className = "empty-state";
    empty.textContent = state.tasks.length ? "No tasks match this filter." : "No project tasks yet.";
    el.task_list.append(empty);
    return;
  }
  tasks.forEach((task) => {
    const card = document.createElement("button");
    card.type = "button";
    card.className = `ui-panel task-card is-${task.status}`;
    const title = document.createElement("span"); title.className = "task-card-title"; title.textContent = task.title;
    const meta = document.createElement("span"); meta.className = "task-card-meta";
    const status = document.createElement("span"); status.className = "task-card-status"; status.textContent = taskStatusLabel(task.status);
    const priority = document.createElement("span"); priority.className = "task-card-priority"; priority.textContent = `${task.priority} priority`;
    const assignee = document.createElement("span"); assignee.textContent = task.assignee_display_name ? `Assigned: ${task.assignee_display_name}` : "Unassigned";
    meta.append(status, priority, assignee);
    if (task.due_at) { const due = document.createElement("span"); due.textContent = `Due ${formatDate(task.due_at)}`; meta.append(due); }
    card.append(title, meta);
    card.addEventListener("click", () => openTaskDetails(task.id));
    el.task_list.append(card);
  });
}

function receiveRealtimeTask(source) {
  if (!source || !source.id || id(source.project_id) !== selectedProjectId()) return;
  const existing = state.tasks.find((task) => id(task.id) === id(source.id));
  if (existing && Number(existing.version || 0) >= Number(source.version || 0)) return;
  const previousLinks = state.tasks.map((task) => `${task.id}:${task.source_message_id || ""}`).join("|");
  state.tasks = existing
    ? state.tasks.map((task) => id(task.id) === id(source.id) ? source : task)
    : [source, ...state.tasks];
  renderTasks();
  const nextLinks = state.tasks.map((task) => `${task.id}:${task.source_message_id || ""}`).join("|");
  if (previousLinks !== nextLinks && state.components.timeline) renderTimeline();
  if (previousLinks !== nextLinks) state.components.responsibilityInbox?.refreshTasks();
}

async function loadTasks(projectGeneration = state.generation) {
  if (!selectedProjectId()) return;
  const payload = await request(`${API.tasks}?${new URLSearchParams({ project_id: selectedProjectId() })}`, { signal: state.abortController?.signal });
  if (projectGeneration !== state.generation) return;
  const previousLinks = state.tasks.map((task) => `${task.id}:${task.source_message_id || ""}`).join("|");
  state.tasks = unwrap(payload) || [];
  renderTasks();
  const nextLinks = state.tasks.map((task) => `${task.id}:${task.source_message_id || ""}`).join("|");
  if (previousLinks !== nextLinks && state.components.timeline) renderTimeline();
  if (previousLinks !== nextLinks) state.components.responsibilityInbox?.refreshTasks();
}

function taskParticipantOptions(includeEmpty = true) {
  return [
    ...(includeEmpty ? [{ value: "", label: "Unassigned" }] : []),
    ...state.participants.map((participant) => ({ value: participant.id, label: `${participant.display_name} · ${participant.kind}` })),
  ];
}

function normalizeTaskForm(values) {
  return {
    title: String(values.title || "").trim(),
    description: String(values.description || "").trim() || null,
    acceptance_criteria: String(values.acceptance_criteria || "").trim() || null,
    priority: values.priority || "normal",
    assignee_participant_id: values.assignee_participant_id || null,
    due_at: values.due_at || null,
  };
}

function taskFormRows() {
  const participants = taskParticipantOptions();
  return [
    [modalTextField("title", "Task title", { required: true, maxlength: 180 })],
    [{ type: "textarea", name: "description", label: "Description" }, { type: "textarea", name: "acceptance_criteria", label: "Acceptance criteria" }],
    [{ type: "select", name: "priority", label: "Priority", required: true, options: [
      { value: "low", label: "Low" }, { value: "normal", label: "Normal" }, { value: "high", label: "High" }, { value: "urgent", label: "Urgent" },
    ] }, { type: "ui.datepicker", name: "due_at", label: "Due date", showTime: true, timePrecision: "minute", valueMode: "wall-clock", closeOnSelect: false, placeholder: "Optional" }],
    [{ type: "select", name: "assignee_participant_id", label: "Assigned participant", options: participants }],
    [{ type: "text", content: "Every active participant in this project can see this task. The task giver is recorded automatically from the signed-in participant." }],
  ];
}

function openCreateTaskModal(sourceMessage = null) {
  const convertsActionRequest = Boolean(sourceMessage?.action_requested);
  const modal = state.factories.createFormModal({
    title: convertsActionRequest ? "Convert action request to task" : "Create task",
    size: "lg", submitLabel: convertsActionRequest ? "Convert to task" : "Create task",
    initialValues: {
      title: "",
      description: "",
      priority: "normal", assignee_participant_id: "", due_at: "",
    },
    rows: taskFormRows(),
    async onSubmit(values, context) {
      try {
        const created = unwrap(await request(`${API.tasks}?${new URLSearchParams({ project_id: selectedProjectId() })}`, { method: "POST", headers: csrfHeaders(), body: JSON.stringify({
          ...normalizeTaskForm(values), source_message_id: sourceMessage?.id || null,
          convert_action_request: convertsActionRequest,
        }) }));
        receiveRealtimeTask(created);
        state.components.toast.success(convertsActionRequest ? "Action request converted to a task." : "Task created.");
        return true;
      } catch (error) { context.setFormError(error.message); return false; }
    },
  });
  modal.open();
}

function openEditTaskModal(task) {
  const due = task.due_at ? String(task.due_at).replace(" ", "T").slice(0, 16) : "";
  const modal = state.factories.createFormModal({
    title: "Edit task", size: "lg", submitLabel: "Save task",
    initialValues: { ...task, assignee_participant_id: id(task.assignee_participant_id), due_at: due },
    rows: taskFormRows(),
    async onSubmit(values, context) {
      try {
        const updated = unwrap(await request(`${API.task}?${new URLSearchParams({ project_id: selectedProjectId(), id: task.id })}`, {
          method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ version: task.version, ...normalizeTaskForm(values) }),
        }));
        receiveRealtimeTask(updated); state.components.toast.success("Task updated."); return true;
      } catch (error) { context.setFormError(error.message); return false; }
    },
  });
  modal.open();
}

function taskDetailContent(task) {
  const content = document.createElement("div"); content.className = "task-detail";
  const hero = document.createElement("section"); hero.className = "task-detail-hero";
  const title = document.createElement("h2"); title.className = "task-detail-title"; title.textContent = task.title;
  const chips = document.createElement("div"); chips.className = "task-detail-chips";
  [[taskStatusLabel(task.status), `is-${task.status}`], [`${task.priority} priority`, "is-priority"]].forEach(([label, className]) => {
    const chip = document.createElement("span"); chip.className = `task-detail-chip ${className}`; chip.textContent = label; chips.append(chip);
  });
  const description = document.createElement("p"); description.className = "task-detail-description"; description.textContent = task.description || "No description provided.";
  hero.append(title, chips, description); content.append(hero);

  const addDetailSection = (headingText, rows) => {
    const section = document.createElement("section"); section.className = "task-detail-section ui-panel";
    const heading = document.createElement("h3"); heading.textContent = headingText;
    const details = document.createElement("dl"); details.className = "task-detail-list";
    rows.forEach(([label, value]) => { const dt = document.createElement("dt"); dt.textContent = label; const dd = document.createElement("dd"); dd.textContent = value; details.append(dt, dd); });
    section.append(heading, details); content.append(section);
  };
  addDetailSection("Responsibility", [
    ["Assigned to", task.assignee_display_name || "Unassigned"],
    ["Task giver", task.creator_display_name || "Unknown"],
  ]);
  addDetailSection("Schedule", [
    ["Due", task.due_at ? formatDate(task.due_at) : "No due date"],
    ["Created", formatDate(task.created_at)],
    ["Updated", formatDate(task.updated_at)],
  ]);
  if (task.acceptance_criteria) {
    const criteriaSection = document.createElement("section"); criteriaSection.className = "task-detail-section ui-panel";
    const heading = document.createElement("h3"); heading.textContent = "Acceptance criteria";
    const criteria = document.createElement("p"); criteria.textContent = task.acceptance_criteria;
    criteriaSection.append(heading, criteria); content.append(criteriaSection);
  }
  if (task.source_message_id) {
    const sourceSection = document.createElement("section"); sourceSection.className = "task-detail-section ui-panel";
    const heading = document.createElement("h3"); heading.textContent = "Source";
    const sourceDescription = document.createElement("p"); sourceDescription.textContent = `Timeline message #${task.source_message_id}`;
    const open = document.createElement("button"); open.type = "button"; open.className = "ui-button ui-button-borderless";
    open.textContent = "View source message";
    open.addEventListener("click", () => void openResponsibilityMessage(task.source_message_id));
    sourceSection.append(heading, sourceDescription, open); content.append(sourceSection);
  }
  if (task.blocked_reason) { const blocked = document.createElement("p"); blocked.className = "task-blocked-reason"; blocked.textContent = `Blocked: ${task.blocked_reason}`; content.append(blocked); }
  const events = Array.isArray(task.events) ? task.events : [];
  const activitySection = document.createElement("section"); activitySection.className = "task-detail-section ui-panel";
  const activityHeading = document.createElement("h3"); activityHeading.textContent = "Activity";
  const activity = document.createElement("ol"); activity.className = "task-detail-activity";
  if (!events.length) { const empty = document.createElement("li"); empty.textContent = "No recorded activity."; activity.append(empty); }
  events.forEach((event) => {
    const item = document.createElement("li");
    const eventLabel = event.event_type === "status_changed"
      ? `${taskStatusLabel(event.from_status)} → ${taskStatusLabel(event.to_status)}`
      : ({ created: "Task created", assigned: "Assignment updated", updated: "Task updated" })[event.event_type] || event.event_type;
    const summary = document.createElement("strong"); summary.textContent = eventLabel;
    const meta = document.createElement("span"); meta.textContent = `${event.actor_display_name || "Unknown participant"} · ${formatDate(event.created_at)}`;
    item.append(summary, meta);
    if (event.body) { const note = document.createElement("p"); note.textContent = event.body; item.append(note); }
    activity.append(item);
  });
  activitySection.append(activityHeading, activity); content.append(activitySection);
  return content;
}

function taskActions(task, modal) {
  const actions = [{ id: "close", label: "Close" }];
  const current = id(state.project?.current_participant?.id);
  const manager = can("project.admin");
  const taskGiver = id(task.created_by_participant_id) === current;
  const assignee = id(task.assignee_participant_id) === current;
  const supervisor = id(task.supervising_participant_id) === current;
  const transitions = [];
  if (task.status === "open" && (manager || assignee)) transitions.push(["in_progress", "Start"]);
  if (task.status === "in_progress" && (manager || assignee)) transitions.push(["blocked", "Mark blocked"], ["in_review", "Submit for review"]);
  if (task.status === "blocked" && (manager || assignee || supervisor)) transitions.push(["in_progress", "Resume"]);
  if (task.status === "in_review" && (manager || supervisor)) transitions.push(["in_progress", "Return to progress"], ["completed", "Complete"]);
  if (manager && ["completed", "cancelled"].includes(task.status)) transitions.push(["open", "Reopen"]);
  if (manager && !["completed", "cancelled"].includes(task.status)) transitions.push(["cancelled", "Cancel task"]);
  transitions.reverse().forEach(([status, label]) => actions.unshift({
    id: `task-${status}`, label, variant: status === "cancelled" ? "danger" : "secondary", closeOnClick: false,
    async onClick() {
      modal.setBusy(true, { message: `${label}...` });
      try {
        const updated = unwrap(await request(`${API.task}?${new URLSearchParams({ project_id: selectedProjectId(), id: task.id })}`, {
          method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ version: task.version, status }),
        }));
        receiveRealtimeTask(updated);
        state.components.toast.success(`Task status changed to ${taskStatusLabel(status)}.`); await modal.close({ reason: "updated" });
      } catch (error) { modal.setBusy(false); state.components.toast.error(error.message, { title: "Task update failed" }); }
    },
  }));
  if (taskGiver) actions.unshift({ id: "edit", label: "Edit", variant: "secondary", async onClick() { await modal.close({ reason: "edit" }); openEditTaskModal(task); } });
  return actions;
}

async function openTaskDetails(taskId) {
  let modal;
  const loading = document.createElement("p"); loading.textContent = "Loading task details...";
  modal = state.factories.createActionModal({ title: "Task details", size: "md", content: loading, actions: [{ id: "close", label: "Close" }] });
  modal.open();
  modal.setBusy(true, { message: "Loading task details..." });
  try {
    const task = unwrap(await request(`${API.task}?${new URLSearchParams({ project_id: selectedProjectId(), id: taskId, include: "events" })}`));
    if (!modal.getState().open) return;
    modal.setActions(taskActions(task, modal));
    modal.setContent(taskDetailContent(task));
    modal.setBusy(false);
  } catch (error) {
    modal.setBusy(false);
    loading.textContent = `Unable to load task details. ${error.message}`;
  }
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
  state.tasks = [];
  state.projectView = "timeline";
  state.components.responsibilityInbox?.destroy();
  state.components.responsibilityInbox = null;
  state.timelineDefaultCollapsed = false;
  updateTimelineCollapseButton();
  state.oldestCursor = "";
  state.newestCursor = "";
  state.hasOlder = false;
  dismissMessageComposerModal();
  state.draft = { mode: "direct", intent: "update", addressees: [], replyTo: null, preReplyAddressing: null, idempotencyKey: "" };
  state.components.timeline?.destroy();
  state.components.timeline = null;
  el.timeline_host.replaceChildren();
  el.status_badge.textContent = "Loading";
  const [payload, participantsPayload, tasksPayload] = await Promise.all([
    request(`${API.context}?${new URLSearchParams({ project_id: nextId })}`, { signal: state.abortController.signal }),
    request(`${API.participants}?${new URLSearchParams({ project_id: nextId, status: "active" })}`, { signal: state.abortController.signal }),
    request(`${API.tasks}?${new URLSearchParams({ project_id: nextId })}`, { signal: state.abortController.signal }),
  ]);
  if (generation !== state.generation) return;
  const context = unwrap(payload) || {};
  state.project = {
    ...(state.projects.find((project) => project.id === nextId) || {}),
    ...(context.project || context),
    governance: context.governance || null,
    effective_instructions: context.effective_instructions || "",
  };
  state.project.id = nextId;
  state.project.capabilities = context.capabilities || state.project.capabilities || {};
  state.participants = (unwrap(participantsPayload) || context.participants || state.project.participants || []).map((participant) => participantFrom(participant, participant.kind));
  state.tasks = unwrap(tasksPayload) || [];
  const currentParticipantId = id(context.current_participant_id || context.current_participant?.id || state.project.participant_id);
  state.project.current_participant = state.participants.find((participant) => participant.id === currentParticipantId)
    || (context.current_participant ? participantFrom(context.current_participant, "human") : null);
  const role = String(context.current_role || state.project.role || "viewer");
  state.project.role = role;
  state.project.permissions = context.permissions || {
    "messages.read": true,
    "messages.write": ["owner", "admin", "member", "agent"].includes(role),
    "messages.acknowledge": true,
    "project.admin": ["owner", "admin"].includes(role),
  };
  state.filters.sender = "";
  setSurface("project");
  setMobilePanel("timeline");
  updateApplicationRoute("project", state.project.public_id || nextId, historyMode);
  writeLocalPreference("syndicatum.workspace.lastProject", state.project.public_id || nextId);
  renderWorkspace();
  renderProjectHeader();
  showProjectView("timeline");
  rebuildParticipantControls();
  renderTasks();
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
    placeholder: "Choose people or agents", ariaLabel: "Direct recipients", searchable: true, multiple: true, closeOnSelect: false,
    selected: state.draft.addressees,
    onChange(values) { state.draft.addressees = values.map(id); },
  });
  renderParticipants();
  if (state.components.timeline && state.messages.length) renderTimeline();
}

async function refreshParticipants(projectGeneration = state.generation) {
  if (state.mode !== "expanded" || projectGeneration !== state.generation || !selectedProjectId()) return;
  const participantsPayload = await request(`${API.participants}?${new URLSearchParams({ project_id: selectedProjectId(), status: "active" })}`, {
    signal: state.abortController?.signal,
  });
  if (projectGeneration !== state.generation) return;
  const currentParticipantId = id(state.project?.current_participant?.id);
  state.participants = (unwrap(participantsPayload) || []).map((participant) => participantFrom(participant, participant.kind));
  state.project.current_participant = state.participants.find((participant) => participant.id === currentParticipantId)
    || state.project.current_participant;
  const activeIds = new Set(state.participants.map((participant) => participant.id));
  state.draft.addressees = state.draft.addressees.filter((participantId) => activeIds.has(participantId));
  rebuildParticipantControls();
}

function scheduleForegroundParticipantRefresh() {
  clearTimeout(state.foregroundSyncTimer);
  state.foregroundSyncTimer = null;
  if (document.visibilityState === "hidden" || state.mode !== "expanded" || state.surface !== "project" || !selectedProjectId()) return;
  const projectGeneration = state.generation;
  state.foregroundSyncTimer = setTimeout(() => {
    state.foregroundSyncTimer = null;
    if (document.visibilityState === "hidden" || state.mode !== "expanded" || state.surface !== "project" || projectGeneration !== state.generation) return;
    void refreshParticipants(projectGeneration).catch(handleLoadError);
  }, 100);
}

function renderComposerControls() {
  const writable = state.mode === "expanded" && can("messages.write");
  el.new_message_trigger.hidden = !writable || !selectedProjectId();
  el.composer_shell.hidden = true;
  if (!writable) return;
  state.components.addressMode?.destroy();
  state.components.addressMode = state.factories.createSelect(el.address_mode, [
    { value: "direct", label: "Direct message" },
    { value: "broadcast", label: "Broadcast to project" },
  ], {
    searchable: false, clearable: false, ariaLabel: "Addressing mode", selected: state.draft.mode,
    onChange(value) {
      state.draft.mode = value === "broadcast" ? "broadcast" : "direct";
      syncAddressingControls();
    },
  });
  state.components.messageIntent?.destroy();
  state.components.messageIntent = state.factories.createSelect(el.message_intent, [
    { value: "update", label: "Update / FYI" },
    { value: "request", label: "Action request" },
  ], {
    searchable: false, clearable: false, ariaLabel: "Message intent", selected: state.draft.intent,
    onChange(value) {
      state.draft.intent = value === "request" ? "request" : "update";
    },
  });
  state.components.composer?.destroy();
  state.components.composer = state.factories.createChatComposer(el.composer_host, { value: "" }, {
    placeholder: "Write to the project timeline…",
    helperText: "Visible to all participants · Shift+Enter for a new line",
    showAttachmentButton: false,
    maxLength: Number(state.project?.message_max_length || 24000),
    onSend: sendMessage,
  });
  enableCompactComposerAutosize();
  syncAddressingControls();
  renderReplyContext();
}

function openMessageComposerModal() {
  if (state.mode !== "expanded" || !can("messages.write") || !selectedProjectId()) return;
  const existing = state.components.composerModal;
  if (existing?.getState?.().open) {
    state.components.composer?.focus();
    return;
  }
  el.composer_shell.hidden = false;
  const title = state.draft.replyTo ? "Reply to message" : "New message";
  let modal;
  modal = state.factories.createActionModal({
    title,
    size: "lg",
    className: "message-composer-modal",
    content: el.composer_shell,
    closeOnEscape: true,
    autoBusy: false,
    actions: [
      { id: "cancel", label: "Cancel" },
      {
        id: "send-message",
        label: "Send message",
        variant: "primary",
        icon: helperIconHtml("comms.message"),
        closeOnClick: false,
        busyMessage: "Sending message…",
        async onClick() {
          const input = el.composer_host.querySelector(".ui-chat-composer-input");
          await sendMessage({ text: input?.value || "" });
          return false;
        },
      },
    ],
    initialFocus: () => el.composer_host.querySelector(".ui-chat-composer-input"),
    onClose() {
      if (state.components.composerModal === modal) {
        el.composer_shell.hidden = true;
        state.components.composerModal = null;
      }
      modal.destroy();
    },
  });
  state.components.composerModal = modal;
  modal.open();
  queueMicrotask(() => state.components.composer?.focus());
}

function dismissMessageComposerModal() {
  const modal = state.components.composerModal;
  state.components.composerModal = null;
  el.composer_shell.hidden = true;
  modal?.destroy?.();
}

function enableCompactComposerAutosize() {
  const input = el.composer_host.querySelector(".ui-chat-composer-input");
  if (!input) return;
  const resize = () => {
    input.style.height = "140px";
    const nextHeight = Math.min(Math.max(input.scrollHeight, 140), 320);
    input.style.height = `${nextHeight}px`;
    input.style.overflowY = input.scrollHeight > 320 ? "auto" : "hidden";
  };
  if (input.dataset.compactAutosize !== "true") {
    input.dataset.compactAutosize = "true";
    input.addEventListener("input", resize);
  }
  resize();
}

function syncAddressingControls() {
  const hasAutomaticReplyRecipient = Boolean(state.draft.replyTo && state.draft.addressees.length);
  const broadcast = state.draft.mode === "broadcast";
  el.addressing_row.hidden = false;
  el.addressing_row.classList.toggle("is-reply", hasAutomaticReplyRecipient);
  el.address_mode.hidden = hasAutomaticReplyRecipient;
  el.message_intent.hidden = broadcast;
  el.addressee_select.hidden = broadcast || hasAutomaticReplyRecipient;
  el.broadcast_warning.hidden = hasAutomaticReplyRecipient || !broadcast;
}

function restoreNormalAddressing() {
  const previous = state.draft.preReplyAddressing;
  state.draft.replyTo = null;
  state.draft.preReplyAddressing = null;
  if (previous) {
    state.draft.mode = previous.mode;
    state.draft.intent = previous.intent;
    state.draft.addressees = [...previous.addressees];
    state.components.addressMode?.setValue(state.draft.mode);
    state.components.messageIntent?.setValue(state.draft.intent);
    state.components.addresseeSelect?.setValue(state.draft.addressees);
  }
  syncAddressingControls();
}

function setReply(message) {
  if (!state.draft.replyTo) {
    state.draft.preReplyAddressing = {
      mode: state.draft.mode,
      intent: state.draft.intent,
      addressees: [...state.draft.addressees],
    };
  }
  state.draft.replyTo = message;
  const currentParticipantId = id(state.project?.current_participant?.id);
  const senderId = id(message.sender?.id);
  const fallbackRecipients = (message.addressees || [])
    .map((entry) => id(entry.participant_id))
    .filter((participantId) => participantId && participantId !== currentParticipantId);
  state.draft.mode = "direct";
  state.draft.intent = "update";
  state.draft.addressees = senderId && senderId !== currentParticipantId ? [senderId] : fallbackRecipients;
  state.components.addressMode?.setValue("direct");
  state.components.messageIntent?.setValue("update");
  state.components.addresseeSelect?.setValue(state.draft.addressees);
  syncAddressingControls();
  renderReplyContext();
  openMessageComposerModal();
}

function renderReplyContext() {
  el.reply_context.replaceChildren();
  if (!state.draft.replyTo) { el.reply_context.hidden = true; return; }
  el.reply_context.hidden = false;
  const copy = document.createElement("span");
  copy.className = "reply-context-copy";
  copy.textContent = `Replying to ${state.draft.replyTo.sender.display_name}: ${state.draft.replyTo.body.slice(0, 160)}`;
  const cancel = actionButton("Cancel reply", () => { restoreNormalAddressing(); renderReplyContext(); });
  el.reply_context.append(copy, cancel);
}

async function sendMessage({ text }) {
  if (!String(text || "").trim()) {
    await state.factories.uiAlert("Write a message before sending.", {
      title: "Message required",
      variant: "warning",
    });
    state.components.composer?.focus();
    return false;
  }
  if (state.draft.mode === "direct" && !state.draft.addressees.length) {
    await state.factories.uiAlert("Select at least one recipient, or choose Broadcast.", {
      title: "Recipients required",
      variant: "warning",
      description: "Review the message addressing before sending.",
    });
    state.components.composer.focus();
    return false;
  }
  if (!state.draft.idempotencyKey) state.draft.idempotencyKey = makeIdempotencyKey();
  state.components.composerModal?.setBusy(true, { message: "Sending message…" });
  state.components.composer.setBusy(true);
  try {
    const body = {
      body: text.trim(),
      reply_to_message_id: state.draft.replyTo?.id || null,
      direct_participant_ids: state.draft.mode === "broadcast" ? [] : state.draft.addressees,
      mention_participant_ids: [],
      broadcast: state.draft.mode === "broadcast",
      action_requested: state.draft.mode === "direct" && state.draft.intent === "request",
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
    state.components.responsibilityInbox?.refreshFromRealtime(message.id);
    state.components.composer.clear();
    enableCompactComposerAutosize();
    state.draft.idempotencyKey = "";
    restoreNormalAddressing();
    state.draft.intent = "update";
    state.components.messageIntent?.setValue("update");
    renderReplyContext();
    await state.components.composerModal?.close({ reason: "sent" });
    return true;
  } catch (error) {
    if (error.status === 422) {
      await state.factories.uiAlert(error.message || "Review the message and try again.", {
        title: "Message needs attention",
        variant: "warning",
      });
    } else {
      state.components.toast.warn(error.message, { title: "Message not sent" });
    }
    return false;
  } finally {
    state.components.composer.setBusy(false);
    if (state.components.composerModal?.getState?.().open) state.components.composerModal.setBusy(false);
    if (state.components.composerModal?.getState?.().open) state.components.composer.focus();
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
    state.components.responsibilityInbox?.refreshFromRealtime(message.id);
    state.components.toast.success("Message acknowledged.");
  } catch (error) {
    state.components.toast.warn(error.message, { title: "Acknowledgement failed" });
  }
}

function showProjectView(view) {
  if (state.mode !== "expanded" || !["workspace", "project"].includes(state.surface) || !selectedProjectId()) return;
  state.projectView = view === "responsibility" ? "responsibility" : "timeline";
  const inbox = state.projectView === "responsibility";
  el.show_timeline.classList.toggle("is-active", !inbox);
  el.show_responsibility.classList.toggle("is-active", inbox);
  el.show_timeline.setAttribute("aria-pressed", String(!inbox));
  el.show_responsibility.setAttribute("aria-pressed", String(inbox));
  el.new_message_trigger.hidden = inbox || !can("messages.write");
  el.timeline_filter_bar.hidden = inbox;
  el.timeline_notice.hidden = inbox || !el.timeline_notice.textContent;
  el.timeline_scroll.hidden = inbox;
  el.responsibility_host.hidden = !inbox;
  if (!inbox && state.components.timeline) renderTimeline();
  if (inbox && !state.components.responsibilityInbox) {
    state.components.responsibilityInbox = createResponsibilityInbox(el.responsibility_host, {
      participants: () => state.participants,
      actorId: () => state.project?.current_participant?.id,
      moderator: () => ["owner", "admin"].includes(state.project?.current_participant?.role),
      newKey: makeIdempotencyKey,
      async fetchPage(view, before, changedByMessageId = null) {
        const query = new URLSearchParams({ project_id: selectedProjectId(), view, limit: "50" });
        if (before) query.set("before", before);
        if (changedByMessageId) query.set("changed_by_message_id", changedByMessageId);
        return request(`${API.responsibilityInbox}?${query}`);
      },
      async writeEvent(body, responsibilityEvent, key) {
        return request(`${API.messages}?${new URLSearchParams({ project_id: selectedProjectId() })}`, {
          method: "POST",
          headers: csrfHeaders({ "Idempotency-Key": key }),
          body: JSON.stringify({ body, idempotency_key: key, responsibility_event: responsibilityEvent }),
        });
      },
      async acknowledge(item) {
        const payload = await request(`${API.acknowledge}?${new URLSearchParams({ project_id: selectedProjectId(), id: item.request_message_id })}`, {
          method: "POST", headers: csrfHeaders(), body: JSON.stringify({}),
        });
        const responseMessage = unwrap(payload);
        if (responseMessage?.id) {
          const updated = normalizeMessage(responseMessage);
          state.messages = state.messages.map((entry) => entry.id === updated.id ? updated : entry);
        }
        state.components.toast.success("Message acknowledged.");
        return payload;
      },
      openMessage: (messageId) => void openResponsibilityMessage(messageId),
      linkedTasks: (messageId) => messageLinkedTasks(messageId),
      canCreateTask: () => state.mode === "expanded" && can("messages.write"),
      convertToTask: (item) => openCreateTaskModal({
        id: item.request_message_id,
        action_requested: true,
      }),
      openTask: (taskId) => void openTaskDetails(taskId),
      openLinkedTasks: (messageId) => openLinkedMessageTasks({ id: messageId }),
      openGuide: (articleId) => showGuideSurface(articleId),
    });
    void state.components.responsibilityInbox.load();
  }
}

function responsibilityMessageContent(message, parent = null) {
  const details = evidenceDetails(message, parent);
  const content = projectInfoElement("section", "responsibility-evidence-content");
  const facts = projectInfoElement("dl", "responsibility-evidence-facts");
  for (const [label, value] of [
    ["Position", details.sequence], ["Sender", details.sender], ["Time", details.created],
    ["Addressing", details.addressed], ["History", `${details.revision}; ${details.tombstone}`],
    ["Thread", details.reply],
  ]) facts.append(projectInfoElement("dt", "", label), projectInfoElement("dd", "", value));
  content.append(facts, projectInfoElement("div", "responsibility-evidence-body", details.body));
  return content;
}

function openResponsibilityMessage(messageId) {
  if (state.components.responsibilityMessageModal?.getState?.().open) return;
  const projectId = selectedProjectId();
  const generation = state.generation;
  let requestController = null;
  const loading = projectInfoElement("p", "responsibility-evidence-intro", "Loading original message…");
  const closeAction = { id: "close", label: "Back to Responsibility Inbox", variant: "primary" };
  const modal = state.factories.createActionModal({
    title: `Message #${messageId}`,
    size: "lg",
    className: "responsibility-message-modal",
    content: loading,
    actions: [closeAction],
    onClose() {
      requestController?.abort();
      if (state.components.responsibilityMessageModal === modal) state.components.responsibilityMessageModal = null;
    },
  });
  state.components.responsibilityMessageModal = modal;
  modal.open();

  const load = async () => {
    requestController?.abort();
    requestController = new AbortController();
    loading.textContent = "Loading original message…";
    modal.setContent(loading);
    modal.setActions([closeAction]);
    modal.setBusy(true, {
      message: "Loading original message…",
      cancelBusy: { label: "Cancel", onCancel: () => modal.close({ reason: "cancelled" }) },
    });
    try {
      const payload = await request(`${API.message}?${new URLSearchParams({ project_id: projectId, id: messageId })}`, { signal: requestController.signal });
      if (!modal.getState().open) return;
      if (generation !== state.generation || projectId !== selectedProjectId()) {
        await modal.close({ reason: "project-changed" });
        return;
      }
      const message = normalizeMessage(unwrap(payload));
      if (!message.id) throw new Error("The canonical message was unavailable.");
      const existing = state.messages.findIndex((entry) => entry.id === message.id);
      if (existing >= 0) state.messages[existing] = message;
      let parent = null;
      if (message.reply_to_message_id) {
        try {
          const parentPayload = await request(`${API.message}?${new URLSearchParams({ project_id: projectId, id: message.reply_to_message_id })}`, { signal: requestController.signal });
          if (!modal.getState().open) return;
          if (generation !== state.generation || projectId !== selectedProjectId()) {
            await modal.close({ reason: "project-changed" });
            return;
          }
          parent = normalizeMessage(unwrap(parentPayload));
        } catch (error) {
          if (requestController.signal.aborted) return;
          /* Keep the exact child visible when optional parent context is unavailable. */
        }
      }
      modal.setContent(responsibilityMessageContent(message, parent));
      modal.setActions([closeAction]);
      modal.setBusy(false);
    } catch (error) {
      if (requestController.signal.aborted || !modal.getState().open) return;
      modal.setBusy(false);
      loading.textContent = `Unable to load the original message. ${error.message || "Try again."}`;
      modal.setContent(loading);
      modal.setActions([
        { id: "retry", label: "Retry", closeOnClick: false, onClick() { void load(); return false; } },
        closeAction,
      ]);
    }
  };
  void load();
}
async function jumpToMessage(messageId) {
  if (!state.messages.some((message) => id(message.id) === id(messageId))) {
    state.components.toast.info("That message is outside the currently loaded timeline. Use search to locate it.", { title: "Message not loaded" });
    return;
  }
  state.components.timeline?.setCollapsed(messageId, false);
  const result = await state.components.timeline?.scrollToItem?.(messageId, { align: "center", focus: true });
  if (result?.found) return;
  const row = el.timeline_host.querySelector(`[data-item-id="${CSS.escape(id(messageId))}"]`);
  if (row) { row.scrollIntoView({ block: "center" }); row.focus(); }
  else if (result?.reason === "not-loaded") state.components.toast.info("That message is outside the currently loaded timeline. Use search to locate it.", { title: "Message not loaded" });
}

async function openSettings() {
  if (!capability("admin.settings", isAdministrator())) return;
  let settings;
  try { settings = unwrap(await request(API.settings))?.settings || {}; }
  catch (error) { state.components.toast.warn(error.message, { title: "Settings unavailable" }); return; }
  const value = (key, fallback = "") => settings[key]?.value ?? settings[key] ?? fallback;
  const configured = (key) => Boolean(settings[key]?.configured);
  const locked = (key) => Boolean(settings[key]?.locked);
  const backupBaseLocation = String(value("recovery.backup_base_path") || "").trim();
  const modal = state.factories.createFormModal({
    title: "System Settings",
    size: "lg",
    submitLabel: "Save settings",
    busyMessage: "Saving settings…",
    manageBusyOnSubmit: false,
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
      public_origin: value("general.public_origin"),
      message_max_length: value("messaging.max_message_bytes", 24000),
      backup_base_location: backupBaseLocation,
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
      self_registration_enabled: Boolean(value("security.self_registration_enabled", true)),
      google_enabled: Boolean(value("google.enabled", false)),
      google_client_id: value("google.client_id"),
      google_callback_url: value("google.callback_url") || new URL("auth/google-callback.php", document.baseURI).href,
      google_client_secret: "",
    },
    rows: [
      [{ type: "text", content: "General and messaging" }],
      [{ type: "input", name: "site_name", label: "Installation name", required: true, disabled: locked("general.installation_name") }, { type: "input", input: "url", name: "public_origin", label: "Public Syndicatum URL", placeholder: "https://syndicatum.example.com", required: true, disabled: locked("general.public_origin") }],
      [{ type: "input", input: "number", name: "message_max_length", label: "Maximum message length", min: 1000, required: true, disabled: locked("messaging.max_message_bytes") }],
      [{ type: "divider" }],
      [{ type: "text", content: "Backup storage" }],
      [{ type: "input", name: "backup_base_location", label: "Base location for generated backups", placeholder: "C:\\private\\syndicatum-backups", required: true, disabled: locked("recovery.backup_base_path") }],
      [{ type: "text", content: "Absolute directory on the Syndicatum server, outside the public web root. This is not a browser download folder." }],
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
      [{ type: "divider" }],
      [{ type: "text", content: "Optional Google sign-in" }],
      [{ type: "checkbox", name: "google_enabled", label: "Enable Google sign-in", disabled: locked("google.enabled") }],
      [{ type: "input", name: "google_client_id", label: "Google OAuth client ID", disabled: locked("google.client_id") }],
      [{ type: "input", input: "url", name: "google_callback_url", label: "Authorized redirect URI", disabled: locked("google.callback_url") }],
      [{ type: "input", input: "password", name: "google_client_secret", label: "Replace Google client secret", disabled: locked("google.client_secret"), placeholder: configured("google.client_secret") ? "Configured — leave blank to keep" : "Not configured" }],
      [{ type: "divider" }],
      [{ type: "text", content: "Human account registration" }],
      [{ type: "checkbox", name: "self_registration_enabled", label: "Allow people to register from the login form", disabled: locked("security.self_registration_enabled") }],
    ],
    async onSubmit(values, context) {
      const backupPath = String(values.backup_base_location || "").trim();
      const absoluteBackupPath = /^[A-Za-z]:[\\/]/.test(backupPath)
        || /^\\\\[^\\/]+[\\/][^\\/]+/.test(backupPath)
        || backupPath.startsWith("/");
      if (!absoluteBackupPath) {
        context.setErrors({ backup_base_location: "Enter an absolute server path, such as C:\\private\\syndicatum-backups or /srv/syndicatum/backups." });
        context.setFormError("Base location for generated backups must be an absolute server filesystem path.");
        return false;
      }
      const updates = {
        "general.installation_name": values.site_name,
        "general.public_origin": values.public_origin,
        "messaging.max_message_bytes": Number(values.message_max_length),
        "recovery.backup_base_path": backupPath,
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
        "security.self_registration_enabled": Boolean(values.self_registration_enabled),
        "google.enabled": Boolean(values.google_enabled),
        "google.client_id": values.google_client_id,
        "google.callback_url": values.google_callback_url,
      };
      Object.keys(updates).forEach((key) => { if (locked(key)) delete updates[key]; });
      if (values.realtime_signing_secret) updates["realtime.signing_secret"] = { operation: "replace", value: values.realtime_signing_secret };
      if (values.realtime_backend_ingress_secret) updates["realtime.backend_ingress_secret"] = { operation: "replace", value: values.realtime_backend_ingress_secret };
      if (values.account_client_secret) updates["account.client_secret"] = { operation: "replace", value: values.account_client_secret };
      if (values.google_client_secret) updates["google.client_secret"] = { operation: "replace", value: values.google_client_secret };
      context.setBusy(true, { message: "Saving settings…" });
      try {
        await request(API.settings, { method: "PATCH", headers: csrfHeaders(), body: JSON.stringify({ settings: updates }) });
        state.components.toast.success("System settings saved.");
        return true;
      } catch (error) {
        context.setFormError(error.message);
        return false;
      } finally {
        if (context.modal.getState().open) context.setBusy(false);
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
  const requestedRoute = currentApplicationRoute();
  if (!state.projects.length) {
    showApplication();
    if (requestedRoute.surface === "guide") showGuideSurface(requestedRoute.articleId, { historyMode: "replace" });
    else showWorkspaceSurface({ historyMode: "replace" });
    return;
  }
  showApplication();
  const requestedProject = requestedRoute.surface === "project"
    ? state.projects.find((project) => project.public_id === requestedRoute.projectId || project.id === requestedRoute.projectId)
    : null;
  if (requestedProject) {
    await switchProject(requestedProject.id, { initial: true, historyMode: "replace" });
  } else if (requestedRoute.surface === "guide") {
    showGuideSurface(requestedRoute.articleId, { historyMode: "replace" });
  } else if (["users", "agents", "audit", "templates", "delivery-health", "backup-restore"].includes(requestedRoute.surface)
      && (["templates", "delivery-health", "backup-restore"].includes(requestedRoute.surface)
        ? capability("admin.settings", isAdministrator()) : capability(`admin.${requestedRoute.surface}`))) {
    await showAdminSurface(requestedRoute.surface, { historyMode: "replace" });
  } else {
    const lastProject = readLocalPreference("syndicatum.workspace.lastProject", "");
    const initialProject = state.projects.find((project) => project.public_id === lastProject || project.id === lastProject) || state.projects[0];
    await switchProject(initialProject.id, { initial: true, historyMode: "replace" });
  }
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
  renderWorkspace();
  renderProjectHeader();
  rebuildParticipantControls();
  renderComposerControls();
  await loadMessages("initial");
  el.status_badge.textContent = "Legacy";
  setMobilePanel("timeline");
  showApplication();
}

async function checkSession() {
  try {
    const query = new URLSearchParams(location.search);
    const googleLinked = query.get("google_linked") === "1";
    const googleLinkError = query.get("google_link_error") || "";
    const googleSsoFailed = query.get("google_sso_error") === "1";
    const payload = await request(API.session);
    const session = { ...(unwrap(payload) || {}), capabilities: payload?.capabilities || unwrap(payload)?.capabilities || {} };
    if (session.setup_required) return loadLegacy();
    state.session = session;
    if (session.authenticated === false || !session.user) {
      const accountFailed = query.get("account_sso_error") === "1";
      const googleFailed = googleSsoFailed;
      showLogin(accountFailed ? "PBB Account sign in could not be completed. You can try again or use native sign in."
        : (googleFailed ? "Google sign in could not be completed. You can try again or use another sign-in method." : ""));
      return;
    }
    const returnPath = requestedReturnPath();
    if (returnPath) { location.replace(returnPath); return; }
    state.mode = "expanded";
    await loadExpanded();
    if (googleLinked) state.components.toast.success("Your Google account is linked and its profile photo is synchronized.");
    else if (googleLinkError) state.components.toast.error(
      googleLinkError.includes("already_linked") ? "That Google account is already linked." : "Google linking expired. Please try again."
    );
    else if (googleSsoFailed) state.components.toast.error("Google authentication could not be completed. Please try again.");
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
  state.realtimeRooms = new Set();
  socket?.close?.();
}

function messageMatchesFilters(message) {
  const ownId = id(state.project?.current_participant?.id);
  if (state.filters.sender && message.sender.id !== state.filters.sender) return false;
  if (state.filters.q && !message.body.toLocaleLowerCase().includes(state.filters.q.toLocaleLowerCase())) return false;
  const day = localDateKey(message.created_at);
  if (state.filters.from && day < state.filters.from) return false;
  if (state.filters.to && day > state.filters.to) return false;
  const addressed = message.addressees.some((entry) => entry.participant_id === ownId);
  if (state.filters.primary === "addressed" && !addressed) return false;
  if (state.filters.primary === "unacknowledged" && (!addressed || message.current_participant_state?.acknowledged_at)) return false;
  return true;
}

function receiveRealtimeMessage(source) {
  const message = normalizeMessage(source);
  if (!message.id) return;
  state.components.responsibilityInbox?.refreshFromRealtime(message.id);
  if (state.messages.some((entry) => entry.id === message.id)) return;
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
  const projectRealtime = state.project?.capabilities?.realtime;
  const backupOnly = !state.project && isAdministrator();
  if (!backupOnly && !projectRealtime?.enabled) {
    state.realtimeError = "PBB Realtime is disabled for this project.";
    el.status_badge.textContent = "Realtime unavailable";
    window.dispatchEvent(new CustomEvent("syndicatum:realtime-error", { detail: { message: state.realtimeError } }));
    startPolling();
    return;
  }
  clearTimeout(state.pollingTimer);
  state.pollingTimer = null;
  const attempt = ++state.realtimeGeneration;
  try {
    const admissionPath = applicationResourceUrl(backupOnly
      ? "api/v1/realtime-admission.php"
      : projectRealtime.admission_url || `api/v1/realtime-admission.php?project_id=${encodeURIComponent(selectedProjectId())}`);
    const admission = unwrap(await request(admissionPath));
    if (!admission?.enabled) {
      state.realtimeError = "PBB Realtime admission is disabled.";
      el.status_badge.textContent = "Polling";
      window.dispatchEvent(new CustomEvent("syndicatum:realtime-error", { detail: { message: state.realtimeError } }));
      if (!backupOnly) startPolling();
      return;
    }
    const rooms = Array.from(new Set([
      ...(Array.isArray(admission?.rooms) ? admission.rooms : []),
      admission?.room,
    ].filter(Boolean)));
    if (!admission.token || !admission.websocket_url || rooms.length === 0) throw new Error("Realtime admission is incomplete.");
    const websocketUrl = new URL(admission.websocket_url, window.location.href);
    if (window.location.protocol === "https:" && websocketUrl.protocol !== "wss:") {
      const error = new Error("Realtime is configured with an insecure WebSocket endpoint. An HTTPS page requires wss://.");
      error.realtimeConfiguration = true;
      throw error;
    }
    const sdkUrl = applicationResourceUrl(projectRealtime?.sdk_module_url || "vendor/pbb-realtime/js/sdk/index.js");
    const sdk = await import(sdkUrl);
    let joinRequested = false;
    const joinedRooms = new Set();
    let client;
    const joinTimeout = setTimeout(() => {
      if (joinedRooms.size !== rooms.length) client?.close?.();
    }, 15000);
    const handleMessage = (raw) => {
      let envelope;
      try { envelope = sdk.parseRealtimeEnvelope(raw); } catch (_error) { return; }
      if (envelope?.phase === "ack" && envelope?.type === "session.auth.request") {
        if (!joinRequested) {
          joinRequested = true;
          rooms.forEach((room) => client.sendRequest("room.join.request", room, sdk.buildRoomJoinPayload()));
        }
        return;
      }
      if (envelope?.phase === "ack" && envelope?.type === "room.join.request") {
        const joinedRoom = String(envelope?.room || envelope?.payload?.room || "");
        if (joinedRoom) joinedRooms.add(joinedRoom);
        if (joinedRooms.size !== rooms.length) return;
        clearTimeout(joinTimeout);
        state.realtimeRetryTimer = null;
        state.realtimeRetryCount = 0;
        state.realtimeRooms = new Set(joinedRooms);
        state.realtimeError = null;
        clearTimeout(state.pollingTimer);
        state.pollingTimer = null;
        el.status_badge.textContent = "Realtime";
        window.dispatchEvent(new CustomEvent("syndicatum:realtime-ready", { detail: { rooms: [...joinedRooms] } }));
        if (state.project && joinedRooms.has(admission.room)) {
          void Promise.all([loadMessages("newer", projectGeneration), loadTasks(projectGeneration)]).catch(handleLoadError);
        }
        return;
      }
      if (envelope?.phase === "error") {
        client.close();
        return;
      }
      if (envelope?.phase === "event" && envelope.type === "syndicatum.message.created" && envelope.payload?.message) {
        receiveRealtimeMessage(envelope.payload.message);
        return;
      }
      if (envelope?.phase === "event" && envelope.type === "syndicatum.backup.updated" && envelope.payload?.backup) {
        window.dispatchEvent(new CustomEvent("syndicatum:backup-updated", { detail: { backup: envelope.payload.backup } }));
        return;
      }
      if (envelope?.phase === "event" && envelope.type === "syndicatum.restore.updated" && envelope.payload?.restore) {
        window.dispatchEvent(new CustomEvent("syndicatum:restore-updated", { detail: { restore: envelope.payload.restore } }));
        return;
      }
      if (envelope?.phase === "event" && envelope.type === "syndicatum.participants.changed") {
        void refreshParticipants(projectGeneration).catch(handleLoadError);
        return;
      }
      if (envelope?.phase === "event" && envelope.type === "syndicatum.task.updated") {
        if (envelope.payload?.task) receiveRealtimeTask(envelope.payload.task);
        else void loadTasks(projectGeneration).catch(handleLoadError);
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
        state.realtimeRooms = new Set();
        state.realtimeError = "The PBB Realtime connection closed.";
        window.dispatchEvent(new CustomEvent("syndicatum:realtime-error", { detail: { message: state.realtimeError } }));
        el.status_badge.textContent = "Realtime reconnecting";
        scheduleRealtimeReconnect(projectGeneration);
      },
    });
    state.realtimeSocket = client;
    client.connect();
  } catch (error) {
    if (attempt !== state.realtimeGeneration || projectGeneration !== state.generation) return;
    console.warn("[Syndicatum] Realtime connection attempt failed.", error);
    state.realtimeRooms = new Set();
    state.realtimeError = error?.message || "PBB Realtime connection failed.";
    window.dispatchEvent(new CustomEvent("syndicatum:realtime-error", { detail: { message: state.realtimeError } }));
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
    try { await Promise.all([loadMessages("newer"), refreshParticipants(), loadTasks()]); } catch (_error) { el.status_badge.textContent = "Reconnect needed"; }
    finally { state.pollingTimer = setTimeout(tick, 15000); }
  };
  state.pollingTimer = setTimeout(tick, 15000);
}

async function bootstrap() {
  const options = { css: false };
  const names = ["ui.navbar", "ui.search", "ui.timeline", "ui.toast", "ui.busy.overlay", "ui.icons", "ui.select", "ui.toggle.group", "ui.chat.composer", "ui.action.modal", "ui.form.modal", "ui.form.modal.login", "ui.dialog.alert", "ui.dialog.confirm", "ui.progress", "ui.grid", "ui.dropdown", "ui.popover", "ui.splitter", "ui.navigation.stack"];
  await uiLoader.loadMany(names, options);
  const iconModule = await uiLoader.get("ui.icons", options);
  try {
    if (typeof iconModule.registerIconPack === "function") {
      iconModule.registerIconPack(AI_ICONS);
      state.aiIconPackAvailable = true;
    } else {
      console.warn("[Syndicatum] Helper icon pack API is unavailable; using core agent markers.");
    }
  } catch (error) {
    console.warn("[Syndicatum] Helper AI icon pack could not be registered; using core agent markers.", error);
  }
  state.factories = {
    createNavbar: await uiLoader.get("ui.navbar", options),
    createIcon: iconModule.createIcon,
    createSearchField: await uiLoader.get("ui.search", options),
    createTimeline: await uiLoader.get("ui.timeline", options),
    createToastStack: await uiLoader.get("ui.toast", options),
    createBusyOverlay: await uiLoader.get("ui.busy.overlay", options),
    createSelect: await uiLoader.get("ui.select", options),
    createToggleGroup: await uiLoader.get("ui.toggle.group", options),
    createChatComposer: await uiLoader.get("ui.chat.composer", options),
    createActionModal: await uiLoader.get("ui.action.modal", options),
    createFormModal: await uiLoader.get("ui.form.modal", options),
    createLoginFormModal: await uiLoader.get("ui.form.modal.login", options),
    uiAlert: await uiLoader.get("ui.dialog.alert", options),
    uiConfirm: await uiLoader.get("ui.dialog.confirm", options),
    createProgress: await uiLoader.get("ui.progress", options),
    createGrid: await uiLoader.get("ui.grid", options),
    createDropdown: await uiLoader.get("ui.dropdown", options),
    createPopover: await uiLoader.get("ui.popover", options),
    createTabs: await uiLoader.get("ui.tabs", options),
    createFileUploader: await uiLoader.get("ui.file.uploader", options),
    createDataInspector: await uiLoader.get("ui.data.inspector", options),
    createSplitter: await uiLoader.get("ui.splitter", options),
    createNavigationStack: await uiLoader.get("ui.navigation.stack", options),
  };
  state.components.toast = state.factories.createToastStack({ position: "bottom-right", defaultDuration: 3200, max: 4 });
  el.project_actions_icon.innerHTML = helperIconHtml("actions.more-horizontal", 18);
  el.new_message_icon.innerHTML = helperIconHtml("actions.add", 18);
  el.project_list_actions_icon.innerHTML = helperIconHtml("actions.more-horizontal", 18);
  el.team_actions_icon.innerHTML = helperIconHtml("actions.more-horizontal", 18);
  el.filter_icon.innerHTML = helperIconHtml("data.filter", 18);
  el.refresh_icon.innerHTML = helperIconHtml("actions.refresh", 18);
  updateTimelineCollapseButton();
  el.new_message_trigger.addEventListener("click", openMessageComposerModal);
  el.show_timeline.addEventListener("click", () => showProjectView("timeline"));
  el.show_responsibility.addEventListener("click", () => showProjectView("responsibility"));
  mountWorkspaceSplitters();
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
  state.components.filterPopover = state.factories.createPopover(el.filter_popover_trigger, {
    placement: "bottom-end",
    panelRole: "dialog",
    ariaLabel: "Timeline filters",
    className: "syndicatum-filter-popover",
    initialFocus: "first",
    content(host) {
      el.filter_popover_content.hidden = false;
      host.appendChild(el.filter_popover_content);
      return el.filter_popover_content;
    },
    onOpenChange(open) { el.filter_popover_trigger.classList.toggle("is-open", open); },
  });
  el.date_from.addEventListener("change", () => { state.filters.from = el.date_from.value; void reloadForFilters(); });
  el.date_to.addEventListener("change", () => { state.filters.to = el.date_to.value; void reloadForFilters(); });
  el.clear_filters.addEventListener("click", () => {
    state.filters = { ...state.filters, primary: "all", sender: "", from: "", to: "" };
    el.date_from.value = ""; el.date_to.value = "";
    state.components.primaryFilter.setPressed("all", true);
    state.components.senderSelect?.setValue(null);
    void reloadForFilters();
  });
  el.refresh_button.addEventListener("click", () => void reloadForFilters());
  el.timeline_collapse_toggle.addEventListener("click", () => setAllMessagesCollapsed(!state.timelineDefaultCollapsed));
  el.participant_search.addEventListener("input", () => { state.participantSearch = el.participant_search.value.trim(); renderParticipants(); });
  el.new_task_trigger.addEventListener("click", () => openCreateTaskModal());
  el.task_guide_trigger.addEventListener("click", () => showGuideSurface("tasks-overview"));
  el.task_status_filter.addEventListener("change", () => { state.taskFilter = el.task_status_filter.value || "active"; renderTasks(); });
  el.admin_refresh_button.addEventListener("click", () => void showAdminSurface(state.adminKind, { historyMode: "none" }));
  document.addEventListener("visibilitychange", scheduleForegroundParticipantRefresh);
  addEventListener("focus", scheduleForegroundParticipantRefresh);
  matchMedia(WORKSPACE_MOBILE_QUERY).addEventListener("change", mountNavbar);
  addEventListener("popstate", () => {
    if (state.mode !== "expanded") return;
    const route = currentApplicationRoute();
    const routeProject = route.surface === "project"
      ? state.projects.find((project) => project.public_id === route.projectId || project.id === route.projectId)
      : null;
    if (routeProject) {
      void switchProject(routeProject.id, { initial: true, historyMode: "none" }).catch(handleLoadError);
    } else if (route.surface === "guide") {
      showGuideSurface(route.articleId, { historyMode: "none" });
    } else if (["users", "agents", "audit", "templates", "delivery-health", "backup-restore"].includes(route.surface)
        && (["templates", "delivery-health", "backup-restore"].includes(route.surface)
          ? capability("admin.settings", isAdministrator()) : capability(`admin.${route.surface}`))) {
      void showAdminSurface(route.surface, { historyMode: "none" });
    } else {
      showWorkspaceSurface({ historyMode: "none" });
    }
  });
  await checkSession();
  if (state.mode === "legacy" && !state.realtimeSocket) startPolling();
}

bootstrap().catch((error) => {
  document.body.innerHTML = `<main class="fatal-error"><h1>Syndicatum could not start</h1><p>${String(error.message || error).replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char])}</p></main>`;
});
