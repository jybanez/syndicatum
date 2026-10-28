import { spawn } from "node:child_process";
import { createHash } from "node:crypto";
import { appendFile, cp, mkdir, readFile, readdir, stat, writeFile, rename } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ListenerLock } from "./listener-lock.mjs";
import { pluginPaths } from "./paths.mjs";
import { isHealthFresh } from "./health-heartbeat.mjs";
import { readProcessTable, selectCodexOwner, sameOwner, liveCodexOwners } from "./codex-session.mjs";

const RUN_VALUE = "SyndicatumCodexConnector";
const TASK_NAME = "Syndicatum Codex Connector";
const LAUNCH_AGENT_LABEL = "ph.pbb.syndicatum.codex-connector";

export class BackgroundServiceManager {
  constructor({ env = process.env, platform = process.platform, execPath = process.execPath, sourceDirectory = path.dirname(fileURLToPath(import.meta.url)), spawnImpl = spawn, processReader = () => readProcessTable(platform), hostPid = process.pid } = {}) {
    Object.assign(this, { env, platform, execPath, sourceDirectory, spawnImpl, processReader, hostPid });
    this.files = pluginPaths(env, platform);
    this.serviceEntry = path.join(this.files.backgroundRuntime, "background-service.mjs");
  }

  async ensureRunning() {
    if (!["win32", "darwin"].includes(this.platform)) return { supported: false, platform: this.platform, state: "not_available" };
    const owner = selectCodexOwner(await this.processReader(), this.hostPid);
    if (!owner) throw new Error("Start Syndicatum from an open Codex session; no owning Codex process was found.");

    const startupLock = new ListenerLock(this.files.startupLock);
    for (let attempt = 0; attempt < 300; attempt += 1) {
      const ownership = await startupLock.acquire();
      if (ownership.acquired) {
        try { return await this.ensureRunningExclusive(owner); }
        finally { await startupLock.release(); }
      }
      await delay(100);
    }
    throw new Error(`Another Syndicatum plugin host did not finish background startup. See ${this.files.backgroundStartupLog} for details.`);
  }

  async desiredRuntime() {
    return { execPath: this.execPath, serviceEntry: this.serviceEntry, sourceDirectory: this.sourceDirectory, sourceRevision: await runtimeRevision(this.sourceDirectory), lifecycle: "codex_session", startupMethod: "codex_session" };
  }

  matchesRuntime(current, desired) {
    return current.metadata?.execPath === desired.execPath && current.metadata?.serviceEntry === desired.serviceEntry && current.metadata?.sourceDirectory === desired.sourceDirectory && current.metadata?.sourceRevision === desired.sourceRevision && current.metadata?.lifecycle === "codex_session";
  }

  async ensureRunningExclusive(owner) {
    const current = await this.status();
    const desired = await this.desiredRuntime();
    if (this.matchesRuntime(current, desired) && isHealthyBackground(current)) {
      const owners = liveCodexOwners(current.metadata.codexOwners, await this.processReader());
      const codexOwners = owners.some(candidate => sameOwner(candidate, owner)) ? owners : [...owners, owner];
      if (codexOwners.length > 32) throw new Error("Too many owning Codex sessions; restart Codex before reconnecting.");
      await this.writeMetadata({ ...current.metadata, codexOwners });
      return { ...current, metadata: { ...current.metadata, codexOwners }, supported: true, installed: true };
    }
    if (current.running && current.metadata?.serviceEntry !== this.serviceEntry) throw new Error("Unexpected listener runtime identity; migration stopped.");
    await this.retireLegacyAutostart();
    if (current.running) {
      await this.stop(current.pid);
      if ((await this.status()).running) throw new Error("Previous listener did not stop; runtime was not replaced.");
    }
    const installed = { ...desired, codexOwners: [owner] };
    await this.installRuntime(installed);
    await this.startSessionRuntime();
    const running = await this.waitUntilRunning();
    if (!isHealthyBackground(running)) {
      throw new Error(`Syndicatum background startup failed. See ${this.files.backgroundStartupLog} for details.`);
    }
    return { ...running, supported: true, installed: true, startupMethod: "codex_session", state: readinessState(running) };
  }

  async status() {
    const pid = await new ListenerLock(this.files.backgroundLock).ownerPid();
    const listenerPid = await new ListenerLock(this.files.listenerLock).ownerPid();
    let metadata = null;
    try { metadata = JSON.parse(await readFile(this.files.backgroundMetadata, "utf8")); }
    catch (error) { if (error.code !== "ENOENT") throw error; }
    let health = null;
    try { health = JSON.parse(await readFile(this.files.backgroundHealth, "utf8")); }
    catch (error) { if (error.code !== "ENOENT") throw error; }
    const running = Boolean(pid && isProcessAlive(pid));
    const ownsListener = Boolean(running && listenerPid === pid);
    const currentHealth = running && Number(health?.pid) === pid ? health : null;
    const healthFresh = isHealthFresh(currentHealth);
    const readiness = readinessState({ running, ownsListener, health: currentHealth, healthFresh });
    const listenerReason = readiness === "stale_health" ? "background_health_stale" : readiness === "starting_listener" && ownsListener ? "listener_reloading" : ownsListener ? (readiness === "authorized_idle" ? "authorized_without_local_routes" : readiness === "degraded" ? "listener_routes_degraded" : "listener_owned") : !running ? "background_not_running" : listenerPid ? "listener_owned_by_other_process" : readiness === "startup_error" ? "listener_startup_failed" : "listener_starting";
    return { supported: ["win32", "darwin"].includes(this.platform), platform: this.platform, installed: Boolean(metadata), running, ownsListener, listenerReason, pid, listenerPid, readiness, healthFresh, health: currentHealth, startupMethod: metadata?.startupMethod || null, startupDiagnostic: metadata?.startupDiagnostic || null, startupLog: this.files.backgroundStartupLog, metadata };
  }

  async restart() {
    return this.ensureRunning();
  }

  async writeMetadata(metadata) {
    const temporary = `${this.files.backgroundMetadata}.${process.pid}.tmp`;
    await writeFile(temporary, `${JSON.stringify(metadata, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
    await rename(temporary, this.files.backgroundMetadata);
  }

  async startSessionRuntime() {
    const child = this.spawnImpl(this.execPath, [this.serviceEntry, "--codex-scoped"], {
      detached: true, windowsHide: true, stdio: "ignore", env: this.env,
    });
    await new Promise((resolve, reject) => { child.once("error", reject); child.once("spawn", resolve); });
    child.unref();
  }

  async retireLegacyAutostart() {
    if (this.platform === "win32") {
      const expectedArguments = powershellTaskArguments(this.files.backgroundLauncher);
      const expectedCommand = windowsRunCommand(windowsPowerShellPath(this.env), this.files.backgroundLauncher);
      const script = [
        "$ErrorActionPreference='Stop'",
        `$task=Get-ScheduledTask -TaskName '${TASK_NAME}' -ErrorAction SilentlyContinue`,
        `if ($task) { if (@($task.Actions).Count -ne 1 -or $task.Actions[0].Execute -ine '${powershellLiteral(windowsPowerShellPath(this.env))}' -or $task.Actions[0].Arguments -cne '${powershellLiteral(expectedArguments)}') { throw 'Unexpected legacy task action; migration stopped.' }; $sid=([Security.Principal.NTAccount]::new($task.Principal.UserId)).Translate([Security.Principal.SecurityIdentifier]).Value; if ($sid -ne [Security.Principal.WindowsIdentity]::GetCurrent().User.Value) { throw 'Legacy task belongs to another user.' } }`,
        `$key='HKCU:\\Software\\Microsoft\\Windows\\CurrentVersion\\Run'; $entry=Get-ItemProperty -LiteralPath $key -Name '${RUN_VALUE}' -ErrorAction SilentlyContinue`,
        `if ($entry -and $entry.${RUN_VALUE} -cne '${powershellLiteral(expectedCommand)}') { throw 'Unexpected legacy Run-key command; migration stopped.' }`,
        `if ($task) { Unregister-ScheduledTask -TaskName '${TASK_NAME}' -Confirm:$false -ErrorAction Stop }`,
        `if ($entry) { Remove-ItemProperty -LiteralPath $key -Name '${RUN_VALUE}' -ErrorAction Stop }`,
        // Missing legacy entries are expected after the first session. Windows
        // PowerShell otherwise returns the last suppressed lookup's failure.
        "exit 0",
      ].join("; ");
      await run(this.spawnImpl, windowsPowerShellPath(this.env), ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script]);
    } else if (this.platform === "darwin") {
      let plist;
      try { plist = await readFile(this.files.launchAgent, "utf8"); } catch (error) { if (error.code === "ENOENT") return; throw error; }
      if (!plist.includes(`<string>${LAUNCH_AGENT_LABEL}</string>`) || !plist.includes(`<string>${xml(this.serviceEntry)}</string>`)) throw new Error("Unexpected legacy LaunchAgent; migration stopped.");
      await run(this.spawnImpl, "/bin/launchctl", ["bootout", `gui/${this.env.UID || process.getuid()}/${LAUNCH_AGENT_LABEL}`], [0, 3, 113]);
      await import("node:fs/promises").then(({ rm }) => rm(this.files.launchAgent));
    }
  }

  async installRuntime(metadata) {
    await mkdir(this.files.root, { recursive: true });
    const rootInfo = await stat(this.files.root);
    if (!rootInfo.isDirectory()) throw new Error(`Syndicatum plugin data path is not a directory: ${this.files.root}`);
    await cp(this.sourceDirectory, this.files.backgroundRuntime, { recursive: true, force: true });
    await this.writeMetadata(metadata);
  }

  async appendStartupDiagnostic(stage, message, exitCode = null) {
    await mkdir(this.files.root, { recursive: true });
    const record = { timestamp: new Date().toISOString(), level: "ERROR", stage, ...(exitCode === null ? {} : { exit_code: exitCode }), message: sanitizedError(message) };
    await appendFile(this.files.backgroundStartupLog, `${JSON.stringify(record)}\n`, { encoding: "utf8", mode: 0o600 });
  }

  async stop(pid = null) {
    const target = pid ?? (await new ListenerLock(this.files.backgroundLock).ownerPid());
    if (!target || !isProcessAlive(target)) return { stopped: false };
    process.kill(target, "SIGTERM");
    for (let attempt = 0; attempt < 30 && isProcessAlive(target); attempt += 1) await delay(100);
    return { stopped: !isProcessAlive(target), pid: target };
  }

  async waitUntilRunning({ attempts = 200 } = {}) {
    for (let attempt = 0; attempt < attempts; attempt += 1) {
      const status = await this.status();
      if (status.running && (status.ownsListener || status.readiness === "startup_error")) return status;
      await delay(100);
    }
    return this.status();
  }
}

export async function runtimeRevision(sourceDirectory) {
  const entries = (await readdir(sourceDirectory, { withFileTypes: true }))
    .filter(entry => entry.isFile() && /\.(?:mjs|json)$/i.test(entry.name))
    .map(entry => entry.name)
    .sort();
  const hash = createHash("sha256");
  for (const name of entries) {
    hash.update(name); hash.update("\0"); hash.update(await readFile(path.join(sourceDirectory, name))); hash.update("\0");
  }
  return hash.digest("hex");
}

function powershellLiteral(value) { return String(value).replaceAll("'", "''"); }
export function windowsTaskArgument(value) { const text = String(value); return /[\s"]/u.test(text) ? `"${text.replaceAll('"', '\\"')}"` : text; }
export function powershellTaskArguments(file) { return ["-NoLogo", "-NoProfile", "-NonInteractive", "-WindowStyle", "Hidden", "-ExecutionPolicy", "Bypass", "-File", windowsTaskArgument(file)].join(" "); }
export function windowsPowerShellPath(env = process.env) { return path.win32.join(env.SystemRoot || env.SYSTEMROOT || "C:\\Windows", "System32", "WindowsPowerShell", "v1.0", "powershell.exe"); }
export function windowsRunCommand(powershellPath, launcherFile) { return `${windowsTaskArgument(powershellPath)} ${powershellTaskArguments(launcherFile)}`; }
function delay(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
function isProcessAlive(pid) { try { process.kill(pid, 0); return true; } catch (error) { return error.code === "EPERM"; } }
function readinessState(status) {
  if (!status.running) return "stopped";
  if (status.health && status.healthFresh === false) return "stale_health";
  if (status.health?.state === "authorized_idle") return "authorized_idle";
  if (status.health?.transport && status.health.transport.joinedProjects < status.health.transport.expectedProjects) return "degraded";
  if (status.health?.state === "degraded") return "degraded";
  if (status.health?.state === "error") return "startup_error";
  if (["starting", "configured", "reloading"].includes(String(status.health?.state || ""))) return "starting_listener";
  return status.ownsListener ? "ready" : "starting_listener";
}
function isHealthyBackground(status) { return Boolean(status?.running && status?.ownsListener && ["ready", "degraded", "authorized_idle"].includes(readinessState(status))); }
function sanitizedError(error) { return String(error?.message || error || "Unknown startup failure").replace(/[\r\n]+/g, " ").slice(0, 1000); }
function xml(value) { return String(value).replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&apos;"); }

function run(spawnImpl, command, args, allowedExitCodes = [0]) {
  return new Promise((resolve, reject) => {
    const child = spawnImpl(command, args, { windowsHide: true, stdio: ["ignore", "pipe", "pipe"] });
    let stdout = "", stderr = "";
    child.stdout?.setEncoding("utf8");
    child.stdout?.on("data", chunk => { stdout += chunk; });
    child.stderr?.setEncoding("utf8");
    child.stderr?.on("data", chunk => { stderr += chunk; });
    child.once("error", reject);
    child.once("close", code => allowedExitCodes.includes(code) ? resolve({ code, stdout, stderr }) : reject(new Error(stderr.trim() || `${command} exited with code ${code}.`)));
  });
}
