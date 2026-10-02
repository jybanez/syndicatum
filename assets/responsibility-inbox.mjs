const VIEWS = [
  ["mine", "My work"], ["unacknowledged", "Unacknowledged"],
  ["waiting_on_others", "Waiting on others"], ["all", "All direct work"],
  ["blocked", "Blocked"], ["transfer_pending", "Handoffs"],
  ["resolution_pending", "Decisions needed"], ["disputed", "Disputed"],
  ["orphaned", "Unassigned"], ["resolved", "Resolved"],
  ["unknown", "Historical / unknown"],
];

export const RESPONSIBILITY_ACTIONS = {
  work_started: "Start work", blocked: "Mark blocked", unblocked: "Unblock",
  resolution_proposed: "Propose resolution", resolution_accepted: "Accept resolution",
  resolution_disputed: "Dispute resolution", resolution_withdrawn: "Withdraw proposal",
  request_withdrawn: "Withdraw request", transfer_offered: "Offer handoff",
  transfer_accepted: "Accept handoff", transfer_declined: "Decline handoff",
  reopened: "Reopen", responder_restored: "Restore responder",
  corrected: "Correct evidence note",
  approval_approved: "Approve", approval_denied: "Deny",
  review_accepted: "Accept", review_revision_requested: "Request revision",
};

export const RESPONSIBILITY_DIRECT_DECISIONS = new Set([
  "approval_approved", "approval_denied",
  "review_accepted", "review_revision_requested",
]);

const STATE_LABELS = {
  open: "Awaiting work",
  resolution_pending: "Awaiting approval",
  transfer_pending: "Handoff pending",
  disputed: "Changes requested",
  orphaned: "Unassigned",
  resolved: "Resolved",
  unknown: "Historical",
};

export function responsibilityActions(item, actorId, moderator, activeParticipantIds = []) {
  if (!item || item.state === "unknown") return [];
  const actor = Number(actorId);
  const requester = actor === Number(item.requester_participant_id);
  const responder = actor === Number(item.current_responder_participant_id);
  const target = actor === Number(item.pending_target_participant_id);
  const proposer = actor === Number(item.pending_proposer_participant_id);
  const mayDecide = requester || moderator;
  const requestType = item.request_type || "work";
  const actions = [];
  if (["open", "disputed"].includes(item.state) && responder) {
    if (requestType === "approval" && item.state === "open") {
      actions.push("approval_approved", "approval_denied");
    } else if (requestType === "review" && item.state === "open") {
      actions.push("review_accepted", "review_revision_requested");
    } else if (requestType === "work") {
      if (!item.work_started) actions.push("work_started");
      if (!item.blocked) actions.push("blocked");
      else if (item.block_event_message_id) actions.push("unblocked");
      actions.push("resolution_proposed");
    }
  }
  if (requestType === "work" && item.state === "resolution_pending") {
    if (mayDecide) actions.push("resolution_accepted", "resolution_disputed");
    if (proposer) actions.push("resolution_withdrawn");
  }
  if (item.state === "transfer_pending" && target) {
    actions.push("transfer_accepted", "transfer_declined");
  }
  if (["open", "disputed", "orphaned"].includes(item.state)
      && (mayDecide || (item.state !== "orphaned" && responder))) {
    const candidates = activeParticipantIds.filter((id) => Number(id)
      !== Number(item.last_responder_participant_id));
    if (candidates.length) actions.push("transfer_offered");
  }
  if (mayDecide && ["open", "disputed", "orphaned", "resolution_pending",
    "transfer_pending"].includes(item.state)) actions.push("request_withdrawn");
  if (mayDecide && item.state === "resolved") actions.push("reopened");
  if (mayDecide && item.state === "orphaned"
      && activeParticipantIds.some((id) => Number(id)
        === Number(item.last_responder_participant_id))) actions.push("responder_restored");
  if (moderator && item.latest_evidence_message_id
      && Number(item.latest_evidence_message_id) !== Number(item.request_message_id)) {
    actions.push("corrected");
  }
  return actions;
}

export function responsibilityLabels(item, participantName) {
  const labels = [];
  if (item.blocked) labels.push(item.state === "resolved" ? "Previously blocked" : "Blocked");
  if (item.work_started) labels.push("Work started");
  if (item.acknowledged) labels.push("Acknowledged");
  if (item.outcome === "withdrawn") labels.push("Request withdrawn, not completed");
  if (item.state === "transfer_pending" && item.pending_target_participant_id) {
    labels.push(`Offered to ${participantName(item.pending_target_participant_id)}`);
  }
  return labels;
}

export function responsibilityStateLabel(item) {
  if (item.state === "open") {
    if (item.request_type === "approval") return "Awaiting approval";
    if (item.request_type === "review") return "Awaiting review";
  }
  if (item.state === "resolved") {
    return {
      approved: "Approved", denied: "Denied", accepted: "Accepted",
      revision_requested: "Revision requested", withdrawn: "Withdrawn",
    }[item.outcome] || "Resolved";
  }
  return STATE_LABELS[item.state] || item.state.replaceAll("_", " ");
}

export function responsibilityEvent(item, kind, targetId = null) {
  if (!Object.hasOwn(RESPONSIBILITY_ACTIONS, kind) || !item || item.state === "unknown") {
    throw new Error("This responsibility action is unavailable.");
  }
  const event = {
    kind,
    request_message_id: Number(item.request_message_id),
    initial_responder_participant_id: Number(item.initial_responder_participant_id),
    expected_event_id: Number(item.latest_evidence_message_id || item.request_message_id),
  };
  if (["unblocked", "resolution_accepted", "resolution_disputed",
    "resolution_withdrawn", "transfer_accepted", "transfer_declined",
    "corrected"].includes(kind)) {
    const reference = kind === "unblocked" ? item.block_event_message_id
      : kind === "corrected" ? item.latest_evidence_message_id
        : item.pending_event_message_id;
    if (!reference) throw new Error("The required evidence reference is unavailable. Refresh this item.");
    event.reference_event_id = Number(reference);
  }
  if (kind === "transfer_offered") {
    if (!targetId) throw new Error("Choose an active handoff target.");
    event.target_participant_id = Number(targetId);
  }
  return event;
}

function element(tag, className = "", text = "") {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text) node.textContent = text;
  return node;
}

function button(label, onClick, className = "ui-button ui-button-ghost") {
  const node = element("button", className, label);
  node.type = "button";
  if (typeof onClick === "function") node.addEventListener("click", onClick);
  return node;
}

export function createResponsibilityInbox(host, options) {
  let view = "mine";
  let rows = [];
  let cursor = null;
  let hasMore = false;
  let loading = false;
  let destroyed = false;
  let generation = 0;
  let conflictNotice = false;
  let autoLoadFailed = false;
  let scrollFrame = null;
  let autoPageArmed = true;
  let realtimeRefreshTimer = null;
  let realtimeRefreshing = false;
  const realtimeMessageIds = new Set();
  const dropdowns = new Set();
  const participants = () => options.participants();
  const participantName = (participantId) => participants()
    .find((entry) => Number(entry.id) === Number(participantId))?.display_name
      || `Participant #${participantId}`;

  function createScrollDismissedDropdown(trigger, items, dropdownOptions) {
    let dropdown = null;
    let listening = false;
    const scrollWindow = trigger.ownerDocument?.defaultView || window;
    const dismiss = () => dropdown?.close?.();
    const listen = (open) => {
      if (listening === open) return;
      listening = open;
      if (open) scrollWindow.addEventListener("scroll", dismiss, { passive: true, capture: true });
      else scrollWindow.removeEventListener("scroll", dismiss, { capture: true });
    };
    dropdown = options.createDropdown(trigger, items, {
      ...dropdownOptions,
      onOpenChange(open, context) {
        listen(open);
        dropdownOptions.onOpenChange?.(open, context);
      },
    });
    return {
      close: (...args) => dropdown?.close?.(...args),
      destroy() {
        listen(false);
        dropdown?.destroy?.();
      },
    };
  }

  host.replaceChildren();
  const shell = element("section", "responsibility-inbox");
  shell.setAttribute("aria-label", "Responsibility Inbox");
  const toolbar = element("div", "responsibility-toolbar");
  const viewLabel = element("label", "responsibility-view-label", "Show ");
  const select = element("select", "ui-input");
  select.setAttribute("aria-label", "Responsibility view");
  for (const [value, label] of VIEWS) {
    const option = element("option", "", label);
    option.value = value;
    select.append(option);
  }
  select.value = view;
  viewLabel.append(select);
  const refresh = button("Refresh", () => void load());
  toolbar.append(viewLabel, refresh);
  if (typeof options.openGuide === "function") {
    toolbar.append(button("Guide to these views", () => options.openGuide("responsibility-views"), "ui-button ui-button-borderless"));
  }
  const status = element("p", "responsibility-status");
  status.setAttribute("role", "status");
  status.setAttribute("aria-live", "polite");
  const list = element("ol", "responsibility-list");
  const sentinel = element("div", "responsibility-page-sentinel");
  sentinel.setAttribute("aria-hidden", "true");
  const supportsIntersectionPaging = typeof IntersectionObserver === "function";
  const more = button("Load older work", () => {
    autoLoadFailed = false;
    void load(true);
  });
  more.classList.add("responsibility-more");
  more.hidden = true;
  shell.append(toolbar, status, list, sentinel, more);
  host.append(shell);

  const pageObserver = supportsIntersectionPaging ? new IntersectionObserver((entries) => {
    if (entries.some((entry) => entry.isIntersecting)) requestAutomaticPage();
  }, { root: host, rootMargin: "0px 0px 240px 0px" }) : null;
  pageObserver?.observe(sentinel);

  function requestAutomaticPage() {
    if (destroyed || loading || autoLoadFailed || !hasMore || !autoPageArmed) return;
    autoPageArmed = false;
    void load(true);
  }

  function scheduleAutomaticPaging() {
    if (destroyed || autoLoadFailed || !hasMore || scrollFrame !== null) return;
    scrollFrame = requestAnimationFrame(() => {
      scrollFrame = null;
      if (destroyed || autoLoadFailed || !hasMore) return;
      const remaining = host.scrollHeight - host.scrollTop - host.clientHeight;
      if (remaining > 360) {
        autoPageArmed = true;
        return;
      }
      if (remaining <= 240) requestAutomaticPage();
    });
  }

  host.addEventListener("scroll", scheduleAutomaticPaging, { passive: true });

  function updatePagingControls() {
    sentinel.hidden = !hasMore || loading;
    more.hidden = !hasMore || !autoLoadFailed;
    more.disabled = loading;
    refresh.disabled = loading;
  }

  function emptyStateMessage() {
    const label = VIEWS.find(([value]) => value === view)?.[1] || "this view";
    if (!hasMore) return `No results for “${label}”.`;
    if (autoLoadFailed) return `No results for “${label}” in the loaded items. Retry loading older work.`;
    return `No results for “${label}” yet.`;
  }

  function render() {
    for (const dropdown of dropdowns) dropdown?.destroy?.();
    dropdowns.clear();
    list.replaceChildren();
    for (const item of rows) list.append(renderItem(item));
    updatePagingControls();
    select.disabled = false;
    if (!loading && !rows.length) {
      const empty = element("li", "responsibility-empty", emptyStateMessage());
      list.append(empty);
    }
    scheduleAutomaticPaging();
  }

  function renderPreservingViewport() {
    const wasAtTop = host.scrollTop <= 4;
    const hostTop = host.getBoundingClientRect().top;
    const anchor = Array.from(list.querySelectorAll(".responsibility-row"))
      .find((row) => row.getBoundingClientRect().bottom > hostTop);
    const anchorId = anchor?.dataset.requestMessageId || "";
    const anchorOffset = anchor ? anchor.getBoundingClientRect().top - hostTop : 0;
    render();
    if (wasAtTop) {
      host.scrollTop = 0;
      return;
    }
    const replacement = anchorId
      ? list.querySelector(`[data-request-message-id="${CSS.escape(anchorId)}"]`)
      : null;
    if (replacement) {
      host.scrollTop += replacement.getBoundingClientRect().top - hostTop - anchorOffset;
    }
  }

  function armRealtimeRefresh() {
    if (destroyed || loading || realtimeRefreshing || realtimeRefreshTimer !== null
        || !realtimeMessageIds.size) return;
    realtimeRefreshTimer = setTimeout(() => {
      realtimeRefreshTimer = null;
      void flushRealtimeRefresh();
    }, 60);
  }

  async function flushRealtimeRefresh() {
    if (destroyed || loading || realtimeRefreshing || !realtimeMessageIds.size) {
      armRealtimeRefresh();
      return;
    }
    const requestedView = view;
    const requestedGeneration = generation;
    const messageIds = [...realtimeMessageIds];
    realtimeMessageIds.clear();
    realtimeRefreshing = true;
    try {
      const pages = await Promise.all(messageIds.map((messageId) =>
        options.fetchPage(requestedView, null, messageId)));
      if (destroyed || loading || requestedView !== view
          || requestedGeneration !== generation) return;
      const changedRequestIds = new Set();
      const incoming = [];
      for (const page of pages) {
        const changedRequestId = Number(page.page?.changed_request_message_id || 0);
        if (changedRequestId) changedRequestIds.add(changedRequestId);
        incoming.push(...(page.data || []));
      }
      if (!changedRequestIds.size) return;
      const incomingKeys = new Set();
      rows = [
        ...rows.filter((item) => !changedRequestIds.has(Number(item.request_message_id))),
        ...incoming.filter((item) => {
          const key = `${item.request_message_id}:${item.initial_responder_participant_id}`;
          if (incomingKeys.has(key)) return false;
          incomingKeys.add(key);
          return true;
        }),
      ].sort((left, right) => Number(right.request_sequence || 0) - Number(left.request_sequence || 0)
        || Number(right.initial_responder_participant_id || 0) - Number(left.initial_responder_participant_id || 0));
      renderPreservingViewport();
      status.textContent = `${rows.length} item${rows.length === 1 ? "" : "s"} shown${hasMore ? " · more available" : ""}. Updated automatically.`;
    } catch (error) {
      if (!destroyed) {
        status.textContent = `A live inbox update could not be loaded: ${error.message || "Unknown error"}. Use Refresh to retry.`;
      }
    } finally {
      realtimeRefreshing = false;
      armRealtimeRefresh();
    }
  }

  function renderEmptyState() {
    if (rows.length) return;
    const existing = list.querySelector(".responsibility-empty");
    if (existing) {
      existing.textContent = emptyStateMessage();
      return;
    }
    list.append(element("li", "responsibility-empty", emptyStateMessage()));
  }

  function renderItem(item) {
    const row = element("li", "responsibility-row");
    row.dataset.requestMessageId = String(item.request_message_id);
    const card = element("article", "responsibility-card");
    const heading = element("div", "responsibility-card-heading");
    const requestLabel = item.request_type === "approval" ? "Approval request"
      : item.request_type === "review" ? "Review request" : "Work request";
    const title = element("h3", "", `${requestLabel} #${item.request_message_id}`);
    const state = element("span", `responsibility-state is-${item.state}`,
      responsibilityStateLabel(item));
    heading.append(title, state);
    const meta = element("p", "responsibility-meta");
    const requester = participantName(item.requester_participant_id);
    const responder = item.current_responder_participant_id
      ? participantName(item.current_responder_participant_id)
      : item.state === "orphaned" || item.state === "transfer_pending"
        ? "No active owner" : "Not verified";
    meta.textContent = `Requested by ${requester} · Assigned to ${responder} · ${item.request_created_at}`;
    const flags = element("p", "responsibility-flags");
    const labels = responsibilityLabels(item, participantName);
    flags.textContent = labels.join(" · ");
    flags.hidden = !labels.length;
    const actions = element("div", "responsibility-actions");
    actions.append(button("View original message", () => options.openMessage(item.request_message_id),
      "ui-button ui-button-borderless"));
    const linkedTasks = typeof options.linkedTasks === "function"
      ? options.linkedTasks(item.request_message_id) : [];
    if (linkedTasks.length) {
      const label = linkedTasks.length === 1 ? "View linked task" : `View linked tasks (${linkedTasks.length})`;
      actions.append(button(label, () => linkedTasks.length === 1
        ? options.openTask(linkedTasks[0].id)
        : options.openLinkedTasks(item.request_message_id),
        "ui-button ui-button-borderless"));
    } else {
      const actor = Number(options.actorId());
      const mayConvert = options.canCreateTask?.()
        && (options.moderator()
          || actor === Number(item.requester_participant_id)
          || actor === Number(item.initial_responder_participant_id));
      if (mayConvert && (item.request_type || "work") === "work"
          && !["resolved", "unknown"].includes(item.state)) {
        actions.append(button("Convert to task", () => options.convertToTask(item),
          "ui-button ui-button-borderless"));
      }
    }
    if (item.latest_evidence_message_id
        && Number(item.latest_evidence_message_id) !== Number(item.request_message_id)) {
      actions.append(button("View latest evidence", () => options.openMessage(item.latest_evidence_message_id),
        "ui-button ui-button-borderless"));
    }
    if (item.state === "unknown") {
      const warning = element("p", "responsibility-unknown",
        "Historical direct message: current responsibility cannot be verified. This is not an active assignment. Send a new direct request to assign current work.");
      card.append(heading, meta, warning, actions);
      row.append(card);
      return row;
    }
    const activeIds = participants().filter((entry) => entry.status === "active")
      .map((entry) => Number(entry.id));
    const available = responsibilityActions(item, options.actorId(),
      options.moderator(), activeIds);
    for (const kind of available.filter((entry) => RESPONSIBILITY_DIRECT_DECISIONS.has(entry))) {
      actions.append(button(RESPONSIBILITY_ACTIONS[kind], () => openActionModal(item, kind),
        "ui-button ui-button-borderless"));
    }
    const menuActions = available.filter((entry) => !RESPONSIBILITY_DIRECT_DECISIONS.has(entry));
    if (menuActions.length) {
      const trigger = button("Update work", null, "ui-button ui-button-borderless");
      actions.append(trigger);
      const dropdown = createScrollDismissedDropdown(trigger, menuActions.map((kind) => ({
        id: kind,
        label: RESPONSIBILITY_ACTIONS[kind],
      })), {
        align: "left",
        ariaLabel: "Update work",
        className: "responsibility-update-dropdown",
        onSelect(option) { openActionModal(item, option.id); },
      });
      dropdowns.add(dropdown);
    }
    const guidance = element("p", "responsibility-guidance");
    const actor = Number(options.actorId());
    const isResponder = actor === Number(item.current_responder_participant_id);
    const isRequester = actor === Number(item.requester_participant_id);
    if (item.state === "open" && isResponder && item.request_type === "approval") {
      guidance.textContent = "Next: approve or deny this request.";
    } else if (item.state === "open" && isResponder && item.request_type === "review") {
      guidance.textContent = "Next: accept the reviewed result or request a revision.";
    } else if (item.state === "open" && isResponder && !item.work_started) {
      guidance.textContent = "Next: start work, or mark the request blocked if you cannot proceed.";
    } else if (["open", "disputed"].includes(item.state) && isResponder && item.blocked) {
      guidance.textContent = "This request is blocked. Unblock it when work can continue.";
    } else if (["open", "disputed"].includes(item.state) && isResponder) {
      guidance.textContent = "Next: propose a resolution when the requested work is complete.";
    } else if (item.state === "resolution_pending" && (isRequester || options.moderator())) {
      guidance.textContent = "Next: accept the proposed resolution or request changes.";
    } else if (item.state === "transfer_pending"
        && actor === Number(item.pending_target_participant_id)) {
      guidance.textContent = "Next: accept or decline this handoff.";
    } else if (item.state === "orphaned") {
      guidance.textContent = "This request needs an active assignee before work can continue.";
    } else if (item.state === "resolved") {
      guidance.textContent = "No action is required; this request is resolved.";
    } else {
      guidance.textContent = "No action is currently required from you.";
    }
    card.append(heading, meta, flags, guidance, actions);
    row.append(card);
    return row;
  }

  function openActionModal(item, kind) {
    options.openActionModal(item, kind, {
      async onSuccess() {
        await load();
        status.textContent = `${RESPONSIBILITY_ACTIONS[kind]} recorded in the project timeline.`;
      },
      async onConflict() {
        const refreshed = await load();
        conflictNotice = true;
        status.textContent = refreshed
          ? "Responsibility changed before your action. Review the refreshed item; nothing was posted by this attempt."
          : "Responsibility changed before your action; nothing was posted. Refresh failed; use Refresh before trying again.";
      },
    });
  }

  async function load(append = false) {
    if (destroyed || (append && loading)) return;
    conflictNotice = false;
    const requestedView = view;
    const requestedCursor = append ? cursor : null;
    if (!append) { rows = []; cursor = null; hasMore = false; autoPageArmed = true; }
    loading = true;
    const current = ++generation;
    status.textContent = append ? "Loading older work…" : "Loading responsibility…";
    if (append) updatePagingControls();
    else render();
    try {
      const page = await options.fetchPage(requestedView, requestedCursor);
      if (destroyed || current !== generation) return false;
      const incoming = page.data || [];
      rows = append ? [...rows, ...incoming] : incoming;
      if (append) {
        list.querySelector(".responsibility-empty")?.remove();
        for (const item of incoming) list.append(renderItem(item));
      }
      const nextCursor = page.page?.older_cursor || null;
      hasMore = Boolean(page.page?.has_more && nextCursor && (!append || nextCursor !== requestedCursor));
      cursor = nextCursor;
      autoLoadFailed = false;
      status.textContent = !rows.length && hasMore
        ? "No matching items shown. This is a live view; refresh after project changes."
        : `${rows.length} item${rows.length === 1 ? "" : "s"} shown${hasMore ? " · more available" : ""}. This is a live view; refresh after project changes.`;
      return true;
    } catch (error) {
      if (destroyed || current !== generation) return false;
      status.textContent = append
        ? `Older work could not be loaded: ${error.message || "Unknown error"}. Choose Load older work to retry.`
        : `Responsibility could not be loaded: ${error.message || "Unknown error"}. Retry Refresh.`;
      if (append) autoLoadFailed = true;
      if (!append) { rows = []; cursor = null; hasMore = false; }
      return false;
    } finally {
      if (!destroyed && current === generation) {
        loading = false;
        if (append) {
          updatePagingControls();
          renderEmptyState();
          scheduleAutomaticPaging();
        } else render();
        armRealtimeRefresh();
      }
    }
  }

  select.addEventListener("change", () => { view = select.value; void load(); });
  return {
    load,
    refreshTasks() {
      if (!destroyed) renderPreservingViewport();
    },
    refreshFromRealtime(messageId) {
      const normalized = Number(messageId);
      if (!destroyed && Number.isInteger(normalized) && normalized > 0) {
        realtimeMessageIds.add(normalized);
        armRealtimeRefresh();
      }
    },
    destroy() {
      destroyed = true;
      generation++;
      for (const dropdown of dropdowns) dropdown?.destroy?.();
      dropdowns.clear();
      pageObserver?.disconnect();
      host.removeEventListener("scroll", scheduleAutomaticPaging);
      if (scrollFrame !== null) cancelAnimationFrame(scrollFrame);
      if (realtimeRefreshTimer !== null) clearTimeout(realtimeRefreshTimer);
      realtimeMessageIds.clear();
      host.replaceChildren();
    },
  };
}
