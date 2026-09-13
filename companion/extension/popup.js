const elements = Object.fromEntries(["connect-view","status-view","base-url","connect","status","bindings","queued","authorization","error","refresh","disconnect"].map(id => [id, document.getElementById(id)]));
const send = message => chrome.runtime.sendMessage(message);

function render(data) {
  const disconnected = data.status === "disconnected";
  elements["connect-view"].hidden = !disconnected;
  elements["status-view"].hidden = disconnected;
  elements.status.textContent = data.status === "authorizing" ? "Authorization required" : data.status === "connected" ? "Connected" : "Disconnected";
  elements.bindings.textContent = String(data.bindingCount || 0);
  elements.queued.textContent = String(data.queuedCount || 0);
  elements.authorization.hidden = !data.userCode;
  elements.authorization.textContent = data.userCode ? `Approve the opened Syndicatum page. Code: ${data.userCode}` : "";
  elements.error.hidden = !data.lastError;
  elements.error.textContent = data.lastError || "";
  if (data.baseUrl) elements["base-url"].value = data.baseUrl;
}

async function action(message) {
  for (const button of document.querySelectorAll("button")) button.disabled = true;
  const result = await send(message).catch(error => ({ ok: false, error: String(error) }));
  for (const button of document.querySelectorAll("button")) button.disabled = false;
  if (!result?.ok) { elements.error.hidden = false; elements.error.textContent = result?.error || "The companion request failed."; return; }
  render(result.data);
}

elements.connect.addEventListener("click", () => action({ type: "syndicatum.connect", baseUrl: elements["base-url"].value }));
elements.refresh.addEventListener("click", () => action({ type: "syndicatum.refresh" }));
elements.disconnect.addEventListener("click", () => action({ type: "syndicatum.disconnect" }));
action({ type: "syndicatum.status" });
setInterval(() => action({ type: "syndicatum.status" }), 2000);
