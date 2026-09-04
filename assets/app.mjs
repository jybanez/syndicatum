import { uiLoader } from "../vendor/pbb-helper/js/ui/ui.loader.js";

const state = {
  payload: null,
  filteredMessages: [],
  selectedMessageId: null,
  search: "",
  directOnly: false,
  participant: "",
  activityProject: "",
  activityDate: "",
  etag: "",
  pollingHandle: null,
  mobilePanel: "summary",
  summaryTabId: "projects",
  components: {},
};

const elements = {
  searchMount: document.getElementById("search-mount"),
  directToggle: document.getElementById("direct-toggle"),
  refreshButton: document.getElementById("refresh-button"),
  statusBadge: document.getElementById("status-badge"),
  metaStats: document.getElementById("meta-stats"),
  summaryTabsHost: document.getElementById("summary-tabs-host"),
  activityHost: document.getElementById("activity-host"),
  activityClear: document.getElementById("activity-clear"),
  timelineCount: document.getElementById("timeline-count"),
  timelineHost: document.getElementById("timeline-host"),
  panelButtons: Array.from(document.querySelectorAll("[data-panel-button]")),
  panels: Array.from(document.querySelectorAll("[data-panel]")),
};

const SUMMARY_TAB_ICONS = {
  projects: "data.grid",
  topics: "status.info",
  participants: "data.tree",
};

const STAT_ICONS = {
  Messages: "media.audio",
  Direct: "navigation.arrow-right",
  Participants: "data.tree",
  Days: "status.info",
};

function iconMarkup(name, options = {}) {
  if (!state.factories?.createIcon || !name) {
    return "";
  }

  try {
    return state.factories.createIcon(name, options).outerHTML;
  } catch (_error) {
    return "";
  }
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function formatDayLabel(dayKey) {
  const date = new Date(`${dayKey}T00:00:00`);
  if (Number.isNaN(date.getTime())) {
    return dayKey;
  }

  return new Intl.DateTimeFormat(undefined, {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
  }).format(date);
}

function shiftDay(dayKey, offset) {
  const date = new Date(`${dayKey}T00:00:00Z`);
  if (Number.isNaN(date.getTime())) {
    return dayKey;
  }
  date.setUTCDate(date.getUTCDate() + offset);
  return date.toISOString().slice(0, 10);
}

function getActivityDates(messages) {
  const latestDay = messages.reduce((latest, message) => {
    if (!message.day_key) {
      return latest;
    }
    return !latest || message.day_key > latest ? message.day_key : latest;
  }, "");

  if (!latestDay) {
    return [];
  }

  return Array.from({ length: 7 }, (_item, index) => shiftDay(latestDay, index - 6));
}

function buildActivityRecords() {
  if (!state.payload) {
    return { records: [], dates: [], projects: [] };
  }

  const projectNames = state.payload.projects.map((project) => project.name);
  const projectSet = new Set(projectNames);
  const dates = getActivityDates(state.payload.messages);
  const dateSet = new Set(dates);
  const buckets = new Map();

  state.payload.messages.forEach((message) => {
    if (!projectSet.has(message.sender) || !dateSet.has(message.day_key)) {
      return;
    }

    const key = `${message.sender}\u0000${message.day_key}`;
    const bucket = buckets.get(key) || {
      date: message.day_key,
      project: message.sender,
      total: 0,
      direct: 0,
      broadcast: 0,
      targeted: 0,
      targets: {},
    };

    bucket.total += 1;
    if (message.is_direct) {
      bucket.direct += 1;
      bucket.targeted += 1;
      const targets = Array.isArray(message.targets) && message.targets.length ? message.targets : (message.target ? [message.target] : []);
      targets.forEach((target) => {
        if (target) {
          bucket.targets[target] = (bucket.targets[target] || 0) + 1;
        }
      });
    } else {
      bucket.broadcast += 1;
    }

    buckets.set(key, bucket);
  });

  const todayKey = dates.at(-1) || "";
  const getProjectMetric = (project, date, metric = "total") => {
    return buckets.get(`${project}\u0000${date}`)?.[metric] || 0;
  };
  const getProjectWindowTotal = (project) => {
    return dates.reduce((sum, date) => sum + getProjectMetric(project, date), 0);
  };
  const activeProjects = projectNames
    .filter((project) => {
      return dates.some((date) => buckets.has(`${project}\u0000${date}`));
    })
    .sort((left, right) => {
      const todayDelta = getProjectMetric(right, todayKey) - getProjectMetric(left, todayKey);
      if (todayDelta !== 0) {
        return todayDelta;
      }

      const windowDelta = getProjectWindowTotal(right) - getProjectWindowTotal(left);
      if (windowDelta !== 0) {
        return windowDelta;
      }

      return left.localeCompare(right);
    });

  return {
    records: Array.from(buckets.values()),
    dates,
    projects: activeProjects,
  };
}

function setMobilePanel(panelName) {
  state.mobilePanel = panelName;
  elements.panelButtons.forEach((button) => {
    button.classList.toggle("is-active", button.dataset.panelButton === panelName);
  });
  elements.panels.forEach((panel) => {
    panel.classList.toggle("is-mobile-active", panel.dataset.panel === panelName);
  });
}

function matchesSearch(message, term) {
  if (!term) {
    return true;
  }

  const haystack = [
    message.sender,
    message.target || "",
    ...(Array.isArray(message.targets) ? message.targets : []),
    message.body,
    message.timestamp,
    message.day_key,
  ]
    .join("\n")
    .toLowerCase();

  return haystack.includes(term.toLowerCase());
}

function senderColor(senderName) {
  if (!state.payload) {
    return "8db8ff";
  }

  const participant = state.payload.participants.find((item) => item.name === senderName);
  return participant?.color_seed || "8db8ff";
}

function ensureSelection() {
  if (!state.filteredMessages.some((message) => message.id === state.selectedMessageId)) {
    state.selectedMessageId = state.filteredMessages.at(-1)?.id || null;
  }
}

function renderMeta() {
  const meta = state.payload.meta;
  const lastUpdated = new Date(meta.last_modified_iso);
  const statusLabel = state.directOnly ? "Filtered" : "Live";
  elements.statusBadge.textContent = `${statusLabel} · ${lastUpdated.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`;

  const stats = [
    { id: "messages", value: meta.message_count, label: "Messages", icon: STAT_ICONS.Messages, tone: "info" },
    { id: "direct", value: meta.direct_count, label: "Direct", icon: STAT_ICONS.Direct, tone: "neutral" },
    { id: "participants", value: meta.participant_count, label: "Participants", icon: STAT_ICONS.Participants, tone: "success" },
    { id: "days", value: meta.day_count, label: "Days", icon: STAT_ICONS.Days, tone: "warning" },
  ];

  if (!state.components.statCards) {
    state.components.statCards = state.factories.createStatCards(elements.metaStats, stats, {
      ariaLabel: "Feed status metrics",
      columns: "2",
      size: "sm",
      chrome: false,
    });
  } else {
    state.components.statCards.update(stats, {
      columns: "2",
      size: "sm",
      chrome: false,
    });
  }

  elements.timelineCount.textContent = `${state.filteredMessages.length} visible`;
}

function renderActivityChart() {
  if (!state.payload || !elements.activityHost) {
    return;
  }

  const { records, dates, projects } = buildActivityRecords();
  const options = {
    ariaLabel: "Project activity over the last 7 days",
    mode: "matrix",
    metric: "total",
    projectOrder: "input",
    projects,
    dates,
    selectedProject: state.activityProject,
    selectedDate: state.activityDate,
    emptyText: "No project activity in the last 7 days.",
    onSelect(selection) {
      state.activityProject = selection.project || "";
      state.activityDate = selection.date && selection.summary?.total ? selection.date : "";
      applyFiltersAndRender();
      if (window.matchMedia("(max-width: 1180px)").matches) {
        setMobilePanel("timeline");
      }
    },
  };

  if (!state.components.activityChart) {
    state.components.activityChart = state.factories.createActivityChart(elements.activityHost, records, options);
  } else {
    state.components.activityChart.update(records, options);
  }

  if (elements.activityClear) {
    elements.activityClear.hidden = !state.activityProject && !state.activityDate;
  }
}

function renderProjectsPanel(panel) {
  panel.className = "summary-panel";
  panel.innerHTML = state.payload.projects
    .map((project) => `<article class="summary-card"><div class="summary-card-title">${iconMarkup("data.grid", { size: 16, title: "Project" })}<strong>${escapeHtml(project.name)}</strong></div><p>${escapeHtml(project.summary || "No summary provided.")}</p></article>`)
    .join("");
}

function renderTopicsPanel(panel) {
  panel.className = "summary-panel";
  panel.innerHTML = state.payload.active_topics
    .map((topic) => `<article class="summary-card is-topic"><div class="summary-card-title">${iconMarkup("status.info", { size: 16, title: "Active topic" })}<strong>Active Topic</strong></div><p>${escapeHtml(topic)}</p></article>`)
    .join("");
}

function renderParticipantsPanel(panel) {
  panel.className = "summary-panel";
  const clearAction = state.participant
    ? `<div class="summary-action-row"><button type="button" class="ui-button ui-button-borderless" data-clear-participant="true">Clear active participant filter</button></div>`
    : "";

  panel.innerHTML = `${clearAction}${state.payload.participants
    .map((participant) => {
      const isActive = participant.name === state.participant;
      return `<button type="button" class="participant-card ${isActive ? "is-active" : ""}" data-participant="${escapeHtml(participant.name)}"><div class="message-header"><span class="sender-chip"><span class="participant-dot" style="background:#${escapeHtml(participant.color_seed)}"></span>${iconMarkup("data.tree", { size: 14, title: "Participant" })}<strong>${escapeHtml(participant.name)}</strong></span></div><p>${escapeHtml(participant.message_count)} sent · ${escapeHtml(participant.sent_direct_count)} direct sent · ${escapeHtml(participant.received_direct_count)} direct received</p></button>`;
    })
    .join("")}`;

  panel.querySelectorAll("[data-participant]").forEach((button) => {
    button.addEventListener("click", () => {
      state.participant = button.dataset.participant || "";
      applyFiltersAndRender();
      if (window.matchMedia("(max-width: 1180px)").matches) {
        setMobilePanel("timeline");
      }
    });
  });

  const clearButton = panel.querySelector("[data-clear-participant]");
  if (clearButton) {
    clearButton.addEventListener("click", () => {
      state.participant = "";
      applyFiltersAndRender();
    });
  }
}

function renderSummaryTabs() {
  const tabs = [
    {
      id: "projects",
      label: `Projects (${state.payload.projects.length})`,
      render(panel) {
        renderProjectsPanel(panel);
      },
    },
    {
      id: "topics",
      label: `Topics (${state.payload.active_topics.length})`,
      render(panel) {
        renderTopicsPanel(panel);
      },
    },
    {
      id: "participants",
      label: `Participants (${state.payload.participants.length})`,
      render(panel) {
        renderParticipantsPanel(panel);
      },
    },
  ];

  if (!state.components.summaryTabs) {
    state.components.summaryTabs = state.factories.createTabs(elements.summaryTabsHost, {
      ariaLabel: "Chat summary tabs",
      tabs,
      activeId: state.summaryTabId,
      onChange(tab) {
        state.summaryTabId = tab?.id || "projects";
      },
    });
    decorateSummaryTabs();
    return;
  }

  state.components.summaryTabs.update(tabs, { activeId: state.summaryTabId });
  decorateSummaryTabs();
}

function toTimelineItems(messages) {
  return messages.map((message) => ({
    id: message.id,
    title: message.sender,
    subtitle: message.target ? `Directed to ${message.target}` : "Broadcast update",
    description: message.body,
    timestamp: `${message.day_key}T${message.timestamp.slice(11).replace(" ", "")}`,
    status: message.is_direct ? "requested" : "accepted",
    meta: [message.timestamp, `#${message.index}`],
    iconHtml: iconMarkup(message.is_direct ? "navigation.arrow-right" : "status.info", {
      size: 12,
      decorative: true,
    }),
  }));
}

function decorateSummaryTabs() {
  elements.summaryTabsHost.querySelectorAll(".ui-tab").forEach((tab) => {
    const tabId = tab.id.split("-tab-").pop();
    const iconName = SUMMARY_TAB_ICONS[tabId];
    if (!iconName) {
      return;
    }

    const label = tab.textContent || "";
    tab.innerHTML = `${iconMarkup(iconName, { size: 14, title: label })}<span>${escapeHtml(label)}</span>`;
  });
}

function decorateTimelineRows() {
  elements.timelineHost.querySelectorAll(".ui-timeline-item").forEach((row) => {
    const message = state.filteredMessages.find((item) => item.id === row.dataset.itemId);
    if (!message) {
      return;
    }

    row.classList.toggle("is-selected", message.id === state.selectedMessageId);
    row.classList.toggle("is-direct", Boolean(message.is_direct));
    row.classList.toggle("is-broadcast", !message.is_direct);

    const title = row.querySelector(".ui-timeline-title");
    if (title) {
      title.innerHTML = `<span class="sender-chip"><span class="sender-dot" style="background:#${escapeHtml(senderColor(message.sender))}"></span>${iconMarkup(message.is_direct ? "navigation.arrow-right" : "status.info", { size: 14, title: message.is_direct ? "Direct message" : "Broadcast message" })}${escapeHtml(message.sender)}</span>`;
    }

    const subtitle = row.querySelector(".ui-timeline-subtitle");
    if (subtitle) {
      const badge = message.is_direct
        ? '<span class="selection-badge is-direct">Direct</span>'
        : '<span class="selection-badge is-broadcast">Broadcast</span>';
      const targetLabel = message.target
        ? `<span class="message-target">to ${escapeHtml(message.target)}</span>`
        : '<span class="message-target">for everyone</span>';
      subtitle.innerHTML = `${targetLabel} ${badge}`;
    }

    const meta = row.querySelector(".ui-timeline-meta");
    if (meta) {
      meta.innerHTML = "";
      const chips = [
        `<span class="ui-timeline-tag">${escapeHtml(formatDayLabel(message.day_key))}</span>`,
        `<span class="ui-timeline-tag">${escapeHtml(message.timestamp)}</span>`,
        `<span class="ui-timeline-tag">#${escapeHtml(message.index)}</span>`,
      ];
      meta.innerHTML = chips.join("");
    }
  });
}

function renderTimeline() {
  const items = toTimelineItems(state.filteredMessages);
  if (!state.components.timeline) {
    state.components.timeline = state.factories.createTimeline(elements.timelineHost, items, {
      ariaLabel: "Chat log timeline",
      groupByDate: true,
      emptyText: "No messages match the current filters.",
      onItemClick(item) {
        state.selectedMessageId = item.id;
        decorateTimelineRows();
      },
    });
  } else {
    state.components.timeline.update(items, {
      emptyText: "No messages match the current filters.",
    });
  }

  decorateTimelineRows();
}
function applyFiltersAndRender() {
  if (!state.payload) {
    return;
  }

  state.filteredMessages = state.payload.messages.filter((message) => {
    if (state.directOnly && !message.is_direct) {
      return false;
    }
    const targets = Array.isArray(message.targets) ? message.targets : (message.target ? [message.target] : []);
    if (state.participant && message.sender !== state.participant && !targets.includes(state.participant)) {
      return false;
    }
    if (state.activityProject && message.sender !== state.activityProject) {
      return false;
    }
    if (state.activityDate && message.day_key !== state.activityDate) {
      return false;
    }
    return matchesSearch(message, state.search);
  });

  ensureSelection();
  renderMeta();
  renderSummaryTabs();
  renderActivityChart();
  renderTimeline();
}

async function loadPayload({ force = false } = {}) {
  elements.statusBadge.textContent = force ? "Refreshing" : "Loading";
  const previousCount = state.payload?.meta?.message_count || 0;
  const headers = {};
  if (state.etag && !force) {
    headers["If-None-Match"] = state.etag;
  }

  const response = await fetch("api/chat-log.php", {
    headers,
    cache: "no-store",
  });

  if (response.status === 304) {
    elements.statusBadge.textContent = "Live · unchanged";
    return;
  }

  if (!response.ok) {
    throw new Error(`Request failed with status ${response.status}`);
  }

  state.etag = response.headers.get("ETag") || "";
  state.payload = await response.json();
  applyFiltersAndRender();

  if (previousCount && state.payload.meta.message_count > previousCount) {
    const delta = state.payload.meta.message_count - previousCount;
    state.components.toast.info(`${delta} new message${delta === 1 ? "" : "s"} loaded`, {
      title: "Chat log updated",
    });
  }
}

async function refreshLoop() {
  try {
    await loadPayload();
  } catch (error) {
    elements.statusBadge.textContent = "Update error";
    if (state.components.toast) {
      state.components.toast.warn(error.message || "Unable to refresh the chat log.", {
        title: "Refresh failed",
      });
    }
  } finally {
    window.clearTimeout(state.pollingHandle);
    state.pollingHandle = window.setTimeout(() => {
      void refreshLoop();
    }, 15000);
  }
}

async function bootstrap() {
  uiLoader.setPreferBundles(true);
  const helperNames = ["ui.search", "ui.timeline", "ui.activity.chart", "ui.tabs", "ui.toast", "ui.icons", "ui.stat.cards"];
  const helperLoadOptions = { css: false };
  await uiLoader.loadMany(helperNames, helperLoadOptions);

  state.factories = {
    createSearchField: await uiLoader.get("ui.search", helperLoadOptions),
    createTimeline: await uiLoader.get("ui.timeline", helperLoadOptions),
    createActivityChart: await uiLoader.get("ui.activity.chart", helperLoadOptions),
    createTabs: await uiLoader.get("ui.tabs", helperLoadOptions),
    createToastStack: await uiLoader.get("ui.toast", helperLoadOptions),
    createIcon: (await uiLoader.get("ui.icons", helperLoadOptions)).createIcon,
    createStatCards: await uiLoader.get("ui.stat.cards", helperLoadOptions),
  };

  state.components.toast = state.factories.createToastStack({
    position: "bottom-right",
    defaultDuration: 2600,
    max: 4,
  });

  const searchField = state.factories.createSearchField({
    classPrefix: "ui-search",
    placeholder: "Search messages, projects, paths, or endpoints",
    clearText: "Clear",
    inputClass: "ui-input",
    onChange(value) {
      state.search = value.trim();
      applyFiltersAndRender();
    },
  });

  elements.searchMount.append(searchField.wrap);
  searchField.bind({
    on(target, eventName, handler) {
      target.addEventListener(eventName, handler);
    },
  });

  elements.directToggle.addEventListener("click", () => {
    state.directOnly = !state.directOnly;
    elements.directToggle.setAttribute("aria-pressed", String(state.directOnly));
    applyFiltersAndRender();
  });
  elements.directToggle.innerHTML = `${iconMarkup("navigation.arrow-right", { size: 14, title: "Direct only" })}<span>Direct Only</span>`;
  elements.refreshButton.innerHTML = `${iconMarkup("actions.download", { size: 14, title: "Refresh" })}<span>Refresh</span>`;

  elements.refreshButton.addEventListener("click", () => {
    void loadPayload({ force: true });
  });

  elements.activityClear.addEventListener("click", () => {
    state.activityProject = "";
    state.activityDate = "";
    applyFiltersAndRender();
  });

  elements.panelButtons.forEach((button) => {
    button.addEventListener("click", () => {
      setMobilePanel(button.dataset.panelButton || "summary");
    });
  });

  setMobilePanel("summary");
  await refreshLoop();
}

bootstrap().catch((error) => {
  elements.statusBadge.textContent = "Boot error";
  elements.timelineHost.innerHTML = `<div class="selection-empty">${escapeHtml(error.message || "Unable to boot the viewer.")}</div>`;
});
