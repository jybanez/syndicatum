function node(tag, className = "", value = "") {
  const result = document.createElement(tag);
  if (className) result.className = className;
  result.textContent = value;
  return result;
}

export function evidenceDetails(message, parent = null) {
  const replyId = message.reply_to_message_id || message.reply_to?.id || null;
  return {
    identity: `Canonical message #${message.id}`,
    sequence: `Project sequence ${message.sequence}`,
    sender: `From ${message.sender?.display_name || "Unknown participant"}`,
    created: `Sent ${message.created_at}`,
    addressed: message.addressees?.length
      ? `Addressed to ${message.addressees.map((entry) => entry.display_name).join(", ")}`
      : "Project broadcast",
    revision: `${Number(message.revision_count || 0)} revision${Number(message.revision_count || 0) === 1 ? "" : "s"}`,
    tombstone: message.deleted_at ? `Removed ${message.deleted_at}; historical evidence retained` : "Current visible revision",
    reply: replyId
      ? parent
        ? `Reply to canonical message #${replyId}, project sequence ${parent.sequence}, from ${parent.sender?.display_name || "Unknown participant"}${parent.deleted_at ? " (removed)" : ""}`
        : `Reply to canonical message #${replyId}; parent context unavailable`
      : "Top-level message",
    body: message.deleted_at ? "This message was removed. Its historical identity and responsibility evidence remain." : message.body,
  };
}

export function showCanonicalEvidence(message, { parent = null, onTimeline = () => {} } = {}) {
  const previouslyFocused = document.activeElement;
  const details = evidenceDetails(message, parent);
  const dialog = node("dialog", "responsibility-evidence-dialog");
  dialog.setAttribute("aria-labelledby", "responsibility-evidence-title");
  const title = node("h2", "", details.identity);
  title.id = "responsibility-evidence-title";
  const intro = node("p", "responsibility-evidence-intro",
    "Exact project message. This view does not change your timeline filters or create a second responsibility record.");
  const facts = node("dl", "responsibility-evidence-facts");
  for (const [label, value] of [
    ["Position", details.sequence], ["Sender", details.sender], ["Time", details.created],
    ["Addressing", details.addressed], ["History", `${details.revision}; ${details.tombstone}`],
    ["Thread", details.reply],
  ]) {
    facts.append(node("dt", "", label), node("dd", "", value));
  }
  const body = node("div", "responsibility-evidence-body", details.body);
  const actions = node("div", "responsibility-evidence-actions");
  const close = node("button", "ui-button ui-button-ghost", "Back to Responsibility Inbox");
  close.type = "button";
  close.addEventListener("click", () => dialog.close());
  const timeline = node("button", "ui-button ui-button-primary", "Open timeline (filters unchanged)");
  timeline.type = "button";
  let navigating = false;
  timeline.addEventListener("click", () => {
    navigating = true;
    dialog.close();
    onTimeline();
  });
  actions.append(close, timeline);
  dialog.append(title, intro, facts, body, actions);
  dialog.addEventListener("close", () => {
    dialog.remove();
    if (!navigating && previouslyFocused?.isConnected) previouslyFocused.focus();
  }, { once: true });
  document.body.append(dialog);
  dialog.showModal();
  close.focus();
  return dialog;
}
