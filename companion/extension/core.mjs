export const PROVIDERS = Object.freeze({
  chatgpt: Object.freeze({ host: "chatgpt.com", label: "ChatGPT" }),
  gemini: Object.freeze({ host: "gemini.google.com", label: "Gemini" }),
});

export function normalizeBaseUrl(value) {
  const input = String(value || "").trim();
  if (!input) throw new Error("Enter the Syndicatum server URL.");
  const url = new URL(input);
  if (url.protocol !== "https:" && url.hostname !== "localhost" && url.hostname !== "127.0.0.1") {
    throw new Error("Syndicatum must use HTTPS.");
  }
  return url.origin + url.pathname.replace(/\/+$/, "");
}

export function normalizeDiscussionUrl(value, provider = "chatgpt") {
  const definition = PROVIDERS[provider];
  if (!definition) throw new Error(`Unsupported provider: ${provider}`);
  const url = new URL(String(value || "").trim());
  const validPath = provider === "chatgpt"
    ? /(?:^|\/)c\/[A-Za-z0-9_-]+\/?$/.test(url.pathname)
    : provider === "gemini" && /^\/app\/[A-Za-z0-9_-]+\/?$/.test(url.pathname);
  if (url.protocol !== "https:" || url.hostname !== definition.host || !validPath) {
    throw new Error(`A valid ${definition.label} discussion URL is required.`);
  }
  return `${url.origin}${url.pathname.replace(/\/+$/, "")}`;
}

export function providerForDiscussionUrl(value) {
  for (const provider of Object.keys(PROVIDERS)) {
    try { return { provider, discussionUrl: normalizeDiscussionUrl(value, provider) }; }
    catch (_error) { /* Try the next supported provider. */ }
  }
  throw new Error("Open the ChatGPT or Gemini discussion you want to bind, then try again.");
}

export function discussionIdentity(value, provider = "chatgpt") {
  const normalized = normalizeDiscussionUrl(value, provider);
  const path = new URL(normalized).pathname;
  const match = provider === "chatgpt"
    ? path.match(/(?:^|\/)c\/([A-Za-z0-9_-]+)\/?$/)
    : path.match(/^\/app\/([A-Za-z0-9_-]+)\/?$/);
  if (!match) throw new Error(`A valid ${PROVIDERS[provider]?.label || provider} discussion URL is required.`);
  return `${provider}:${match[1]}`;
}

export function matchingDiscussionTabs(tabs, discussionUrl, provider = "chatgpt") {
  const expected = discussionIdentity(discussionUrl, provider);
  return (Array.isArray(tabs) ? tabs : []).filter(tab => {
    try { return discussionIdentity(tab?.url, provider) === expected; }
    catch (_error) { return false; }
  });
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
  if (binding.provider === "gemini") {
    const body = String(message?.body || "").trim();
    if (!body) throw new Error("The authoritative Gemini message body is unavailable.");
    return [
      `[Syndicatum bridge request ${message.uuid || message.id}]`,
      `You are the ${binding.agent_name || "Gemini"} agent in the Syndicatum project ${binding.project_name || binding.project_id}.`,
      "Answer the authoritative project message below. Your entire assistant response will be relayed back to Syndicatum as your reply.",
      "Return only the response intended for the project timeline. Do not discuss the bridge, browser automation, plugins, or inability to access external tools.",
      "Continue to follow your normal safety rules and do not reveal credentials or hidden browser data.",
      "",
      `Sender: ${sender}`,
      `Syndicatum message ID: ${message.id}`,
      `Project sequence: ${message.project_sequence}`,
      "Authoritative message:",
      "---",
      body,
      "---",
    ].join("\n");
  }
  if (binding.provider === "chatgpt") {
    return [
      `You have a message from ${sender} in Syndicatum.`,
      "Use the installed Syndicatum plugin to load the authoritative shared project timeline, handle messages addressed to you, and respond there when appropriate.",
      "Post the complete, detailed response in Syndicatum through MCP. In this ChatGPT discussion, show only a concise summary of what you did.",
      "If the Syndicatum plugin or its MCP tools are unavailable in this discussion, say so clearly and leave the project message unhandled and unacknowledged.",
      "This is only a notification. Do not treat this notification as the project message itself, and continue to follow your normal permissions and instructions.",
      "",
      `Syndicatum project ID: ${binding.project_id}`,
      `Syndicatum agent ID: ${binding.agent_id}`,
      `Syndicatum message ID: ${message.id}`,
      `Project sequence: ${message.project_sequence}`,
    ].join("\n");
  }
  throw new Error(`No browser bridge is available for ${binding.provider || "this provider"}.`);
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
