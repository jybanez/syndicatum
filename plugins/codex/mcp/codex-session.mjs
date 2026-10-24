import { readFile } from "node:fs/promises";
import { runCommand } from "./codex-driver.mjs";

export function selectCodexOwner(records, hostPid) {
  const byPid = new Map(records.map(record => [Number(record.pid), record]));
  const seen = new Set();
  let current = byPid.get(Number(hostPid));
  let cli = null;
  while (current && !seen.has(Number(current.pid))) {
    seen.add(Number(current.pid));
    const executable = String(current.executable || "").replaceAll("\\", "/");
    if (/\/OpenAI\.Codex_[^/]+\/app\/(?:ChatGPT|Codex)\.exe$/i.test(executable)
        || /\/(?:Codex|ChatGPT)\.app\/Contents\/MacOS\/(?:Codex|ChatGPT)$/i.test(executable)) return normalizeOwner(current);
    if (!cli && /\/codex(?:\.exe)?$/i.test(executable)) cli = normalizeOwner(current);
    current = byPid.get(Number(current.parentPid));
  }
  return cli;
}

export function normalizeOwner(record) {
  const pid = Number(record?.pid);
  const startedAt = String(record?.startedAt || "");
  const executable = String(record?.executable || "");
  return Number.isSafeInteger(pid) && pid > 0 && startedAt && executable ? { pid, startedAt, executable } : null;
}

export function sameOwner(left, right) {
  return Boolean(left && right && left.pid === right.pid && left.startedAt === right.startedAt && left.executable === right.executable);
}

export function liveCodexOwners(owners, records) {
  return (Array.isArray(owners) ? owners : []).map(normalizeOwner).filter(owner => owner && records.some(record => sameOwner(owner, normalizeOwner(record))));
}

export async function readProcessTable(platform = process.platform, commandRunner = runCommand) {
  if (platform === "win32") {
    const systemRoot = process.env.SystemRoot || process.env.SYSTEMROOT || "C:\\Windows";
    const result = await commandRunner(`${systemRoot}\\System32\\WindowsPowerShell\\v1.0\\powershell.exe`, ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command",
      "$ErrorActionPreference='Stop'; @(Get-CimInstance Win32_Process | ForEach-Object { [pscustomobject]@{pid=[int]$_.ProcessId;parentPid=[int]$_.ParentProcessId;executable=[string]$_.ExecutablePath;startedAt=$(if ($_.CreationDate) {$_.CreationDate.ToUniversalTime().ToString('o')} else {''})} }) | ConvertTo-Json -Compress"], { timeoutMs: 10000 });
    const records = JSON.parse(result.stdout);
    if (!Array.isArray(records)) throw new Error("Unable to read Codex process identities.");
    return records;
  }
  if (platform === "darwin") {
    const result = await commandRunner("/bin/ps", ["-axo", "pid=,ppid=,lstart=,comm="], { timeoutMs: 10000 });
    return result.stdout.split("\n").flatMap(line => {
      const match = /^\s*(\d+)\s+(\d+)\s+(.{24})\s+(.+)$/.exec(line);
      return match ? [{ pid: Number(match[1]), parentPid: Number(match[2]), startedAt: match[3], executable: match[4] }] : [];
    });
  }
  throw new Error("Codex session lifetime detection is unavailable on this platform.");
}

export async function sessionIsOpen(metadataPath, processReader = readProcessTable) {
  const metadata = JSON.parse(await readFile(metadataPath, "utf8"));
  if (metadata.lifecycle !== "codex_session") return false;
  return liveCodexOwners(metadata.codexOwners, await processReader()).length > 0;
}

export function watchCodexSession({ isOpen, onClosed, intervalMs = 2000 }) {
  let stopped = false, pending = null;
  const tick = () => {
    if (stopped || pending) return pending;
    pending = Promise.resolve().then(isOpen).catch(() => false).then(async open => {
      if (!open && !stopped && await onClosed() !== false) { stopped = true; clearInterval(timer); }
    }).finally(() => { pending = null; });
    return pending;
  };
  // Keep a failed cleanup retryable without an unhandled timer rejection.
  const timer = setInterval(() => { void tick()?.catch(() => {}); }, intervalMs);
  timer.unref?.();
  return { tick, stop() { stopped = true; clearInterval(timer); } };
}
