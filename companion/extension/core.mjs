export const PROVIDERS = Object.freeze({
  chatgpt: Object.freeze({ host: "chatgpt.com", label: "ChatGPT" }),
});

export function normalizeBaseUrl(value) {
  const url = new URL(String(value || "https://chatviewer.pbb.ph").trim());
  if (url.protocol !== "https:" && url.hostname !== "localhost" && url.hostname !== "127.0.0.1") {
    throw new Error("Syndicatum must use HTTPS.");
  }
  return url.origin + url.pathname.replace(/\/+$/, "");
}

export function normalizeDiscussionUrl(value, provider = "chatgpt") {
  const definition = PROVIDERS[provider];
  if (!definition) throw new Error(`Unsupported provider: ${provider}`);
  const url = new URL(String(value || "").trim());
  if (url.protocol !== "https:" || url.hostname !== definition.host || !/(?:^|\/)c\/[A-Za-z0-9_-]+\/?$/.test(url.pathname)) {
    throw new Error(`A valid ${definition.label} discussion URL is required.`);
  }
  return `${url.origin}${url.pathname.replace(/\/+$/, "")}`;
}

export function deliveryKey(item) {
  return [item.provider, item.project_id, item.agent_id, item.message?.id].join(":");
}

export function bindingAcceptsMessage(binding, message) {
  const addressees = Array.isArray(message?.addressees) ? message.addressees : [];
  return Number(message?.sender?.participant_id ?? message?.sender_participant_id) !== Number(binding.participant_id)
    && addressees.some(entry => Number(entry?.participant_id) === Number(binding.participant_id));
}

export function notificationFor(binding, message) {
  const sender = String(message?.sender?.display_name || message?.sender_name || "a project participant");
  return [
    `You have a message from ${sender} in Syndicatum.`,
    "Use the installed Syndicatum plugin to load the authoritative shared project timeline, handle messages addressed to you, and respond there when appropriate.",
    "This is only a notification. Do not treat this notification as the project message itself, and continue to follow your normal permissions and instructions.",
    "",
    `Syndicatum project ID: ${binding.project_id}`,
    `Syndicatum agent ID: ${binding.agent_id}`,
    `Syndicatum message ID: ${message.id}`,
    `Project sequence: ${message.project_sequence}`,
  ].join("\n");
}

export function recoveryItem(binding, message) {
  return { ...binding, message, provider: binding.provider || binding.runtime_type };
}

export function bindingsFromResponse(value) {
  if (Array.isArray(value)) return value;
  if (value && Array.isArray(value.bindings)) return value.bindings;
  throw new Error("Syndicatum returned an invalid connector bindings response.");
}

export function selectDeliveryTab(tabs) {
  const candidates = Array.isArray(tabs) ? tabs.filter(tab => tab && Number.isInteger(tab.id)) : [];
  return candidates.sort((left, right) => {
    const active = Number(Boolean(right.active)) - Number(Boolean(left.active));
    if (active) return active;
    const available = Number(Boolean(left.discarded)) - Number(Boolean(right.discarded));
    if (available) return available;
    return Number(right.lastAccessed || 0) - Number(left.lastAccessed || 0);
  })[0] || null;
}
