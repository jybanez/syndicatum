(function registerChatGptAdapter(root) {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const queryComposer = () => document.querySelector("#prompt-textarea")
    || document.querySelector("main form textarea")
    || document.querySelector("main form [contenteditable='true']");
  const userTurnCount = () => document.querySelectorAll("[data-message-author-role='user']").length;
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
      if (isBusy()) return { ok: false, retryable: true, code: "discussion_busy" };
      const composer = queryComposer();
      if (!composer) {
        const loggedOut = Boolean(document.querySelector("a[href*='auth/login'], button[data-testid*='login']"));
        return { ok: false, retryable: true, code: loggedOut ? "login_required" : "composer_not_found" };
      }
      const before = userTurnCount();
      setComposerValue(composer, text);
      const send = await waitForSendButton();
      if (!send) return { ok: false, retryable: true, code: "send_unavailable" };
      send.click();
      const deadline = Date.now() + 12000;
      while (Date.now() < deadline) {
        if (userTurnCount() > before) return { ok: true };
        await wait(200);
      }
      return { ok: false, retryable: true, code: "submission_unconfirmed" };
    },
  };
})(globalThis);
