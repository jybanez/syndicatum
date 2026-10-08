import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import vm from "node:vm";

const source = await readFile(new URL("../extension/providers/chatgpt.js", import.meta.url), "utf8");

const editableComposer = overrides => ({
  isContentEditable: true,
  focus() {},
  textContent: "",
  dispatchEvent() {},
  getAttribute: () => null,
  ...overrides,
});

function adapterFor({ pathname = "/c/test-discussion", composer = null, composerAfter = 0, send = null, sendAfter = 0, selectorComposer = null, navigateAfter = null } = {}) {
  let queryAllCalls = 0;
  let composerQueries = 0;
  let sendQueries = 0;
  let now = 0;
  const location = { pathname };
  const context = {
    HTMLTextAreaElement: class {}, HTMLInputElement: class {}, InputEvent: class {},
    Date: { now: () => now },
    document: {
      querySelector(selector) {
        if (selector.includes("auth/login") || selector.includes("data-testid*='login'")) return null;
        if (selector.includes("send-button")) {
          sendQueries += 1;
          return sendQueries > sendAfter ? send : null;
        }
        if (selectorComposer ? selector === selectorComposer : selector.includes("prompt-textarea")) {
          composerQueries += 1;
          return composerQueries > composerAfter ? composer : null;
        }
        return null;
      },
      querySelectorAll() { queryAllCalls += 1; return []; },
      createRange() { return { selectNodeContents() {} }; },
      execCommand() { return true; },
    },
    location,
    setTimeout(callback, milliseconds) {
      now += milliseconds;
      if (navigateAfter !== null && now >= navigateAfter) location.pathname = "/c/another-discussion";
      callback();
      return 1;
    },
    window: { getSelection: () => ({ removeAllRanges() {}, addRange() {} }) },
  };
  vm.runInNewContext(source, context);
  return {
    adapter: context.SyndicatumProviderAdapters.chatgpt,
    queryAllCalls: () => queryAllCalls,
    composerQueries: () => composerQueries,
  };
}

test("ChatGPT submits once and waits for an explicit agent receipt", async () => {
  let clicks = 0;
  const composer = editableComposer();
  const send = { disabled: false, getAttribute: () => null, click: () => { clicks += 1; } };
  const harness = adapterFor({ composer, send });
  const result = await harness.adapter.deliver("Syndicatum notification");
  assert.deepEqual({ ...result }, { ok: true, confirmation: "submitted_awaiting_agent_receipt" });
  assert.equal(clicks, 1);
  assert.equal(harness.queryAllCalls(), 0, "rendered ChatGPT turns must not be inspected for delivery confirmation");
});

test("ChatGPT reports clear pre-submission failures", async () => {
  assert.equal((await adapterFor({ pathname: "/" }).adapter.deliver("notice")).code, "wrong_discussion");
  assert.equal((await adapterFor().adapter.deliver("notice")).code, "composer_not_found");
});

test("ChatGPT waits for delayed SPA composer rendering", async () => {
  let clicks = 0;
  const composer = editableComposer();
  const send = { disabled: false, getAttribute: () => null, click: () => { clicks += 1; } };
  const harness = adapterFor({ composer, composerAfter: 3, send });
  const result = await harness.adapter.deliver("delayed notice");
  assert.equal(result.ok, true);
  assert.equal(clicks, 1);
  assert.equal(harness.composerQueries(), 4);
});

test("ChatGPT recognizes the unified composer variant", async () => {
  let clicks = 0;
  const composer = editableComposer();
  const send = { disabled: false, getAttribute: () => null, click: () => { clicks += 1; } };
  const result = await adapterFor({
    composer,
    send,
    selectorComposer: "[data-type='unified-composer'] [contenteditable='true'][role='textbox']",
  }).adapter.deliver("variant notice");
  assert.equal(result.ok, true);
  assert.equal(clicks, 1);
});

test("ChatGPT rejects hidden, disabled, or non-editable composer candidates", async () => {
  const hidden = editableComposer({ hidden: true });
  const disabled = editableComposer({ disabled: true });
  const wrapper = { focus() {}, textContent: "", dispatchEvent() {}, getAttribute: () => null };
  assert.equal((await adapterFor({ composer: hidden }).adapter.deliver("notice")).code, "composer_not_found");
  assert.equal((await adapterFor({ composer: disabled }).adapter.deliver("notice")).code, "composer_not_found");
  assert.equal((await adapterFor({ composer: wrapper }).adapter.deliver("notice")).code, "composer_not_found");
});

test("ChatGPT cancels delivery when the discussion changes while waiting", async () => {
  let clicks = 0;
  const composer = editableComposer();
  const send = { disabled: false, getAttribute: () => null, click: () => { clicks += 1; } };
  const result = await adapterFor({ composer, composerAfter: 5, send, navigateAfter: 200 }).adapter.deliver("notice");
  assert.equal(result.ok, false);
  assert.equal(result.code, "wrong_discussion");
  assert.equal(result.retryable, false);
  assert.equal(clicks, 0);
});

test("ChatGPT does not submit when the send control never becomes available", async () => {
  const composer = editableComposer();
  const result = await adapterFor({ composer }).adapter.deliver("notice");
  assert.equal(result.ok, false);
  assert.equal(result.code, "send_unavailable");
});
