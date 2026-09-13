(function installSyndicatumCompanionListener(root) {
  if (root.__syndicatumCompanionListenerInstalled) return;
  root.__syndicatumCompanionListenerInstalled = true;
  chrome.runtime.onMessage.addListener((request, sender, respond) => {
    if (request?.type !== "syndicatum.provider.deliver") return false;
    const adapter = root.SyndicatumProviderAdapters?.[request.provider];
    if (!adapter) { respond({ ok: false, retryable: false, code: "provider_adapter_missing" }); return false; }
    Promise.resolve(adapter.deliver(String(request.text || "")))
      .then(respond)
      .catch(error => respond({ ok: false, retryable: true, code: "provider_error", message: String(error?.message || error) }));
    return true;
  });
})(globalThis);
