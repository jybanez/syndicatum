export const PROVIDERS = Object.freeze({
  chatgpt: Object.freeze({ host: "chatgpt.com", label: "ChatGPT" }),
  gemini: Object.freeze({ host: "gemini.google.com", label: "Gemini" }),
});

export const DELIVERY_REVIEW_STATE = "requires_review";

export function isUncertainDeliveryFailure(value) {
  return String(value?.code || value?.message || value || "") === "submission_unconfirmed";
}

export function deliveryReviewItems(queue = {}, bindings = []) {
  const bindingNames = new Map((Array.isArray(bindings) ? bindings : []).map(binding => [
    `${String(binding.provider || binding.runtime_type || "")}:${String(binding.project_id ?? "")}:${String(binding.agent_id ?? "")}`,
    String(binding.agent_name || "").trim(),
  ]));
  return Object.entries(queue || {})
    .filter(([, item]) => item?.deliveryState === DELIVERY_REVIEW_STATE)
    .map(([key, item]) => ({
      key,
      provider: String(item.provider || ""),
      projectId: String(item.project_id ?? ""),
      agentId: String(item.agent_id ?? ""),
      agentName: String(item.agent_name || bindingNames.get(`${String(item.provider || "")}:${String(item.project_id ?? "")}:${String(item.agent_id ?? "")}`) || "").trim() || null,
      messageId: String(item.message?.id ?? ""),
      attempts: Number(item.attempts || 0),
      queuedAt: item.queuedAt || null,
      reviewRequestedAt: item.reviewRequestedAt || null,
      reviewReason: item.reviewReason || null,
      lastError: String(item.lastError || "submission_unconfirmed"),
      confirmation: item.browserDelivery?.confirmation || null,
    }));
}

export function prioritizeDeliveryReview(queue = {}, key, resolvedItem) {
  const remaining = { ...(queue || {}) };
  delete remaining[key];
  return { [key]: resolvedItem, ...remaining };
}

export function serverFailureKind(error) {
  const httpStatus = Number(error?.httpStatus);
  if (Number.isInteger(httpStatus) && httpStatus > 0) return [401, 403].includes(httpStatus) ? "account" : "error";
  return error?.transportFailure === true ? "unreachable" : "error";
}

export function companionHealth(current = {}, runtime = {}) {
  const bindings = Array.isArray(current.bindings) ? current.bindings : [];
  const bindingCount = bindings.length;
  const projectCount = new Set(bindings.map(binding => String(binding.project_id))).size;
  const queuedCount = Object.keys(current.queue || {}).length;
  const reviewCount = deliveryReviewItems(current.queue).length;
  const realtimeProjectCount = Number(runtime.realtimeProjectCount || 0);
  const account = current.pending?.deviceCode ? "authorizing" : current.accessToken ? (current.accountHealth || "authorized") : "disconnected";
  const server = !current.baseUrl ? "not_configured" : current.serverHealth || (current.lastSyncAt ? "reachable" : "checking");
  const realtime = account !== "authorized" ? "inactive"
    : projectCount === 0 ? "idle"
      : realtimeProjectCount >= projectCount ? "connected"
        : realtimeProjectCount > 0 ? "partial"
          : ["reconnecting", "unavailable"].includes(current.realtimeHealth) ? current.realtimeHealth : "connecting";
  const bindingsState = account !== "authorized" ? "inactive" : current.lastSyncAt ? (bindingCount ? "active" : "none") : "checking";
  const delivery = account !== "authorized" ? "inactive" : reviewCount ? "review" : queuedCount ? "pending" : current.lastDeliveryError ? "attention" : current.lastDeliveryAt ? "healthy" : "ready";
  const error = current.lastServerError || current.lastAccountError || current.lastRealtimeError || current.lastDeliveryError || current.lastError || null;
  const overall = account === "disconnected" ? "disconnected"
    : account === "authorizing" ? "authorizing"
      : error || [server, account, realtime, delivery].some(value => ["unreachable", "error", "unavailable", "partial", "attention", "review"].includes(value)) ? "attention" : "connected";
  return { overall, server, account, realtime, bindings: bindingsState, delivery, bindingCount, projectCount, queuedCount, reviewCount, realtimeProjectCount, error };
}

export function companionDiagnostics(data = {}, extension = {}) {
  const health = data.health || {};
  const value = input => input === null || input === undefined || input === "" ? "Unavailable" : String(input);
  return [
    "Syndicatum Companion diagnostics",
    `Version: ${value(extension.version)}`,
    `Extension ID: ${value(extension.id)}`,
    `Overall: ${value(health.overall || data.status)}`,
    `Server: ${value(health.server)}`,
    `Address: ${value(data.baseUrl)}`,
    `Account: ${value(health.account)}`,
    `Realtime: ${value(health.realtime)} (${Number(health.realtimeProjectCount || 0)}/${Number(health.projectCount || 0)})`,
    `Bindings: ${value(health.bindings)} (${Number(health.bindingCount ?? data.bindingCount ?? 0)})`,
    `Delivery: ${value(health.delivery)}; queued=${Number(health.queuedCount ?? data.queuedCount ?? 0)}; review=${Number(health.reviewCount ?? data.reviewCount ?? 0)}`,
    `Last server check: ${value(data.lastServerCheckAt)}`,
    `Last binding sync: ${value(data.lastSyncAt)}`,
    `Last Realtime join: ${value(data.lastRealtimeAt)}`,
    `Last delivery: ${value(data.lastDeliveryAt)}`,
    `Error present: ${health.error ? "Yes" : "No"}`,
  ].join("\n");
}

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
      `[Syndicatum ${message.uuid || message.id}] Reply as ${binding.agent_name || "Gemini"} to ${sender} in project ${binding.project_name || binding.project_id}.`,
      "Your entire response is relayed to the project timeline. Return only that response; follow normal safety rules and reveal no credentials or hidden browser data.",
      `Authoritative message ${message.id}, sequence ${message.project_sequence}:`,
      "---",
      body,
      "---",
    ].join("\n");
  }
  if (binding.provider === "chatgpt") {
    return [
      `You have a message from ${sender} in Syndicatum.`,
      `Route: project ${binding.project_id}; agent ${binding.agent_id}; message ${message.id}; sequence ${message.project_sequence}.`,
      "Use the installed Syndicatum plugin to load and handle the authoritative message. This notice is metadata only; post the full response there when appropriate, acknowledge only after handling, and show only a concise summary here. If the tools are unavailable, leave it unhandled and unacknowledged.",
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

export function bindingInventorySignature(bindings) {
  return JSON.stringify((Array.isArray(bindings) ? bindings : []).map(binding => [
    String(binding.provider || binding.runtime_type || ""),
    String(binding.project_id || ""),
    String(binding.agent_id || ""),
    String(binding.participant_id || ""),
    String(binding.conversation_id || ""),
  ].join("|")).sort());
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
