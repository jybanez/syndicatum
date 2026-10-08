(function registerChatGptAdapter(root) {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const COMPOSER_TIMEOUT_MS = 15000;
  const COMPOSER_SELECTORS = [
    "#prompt-textarea",
    "[data-testid='prompt-textarea']",
    "[data-type='unified-composer'] textarea",
    "[data-type='unified-composer'] [contenteditable='true'][role='textbox']",
    "[data-type='unified-composer'] [contenteditable='true']",
    "main form textarea",
    "main form [contenteditable='true'][role='textbox']",
    "main form [contenteditable='true']",
  ];

  function isUsableComposer(element) {
    if (!element || element.isConnected === false || element.hidden || element.disabled || element.readOnly) return false;
    if (element.getAttribute?.("aria-hidden") === "true" || element.getAttribute?.("aria-disabled") === "true") return false;
    if (element.getAttribute?.("contenteditable") === "false") return false;
    return element instanceof HTMLTextAreaElement
      || element instanceof HTMLInputElement
      || element.isContentEditable === true
      || element.getAttribute?.("contenteditable") === "true";
  }

  function queryComposer() {
    for (const selector of COMPOSER_SELECTORS) {
      const element = document.querySelector(selector);
      if (isUsableComposer(element)) return element;
    }
    return null;
  }

  const isDiscussionPath = path => /(?:^|\/)c\/[A-Za-z0-9_-]+\/?$/.test(path);
  const isLoggedOut = () => Boolean(document.querySelector("a[href*='auth/login'], button[data-testid*='login']"));

  async function waitForComposer(expectedPath, timeoutMs = COMPOSER_TIMEOUT_MS) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      if (location.pathname !== expectedPath || !isDiscussionPath(location.pathname)) return { code: "wrong_discussion" };
      if (isLoggedOut()) return { code: "login_required" };
      const composer = queryComposer();
      if (composer) return { composer };
      await wait(100);
    }
    return { code: isLoggedOut() ? "login_required" : "composer_not_found" };
  }

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

  async function waitForSendButton(expectedPath, timeoutMs = 5000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      if (location.pathname !== expectedPath || !isDiscussionPath(location.pathname)) return { code: "wrong_discussion" };
      const button = document.querySelector("[data-testid='send-button'], button[aria-label*='Send message' i], main form button[type='submit']");
      if (button && !button.disabled && button.getAttribute("aria-disabled") !== "true") return { button };
      await wait(100);
    }
    return { code: "send_unavailable" };
  }

  const registry = root.SyndicatumProviderAdapters ||= {};
  registry.chatgpt = {
    async deliver(text) {
      if (!isDiscussionPath(location.pathname)) return { ok: false, retryable: false, code: "wrong_discussion" };
      const expectedPath = location.pathname;
      const composerResult = await waitForComposer(expectedPath);
      if (!composerResult.composer) return { ok: false, retryable: composerResult.code !== "wrong_discussion", code: composerResult.code };
      const composer = composerResult.composer;
      setComposerValue(composer, text);
      const sendResult = await waitForSendButton(expectedPath);
      if (!sendResult.button) return { ok: false, retryable: sendResult.code !== "wrong_discussion", code: sendResult.code };
      const send = sendResult.button;
      send.click();
      return { ok: true, confirmation: "submitted_awaiting_agent_receipt" };
    },
  };
})(globalThis);
