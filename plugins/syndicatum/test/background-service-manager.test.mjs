import assert from "node:assert/strict";
import test from "node:test";
import { mkdtemp, readFile, mkdir, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { EventEmitter } from "node:events";
import { PassThrough } from "node:stream";
import { BackgroundServiceManager, powershellTaskArguments, windowsTaskArgument } from "../mcp/background-service-manager.mjs";

test("Windows task arguments preserve plugin paths containing spaces", () => {
  const file = "C:\\Users\\Test User\\.codex\\plugins\\syndicatum\\background-service.mjs";
  assert.equal(windowsTaskArgument(file), `"${file}"`);
  assert.match(powershellTaskArguments(file), /-WindowStyle Hidden/);
  assert.match(powershellTaskArguments(file), /-File "C:\\Users\\Test User/);
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
});

test("background status distinguishes an authorized device with no local routes", async () => {
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
