import { spawn } from "node:child_process";
import { createHash } from "node:crypto";
import { appendFile, cp, mkdir, readFile, readdir, stat, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ListenerLock } from "./listener-lock.mjs";
import { pluginPaths } from "./paths.mjs";

const RUN_KEY = "HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run";
const RUN_VALUE = "SyndicatumCodexConnector";
const TASK_NAME = "Syndicatum Codex Connector";
const LAUNCH_AGENT_LABEL = "ph.pbb.syndicatum.codex-connector";

export class BackgroundServiceManager {
  constructor({ env = process.env, platform = process.platform, execPath = process.execPath, sourceDirectory = path.dirname(fileURLToPath(import.meta.url)), spawnImpl = spawn } = {}) {
    Object.assign(this, { env, platform, execPath, sourceDirectory, spawnImpl });
    this.files = pluginPaths(env, platform);
    this.serviceEntry = path.join(this.files.backgroundRuntime, "background-service.mjs");
  }

  async ensureRunning() {
    if (!["win32", "darwin"].includes(this.platform)) return { supported: false, platform: this.platform, state: "not_available" };
    const current = await this.status();
    const desired = await this.desiredRuntime();
    if (this.matchesRuntime(current, desired) && isHealthyBackground(current)) return { ...current, supported: true, installed: true };

    const startupLock = new ListenerLock(this.files.startupLock);
    for (let attempt = 0; attempt < 300; attempt += 1) {
      const ownership = await startupLock.acquire();
      if (ownership.acquired) {
        try { return await this.ensureRunningExclusive(); }
        finally { await startupLock.release(); }
      }
      const concurrent = await this.status();
      if (this.matchesRuntime(concurrent, desired) && isHealthyBackground(concurrent)) return { ...concurrent, supported: true, installed: true };
      await delay(100);
    }
    throw new Error(`Another Syndicatum plugin host did not finish background startup. See ${this.files.backgroundStartupLog} for details.`);
  }

  async desiredRuntime() {
    return { execPath: this.execPath, serviceEntry: this.serviceEntry, sourceDirectory: this.sourceDirectory, sourceRevision: await runtimeRevision(this.sourceDirectory) };
  }

  matchesRuntime(current, desired) {
    return current.metadata?.execPath === desired.execPath && current.metadata?.serviceEntry === desired.serviceEntry && current.metadata?.sourceDirectory === desired.sourceDirectory && current.metadata?.sourceRevision === desired.sourceRevision;
  }

  async ensureRunningExclusive() {
    const current = await this.status();
    const desired = await this.desiredRuntime();
    if (this.matchesRuntime(current, desired) && isHealthyBackground(current)) return { ...current, supported: true, installed: true };
    if (current.running) await this.stop(current.pid);
    await this.installRuntime(desired);
    let startupMethod = this.platform === "win32" ? "scheduled_task" : "launch_agent";
    let startupDiagnostic = null;
    if (this.platform === "win32") {
      let running = null;
      try {
        await this.registerWindowsLauncher();
        await this.startWindowsLauncher();
        running = await this.waitUntilRunning({ attempts: 50 });
      } catch (error) {
        startupDiagnostic = sanitizedError(error);
      }
      if (!isHealthyBackground(running)) {
        const task = await this.windowsTaskInfo().catch(error => ({ error: sanitizedError(error) }));
        startupDiagnostic = startupDiagnostic || task.error || `Scheduled Task did not reach ready state (state=${task.state || "unknown"}, result=${task.lastTaskResult ?? "unknown"}).`;
        await this.appendStartupDiagnostic("scheduled_task", startupDiagnostic);
        if (running?.running) await this.stop(running.pid);
        await this.disableWindowsTask().catch(() => {});
        await this.registerWindowsRunFallback();
        await this.startWindowsRunFallback();
        startupMethod = "run_key";
      }
    } else {
      await this.registerMacLaunchAgent();
    }
    const running = await this.waitUntilRunning();
    if (!isHealthyBackground(running)) {
      throw new Error(`Syndicatum background startup failed. See ${this.files.backgroundStartupLog} for details.`);
    }
    const installed = { ...desired, startupMethod, ...(startupDiagnostic ? { startupDiagnostic } : {}) };
    await writeFile(this.files.backgroundMetadata, `${JSON.stringify(installed, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
    return { ...running, supported: true, installed: true, startupMethod, startupDiagnostic, state: readinessState(running) };
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
    const readiness = readinessState({ running, ownsListener, health: currentHealth });
    const listenerReason = ownsListener ? (readiness === "authorized_idle" ? "authorized_without_local_routes" : "listener_owned") : !running ? "background_not_running" : listenerPid ? "listener_owned_by_other_process" : readiness === "startup_error" ? "listener_startup_failed" : "listener_starting";
    return { supported: ["win32", "darwin"].includes(this.platform), platform: this.platform, installed: Boolean(metadata), running, ownsListener, listenerReason, pid, listenerPid, readiness, health: currentHealth, startupMethod: metadata?.startupMethod || null, startupDiagnostic: metadata?.startupDiagnostic || null, startupLog: this.files.backgroundStartupLog, metadata };
  }

  async restart() {
    const current = await this.status();
    if (current.running) await this.stop(current.pid);
    if (this.platform === "win32" && current.startupMethod === "run_key") await this.startWindowsRunFallback();
    else if (this.platform === "win32") await this.startWindowsLauncher();
    else if (this.platform === "darwin") await this.registerMacLaunchAgent();
    else return { supported: false, platform: this.platform, state: "not_available" };
    const running = await this.waitUntilRunning();
    if (!isHealthyBackground(running)) throw new Error(`Syndicatum background restart failed. See ${this.files.backgroundStartupLog} for details.`);
    return { ...running, supported: true, installed: true, state: readinessState(running) };
  }

  async installRuntime(metadata) {
    await mkdir(this.files.root, { recursive: true });
    const rootInfo = await stat(this.files.root);
    if (!rootInfo.isDirectory()) throw new Error(`Syndicatum plugin data path is not a directory: ${this.files.root}`);
    await cp(this.sourceDirectory, this.files.backgroundRuntime, { recursive: true, force: true });
    if (this.platform === "win32") {
      const script = [
      "$ErrorActionPreference = 'Stop'",
      `$startupLog = '${powershellLiteral(this.files.backgroundStartupLog)}'`,
      "$stage = 'launcher_start'",
      "function Write-StartupFailure([string]$message, [int]$exitCode = 1) {",
      "  try {",
      "    $safe = ($message -replace '[\\r\\n]+', ' ').Trim()",
      "    if ($safe.Length -gt 1000) { $safe = $safe.Substring(0, 1000) }",
      "    $record = [ordered]@{ timestamp = [DateTime]::UtcNow.ToString('o'); level = 'ERROR'; stage = $stage; exit_code = $exitCode; message = $safe } | ConvertTo-Json -Compress",
      "    [IO.File]::AppendAllText($startupLog, $record + [Environment]::NewLine)",
      "  } catch {}",
      "}",
      "try {",
      "  $stage = 'credential_read'",
      `  $stored = [IO.File]::ReadAllText('${powershellLiteral(this.files.credential)}').Trim()`,
      "if ($stored.StartsWith('plain:')) {",
      "  $env:SYNDICATUM_AGENT_TOKEN = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($stored.Substring(6)))",
      "} else {",
      "  $stage = 'credential_dpapi'",
      "  Add-Type -AssemblyName System.Security",
      "  $encoded = if ($stored.StartsWith('dpapi:')) { $stored.Substring(6) } else { $stored }",
      "  $protected = [Convert]::FromBase64String($encoded)",
      "  $bytes = [Security.Cryptography.ProtectedData]::Unprotect($protected, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser)",
      "  $env:SYNDICATUM_AGENT_TOKEN = [Text.Encoding]::UTF8.GetString($bytes)",
      "}",
      "  $stage = 'runtime_start'",
      `  & '${powershellLiteral(metadata.execPath)}' '${powershellLiteral(metadata.serviceEntry)}'`,
      "  $runtimeExit = if ($null -eq $LASTEXITCODE) { 0 } else { [int]$LASTEXITCODE }",
      "  if ($runtimeExit -ne 0) { Write-StartupFailure \"Background runtime exited with code $runtimeExit.\" $runtimeExit }",
      "  exit $runtimeExit",
      "} catch {",
      "  $code = if ($_.Exception.HResult) { [int]$_.Exception.HResult } else { 1 }",
      "  Write-StartupFailure (\"{0}: {1}\" -f $_.Exception.GetType().FullName, $_.Exception.Message) $code",
      "  exit 1",
      "}",
      "",
      ].join("\r\n");
      await writeFile(this.files.backgroundLauncher, script, { encoding: "utf8", mode: 0o600 });
    }
    await writeFile(this.files.backgroundMetadata, `${JSON.stringify(metadata, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
  }

  async registerWindowsLauncher() {
    const actionArgs = powershellTaskArguments(this.files.backgroundLauncher);
    const powershellPath = windowsPowerShellPath(this.env);
    const script = [
      `Unregister-ScheduledTask -TaskName '${TASK_NAME}' -Confirm:$false -ErrorAction SilentlyContinue`,
      "$user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name",
      `$action = New-ScheduledTaskAction -Execute '${powershellLiteral(powershellPath)}' -Argument '${powershellLiteral(actionArgs)}'`,
      "$trigger = New-ScheduledTaskTrigger -AtLogOn -User $user",
      "$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited",
      "$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries",
      `Register-ScheduledTask -TaskName '${TASK_NAME}' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null`,
    ].join("; ");
    await run(this.spawnImpl, powershellPath, ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script]);
    await run(this.spawnImpl, "reg.exe", ["DELETE", RUN_KEY, "/v", RUN_VALUE, "/f"], [0, 1]);
  }

  async startWindowsLauncher() {
    await run(this.spawnImpl, windowsPowerShellPath(this.env), ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", `Start-ScheduledTask -TaskName '${TASK_NAME}'`]);
  }

  async windowsTaskInfo() {
    const script = `$task = Get-ScheduledTask -TaskName '${TASK_NAME}' -ErrorAction Stop; $info = Get-ScheduledTaskInfo -TaskName '${TASK_NAME}' -ErrorAction Stop; [pscustomobject]@{ state = [string]$task.State; lastTaskResult = [int64]$info.LastTaskResult } | ConvertTo-Json -Compress`;
    const result = await run(this.spawnImpl, windowsPowerShellPath(this.env), ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script]);
    return JSON.parse(result.stdout.trim() || "{}");
  }

  async disableWindowsTask() {
    await run(this.spawnImpl, windowsPowerShellPath(this.env), ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", `Disable-ScheduledTask -TaskName '${TASK_NAME}' -ErrorAction SilentlyContinue | Out-Null`]);
  }

  async registerWindowsRunFallback() {
    const command = windowsRunCommand(windowsPowerShellPath(this.env), this.files.backgroundLauncher);
    await run(this.spawnImpl, "reg.exe", ["ADD", RUN_KEY, "/v", RUN_VALUE, "/t", "REG_SZ", "/d", command, "/f"]);
  }

  async startWindowsRunFallback() {
    const powershellPath = windowsPowerShellPath(this.env);
    const script = `Start-Process -FilePath '${powershellLiteral(powershellPath)}' -ArgumentList @('-NoLogo','-NoProfile','-NonInteractive','-WindowStyle','Hidden','-ExecutionPolicy','Bypass','-File','${powershellLiteral(this.files.backgroundLauncher)}') -WindowStyle Hidden`;
    await run(this.spawnImpl, powershellPath, ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script]);
  }

  async appendStartupDiagnostic(stage, message, exitCode = null) {
    await mkdir(this.files.root, { recursive: true });
    const record = { timestamp: new Date().toISOString(), level: "ERROR", stage, ...(exitCode === null ? {} : { exit_code: exitCode }), message: sanitizedError(message) };
    await appendFile(this.files.backgroundStartupLog, `${JSON.stringify(record)}\n`, { encoding: "utf8", mode: 0o600 });
  }

  async registerMacLaunchAgent() {
    await mkdir(path.dirname(this.files.launchAgent), { recursive: true });
    const plist = `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>${LAUNCH_AGENT_LABEL}</string>
  <key>ProgramArguments</key><array><string>${xml(this.execPath)}</string><string>${xml(this.serviceEntry)}</string></array>
  <key>WorkingDirectory</key><string>${xml(this.files.root)}</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>ProcessType</key><string>Background</string>
</dict></plist>
`;
    await writeFile(this.files.launchAgent, plist, { encoding: "utf8", mode: 0o600 });
    const domain = `gui/${typeof process.getuid === "function" ? process.getuid() : this.env.UID}`;
    await run(this.spawnImpl, "/bin/launchctl", ["bootout", domain, this.files.launchAgent], [0, 3, 5, 113]);
    await run(this.spawnImpl, "/bin/launchctl", ["bootstrap", domain, this.files.launchAgent]);
    await run(this.spawnImpl, "/bin/launchctl", ["kickstart", "-k", `${domain}/${LAUNCH_AGENT_LABEL}`]);
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
  if (status.health?.state === "authorized_idle") return "authorized_idle";
  if (status.health?.state === "error") return "startup_error";
  return status.ownsListener ? "ready" : "starting_listener";
}
function isHealthyBackground(status) { return Boolean(status?.running && status?.ownsListener && !["startup_error", "stopped"].includes(readinessState(status))); }
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
