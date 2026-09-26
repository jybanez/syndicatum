import { companionDiagnostics } from "./core.mjs";

const elements = Object.fromEntries(["connect-view","status-view","base-url","connect","connect-error","status","server","server-health","account-health","realtime-health","bindings-health","delivery-health","authorization","binding-result","error","delivery-review","delivery-review-list","copy-review","copy-review-result","refresh","disconnect","edit-server","server-dialog","new-base-url","server-change-error","cancel-server-change","confirm-server-change","last-server-check","last-sync","last-realtime","last-delivery","extension-version","diagnostic-version","copy-diagnostics","copy-result"].map(id => [id, document.getElementById(id)]));
const send = message => chrome.runtime.sendMessage(message);
const extension = { id: chrome.runtime.id, version: chrome.runtime.getManifest().version };
let latestStatus = null;

const labels = {
  connected: "Connected", disconnected: "Disconnected", authorizing: "Authorization required", attention: "Needs attention",
  reachable: "Reachable", unreachable: "Unreachable", checking: "Checking", not_configured: "Not configured",
  authorized: "Authorized", error: "Error", inactive: "Inactive", idle: "Idle", connecting: "Connecting", reconnecting: "Reconnecting", unavailable: "Unavailable", partial: "Partially connected",
  active: "Active", none: "None", pending: "Pending", review: "Review required", healthy: "Healthy", ready: "Ready",
};

function showHealth(element, value, suffix = "") {
  element.textContent = `${labels[value] || value || "Unknown"}${suffix}`;
  element.dataset.state = value || "unknown";
}

function timestamp(value) {
  if (!value) return "Never";
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? "Unavailable" : parsed.toLocaleString();
}

function renderDeliveryReviews(reviews = []) {
  elements["delivery-review"].hidden = !reviews.length;
  elements["delivery-review-list"].replaceChildren();
  for (const review of reviews) {
    const item = document.createElement("article");
    item.className = "review-item";
    const title = document.createElement("strong");
    title.textContent = `${review.provider || "Provider"} · project ${review.projectId} · agent ${review.agentId}`;
    const metadata = document.createElement("p");
    metadata.textContent = `Message ${review.messageId} · attempts ${review.attempts} · queued ${timestamp(review.queuedAt)}`;
    const actions = document.createElement("div");
    actions.className = "review-actions";
    if (review.provider === "chatgpt") {
      const confirm = document.createElement("button");
      confirm.type = "button";
      confirm.className = "secondary";
      confirm.textContent = "Confirm visible";
      confirm.addEventListener("click", () => resolveReview(review, "confirm_visible"));
      actions.append(confirm);
    }
    const retry = document.createElement("button");
    retry.type = "button";
    retry.textContent = "Retry once";
    retry.addEventListener("click", () => resolveReview(review, "retry_once"));
    actions.append(retry);
    if (review.reviewReason === "upgrade_reconciliation_required") {
      const discard = document.createElement("button");
      discard.type = "button";
      discard.className = "danger";
      discard.textContent = "Remove stale";
      discard.addEventListener("click", () => resolveReview(review, "discard_stale"));
      actions.append(discard);
    }
    item.append(title, metadata, actions);
    elements["delivery-review-list"].append(item);
  }
}

async function resolveReview(review, resolution) {
  const prompt = resolution === "confirm_visible"
    ? `Confirm only if the exact Syndicatum notice for message ${review.messageId} is already visible as a user turn. Mark it delivered without submitting again?`
    : resolution === "discard_stale"
      ? `Remove only this local queue item for message ${review.messageId}? Continue only after verifying that it is absent from the canonical pending set. This does not submit or mark anything on the server.`
    : `Authorize exactly one new browser submission attempt for message ${review.messageId}? If confirmation is uncertain again, it will pause for review.`;
  if (!window.confirm(prompt)) return;
  await action({ type: "syndicatum.resolve-delivery-review", key: review.key, resolution });
}

function serverPermission(value) {
  const url = new URL(String(value || "").trim());
  const localHttp = url.protocol === "http:" && ["localhost", "127.0.0.1"].includes(url.hostname);
  if (url.protocol !== "https:" && !localHttp) throw new Error("Use HTTPS. HTTP is allowed only for localhost development.");
  if (url.username || url.password) throw new Error("The server URL must not contain credentials.");
  return `${url.protocol}//${url.host}/*`;
}

function render(data) {
  latestStatus = data;
  const disconnected = data.status === "disconnected";
  elements["connect-view"].hidden = !disconnected;
  elements["status-view"].hidden = disconnected;
  const health = data.health || {};
  showHealth(elements.status, health.overall || data.status);
  showHealth(elements["server-health"], health.server || "checking");
  showHealth(elements["account-health"], health.account || "disconnected");
  const realtimeSuffix = health.projectCount ? ` (${health.realtimeProjectCount || 0}/${health.projectCount})` : "";
  showHealth(elements["realtime-health"], health.realtime || "inactive", realtimeSuffix);
  showHealth(elements["bindings-health"], health.bindings || "checking", ` (${health.bindingCount ?? data.bindingCount ?? 0})`);
  const deliverySuffix = health.queuedCount ? ` (${health.queuedCount})` : "";
  showHealth(elements["delivery-health"], health.delivery || "ready", deliverySuffix);
  elements.server.textContent = data.baseUrl || "";
  elements.authorization.hidden = !data.userCode;
  elements.authorization.textContent = data.userCode ? `Approve the opened Syndicatum page. Code: ${data.userCode}` : "";
  elements["binding-result"].hidden = !data.lastBindingMessage;
  elements["binding-result"].textContent = data.lastBindingMessage || "";
  elements.error.hidden = !health.error;
  elements.error.textContent = health.error || "";
  renderDeliveryReviews(data.deliveryReviews || []);
  elements["last-server-check"].textContent = timestamp(data.lastServerCheckAt);
  elements["last-sync"].textContent = timestamp(data.lastSyncAt);
  elements["last-realtime"].textContent = timestamp(data.lastRealtimeAt);
  elements["last-delivery"].textContent = timestamp(data.lastDeliveryAt);
  elements["copy-result"].textContent = "";
  elements["copy-review-result"].textContent = "";
  if (data.baseUrl) elements["base-url"].value = data.baseUrl;
}

async function action(message) {
  for (const button of document.querySelectorAll("button")) button.disabled = true;
  const result = await send(message).catch(error => ({ ok: false, error: String(error) }));
  for (const button of document.querySelectorAll("button")) button.disabled = false;
  if (!result?.ok) { elements.error.hidden = false; elements.error.textContent = result?.error || "The companion request failed."; return false; }
  render(result.data);
  return true;
}

elements.connect.addEventListener("click", async () => {
  elements["connect-error"].hidden = true;
  const originalLabel = elements.connect.textContent;
  elements.connect.disabled = true;
  try {
    const baseUrl = elements["base-url"].value;
    if (!String(baseUrl || "").trim()) throw new Error("Enter the Syndicatum server URL.");
    const origin = serverPermission(baseUrl);
    elements.connect.textContent = "Validating…";
    const granted = await chrome.permissions.request({ origins: [origin] });
    if (!granted) throw new Error("Permission to connect to this Syndicatum server was not granted.");
    const connected = await action({ type: "syndicatum.connect", baseUrl });
    if (!connected) throw new Error(elements.error.textContent || "This server could not be verified as a compatible Syndicatum installation.");
  } catch (error) {
    elements["connect-error"].hidden = false;
    elements["connect-error"].textContent = String(error?.message || error);
  } finally {
    elements.connect.textContent = originalLabel;
    elements.connect.disabled = false;
  }
});
elements.refresh.addEventListener("click", () => action({ type: "syndicatum.refresh" }));
elements.disconnect.addEventListener("click", () => action({ type: "syndicatum.disconnect" }));
elements["copy-diagnostics"].addEventListener("click", async () => {
  if (!latestStatus) return;
  try {
    await navigator.clipboard.writeText(companionDiagnostics(latestStatus, extension));
    elements["copy-result"].textContent = "Copied";
  } catch (_error) {
    elements["copy-result"].textContent = "Copy failed";
  }
});
elements["copy-review"].addEventListener("click", async () => {
  if (!latestStatus) return;
  try {
    await navigator.clipboard.writeText(JSON.stringify(latestStatus.deliveryReviews || [], null, 2));
    elements["copy-review-result"].textContent = "Copied";
  } catch (_error) {
    elements["copy-review-result"].textContent = "Copy failed";
  }
});
elements["edit-server"].addEventListener("click", () => {
  elements["new-base-url"].value = elements.server.textContent || "";
  elements["server-change-error"].hidden = true;
  elements["server-dialog"].showModal();
  elements["new-base-url"].focus();
  elements["new-base-url"].select();
});
elements["cancel-server-change"].addEventListener("click", () => elements["server-dialog"].close("cancel"));
elements["confirm-server-change"].addEventListener("click", async () => {
  const button = elements["confirm-server-change"];
  const originalLabel = button.textContent;
  elements["server-change-error"].hidden = true;
  button.disabled = true;
  let origin = null;
  let previousOrigin = null;
  try {
    const baseUrl = elements["new-base-url"].value;
    origin = serverPermission(baseUrl);
    previousOrigin = serverPermission(elements.server.textContent);
    button.textContent = "Validating…";
    const prepared = await send({ type: "syndicatum.prepare-server-migration", baseUrl });
    if (!prepared?.ok) throw new Error(prepared?.error || "The server change could not be prepared.");
    const granted = await chrome.permissions.request({ origins: [origin] });
    if (!granted) {
      await send({ type: "syndicatum.cancel-server-migration" }).catch(() => null);
      throw new Error("Permission to connect to this Syndicatum server was not granted.");
    }
    const result = await send({ type: "syndicatum.resume-server-migration" });
    if (!result?.ok) {
      if (origin !== previousOrigin) await chrome.permissions.remove({ origins: [origin] }).catch(() => false);
      throw new Error(result?.error || "The Syndicatum server change failed.");
    }
    render(result.data);
    elements["server-dialog"].close("migrated");
  } catch (error) {
    elements["server-change-error"].hidden = false;
    elements["server-change-error"].textContent = String(error?.message || error);
  } finally {
    button.textContent = originalLabel;
    button.disabled = false;
  }
});
async function initialize() {
  elements["extension-version"].textContent = `v${extension.version}`;
  elements["diagnostic-version"].textContent = `${extension.version} · ${extension.id}`;
  const result = await send({ type: "syndicatum.status" }).catch(error => ({ ok: false, error: String(error) }));
  if (!result?.ok) { elements.error.hidden = false; elements.error.textContent = result?.error || "The companion request failed."; return; }
  render(result.data);
  if (!result.data?.pendingServerMigration?.to) return;
  const permission = serverPermission(result.data.pendingServerMigration.to);
  if (!await chrome.permissions.contains({ origins: [permission] })) return;
  await action({ type: "syndicatum.resume-server-migration" });
}
initialize();
setInterval(() => action({ type: "syndicatum.status" }), 2000);
