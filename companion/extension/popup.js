const elements = Object.fromEntries(["connect-view","status-view","base-url","connect","connect-error","status","server","bindings","queued","authorization","binding-result","error","refresh","disconnect"].map(id => [id, document.getElementById(id)]));
const send = message => chrome.runtime.sendMessage(message);

function serverPermission(value) {
  const url = new URL(String(value || "").trim());
  const localHttp = url.protocol === "http:" && ["localhost", "127.0.0.1"].includes(url.hostname);
  if (url.protocol !== "https:" && !localHttp) throw new Error("Use HTTPS. HTTP is allowed only for localhost development.");
  if (url.username || url.password) throw new Error("The server URL must not contain credentials.");
  return `${url.protocol}//${url.host}/*`;
}

function render(data) {
  const disconnected = data.status === "disconnected";
  elements["connect-view"].hidden = !disconnected;
  elements["status-view"].hidden = disconnected;
  elements.status.textContent = data.status === "authorizing" ? "Authorization required" : data.status === "connected" ? "Connected" : "Disconnected";
  elements.server.textContent = data.baseUrl || "";
  elements.bindings.textContent = String(data.bindingCount || 0);
  elements.queued.textContent = String(data.queuedCount || 0);
  elements.authorization.hidden = !data.userCode;
  elements.authorization.textContent = data.userCode ? `Approve the opened Syndicatum page. Code: ${data.userCode}` : "";
  elements["binding-result"].hidden = !data.lastBindingMessage;
  elements["binding-result"].textContent = data.lastBindingMessage || "";
  elements.error.hidden = !data.lastError;
  elements.error.textContent = data.lastError || "";
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
action({ type: "syndicatum.status" });
setInterval(() => action({ type: "syndicatum.status" }), 2000);
