import { uiLoader } from "../vendor/pbb-helper/js/ui/ui.loader.js";

async function request(url, options = {}) {
  const response = await fetch(url, { credentials: "same-origin", cache: "no-store", ...options });
  const payload = await response.json().catch(() => null);
  if (!response.ok) throw new Error(payload?.message || payload?.error?.message || `Request failed with status ${response.status}`);
  return payload?.data ?? payload ?? {};
}

uiLoader.setPreferBundles(true);
const options = { css: false };
await uiLoader.loadMany(["ui.form.modal", "ui.form.modal.login"], options);
const createLoginFormModal = await uiLoader.get("ui.form.modal.login", options);
const session = await request("api/v1/session.php");
const capabilities = session.capabilities || {};
const returnPath = `${location.pathname}${location.search}`;

const modal = createLoginFormModal({
  title: "Sign in to Syndicatum",
  message: "Sign in to review this Codex device authorization request.",
  identifierKind: "username",
  identifierLabel: "Email or username",
  identifierPlaceholder: "Enter email or username",
  fields: { identifier: "identity", password: "password" },
  submitLabel: "Sign in",
  busyMessage: "Signing in...",
  closeOnBackdrop: false,
  closeOnEscape: false,
  showCloseButton: false,
  extraActionsPlacement: "start",
  extraActions: capabilities.account_sso || capabilities.pbb_account ? [{
    id: "pbb-account",
    label: "Continue with PBB Account",
    variant: "ghost",
    closeOnClick: false,
    onClick() {
      location.assign(`auth/account.php?return=${encodeURIComponent(returnPath)}`);
      return false;
    },
  }] : [],
  async onSubmit(values, context) {
    try {
      await request("api/v1/session.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "login", identity: String(values.identity || "").trim(), password: String(values.password || "") }),
      });
      location.reload();
      return true;
    } catch (error) {
      context.setFormError(error.message);
      return false;
    }
  },
  onClose() {
    if (history.length > 1) history.back();
    else location.assign("./");
  },
});

modal.open();
