chrome.runtime.onMessage.addListener((request, sender, respond) => {
  if (request?.type !== "syndicatum.provider.deliver") return false;
  const adapter = globalThis.SyndicatumProviderAdapters?.[request.provider];
  if (!adapter) { respond({ ok: false, retryable: false, code: "provider_adapter_missing" }); return false; }
  Promise.resolve(adapter.deliver(String(request.text || "")))
    .then(respond)
    .catch(error => respond({ ok: false, retryable: true, code: "provider_error", message: String(error?.message || error) }));
  return true;
});
