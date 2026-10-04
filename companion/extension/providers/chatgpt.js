(function registerChatGptAdapter(root) {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const queryComposer = () => document.querySelector("#prompt-textarea")
    || document.querySelector("main form textarea")
    || document.querySelector("main form [contenteditable='true']");
  const normalizeText = value => String(value || "").replace(/\s+/g, " ").trim();
  const notificationIdentity = value => {
    const text = normalizeText(value);
    const field = label => text.match(new RegExp(`\\b${label}\\s*[:#]?\\s*(\\d+)\\b`, "i"))?.[1] || null;
    const identity = [field("project"), field("agent"), field("message"), field("sequence")];
    return identity.every(Boolean) ? identity.join(":") : null;
  };
  const matchesNotification = (turn, text) => {
    const actual = normalizeText(turn?.innerText || turn?.textContent);
    const expected = normalizeText(text);
    if (!actual || !expected) return false;
    if (actual.includes(expected)) return true;
    const expectedIdentity = notificationIdentity(expected);
    return Boolean(expectedIdentity && notificationIdentity(actual) === expectedIdentity);
  };
  const isVisibleTurn = turn => {
    if (!turn?.isConnected || turn.closest?.("[hidden], [aria-hidden='true'], [inert]")) return false;
    if (typeof turn.checkVisibility === "function") {
      return turn.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true });
    }
    for (let node = turn; node instanceof HTMLElement; node = node.parentElement) {
      const style = window.getComputedStyle(node);
      if (style.display === "none" || style.visibility === "hidden" || style.opacity === "0") return false;
    }
    return turn.getClientRects().length > 0;
  };
  const userTurns = () => [...new Set(document.querySelectorAll("[data-message-author-role='user'], [data-testid='user-message']"))].filter(isVisibleTurn);
  const matchingUserTurnCount = text => {
    return userTurns().filter(turn => matchesNotification(turn, text)).length;
  };
  const isBusy = () => Boolean(document.querySelector("[data-testid='stop-button'], button[aria-label*='Stop generating' i], button[aria-label='Stop']"));

  function setComposerValue(element, text) {
    element.focus();
    if (element instanceof HTMLTextAreaElement || element instanceof HTMLInputElement) {
      const descriptor = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), "value");
      descriptor?.set ? descriptor.set.call(element, text) : (element.value = text);
      element.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertText", data: text }));
      return;
    }
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(element);
    selection.removeAllRanges();
    selection.addRange(range);
    document.execCommand("insertText", false, text);
    if (!String(element.innerText || element.textContent || "").includes(text.slice(0, 40))) {
      element.textContent = text;
      element.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertText", data: text }));
    }
  }

  async function waitForSendButton(timeoutMs = 5000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      const button = document.querySelector("[data-testid='send-button'], button[aria-label*='Send message' i], main form button[type='submit']");
      if (button && !button.disabled && button.getAttribute("aria-disabled") !== "true") return button;
      await wait(100);
    }
    return null;
  }

  const registry = root.SyndicatumProviderAdapters ||= {};
  registry.chatgpt = {
    async deliver(text) {
      if (!/(?:^|\/)c\/[A-Za-z0-9_-]+\/?$/.test(location.pathname)) return { ok: false, retryable: false, code: "wrong_discussion" };
      const before = matchingUserTurnCount(text);
      if (before > 0) return { ok: true, confirmation: "existing_notification_turn", deduplicated: true };
      if (isBusy()) return { ok: false, retryable: true, code: "discussion_busy" };
      const composer = queryComposer();
      if (!composer) {
        const loggedOut = Boolean(document.querySelector("a[href*='auth/login'], button[data-testid*='login']"));
        return { ok: false, retryable: true, code: loggedOut ? "login_required" : "composer_not_found" };
      }
      setComposerValue(composer, text);
      const send = await waitForSendButton();
      if (!send) return { ok: false, retryable: true, code: "send_unavailable" };
      send.click();
      const deadline = Date.now() + 30000;
      while (Date.now() < deadline) {
        if (matchingUserTurnCount(text) > before) return { ok: true, confirmation: "new_notification_turn" };
        await wait(200);
      }
      return { ok: false, retryable: true, code: "submission_unconfirmed" };
    },
  };
})(globalThis);
