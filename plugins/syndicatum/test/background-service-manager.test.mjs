import assert from "node:assert/strict";
import test from "node:test";
import { mkdtemp, readFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { BackgroundServiceManager, powershellTaskArguments, windowsTaskArgument } from "../mcp/background-service-manager.mjs";

test("Windows task arguments preserve plugin paths containing spaces", () => {
  const file = "C:\\Users\\Test User\\.codex\\plugins\\syndicatum\\background-service.mjs";
  assert.equal(windowsTaskArgument(file), `"${file}"`);
  assert.match(powershellTaskArguments(file), /-WindowStyle Hidden/);
  assert.match(powershellTaskArguments(file), /-File "C:\\Users\\Test User/);
});

test("non-Windows platforms remain explicit until their startup adapters exist", async () => {
  const manager = new BackgroundServiceManager({ platform: "darwin", env: {} });
  assert.deepEqual(await manager.ensureRunning(), { supported: false, platform: "darwin", state: "not_available" });
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
  await import("node:fs/promises").then(({ mkdir, writeFile }) => Promise.all([
    mkdir(sourceDirectory, { recursive: true }),
    writeFile(path.join(sourceDirectory, "background-service.mjs"), "", "utf8"),
  ]));
  await manager.installRuntime({ execPath: manager.execPath, serviceEntry: manager.serviceEntry, sourceDirectory });
  const launcher = await readFile(manager.files.backgroundLauncher, "utf8");
  assert.match(launcher, /ProtectedData\]::Unprotect/);
  assert.match(launcher, /SYNDICATUM_AGENT_TOKEN/);
  assert.match(launcher, /background-service\.mjs/);
});
