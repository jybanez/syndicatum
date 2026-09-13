(function registerGeminiAdapter(root) {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const normalizeText = value => String(value || "").replace(/\s+/g, " ").trim();
  const queryComposer = () => document.querySelector("rich-textarea [contenteditable='true']")
    || document.querySelector(".ql-editor[contenteditable='true']")
    || document.querySelector("main [contenteditable='true'][role='textbox']")
    || document.querySelector("main [contenteditable='true']");
  const userTurns = () => [...document.querySelectorAll("user-query, [data-test-id='user-query'], .user-query-container")];
  const matchingUserTurnCount = text => {
    const expected = normalizeText(text);
    return userTurns().filter(turn => normalizeText(turn.innerText || turn.textContent).includes(expected)).length;
  };
  const isBusy = () => Boolean(document.querySelector("button[aria-label*='Stop response' i], button[aria-label*='Stop generating' i], button.stop-button"));

  function setComposerValue(element, text) {
    element.focus();
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(element);
    selection.removeAllRanges();
    selection.addRange(range);
    document.execCommand("insertText", false, text);
    if (!normalizeText(element.innerText || element.textContent).includes(normalizeText(text).slice(0, 40))) {
      element.textContent = text;
      element.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertText", data: text }));
    }
  }

  async function waitForSendButton(timeoutMs = 5000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      const button = document.querySelector("button.send-button, button[aria-label*='Send message' i], [data-test-id='send-button']");
      if (button && !button.disabled && button.getAttribute("aria-disabled") !== "true") return button;
      await wait(100);
    }
    return null;
  }

  const registry = root.SyndicatumProviderAdapters ||= {};
  registry.gemini = {
    async deliver(text) {
      if (!/^\/app\/[A-Za-z0-9_-]+\/?$/.test(location.pathname)) return { ok: false, retryable: false, code: "wrong_discussion" };
      if (isBusy()) return { ok: false, retryable: true, code: "discussion_busy" };
      const composer = queryComposer();
      if (!composer) {
        const loggedOut = Boolean(document.querySelector("a[href*='accounts.google.com'], a[href*='ServiceLogin']"));
        return { ok: false, retryable: true, code: loggedOut ? "login_required" : "composer_not_found" };
      }
      const before = matchingUserTurnCount(text);
      if (before > 0) return { ok: true, confirmation: "existing_exact_user_turn", deduplicated: true };
      setComposerValue(composer, text);
      const send = await waitForSendButton();
      if (!send) return { ok: false, retryable: true, code: "send_unavailable" };
      send.click();
      const deadline = Date.now() + 12000;
      while (Date.now() < deadline) {
        if (matchingUserTurnCount(text) > before) return { ok: true, confirmation: "new_exact_user_turn" };
        await wait(200);
      }
      return { ok: false, retryable: true, code: "submission_unconfirmed" };
    },
  };
})(globalThis);
