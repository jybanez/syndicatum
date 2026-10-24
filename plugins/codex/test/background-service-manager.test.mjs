import assert from "node:assert/strict";
import test from "node:test";
import { mkdtemp, readFile, mkdir, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { EventEmitter } from "node:events";
import { PassThrough } from "node:stream";
import { BackgroundServiceManager, powershellTaskArguments, runtimeRevision, windowsPowerShellPath, windowsRunCommand, windowsTaskArgument } from "../mcp/background-service-manager.mjs";

function successfulSpawn(calls, stdout = "") {
  return (command, args, options) => {
    calls.push([command, args, options]);
    const child = new EventEmitter(); child.stdout = new PassThrough(); child.stderr = new PassThrough();
    process.nextTick(() => { if (stdout) child.stdout.end(stdout); child.emit("close", 0); });
    return child;
  };
}

const sessionProcesses = async () => [
  { pid: process.pid, parentPid: 90000, executable: process.execPath, startedAt: "host-start" },
  { pid: 90000, parentPid: 1, executable: "C:\\Codex\\codex.exe", startedAt: "codex-start" },
];

test("background runtime revision changes when connector source changes", async () => {
  const source = await mkdtemp(path.join(os.tmpdir(), "syndicatum-runtime-revision-"));
  await writeFile(path.join(source, "connector.mjs"), "export const revision = 1;\n", "utf8");
  const first = await runtimeRevision(source);
  await writeFile(path.join(source, "connector.mjs"), "export const revision = 2;\n", "utf8");
  assert.notEqual(await runtimeRevision(source), first);
});

test("Windows task arguments preserve plugin paths containing spaces", () => {
  const file = "C:\\Users\\Test User\\.codex\\plugins\\syndicatum\\background-service.mjs";
  assert.equal(windowsTaskArgument(file), `"${file}"`);
  assert.match(powershellTaskArguments(file), /-WindowStyle Hidden/);
  assert.match(powershellTaskArguments(file), /-File "C:\\Users\\Test User/);
  assert.equal(windowsPowerShellPath({ SystemRoot: "D:\\Windows" }), "D:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe");
  assert.match(windowsRunCommand("C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe", file), /^C:\\Windows.*-File "C:\\Users\\Test User/);
});

test("Windows migration validates owned startup entries before removing them and never creates autostart", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-task-"));
  const calls = [];
  const manager = new BackgroundServiceManager({
    platform: "win32",
    env: { LOCALAPPDATA: localAppData, SystemRoot: "C:\\Windows" },
    spawnImpl: successfulSpawn(calls),
  });
  await manager.retireLegacyAutostart();
  assert.equal(calls[0][0], "C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe");
  const script = calls[0][1].at(-1);
  assert.match(script, /Unexpected legacy task action/);
  assert.match(script, /Legacy task belongs to another user/);
  assert.match(script, /Unexpected legacy Run-key command/);
  assert.ok(script.indexOf("Unexpected legacy Run-key command") < script.indexOf("Unregister-ScheduledTask"));
  assert.match(script, /Remove-ItemProperty/);
  assert.doesNotMatch(script, /New-ScheduledTask|Register-ScheduledTask|Set-ItemProperty|credential/);
});

test("macOS migration removes only its matching legacy LaunchAgent without reinstalling it", async () => {
  const home = await mkdtemp(path.join(os.tmpdir(), "syndicatum mac home "));
  const sourceDirectory = path.join(home, "plugin source");
  await mkdir(sourceDirectory, { recursive: true });
  await writeFile(path.join(sourceDirectory, "background-service.mjs"), "", "utf8");
  const calls = [];
  const spawnImpl = (command, args) => {
    calls.push([command, args]);
    const child = new EventEmitter(); child.stdout = new PassThrough(); child.stderr = new PassThrough();
    process.nextTick(() => child.emit("close", 0)); return child;
  };
  const manager = new BackgroundServiceManager({ platform: "darwin", env: { HOME: home, UID: "501" }, execPath: "/Applications/Codex App/node", sourceDirectory, spawnImpl });
  await manager.installRuntime({ execPath: manager.execPath, serviceEntry: manager.serviceEntry, sourceDirectory });
  await mkdir(path.dirname(manager.files.launchAgent), { recursive: true });
  await writeFile(manager.files.launchAgent, `<string>ph.pbb.syndicatum.codex-connector</string><string>${manager.serviceEntry}</string>`);
  await manager.retireLegacyAutostart();
  await assert.rejects(readFile(manager.files.launchAgent), { code: "ENOENT" });
  assert.deepEqual(calls.map(item => item[1][0]), ["bootout"]);
});

test("session installation copies runtime and ownership metadata without a sign-in launcher", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-background-"));
  const sourceDirectory = path.join(localAppData, "source");
  const manager = new BackgroundServiceManager({
    platform: "win32",
    env: { LOCALAPPDATA: localAppData },
    execPath: "C:\\Program Files\\nodejs\\node.exe",
    sourceDirectory,
  });
  await mkdir(sourceDirectory, { recursive: true });
  await writeFile(path.join(sourceDirectory, "background-service.mjs"), "", "utf8");
  await manager.installRuntime({ execPath: manager.execPath, serviceEntry: manager.serviceEntry, sourceDirectory, lifecycle: "codex_session", codexOwners: [{ pid: 123 }] });
  await assert.rejects(readFile(manager.files.backgroundLauncher), { code: "ENOENT" });
  const metadata = JSON.parse(await readFile(manager.files.backgroundMetadata, "utf8"));
  assert.equal(metadata.lifecycle, "codex_session");
  assert.deepEqual(metadata.codexOwners, [{ pid: 123 }]);
});

test("Windows session startup retires legacy autostart without creating a task or Run key", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-fallback-"));
  const manager = new BackgroundServiceManager({ platform: "win32", env: { LOCALAPPDATA: localAppData }, processReader: sessionProcesses });
  const calls = [];
  manager.status = async () => ({ running: false, ownsListener: false, metadata: null });
  manager.installRuntime = async () => { await mkdir(manager.files.root, { recursive: true }); };
  manager.retireLegacyAutostart = async () => { calls.push("retire-autostart"); };
  manager.startSessionRuntime = async () => { calls.push("start-session"); };
  manager.waitUntilRunning = async () => ({ running: true, ownsListener: true, readiness: "ready", pid: 42 });
  const result = await manager.ensureRunning();
  assert.equal(result.startupMethod, "codex_session");
  assert.deepEqual(calls, ["retire-autostart", "start-session"]);
});

test("concurrent background installation is serialized across plugin hosts", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-startup-lock-"));
  const sourceDirectory = path.join(localAppData, "source");
  await mkdir(sourceDirectory, { recursive: true });
  await writeFile(path.join(sourceDirectory, "background-service.mjs"), "", "utf8");
  let healthy = false;
  let installations = 0;
  const makeManager = () => {
    const manager = new BackgroundServiceManager({ platform: "win32", env: { LOCALAPPDATA: localAppData }, sourceDirectory, processReader: sessionProcesses });
    manager.status = async () => healthy
      ? { running: true, ownsListener: true, readiness: "ready", pid: 42, metadata: await manager.desiredRuntime() }
      : { running: false, ownsListener: false, readiness: "stopped", metadata: null };
    manager.installRuntime = async () => { installations += 1; await new Promise(resolve => setTimeout(resolve, 25)); };
    manager.retireLegacyAutostart = async () => {};
    manager.startSessionRuntime = async () => {};
    manager.waitUntilRunning = async () => { healthy = true; return manager.status(); };
    return manager;
  };
  const first = makeManager();
  const second = makeManager();
  const results = await Promise.all([first.ensureRunning(), second.ensureRunning()]);
  assert.equal(installations, 1);
  assert.ok(results.every(result => result.running && result.ownsListener));
});

test("background status distinguishes an authorized device with no usable discussion bindings", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-health-"));
  const manager = new BackgroundServiceManager({ platform: "win32", env: { LOCALAPPDATA: localAppData } });
  await mkdir(manager.files.root, { recursive: true });
  await writeFile(manager.files.backgroundLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.listenerLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.backgroundHealth, JSON.stringify({ pid: process.pid, state: "authorized_idle", unavailableBindings: 2, updatedAt: new Date().toISOString() }), "utf8");
  const status = await manager.status();
  assert.equal(status.readiness, "authorized_idle");
  assert.equal(status.healthFresh, true);
  assert.equal(status.listenerReason, "authorized_without_local_routes");
});

test("background status exposes partial listener loss as degraded", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-degraded-health-"));
  const manager = new BackgroundServiceManager({ platform: "win32", env: { LOCALAPPDATA: localAppData } });
  await mkdir(manager.files.root, { recursive: true });
  await writeFile(manager.files.backgroundLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.listenerLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.backgroundHealth, JSON.stringify({ pid: process.pid, state: "degraded", bindings: 1, projects: 1,
    requestedBindings: 2, initializedBindings: 1, unavailableBindings: 1,
    unavailableBindingReasons: [{ projectId: "2", agentId: "30", reason: "binding_initialization_failed" }],
    updatedAt: new Date().toISOString() }), "utf8");

  const status = await manager.status();

  assert.equal(status.readiness, "degraded");
  assert.equal(status.listenerReason, "listener_routes_degraded");
  assert.equal(status.health.bindings, 1);
  assert.equal(status.health.unavailableBindings, 1);
});

test("background status rejects stale health even when the process owns the listener", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-stale-health-"));
  const manager = new BackgroundServiceManager({ platform: "win32", env: { LOCALAPPDATA: localAppData } });
  await mkdir(manager.files.root, { recursive: true });
  await writeFile(manager.files.backgroundLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.listenerLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.backgroundHealth, JSON.stringify({ pid: process.pid, state: "ready", updatedAt: "2000-01-01T00:00:00.000Z" }), "utf8");
  const status = await manager.status();
  assert.equal(status.running, true);
  assert.equal(status.ownsListener, true);
  assert.equal(status.healthFresh, false);
  assert.equal(status.readiness, "stale_health");
  assert.equal(status.listenerReason, "background_health_stale");
});

test("background status does not report ready while configuration reload is pending", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-config-reload-health-"));
  const manager = new BackgroundServiceManager({ platform: "win32", env: { LOCALAPPDATA: localAppData } });
  await mkdir(manager.files.root, { recursive: true });
  await writeFile(manager.files.backgroundLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.listenerLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.backgroundHealth, JSON.stringify({ pid: process.pid, state: "reloading", bindings: 38, projects: 7, updatedAt: new Date().toISOString() }), "utf8");

  const status = await manager.status();

  assert.equal(status.running, true);
  assert.equal(status.ownsListener, true);
  assert.equal(status.readiness, "starting_listener");
  assert.equal(status.listenerReason, "listener_reloading");
  assert.notEqual(status.readiness, "ready");
});

test("background status rejects legacy configured-only health as not ready", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-configured-health-"));
  const manager = new BackgroundServiceManager({ platform: "win32", env: { LOCALAPPDATA: localAppData } });
  await mkdir(manager.files.root, { recursive: true });
  await writeFile(manager.files.backgroundLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.listenerLock, `${process.pid}\n`, "utf8");
  await writeFile(manager.files.backgroundHealth, JSON.stringify({ pid: process.pid, state: "configured", bindings: 0, projects: 0, updatedAt: new Date().toISOString() }), "utf8");

  const status = await manager.status();

  assert.equal(status.readiness, "starting_listener");
  assert.equal(status.listenerReason, "listener_reloading");
  assert.notEqual(status.readiness, "ready");
});
