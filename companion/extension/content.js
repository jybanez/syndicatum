(function installSyndicatumCompanionListener(root) {
  if (root.__syndicatumCompanionListenerInstalled) return;
  root.__syndicatumCompanionListenerInstalled = true;
  chrome.runtime.onMessage.addListener((request, sender, respond) => {
    if (request?.type === "syndicatum.binding-intent") {
      showBindingIntent(request.intent);
      respond({ ok: true });
      return false;
    }
    if (request?.type !== "syndicatum.provider.deliver") return false;
    const adapter = root.SyndicatumProviderAdapters?.[request.provider];
    if (!adapter) { respond({ ok: false, retryable: false, code: "provider_adapter_missing" }); return false; }
    const hooks = {
      accepted: delivery => chrome.runtime.sendMessage({
        type: "syndicatum.provider.accepted",
        delivery: { ...(request.delivery || {}), ...(delivery || {}) },
      }).catch(() => {}),
    };
    Promise.resolve(adapter.deliver(String(request.text || ""), hooks))
      .then(respond)
      .catch(error => respond({ ok: false, retryable: true, code: "provider_error", message: String(error?.message || error) }));
    return true;
  });

  function showBindingIntent(intent) {
    const id = String(intent?.intent_id || "");
    if (!id) return;
    let confirmedBinding = null;
    const existing = document.getElementById("syndicatum-binding-confirmation");
    if (existing?.dataset.intentId === id) return;
    existing?.remove();

    const host = document.createElement("div");
    host.id = "syndicatum-binding-confirmation";
    host.dataset.intentId = id;
    host.style.cssText = "position:fixed;inset:0;z-index:2147483647;display:grid;place-items:center;background:rgba(2,8,20,.68);padding:24px";
    const shadow = host.attachShadow({ mode: "closed" });
    const panel = document.createElement("section");
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-modal", "true");
    panel.setAttribute("aria-labelledby", "syndicatum-binding-title");
    panel.style.cssText = "width:min(520px,100%);box-sizing:border-box;border:1px solid #405477;border-radius:14px;background:#0d1728;color:#edf4ff;padding:22px;font:14px/1.45 system-ui,sans-serif;box-shadow:0 24px 80px rgba(0,0,0,.5)";
    panel.innerHTML = `<h2 id="syndicatum-binding-title" style="margin:0 0 8px;font-size:20px">Confirm Syndicatum discussion binding</h2>
      <p style="margin:0 0 18px;color:#b7c7df">Review where this ChatGPT discussion will post and receive project messages.</p>
      <dl style="display:grid;grid-template-columns:130px 1fr;gap:9px;margin:0 0 18px"><dt>Server</dt><dd data-field="server"></dd><dt>Discussion</dt><dd data-field="discussion"></dd><dt>Project</dt><dd data-field="project"></dd><dt>Agent identity</dt><dd data-field="agent"></dd><dt>Agent action</dt><dd data-field="action"></dd></dl>
      <p data-field="error" role="alert" style="display:none;background:#481e29;color:#ffc4cf;padding:9px;border-radius:7px"></p>
      <div style="display:flex;justify-content:flex-end;gap:10px"><button data-action="cancel">Cancel</button><button data-action="continue">Continue</button></div>`;
    for (const button of panel.querySelectorAll("button")) button.style.cssText = "border:1px solid #405477;border-radius:8px;background:#121f34;color:#edf4ff;padding:10px 16px;cursor:pointer;font-weight:650";
    panel.querySelector('[data-action="continue"]').style.background = "#234b87";
    panel.querySelector('[data-field="server"]').textContent = intent.server_url || "Syndicatum server";
    panel.querySelector('[data-field="discussion"]').textContent = `${document.title || "ChatGPT discussion"} — ${location.origin}${location.pathname}`;
    panel.querySelector('[data-field="project"]').textContent = `${intent.project_name} (ID ${intent.project_id})`;
    panel.querySelector('[data-field="agent"]').textContent = intent.agent_id
      ? `${intent.agent_name} (ID ${intent.agent_id})` : `${intent.agent_name} (new agent)`;
    panel.querySelector('[data-field="action"]').textContent = intent.agent_action === "create" ? "Create this ChatGPT agent" : "Use the existing ChatGPT agent";
    shadow.appendChild(panel);
    document.documentElement.appendChild(host);
    panel.querySelector('[data-action="cancel"]').focus();

    panel.addEventListener("click", async event => {
      const action = event.target?.dataset?.action;
      if (!action) return;
      if (action === "close") { host.remove(); return; }
      const buttons = [...panel.querySelectorAll("button")];
      buttons.forEach(button => { button.disabled = true; });
      if (action === "verify") {
        await submitBindingStatusCheck(id, panel, confirmedBinding);
        return;
      }
      const result = await chrome.runtime.sendMessage({ type: "syndicatum.binding-intent-response", intentId: id, action })
        .catch(error => ({ ok: false, error: String(error?.message || error) }));
      if (result?.ok && action === "cancel") { host.remove(); return; }
      if (result?.ok && action === "continue") {
        confirmedBinding = result.data;
        await submitBindingStatusCheck(id, panel, confirmedBinding);
        return;
      }
      const error = panel.querySelector('[data-field="error"]');
      error.textContent = result?.error || "The discussion binding could not be completed.";
      error.style.display = "block";
      buttons.forEach(button => { button.disabled = false; });
    });
  }

  async function submitBindingStatusCheck(intentId, panel, binding) {
    const title = panel.querySelector("h2");
    const description = panel.querySelector("h2 + p");
    const error = panel.querySelector('[data-field="error"]');
    const cancel = panel.querySelector('[data-action="cancel"], [data-action="close"]');
    const proceed = panel.querySelector('[data-action="continue"], [data-action="verify"]');
    title.textContent = "Discussion binding successful";
    description.textContent = "Running the Syndicatum MCP status check in this ChatGPT discussion…";
    panel.querySelector('[data-field="server"]').textContent = binding?.server_url || "Syndicatum server";
    panel.querySelector('[data-field="project"]').textContent = `${binding?.project_name || "Project"} (ID ${binding?.project_id || "unavailable"})`;
    panel.querySelector('[data-field="agent"]').textContent = `${binding?.agent_name || "Agent"} (ID ${binding?.agent_id || "unavailable"})`;
    panel.querySelector('[data-field="action"]').textContent = "Bound as this ChatGPT agent";
    error.style.display = "none";
    cancel.dataset.action = "close";
    cancel.textContent = "Close";
    proceed.dataset.action = "verify";
    proceed.textContent = "Retry status check";
    cancel.disabled = true;
    proceed.disabled = true;

    const adapter = root.SyndicatumProviderAdapters?.chatgpt;
    const prompt = [
      "@Syndicatum check status for the discussion binding that just completed.",
      "Call diagnose_connection using the binding_context_id returned by the immediately preceding prepare_discussion_binding tool call, then report the binding status.",
      `Binding request: ${intentId}`,
    ].join("\n");
    const verification = adapter
      ? await Promise.resolve(adapter.deliver(prompt)).catch(reason => ({ ok: false, code: String(reason?.message || reason) }))
      : { ok: false, code: "chatgpt_adapter_missing" };
    if (verification?.ok) {
      description.textContent = "Status check submitted in this discussion. Check its reply to verify the live MCP connection."
        + (binding?.companion_sync_warning ? ` ${binding.companion_sync_warning}` : "");
      cancel.disabled = false;
      return;
    }

    description.textContent = "The binding succeeded, but the automatic MCP status check could not be submitted.";
    error.textContent = `Status check pending: ${verification?.message || verification?.code || "submission failed"}. ${binding?.companion_sync_warning || "You can retry or close this confirmation."}`;
    error.style.display = "block";
    cancel.disabled = false;
    proceed.disabled = false;
  }
})(globalThis);
