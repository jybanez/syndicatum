const VIEWS = [
  ["mine", "My work"], ["unacknowledged", "Unacknowledged"],
  ["waiting_on_others", "Waiting on others"], ["all", "All direct work"],
  ["blocked", "Blocked"], ["transfer_pending", "Handoffs"],
  ["resolution_pending", "Decisions needed"], ["disputed", "Disputed"],
  ["orphaned", "Unassigned"], ["resolved", "Resolved"],
  ["unknown", "Historical / unknown"],
];

const ACTIONS = {
  work_started: "Start work", blocked: "Mark blocked", unblocked: "Unblock",
  resolution_proposed: "Propose resolution", resolution_accepted: "Accept resolution",
  resolution_disputed: "Dispute resolution", resolution_withdrawn: "Withdraw proposal",
  request_withdrawn: "Withdraw request", transfer_offered: "Offer handoff",
  transfer_accepted: "Accept handoff", transfer_declined: "Decline handoff",
  reopened: "Reopen", responder_restored: "Restore responder",
  corrected: "Correct evidence note",
};

export function responsibilityActions(item, actorId, moderator, activeParticipantIds = []) {
  if (!item || item.state === "unknown") return [];
  const actor = Number(actorId);
  const requester = actor === Number(item.requester_participant_id);
  const responder = actor === Number(item.current_responder_participant_id);
  const target = actor === Number(item.pending_target_participant_id);
  const proposer = actor === Number(item.pending_proposer_participant_id);
  const mayDecide = requester || moderator;
  const actions = [];
  if (["open", "disputed"].includes(item.state) && responder) {
    if (!item.work_started) actions.push("work_started");
    if (!item.blocked) actions.push("blocked");
    else if (item.block_event_message_id) actions.push("unblocked");
    actions.push("resolution_proposed");
  }
  if (item.state === "resolution_pending") {
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

export function responsibilityEvent(item, kind, targetId = null) {
  if (!Object.hasOwn(ACTIONS, kind) || !item || item.state === "unknown") {
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
  node.addEventListener("click", onClick);
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
  const participants = () => options.participants();
  const participantName = (participantId) => participants()
    .find((entry) => Number(entry.id) === Number(participantId))?.display_name
      || `Participant #${participantId}`;

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
  const status = element("p", "responsibility-status");
  status.setAttribute("role", "status");
  status.setAttribute("aria-live", "polite");
  const list = element("ol", "responsibility-list");
  const more = button("Load older work", () => void load(true));
  more.classList.add("responsibility-more");
  more.hidden = true;
  shell.append(toolbar, status, list, more);
  host.append(shell);

  function render() {
    list.replaceChildren();
    for (const item of rows) list.append(renderItem(item));
    more.hidden = !hasMore;
    more.disabled = loading;
    refresh.disabled = loading;
    select.disabled = false;
    if (!loading && !rows.length) {
      const empty = element("li", "responsibility-empty",
        hasMore ? "No matches in this page. Load older work to continue."
          : "No work matches this view.");
      list.append(empty);
    }
  }

  function renderItem(item) {
    const row = element("li", "responsibility-row");
    const card = element("article", "responsibility-card");
    const heading = element("div", "responsibility-card-heading");
    const title = element("h3", "", `Request #${item.request_message_id}`);
    const state = element("span", `responsibility-state is-${item.state}`,
      item.state.replaceAll("_", " "));
    heading.append(title, state);
    const meta = element("p", "responsibility-meta");
    const requester = participantName(item.requester_participant_id);
    const responder = item.current_responder_participant_id
      ? participantName(item.current_responder_participant_id)
      : item.state === "orphaned" || item.state === "transfer_pending"
        ? "No active owner" : "Not verified";
    meta.textContent = `From ${requester} · ${responder} · ${item.request_created_at}`;
    const flags = element("p", "responsibility-flags");
    const labels = [];
    if (item.blocked) labels.push("Blocked");
    if (item.work_started) labels.push("Work started");
    if (item.acknowledged) labels.push("Acknowledged");
    if (item.outcome === "withdrawn") labels.push("Request withdrawn, not completed");
    if (item.state === "transfer_pending" && item.pending_target_participant_id) {
      labels.push(`Offered to ${participantName(item.pending_target_participant_id)}`);
    }
    flags.textContent = labels.join(" · ");
    flags.hidden = !labels.length;
    const actions = element("div", "responsibility-actions");
    actions.append(button("View original message", () => options.openMessage(item.request_message_id),
      "ui-button ui-button-borderless"));
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
    if (!item.acknowledged
        && Number(item.initial_responder_participant_id) === Number(options.actorId())) {
      actions.append(button("Acknowledge", async () => {
        try {
          await options.acknowledge(item);
          await load();
        } catch (error) {
          status.textContent = error.message || "Acknowledgement failed.";
        }
      }, "ui-button ui-button-borderless"));
    }
    const activeIds = participants().filter((entry) => entry.status === "active")
      .map((entry) => Number(entry.id));
    const available = responsibilityActions(item, options.actorId(),
      options.moderator(), activeIds);
    if (available.length) {
      const menu = element("details", "responsibility-action-menu");
      const summary = element("summary", "", "Change responsibility");
      const choices = element("div", "responsibility-action-choices");
      for (const kind of available) {
        choices.append(button(ACTIONS[kind], () => {
          menu.open = false;
          openActionForm(card, item, kind);
        }));
      }
      menu.append(summary, choices);
      actions.append(menu);
    }
    card.append(heading, meta, flags, actions);
    row.append(card);
    return row;
  }

  function openActionForm(card, item, kind) {
    card.querySelector(".responsibility-action-form")?.remove();
    const form = element("form", "responsibility-action-form");
    const noteLabel = element("label", "", `${ACTIONS[kind]} — reason or evidence note`);
    const note = element("textarea", "ui-input");
    note.required = true;
    note.maxLength = 10000;
    note.rows = 3;
    noteLabel.append(note);
    form.append(noteLabel);
    let target = null;
    if (kind === "transfer_offered") {
      const targetLabel = element("label", "", "Active handoff target");
      target = element("select", "ui-input");
      target.required = true;
      const placeholder = element("option", "", "Choose a participant");
      placeholder.value = "";
      target.append(placeholder);
      for (const person of participants().filter((entry) => entry.status === "active"
          && Number(entry.id) !== Number(item.last_responder_participant_id))) {
        const choice = element("option", "", person.display_name);
        choice.value = person.id;
        target.append(choice);
      }
      targetLabel.append(target);
      form.append(targetLabel);
    }
    const errorText = element("p", "responsibility-action-error");
    errorText.setAttribute("role", "alert");
    const controls = element("div", "responsibility-action-controls");
    const submit = element("button", "ui-button ui-button-primary", ACTIONS[kind]);
    submit.type = "submit";
    controls.append(submit, button("Cancel", () => form.remove()));
    form.append(errorText, controls);
    let key = options.newKey();
    note.addEventListener("input", () => { key = options.newKey(); });
    target?.addEventListener("change", () => { key = options.newKey(); });
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      submit.disabled = true;
      errorText.textContent = "";
      try {
        const structured = responsibilityEvent(item, kind, target?.value);
        await options.writeEvent(note.value.trim(), structured, key);
        await load();
        status.textContent = `${ACTIONS[kind]} recorded in the project timeline.`;
      } catch (error) {
        if (error.status === 409) {
          await load();
          status.textContent = "Responsibility changed before your action. Review the refreshed item; nothing was posted by this attempt.";
        } else {
          errorText.textContent = error.message || "Action failed. You can retry the unchanged request.";
          submit.disabled = false;
        }
      }
    });
    card.append(form);
    note.focus();
  }

  async function load(append = false) {
    if (destroyed || (append && loading)) return;
    const requestedView = view;
    const requestedCursor = append ? cursor : null;
    if (!append) { rows = []; cursor = null; hasMore = false; }
    loading = true;
    const current = ++generation;
    status.textContent = append ? "Loading older work…" : "Loading responsibility…";
    render();
    try {
      const page = await options.fetchPage(requestedView, requestedCursor);
      if (destroyed || current !== generation) return;
      rows = append ? [...rows, ...(page.data || [])] : (page.data || []);
      cursor = page.page?.older_cursor || null;
      hasMore = Boolean(page.page?.has_more);
      status.textContent = `${rows.length} item${rows.length === 1 ? "" : "s"} shown${hasMore ? " · more available" : ""}. This is a live view; refresh after project changes.`;
    } catch (error) {
      if (destroyed || current !== generation) return;
      status.textContent = `Responsibility could not be loaded: ${error.message || "Unknown error"}. Retry Refresh.`;
      if (!append) { rows = []; cursor = null; hasMore = false; }
    } finally {
      if (!destroyed && current === generation) { loading = false; render(); }
    }
  }

  select.addEventListener("change", () => { view = select.value; void load(); });
  return {
    load,
    markStale() {
      if (!destroyed) status.textContent = "Project activity may have changed this live view. Refresh before acting or paging.";
    },
    destroy() { destroyed = true; generation++; host.replaceChildren(); },
  };
}
