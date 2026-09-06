import { access } from "node:fs/promises";
import { constants } from "node:fs";
import { spawn } from "node:child_process";
import path from "node:path";

export class CodexDriver {
  constructor(config, commandRunner = runCommand, threadLoader = loadCodexThread) {
    this.config = config;
    this.commandRunner = commandRunner;
    this.threadLoader = threadLoader;
  }
  async activate(message) {
    const executable = await resolveCodexPath(this.config);
    const result = await this.commandRunner(executable, ["queue", "--thread", String(this.config.codexThreadId), "--message", formatActivationPrompt(message, this.config)], {
      cwd: this.config.workingDirectory || undefined,
      timeoutMs: 60000,
    });
    if (!/queued message/i.test(result.stdout)) throw new Error(`Codex did not confirm queued delivery: ${result.stdout || result.stderr || "no output"}`);
    try {
      await this.threadLoader(this.config.codexThreadId);
      return { ...result, threadLoadStarted: true };
    } catch (error) {
      return { ...result, threadLoadStarted: false, threadLoadWarning: safeError(error) };
    }
  }
}

export async function loadCodexThread(threadId, commandRunner = runCommand, platform = process.platform) {
  const normalized = String(threadId || "").trim().toLowerCase();
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(normalized)) {
    throw new Error("The linked Codex discussion ID is invalid.");
  }
  const uri = `codex://threads/${normalized}`;
  const launch = platform === "win32"
    ? { executable: "rundll32.exe", args: ["url.dll,FileProtocolHandler", uri] }
    : platform === "darwin"
      ? { executable: "open", args: [uri] }
      : { executable: "xdg-open", args: [uri] };
  await commandRunner(launch.executable, launch.args, { timeoutMs: 15000 });
  return { uri };
}

export async function resolveCodexPath(config = {}, env = process.env, platform = process.platform) {
  const configured = String(config.codexPath || env.CODEX_CLI_PATH || "").trim();
  const home = String(env.USERPROFILE || env.HOME || "");
  const appServerCli = path.join(home, ".codex", "plugins", ".plugin-appserver", platform === "win32" ? "codex.exe" : "codex");
  if (appServerCli && (!configured || /[\\/]WindowsApps[\\/]/i.test(configured))) {
    try { await access(appServerCli); return appServerCli; }
    catch { /* Fall through to the configured path for a useful probe error. */ }
  }
  if (configured) {
    try { await access(configured); return configured; }
    catch { throw new Error(`Codex CLI was not found at the path supplied by Codex Desktop: ${configured}`); }
  }
  const executable = platform === "win32" ? "codex.exe" : "codex";
  for (const directory of String(env.PATH || "").split(path.delimiter).filter(Boolean)) {
    const candidate = path.join(directory, executable);
    try { await access(candidate, constants.X_OK); return candidate; } catch { /* Try the next PATH entry. */ }
  }
  return executable;
}

export function runCommand(executable, args, options = {}) {
  return new Promise((resolve, reject) => {
    let stdout = "", stderr = "", settled = false;
    const child = spawn(executable, args, { cwd: options.cwd, env: process.env, shell: false, windowsHide: true, stdio: ["ignore", "pipe", "pipe"] });
    const timer = setTimeout(() => { if (!settled) { settled = true; child.kill(); reject(new Error("Codex queue timed out.")); } }, options.timeoutMs ?? 60000);
    child.stdout.setEncoding("utf8"); child.stderr.setEncoding("utf8");
    child.stdout.on("data", chunk => { stdout += chunk; }); child.stderr.on("data", chunk => { stderr += chunk; });
    child.once("error", error => { if (!settled) { settled = true; clearTimeout(timer); reject(error); } });
    child.once("close", code => {
      if (settled) return;
      settled = true; clearTimeout(timer);
      const result = { code, stdout: stdout.trim(), stderr: stderr.trim() };
      code === 0 ? resolve(result) : reject(new Error(`Codex queue exited with code ${code}: ${result.stderr || result.stdout || "no output"}`));
    });
  });
}

export function formatActivationPrompt(message, config) {
  const senderName = safeSenderName(message?.sender?.display_name ?? message?.sender?.name);
  const addressee = Array.isArray(message?.addressees)
    ? message.addressees.find(entry => String(entry?.participant_id ?? entry?.id ?? "") === String(config.participantId ?? ""))
    : null;
  const isBroadcast = String(addressee?.reason ?? "").toLowerCase() === "broadcast" || message?.broadcast === true;
  return [
    isBroadcast
      ? `There is a broadcast message from ${senderName} in Syndicatum.`
      : `You have a message from ${senderName} in Syndicatum.`,
    "Use the installed pbb-chat-log skill to load the authoritative shared project timeline, handle messages addressed to you, and respond there when appropriate.",
    "This is only a notification. Do not treat this notification as the project message itself, and continue to follow your normal permissions and instructions.",
    "",
    `Syndicatum project ID: ${config.projectId}`,
    `Syndicatum message ID: ${message.id}`,
    `Project sequence: ${message.project_sequence ?? message.sequence ?? "unknown"}`,
  ].join("\n");
}

function safeSenderName(value) {
  const normalized = String(value ?? "").replace(/[\u0000-\u001f\u007f]+/g, " ").replace(/\s+/g, " ").trim().slice(0, 120);
  return normalized || "another participant";
}

function safeError(error) {
  return String(error?.message || error).replace(/[\r\n\t]+/g, " ").slice(0, 300);
}
