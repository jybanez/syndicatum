import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import vm from "node:vm";

const source = await readFile(new URL("../extension/providers/chatgpt.js", import.meta.url), "utf8");

function adapterFor({ pathname = "/c/test-discussion", composer = null, send = null } = {}) {
  let queryAllCalls = 0;
  const context = {
    HTMLTextAreaElement: class {}, HTMLInputElement: class {}, InputEvent: class {},
    document: {
      querySelector(selector) {
        if (selector.includes("prompt-textarea")) return composer;
        if (selector.includes("send-button")) return send;
        return null;
      },
      querySelectorAll() { queryAllCalls += 1; return []; },
      createRange() { return { selectNodeContents() {} }; },
      execCommand() { return true; },
    },
    location: { pathname }, setTimeout,
    window: { getSelection: () => ({ removeAllRanges() {}, addRange() {} }) },
  };
  vm.runInNewContext(source, context);
  return { adapter: context.SyndicatumProviderAdapters.chatgpt, queryAllCalls: () => queryAllCalls };
}

test("ChatGPT submits once and waits for an explicit agent receipt", async () => {
  let clicks = 0;
  const composer = { focus() {}, textContent: "", dispatchEvent() {} };
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

test("ChatGPT does not submit when the send control never becomes available", async () => {
  const composer = { focus() {}, textContent: "", dispatchEvent() {} };
  const result = await adapterFor({ composer }).adapter.deliver("notice");
  assert.equal(result.ok, false);
  assert.equal(result.code, "send_unavailable");
});
