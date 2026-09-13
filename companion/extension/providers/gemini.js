(function registerGeminiAdapter(root) {
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  const normalizeText = value => String(value || "").replace(/\s+/g, " ").trim();
  const outermost = turns => turns.filter((turn, index) => !turns.some((other, otherIndex) => otherIndex !== index && other.contains(turn)));
  const queryComposer = () => document.querySelector("rich-textarea [contenteditable='true']")
    || document.querySelector(".ql-editor[contenteditable='true']")
    || document.querySelector("main [contenteditable='true'][role='textbox']")
    || document.querySelector("main [contenteditable='true']");
  const userTurns = () => outermost([...document.querySelectorAll("user-query, [data-test-id='user-query'], .user-query-container, [id^='user-query-content-']")]);
  const assistantTurns = () => outermost([...document.querySelectorAll("model-response, [data-test-id='model-response'], .model-response, response-container, [id^='model-response-message-contentr_']")]);
  const matchingUserTurnCount = text => {
    const expected = normalizeText(text);
    return userTurns().filter(turn => normalizeText(turn.innerText || turn.textContent).includes(expected)).length;
  };
  const isBusy = () => Boolean(document.querySelector("button[aria-label*='Stop response' i], button[aria-label*='Stop generating' i], button.stop-button"));

  function responseText(turn) {
    const content = turn?.querySelector?.("message-content, .markdown-main-panel, .response-content, .model-response-text") || turn;
    return String(content?.innerText || content?.textContent || "")
      .replace(/^\s*Gemini said\s*/i, "")
      .trim();
  }

  function isResponseSettled(turn) {
    const container = turn?.closest?.("model-response, [data-test-id='model-response'], .model-response, response-container") || turn?.parentElement;
    return Boolean(container?.querySelector?.("button[aria-label*='Good response' i], button[aria-label*='Bad response' i], button[aria-label='Copy' i], button[aria-label*='Show more options' i]"));
  }

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

  async function waitForResponse(minimumIndex, timeoutMs = 180000) {
    const deadline = Date.now() + timeoutMs;
    let candidate = null;
    let stableText = "";
    let stableSince = 0;
    while (Date.now() < deadline) {
      const turns = assistantTurns();
      candidate = turns[minimumIndex] || null;
      const text = responseText(candidate);
      if (candidate && text) {
        if (text !== stableText) {
          stableText = text;
          stableSince = Date.now();
        } else if (!isBusy() && (isResponseSettled(candidate) || Date.now() - stableSince >= 3500)) {
          return text;
        }
      }
      await wait(250);
    }
    throw new Error(candidate ? "gemini_response_incomplete" : "gemini_response_not_found");
  }

  const registry = root.SyndicatumProviderAdapters ||= {};
  registry.gemini = {
    async deliver(text, hooks = {}) {
      if (!/^\/app\/[A-Za-z0-9_-]+\/?$/.test(location.pathname)) return { ok: false, retryable: false, code: "wrong_discussion" };
      if (isBusy()) return { ok: false, retryable: true, code: "discussion_busy" };
      const composer = queryComposer();
      if (!composer) {
        const loggedOut = Boolean(document.querySelector("a[href*='accounts.google.com'], a[href*='ServiceLogin']"));
        return { ok: false, retryable: true, code: loggedOut ? "login_required" : "composer_not_found" };
      }
      const before = matchingUserTurnCount(text);
      const responseIndex = userTurns().findIndex(turn => normalizeText(turn.innerText || turn.textContent).includes(normalizeText(text)));
      if (before > 0) {
        await hooks.accepted?.({ confirmation: "existing_exact_user_turn", deduplicated: true });
        const captured = await waitForResponse(responseIndex < 0 ? Math.max(0, assistantTurns().length - 1) : responseIndex);
        return { ok: true, confirmation: "existing_exact_user_turn", deduplicated: true, responseText: captured };
      }
      const beforeResponses = assistantTurns().length;
      setComposerValue(composer, text);
      const send = await waitForSendButton();
      if (!send) return { ok: false, retryable: true, code: "send_unavailable" };
      send.click();
      const deadline = Date.now() + 12000;
      while (Date.now() < deadline) {
        if (matchingUserTurnCount(text) > before) {
          await hooks.accepted?.({ confirmation: "new_exact_user_turn" });
          const captured = await waitForResponse(beforeResponses);
          return { ok: true, confirmation: "new_exact_user_turn", responseText: captured };
        }
        await wait(200);
      }
      return { ok: false, retryable: true, code: "submission_unconfirmed" };
    },
  };
})(globalThis);
