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

test("Windows task registration uses an absolute PowerShell path and no working directory", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-task-"));
  const calls = [];
  const manager = new BackgroundServiceManager({
    platform: "win32",
    env: { LOCALAPPDATA: localAppData, SystemRoot: "C:\\Windows" },
    spawnImpl: successfulSpawn(calls),
  });
  await manager.registerWindowsLauncher();
  assert.equal(calls[0][0], "C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe");
  assert.match(calls[0][1].at(-1), /New-ScheduledTaskAction -Execute 'C:\\Windows\\System32\\WindowsPowerShell\\v1\.0\\powershell\.exe'/);
  assert.doesNotMatch(calls[0][1].at(-1), /WorkingDirectory/);
});

test("macOS installs a per-user LaunchAgent with paths safe for spaces", async () => {
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
  await manager.registerMacLaunchAgent();
  const plist = await readFile(manager.files.launchAgent, "utf8");
  assert.match(plist, /ph\.pbb\.syndicatum\.codex-connector/);
  assert.match(plist, /<key>KeepAlive<\/key><true\/>/);
  assert.match(plist, /\/Applications\/Codex App\/node/);
  assert.deepEqual(calls.map(item => item[1][0]), ["bootout", "bootstrap", "kickstart"]);
});

test("Windows launcher decrypts the credential before starting the background runtime", async () => {
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
  await manager.installRuntime({ execPath: manager.execPath, serviceEntry: manager.serviceEntry, sourceDirectory });
  const launcher = await readFile(manager.files.backgroundLauncher, "utf8");
  assert.match(launcher, /ProtectedData\]::Unprotect/);
  assert.match(launcher, /SYNDICATUM_AGENT_TOKEN/);
  assert.match(launcher, /background-service\.mjs/);
  assert.match(launcher, /background-startup\.log/);
  assert.match(launcher, /credential_dpapi/);
  assert.match(launcher, /runtime_start/);
});

test("Windows installation falls back to the current-user Run key when the task is not healthy", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-fallback-"));
  const manager = new BackgroundServiceManager({ platform: "win32", env: { LOCALAPPDATA: localAppData } });
  const calls = [];
  manager.status = async () => ({ running: false, ownsListener: false, metadata: null });
  manager.installRuntime = async () => { await mkdir(manager.files.root, { recursive: true }); };
  manager.registerWindowsLauncher = async () => { calls.push("register-task"); };
  manager.startWindowsLauncher = async () => { calls.push("start-task"); };
  manager.windowsTaskInfo = async () => ({ state: "Ready", lastTaskResult: 1 });
  manager.disableWindowsTask = async () => { calls.push("disable-task"); };
  manager.registerWindowsRunFallback = async () => { calls.push("register-run-key"); };
  manager.startWindowsRunFallback = async () => { calls.push("start-run-key"); };
  manager.waitUntilRunning = async options => options?.attempts === 50
    ? { running: false, ownsListener: false, readiness: "stopped" }
    : { running: true, ownsListener: true, readiness: "ready", pid: 42 };
  const result = await manager.ensureRunning();
  assert.equal(result.startupMethod, "run_key");
  assert.match(result.startupDiagnostic, /result=1/);
  assert.deepEqual(calls, ["register-task", "start-task", "disable-task", "register-run-key", "start-run-key"]);
  assert.match(await readFile(manager.files.backgroundStartupLog, "utf8"), /scheduled_task/);
});

test("concurrent background installation is serialized across plugin hosts", async () => {
  const localAppData = await mkdtemp(path.join(os.tmpdir(), "syndicatum-startup-lock-"));
  const sourceDirectory = path.join(localAppData, "source");
  await mkdir(sourceDirectory, { recursive: true });
  await writeFile(path.join(sourceDirectory, "background-service.mjs"), "", "utf8");
  let healthy = false;
  let installations = 0;
  const makeManager = () => {
    const manager = new BackgroundServiceManager({ platform: "win32", env: { LOCALAPPDATA: localAppData }, sourceDirectory });
    manager.status = async () => healthy
      ? { running: true, ownsListener: true, readiness: "ready", pid: 42, metadata: await manager.desiredRuntime() }
      : { running: false, ownsListener: false, readiness: "stopped", metadata: null };
    manager.installRuntime = async () => { installations += 1; await new Promise(resolve => setTimeout(resolve, 25)); };
    manager.registerWindowsLauncher = async () => {};
    manager.startWindowsLauncher = async () => {};
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
  await writeFile(manager.files.backgroundHealth, JSON.stringify({ pid: process.pid, state: "authorized_idle", unavailableBindings: 2 }), "utf8");
  const status = await manager.status();
  assert.equal(status.readiness, "authorized_idle");
  assert.equal(status.listenerReason, "authorized_without_local_routes");
});
