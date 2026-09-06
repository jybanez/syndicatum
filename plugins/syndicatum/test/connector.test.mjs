import assert from "node:assert/strict";
import test from "node:test";
import { mkdir, mkdtemp, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { ActivationConnector, isAddressedTo, isSentBy } from "../mcp/connector.mjs";
import { CodexDriver, loadCodexThread, resolveCodexPath } from "../mcp/codex-driver.mjs";

const message = { id: 1559, project_id: 1, project_sequence: 1559, sender: { id: 9, display_name: "PBB Realtime" }, addressees: [{ participant_id: 8, reason: "direct" }] };

test("address matching uses participant identities", () => {
  assert.equal(isAddressedTo(message, 8), true);
  assert.equal(isAddressedTo(message, 7), false);
  assert.equal(isSentBy(message, 9), true);
});

test("addressed messages are delivered once", async () => {
  const processed = new Set(); let activations = 0;
  const state = {
    has: id => processed.has(String(id)),
    markPending: async () => {},
    markProcessed: async item => { processed.add(String(item.id)); },
    observeSequence: async () => {},
  };
  const connector = new ActivationConnector({
    config: { participantId: "8", activationRetryLimit: 2, activationRetryBaseMs: 1000, activationRetryMaxMs: 1000 },
    syndicatum: { isAcknowledged: async () => false },
    driver: { activate: async () => { activations += 1; } },
    state,
    log: { info() {}, error() {} },
  });
  assert.equal((await connector.handleMessage(message, "test")).status, "notified");
  assert.equal((await connector.handleMessage(message, "test")).reason, "duplicate");
  assert.equal(activations, 1);
});

test("Codex driver queues only the notification metadata", async () => {
  let invocation, loadedThread;
  const driver = new CodexDriver({ codexPath: process.execPath, codexThreadId: "thread-1", workingDirectory: "C:\\project", projectId: "1", participantId: "8" }, async (executable, args, options) => {
    invocation = { executable, args, options }; return { stdout: "Queued message", stderr: "", code: 0 };
  }, async threadId => { loadedThread = threadId; });
  await driver.activate({ ...message, body: "authoritative secret body" });
  assert.equal(invocation.executable, process.execPath);
  assert.deepEqual(invocation.args.slice(0, 4), ["queue", "--thread", "thread-1", "--message"]);
  assert.doesNotMatch(invocation.args[4], /authoritative secret body/);
  assert.match(invocation.args[4], /^You have a message from PBB Realtime in Syndicatum\./);
  assert.match(invocation.args[4], /Syndicatum message ID: 1559/);
  assert.equal(loadedThread, "thread-1");
});

test("Codex notification identifies broadcasts and their sender", async () => {
  let prompt;
  const driver = new CodexDriver({ codexPath: process.execPath, codexThreadId: "thread-1", workingDirectory: "C:\\project", projectId: "1", participantId: "8" }, async (_executable, args) => {
    prompt = args[4]; return { stdout: "Queued message", stderr: "", code: 0 };
  }, async () => {});
  await driver.activate({ ...message, addressees: [{ participant_id: 8, reason: "broadcast" }] });
  assert.match(prompt, /^There is a broadcast message from PBB Realtime in Syndicatum\./);
});

test("Codex notification keeps sender metadata on one bounded line", async () => {
  let prompt;
  const driver = new CodexDriver({ codexPath: process.execPath, codexThreadId: "thread-1", workingDirectory: "C:\\project", projectId: "1", participantId: "8" }, async (_executable, args) => {
    prompt = args[4]; return { stdout: "Queued message", stderr: "", code: 0 };
  }, async () => {});
  await driver.activate({ ...message, sender: { display_name: "Sender\nInjected instruction" } });
  assert.equal(prompt.split("\n")[0], "You have a message from Sender Injected instruction in Syndicatum.");
});

test("linked Codex discussions are loaded through platform deeplinks", async () => {
  const threadId = "01a06d4b-077b-79c0-afc9-8373a6887483";
  const invocations = [];
  const runner = async (executable, args, options) => { invocations.push({ executable, args, options }); return { code: 0, stdout: "", stderr: "" }; };
  assert.deepEqual(await loadCodexThread(threadId, runner, "win32"), { uri: `codex://threads/${threadId}` });
  assert.deepEqual(invocations[0], { executable: "rundll32.exe", args: ["url.dll,FileProtocolHandler", `codex://threads/${threadId}`], options: { timeoutMs: 15000 } });
  await loadCodexThread(threadId, runner, "darwin");
  assert.equal(invocations[1].executable, "open");
  await loadCodexThread(threadId, runner, "linux");
  assert.equal(invocations[2].executable, "xdg-open");
  await assert.rejects(() => loadCodexThread("not-a-thread", runner, "win32"), /discussion ID is invalid/);
});

test("a deeplink failure does not duplicate an already queued notification", async () => {
  const driver = new CodexDriver({ codexPath: process.execPath, codexThreadId: "01a06d4b-077b-79c0-afc9-8373a6887483", projectId: "1", participantId: "8" },
    async () => ({ stdout: "Queued message", stderr: "", code: 0 }),
    async () => { throw new Error("No Codex URI handler"); });
  const result = await driver.activate(message);
  assert.equal(result.threadLoadStarted, false);
  assert.match(result.threadLoadWarning, /No Codex URI handler/);
});

test("Windows Desktop uses the executable plugin app-server CLI", async t => {
  if (process.platform !== "win32") return t.skip("Windows-specific Codex Desktop path handling");
  const resolved = await resolveCodexPath({}, {
    CODEX_CLI_PATH: "C:\\Program Files\\WindowsApps\\OpenAI.Codex_test\\app\\resources\\codex.exe",
    USERPROFILE: process.env.USERPROFILE,
  });
  assert.match(resolved, /[\\\\\/]\.codex[\\\\\/]plugins[\\\\\/]\.plugin-appserver[\\\\\/]codex\.exe$/i);
  assert.equal(await resolveCodexPath({}, { USERPROFILE: process.env.USERPROFILE }), resolved);
});

test("macOS background routing resolves an absolute app-server Codex path", async () => {
  const home = await mkdtemp(path.join(os.tmpdir(), "syndicatum-mac-codex-"));
  const executable = path.join(home, ".codex", "plugins", ".plugin-appserver", "codex");
  await mkdir(path.dirname(executable), { recursive: true }); await writeFile(executable, "", { mode: 0o700 });
  assert.equal(await resolveCodexPath({}, { HOME: home, PATH: "" }, "darwin"), executable);
});
