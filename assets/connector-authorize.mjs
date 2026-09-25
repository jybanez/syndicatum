import { uiLoader } from "../vendor/pbb-helper/dist/helpers.ui.bundle.min.js?v=0.21.205";

const GOOGLE_SIGN_IN_ICON = '<img class="syndicatum-google-button-image" src="assets/google-signin-dark.svg" alt="">';

async function request(url, options = {}) {
  const response = await fetch(url, { credentials: "same-origin", cache: "no-store", ...options });
  const payload = await response.json().catch(() => null);
  if (!response.ok) throw new Error(payload?.message || payload?.error?.message || `Request failed with status ${response.status}`);
  return payload ?? {};
}

function redirectWithBusy(context, url) {
  context?.clearFormError?.();
  context?.setBusy?.(true, { message: "Opening Google sign in..." });
  requestAnimationFrame(() => requestAnimationFrame(() => location.assign(url)));
  return false;
}

const options = { css: false };
await uiLoader.loadMany(["ui.form.modal", "ui.form.modal.login"], options);
const createLoginFormModal = await uiLoader.get("ui.form.modal.login", options);
const session = await request("api/v1/session.php");
const capabilities = session.capabilities || {};
const returnPath = `${location.pathname}${location.search}`;
const extraActions = [];
if (capabilities.google_sso) extraActions.push({
  id: "google",
  label: "Sign in with Google",
  ariaLabel: "Sign in with Google",
  icon: GOOGLE_SIGN_IN_ICON,
  className: "syndicatum-google-button",
  variant: "ghost",
  closeOnClick: false,
  onClick(_values, context) {
    return redirectWithBusy(context, `auth/google.php?return=${encodeURIComponent(returnPath)}`);
  },
});
if (capabilities.account_sso || capabilities.pbb_account) extraActions.push({
  id: "pbb-account",
  label: "Continue with PBB Account",
  variant: "ghost",
  closeOnClick: false,
  onClick() {
    location.assign(`auth/account.php?return=${encodeURIComponent(returnPath)}`);
    return false;
  },
});

const modal = createLoginFormModal({
  title: "Sign in to Syndicatum",
  className: "syndicatum-login-modal",
  size: (capabilities.account_sso || capabilities.pbb_account) && capabilities.google_sso ? "md" : "sm",
  message: "Sign in to review this connector device authorization request.",
  mediaUrl: "assets/brand/svg/syndicatum-standard-color.svg?v=20260907115852",
  mediaAlt: "Syndicatum",
  backgroundTone: "none",
  identifierKind: "email",
  identifierLabel: "Email address",
  identifierPlaceholder: "Enter your email address",
  fields: { identifier: "identity", password: "password" },
  submitLabel: "Sign in",
  busyMessage: "Signing in...",
  closeOnBackdrop: false,
  closeOnEscape: false,
  showCloseButton: false,
  extraActionsPlacement: "start",
  extraActions,
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
