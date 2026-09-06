import { spawn } from "node:child_process";
import { cp, mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ListenerLock } from "./listener-lock.mjs";
import { pluginPaths } from "./paths.mjs";

const RUN_KEY = "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run";
const RUN_VALUE = "SyndicatumCodexConnector";
const TASK_NAME = "Syndicatum Codex Connector";

export class BackgroundServiceManager {
  constructor({ env = process.env, platform = process.platform, execPath = process.execPath, sourceDirectory = path.dirname(fileURLToPath(import.meta.url)), spawnImpl = spawn } = {}) {
    Object.assign(this, { env, platform, execPath, sourceDirectory, spawnImpl });
    this.files = pluginPaths(env);
    this.serviceEntry = path.join(this.files.backgroundRuntime, "background-service.mjs");
  }

  async ensureRunning() {
    if (this.platform !== "win32") return { supported: false, platform: this.platform, state: "not_available" };
    const current = await this.status();
    const desired = { execPath: this.execPath, serviceEntry: this.serviceEntry, sourceDirectory: this.sourceDirectory };
    const matches = current.metadata?.execPath === desired.execPath && current.metadata?.serviceEntry === desired.serviceEntry && current.metadata?.sourceDirectory === desired.sourceDirectory;
    if (current.running && matches) return { ...current, supported: true, installed: true };
    if (current.running) await this.stop(current.pid);
    await this.installRuntime(desired);
    await this.registerLauncher();
    await this.startLauncher();
    const running = await this.waitUntilRunning();
    return { supported: true, installed: true, running: running.running, pid: running.pid ?? null, state: running.running ? "running" : "starting" };
  }

  async status() {
    const pid = await new ListenerLock(this.files.backgroundLock).ownerPid();
    const listenerPid = await new ListenerLock(this.files.listenerLock).ownerPid();
    let metadata = null;
    try { metadata = JSON.parse(await readFile(this.files.backgroundMetadata, "utf8")); }
    catch (error) { if (error.code !== "ENOENT") throw error; }
    const running = Boolean(pid && isProcessAlive(pid));
    return { supported: this.platform === "win32", installed: Boolean(metadata), running, ownsListener: Boolean(running && listenerPid === pid), pid, listenerPid, metadata };
  }

  async installRuntime(metadata) {
    await mkdir(this.files.root, { recursive: true });
    await cp(this.sourceDirectory, this.files.backgroundRuntime, { recursive: true, force: true });
    const script = [
      "$ErrorActionPreference = 'Stop'",
      `$stored = [IO.File]::ReadAllText('${powershellLiteral(this.files.credential)}').Trim()`,
      "if ($stored.StartsWith('plain:')) {",
      "  $env:SYNDICATUM_AGENT_TOKEN = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($stored.Substring(6)))",
      "} else {",
      "  Add-Type -AssemblyName System.Security",
      "  $encoded = if ($stored.StartsWith('dpapi:')) { $stored.Substring(6) } else { $stored }",
      "  $protected = [Convert]::FromBase64String($encoded)",
      "  $bytes = [Security.Cryptography.ProtectedData]::Unprotect($protected, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)",
      "  $env:SYNDICATUM_AGENT_TOKEN = [Text.Encoding]::UTF8.GetString($bytes)",
      "}",
      `& '${powershellLiteral(metadata.execPath)}' '${powershellLiteral(metadata.serviceEntry)}'`,
      "exit $LASTEXITCODE",
      "",
    ].join("\r\n");
    await writeFile(this.files.backgroundLauncher, script, { encoding: "utf8", mode: 0o600 });
    await writeFile(this.files.backgroundMetadata, `${JSON.stringify(metadata, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
  }

  async registerLauncher() {
    const actionArgs = powershellTaskArguments(this.files.backgroundLauncher);
    const script = [
      `Unregister-ScheduledTask -TaskName '${TASK_NAME}' -Confirm:$false -ErrorAction SilentlyContinue`,
      "$user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name",
      `$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '${powershellLiteral(actionArgs)}' -WorkingDirectory '${powershellLiteral(this.files.root)}'`,
      "$trigger = New-ScheduledTaskTrigger -AtLogOn -User $user",
      "$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited",
      "$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries",
      `Register-ScheduledTask -TaskName '${TASK_NAME}' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null`,
    ].join("; ");
    await run(this.spawnImpl, "powershell.exe", ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script]);
    await run(this.spawnImpl, "reg.exe", ["DELETE", RUN_KEY, "/v", RUN_VALUE, "/f"], [0, 1]);
  }

  async startLauncher() {
    await run(this.spawnImpl, "powershell.exe", ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", `Start-ScheduledTask -TaskName '${TASK_NAME}'`]);
  }

  async stop(pid = null) {
    const target = pid ?? (await new ListenerLock(this.files.backgroundLock).ownerPid());
    if (!target || !isProcessAlive(target)) return { stopped: false };
    process.kill(target, "SIGTERM");
    for (let attempt = 0; attempt < 30 && isProcessAlive(target); attempt += 1) await delay(100);
    return { stopped: !isProcessAlive(target), pid: target };
  }

  async waitUntilRunning() {
    for (let attempt = 0; attempt < 600; attempt += 1) {
      const status = await this.status();
      if (status.running && status.ownsListener) return status;
      await delay(100);
    }
    return this.status();
  }
}

function powershellLiteral(value) { return String(value).replaceAll("'", "''"); }
export function windowsTaskArgument(value) { const text = String(value); return /[\s"]/u.test(text) ? `"${text.replaceAll('"', '\\"')}"` : text; }
export function powershellTaskArguments(file) { return ["-NoLogo", "-NoProfile", "-NonInteractive", "-WindowStyle", "Hidden", "-ExecutionPolicy", "Bypass", "-File", windowsTaskArgument(file)].join(" "); }
function delay(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
function isProcessAlive(pid) { try { process.kill(pid, 0); return true; } catch (error) { return error.code === "EPERM"; } }

function run(spawnImpl, command, args, allowedExitCodes = [0]) {
  return new Promise((resolve, reject) => {
    const child = spawnImpl(command, args, { windowsHide: true, stdio: ["ignore", "pipe", "pipe"] });
    let stderr = "";
    child.stderr?.setEncoding("utf8");
    child.stderr?.on("data", chunk => { stderr += chunk; });
    child.once("error", reject);
    child.once("close", code => allowedExitCodes.includes(code) ? resolve() : reject(new Error(stderr.trim() || `${command} exited with code ${code}.`)));
  });
}
