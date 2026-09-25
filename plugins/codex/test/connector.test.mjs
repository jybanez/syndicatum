import assert from "node:assert/strict";
import test from "node:test";
import { mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { ActivationConnector, isAddressedTo, isSentBy } from "../mcp/connector.mjs";
import { CodexDriver, loadCodexThread, resolveCodexPath } from "../mcp/codex-driver.mjs";
import { StateStore } from "../mcp/state-store.mjs";
import { DeviceConnector } from "../mcp/device-connector.mjs";

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
    activeWake: () => null,
    markPending: async () => {},
    markProcessed: async item => { processed.add(String(item.id)); }, markWakeQueued: async item => { processed.add(String(item.id)); },
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

test("new activity is coalesced behind one wake and only unresolved activity gets one follow-up", async () => {
  const processed = new Set(), acknowledged = new Set(), activations = [];
  let wake = null;
  const state = {
    has: id => processed.has(String(id)) || Boolean(wake?.messages.some(item => String(item.id) === String(id))),
    activeWake: () => wake ? structuredClone(wake) : null,
    markPending: async () => {},
    markProcessed: async item => { processed.add(String(item.id)); },
    markWakeQueued: async (item, discussionId) => { wake = { anchor_message_id: String(item.id), discussion_id: String(discussionId || ""), high_water_sequence: item.project_sequence, messages: [structuredClone(item)] }; },
    coalesceWake: async item => { wake.messages.push(structuredClone(item)); wake.high_water_sequence = item.project_sequence; },
    clearWake: async items => { for (const item of items) processed.add(String(item.id)); wake = null; },
    observeSequence: async () => {},
  };
  const second = { ...message, id: 1560, project_sequence: 1560 };
  const connector = new ActivationConnector({
    config: { participantId: "8", coalescingPollMs: 60000, activationRetryLimit: 2, activationRetryBaseMs: 1000, activationRetryMaxMs: 1000 },
    syndicatum: {
      isAcknowledged: async id => acknowledged.has(String(id)),
      addressedUnacknowledged: async () => [second],
    },
    driver: { activate: async item => { activations.push(String(item.id)); } },
    state,
    log: { info() {}, error() {} },
  });
  assert.equal((await connector.handleMessage(message, "test")).status, "notified");
  assert.equal((await connector.handleMessage(second, "test")).status, "coalesced");
  assert.deepEqual(activations, ["1559"]);
  acknowledged.add("1559");
  assert.equal((await connector.reconcileWake()).status, "notified");
  assert.deepEqual(activations, ["1559", "1560"]);
  connector.stop();
});

test("a stale unacknowledged wake cannot suppress newer notifications indefinitely", async () => {
  const processed = new Set(), activations = [];
  let wake = null;
  const second = { ...message, id: 1560, project_sequence: 1560 };
  const third = { ...message, id: 1561, project_sequence: 1561 };
  const state = {
    has: id => processed.has(String(id)) || Boolean(wake?.messages.some(item => String(item.id) === String(id))),
    activeWake: () => wake ? structuredClone(wake) : null,
    markPending: async () => {},
    markProcessed: async item => { processed.add(String(item.id)); },
    markWakeQueued: async (item, discussionId) => { wake = { anchor_message_id: String(item.id), discussion_id: String(discussionId || ""), high_water_sequence: item.project_sequence, messages: [structuredClone(item)], queued_at: new Date(Date.now() - 120000).toISOString() }; },
    coalesceWake: async item => { wake.messages.push(structuredClone(item)); wake.high_water_sequence = item.project_sequence; },
    clearWake: async items => { for (const item of items) processed.add(String(item.id)); wake = null; },
    observeSequence: async () => {},
  };
  const connector = new ActivationConnector({
    config: { participantId: "8", codexThreadId: "discussion-1", coalescingPollMs: 60000, coalescingMaxAgeMs: 60000, activationRetryLimit: 2, activationRetryBaseMs: 1000, activationRetryMaxMs: 1000 },
    syndicatum: { isAcknowledged: async () => false, addressedUnacknowledged: async () => [third, second, message] },
    driver: { activate: async item => { activations.push(String(item.id)); } },
    state,
    log: { info() {}, error() {} },
  });
  assert.equal((await connector.handleMessage(message, "test")).status, "notified");
  await connector.handleMessage(second, "test");
  await connector.handleMessage(third, "test");
  assert.equal((await connector.reconcileWake()).status, "notified");
  assert.deepEqual(activations, ["1559", "1561"]);
  assert.equal(wake.anchor_message_id, "1561");
  assert.equal(processed.has("1559"), true);
  assert.equal(processed.has("1560"), true);
  connector.stop();
});

test("coalesced wake state and its discussion scope survive connector restart", async () => {
  const directory = await mkdtemp(path.join(os.tmpdir(), "syndicatum-wake-state-"));
  const file = path.join(directory, "state.json");
  try {
    const first = new StateStore(file); await first.load();
    const second = { ...message, id: 1560, project_sequence: 1560 };
    await first.markPending(message);
    await first.markWakeQueued(message, "discussion-1");
    await first.coalesceWake(second);
    const restored = new StateStore(file); await restored.load();
    assert.equal(restored.activeWake().discussion_id, "discussion-1");
    assert.equal(restored.activeWake().high_water_sequence, 1560);
    assert.equal(restored.activeWake().messages.length, 2);
    assert.equal(restored.has(1559), true);
    assert.equal(restored.has(1560), true);
    await restored.clearWake([message]);
    assert.equal(restored.activeWake(), null);
    assert.equal(restored.has(1559), true);
    assert.equal(restored.has(1560), false);
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test("device startup resumes reconciliation for a persisted coalesced wake", async () => {
  const directory = await mkdtemp(path.join(os.tmpdir(), "syndicatum-resume-wake-"));
  const stateFile = path.join(directory, "state.json");
  const participantState = new StateStore(`${stateFile}.participant-34`);
  const newer = { ...message, id: 1684, project_id: 3, project_sequence: 43, addressees: [{ participant_id: 34, reason: "direct" }] };
  const older = { ...message, id: 1683, project_id: 3, project_sequence: 42, addressees: [{ participant_id: 34, reason: "direct" }] };
  try {
    await participantState.load();
    await participantState.markPending(older);
    await participantState.markWakeQueued(older, "discussion-1");
    await participantState.coalesceWake(newer);
    const identityClient = { isAcknowledged: async () => false, addressedUnacknowledged: async () => [newer, older] };
    const connector = new DeviceConnector({
      config: { stateFile, syndicatumUrl: "https://syndicatum.wizaya.com", coalescingPollMs: 60000, coalescingMaxAgeMs: 60000 },
      syndicatum: {},
      bindings: [{ project_id: 3, participant_id: 34, agent_id: 31, conversation_id: "discussion-1", working_directory: null }],
      loadProfile: async () => ({ syndicatum_url: "https://syndicatum.wizaya.com", project_id: 3, participant_id: 34, token: "protected" }),
      identityClientFactory: () => identityClient,
      driverFactory: () => ({ activate: async () => {} }),
      log: { info() {}, error() {} },
    });
    connector.stopped = true;
    await connector.start();
    const processor = connector.processors.get("3")[0];
    await processor.queue;
    assert.notEqual(processor.coalescingTimer, null);
    processor.stop();
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});

test("an unclaimed legacy binding cannot abort the device listener", async () => {
  const directory = await mkdtemp(path.join(os.tmpdir(), "syndicatum-unclaimed-binding-"));
  const logs = [];
  try {
    const connector = new DeviceConnector({
      config: { stateFile: path.join(directory, "state.json"), syndicatumUrl: "https://syndicatum.wizaya.com" },
      syndicatum: {},
      bindings: [{ project_id: 1, participant_id: 1, agent_id: 1, conversation_id: "discussion-1", working_directory: null }],
      loadProfile: async () => { const error = new Error("missing profile"); error.code = "ENOENT"; throw error; },
      driverFactory: () => ({ activate: async () => {} }),
      log: { info: value => logs.push(value), error() {} },
    });
    connector.stopped = true;
    await connector.start();
    assert.equal(connector.processors.get("1")[0].config.coalescingEnabled, false);
    assert.match(logs[0], /local claimed profile could not be used/);
  } finally { await rm(directory, { recursive: true, force: true }); }
});

test("claimed bindings recover missed messages into one coalesced startup wake", async () => {
  const directory = await mkdtemp(path.join(os.tmpdir(), "syndicatum-startup-recovery-"));
  const activations = [];
  const older = { ...message, id: 1683, project_id: 3, project_sequence: 42, addressees: [{ participant_id: 34, reason: "mention" }] };
  const newer = { ...message, id: 1684, project_id: 3, project_sequence: 43, addressees: [{ participant_id: 34, reason: "direct" }] };
  try {
    const identityClient = { isAcknowledged: async () => false, addressedUnacknowledged: async () => [newer, older] };
    const connector = new DeviceConnector({
      config: { stateFile: path.join(directory, "state.json"), syndicatumUrl: "https://syndicatum.wizaya.com", coalescingPollMs: 60000 },
      syndicatum: {},
      bindings: [{ project_id: 3, participant_id: 34, agent_id: 31, conversation_id: "discussion-1", working_directory: null }],
      loadProfile: async () => ({ syndicatum_url: "https://syndicatum.wizaya.com", project_id: 3, participant_id: 34, token: "protected" }),
      identityClientFactory: () => identityClient,
      driverFactory: () => ({ activate: async item => { activations.push(String(item.id)); } }),
      log: { info() {}, error() {} },
    });
    connector.stopped = true;
    await connector.start();
    const processor = connector.processors.get("3")[0]; await processor.queue;
    assert.deepEqual(activations, ["1683"]);
    assert.equal(processor.state.activeWake().high_water_sequence, 43);
    assert.equal(processor.state.activeWake().messages.length, 2);
    processor.stop();
  } finally { await rm(directory, { recursive: true, force: true }); }
});

test("a stale claimed credential cannot abort recovery for another binding", async () => {
  const directory = await mkdtemp(path.join(os.tmpdir(), "syndicatum-stale-profile-"));
  const activations = [];
  const recovered = { ...message, id: 1684, project_id: 3, project_sequence: 43, addressees: [{ participant_id: 34, reason: "direct" }] };
  try {
    const connector = new DeviceConnector({
      config: { stateFile: path.join(directory, "state.json"), syndicatumUrl: "https://syndicatum.wizaya.com", coalescingPollMs: 60000 },
      syndicatum: {},
      bindings: [
        { project_id: 3, participant_id: 32, agent_id: 29, conversation_id: "discussion-stale", working_directory: null },
        { project_id: 3, participant_id: 34, agent_id: 31, conversation_id: "discussion-helper", working_directory: null },
      ],
      loadProfile: async profileId => ({ syndicatum_url: "https://syndicatum.wizaya.com", project_id: 3, participant_id: profileId.endsWith(".29") ? 32 : 34, token: profileId.endsWith(".29") ? "stale" : "healthy" }),
      identityClientFactory: options => options.token === "stale"
        ? { addressedUnacknowledged: async () => { const error = new Error("Authentication is required."); error.status = 401; throw error; } }
        : { isAcknowledged: async () => false, addressedUnacknowledged: async () => [recovered] },
      driverFactory: config => ({ activate: async item => { activations.push(`${config.agentId}:${item.id}`); } }),
      log: { info() {}, error() {} },
    });
    connector.stopped = true;
    await connector.start();
    for (const processors of connector.processors.values()) for (const processor of processors) await processor.queue;
    assert.equal(connector.processors.get("3")[0].config.coalescingEnabled, false);
    assert.equal(connector.processors.get("3")[1].config.coalescingEnabled, true);
    assert.deepEqual(activations, ["31:1684"]);
    for (const processors of connector.processors.values()) for (const processor of processors) processor.stop();
  } finally { await rm(directory, { recursive: true, force: true }); }
});

test("Codex driver queues only the notification metadata", async () => {
  let invocation, loadedThread;
  const driver = new CodexDriver({ codexPath: process.execPath, codexThreadId: "thread-1", workingDirectory: "C:\\project", projectId: "1", participantId: "8", agentId: "29", profileId: "1234567890abcdef.1.29" }, async (executable, args, options) => {
    invocation = { executable, args, options }; return { stdout: "Queued message", stderr: "", code: 0 };
  }, async threadId => { loadedThread = threadId; });
  await driver.activate({ ...message, body: "authoritative secret body" });
  assert.equal(invocation.executable, process.execPath);
  assert.deepEqual(invocation.args.slice(0, 4), ["queue", "--thread", "thread-1", "--message"]);
  assert.doesNotMatch(invocation.args[4], /authoritative secret body/);
  assert.match(invocation.args[4], /^You have a message from PBB Realtime in Syndicatum\./);
  assert.match(invocation.args[4], /installed syndicatum-timeline skill/);
  assert.match(invocation.args[4], /protected profile 1234567890abcdef\.1\.29/);
  assert.match(invocation.args[4], /do not substitute another identity/);
  assert.doesNotMatch(invocation.args[4], /installed pbb-chat-log skill/);
  assert.match(invocation.args[4], /message 1559/);
  assert.match(invocation.args[4], /agent 29/);
  assert.equal(invocation.args[4].split("\n").length, 3);
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
