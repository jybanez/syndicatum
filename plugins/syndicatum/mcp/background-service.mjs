import { ListenerLock } from "./listener-lock.mjs";
import { pluginPaths } from "./paths.mjs";
import { PluginRuntime } from "./runtime.mjs";
import { appendFile, readFile, rename, writeFile } from "node:fs/promises";

const files = pluginPaths();
const serviceLock = new ListenerLock(files.backgroundLock);
const ownership = await serviceLock.acquire();
if (!ownership.acquired) {
  const healthy = await healthyExistingOwner(ownership.ownerPid);
  await writeStartupDiagnostic(healthy ? "INFO" : "ERROR", "single_instance_lock", healthy
    ? `A healthy background instance already owns the lock (pid=${ownership.ownerPid}).`
    : `The background lock is owned by pid=${ownership.ownerPid || "unknown"}, but no healthy matching instance was found.`);
  process.exit(healthy ? 0 : 75);
}

const runtime = new PluginRuntime(process.env, { manageBackground: false });
let stopping = false;
let retryTimer = null;
const keepAlive = setInterval(() => {}, 60_000);

async function run() {
  let status;
  try { status = await runtime.start(); }
  catch (error) { status = { state: "error", error: String(error?.message || error) }; }
  await writeHealth(status);
  if (!stopping && (status.role === "standby" || ["error", "unconfigured"].includes(status.state))) {
    retryTimer = setTimeout(() => void run(), 5000);
  }
}

async function writeHealth(status) {
  const health = { pid: process.pid, updatedAt: new Date().toISOString(), state: status.state, role: status.role || "background",
    bindings: Number(status.bindings || 0), projects: Number(status.projects || 0), unavailableBindings: Number(status.unavailableBindings || 0),
    ...(status.error ? { error: String(status.error).slice(0, 500) } : {}) };
  const temporary = `${files.backgroundHealth}.${process.pid}.tmp`;
  await writeFile(temporary, `${JSON.stringify(health, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
  await rename(temporary, files.backgroundHealth);
  if (status.state === "error") runtime.logger("background").error(status.error || "Connector startup failed.");
}

async function stop() {
  if (stopping) return;
  stopping = true;
  clearTimeout(retryTimer);
  clearInterval(keepAlive);
  await runtime.stop();
  await serviceLock.release();
  process.exit(0);
}

process.once("SIGINT", () => void stop());
process.once("SIGTERM", () => void stop());
process.once("SIGHUP", () => void stop());
await run();

async function healthyExistingOwner(ownerPid) {
  if (!ownerPid || !isProcessAlive(ownerPid)) return false;
  try {
    const health = JSON.parse(await readFile(files.backgroundHealth, "utf8"));
    return Number(health?.pid) === ownerPid && ["running", "ready", "authorized_idle"].includes(String(health?.state || ""));
  } catch (_error) {
    return false;
  }
}

async function writeStartupDiagnostic(level, stage, message) {
  const record = { timestamp: new Date().toISOString(), level, stage, message: String(message).replace(/[\r\n]+/g, " ").slice(0, 1000) };
  await appendFile(files.backgroundStartupLog, `${JSON.stringify(record)}\n`, { encoding: "utf8", mode: 0o600 }).catch(() => {});
}

function isProcessAlive(pid) {
  try { process.kill(pid, 0); return true; }
  catch (error) { return error.code === "EPERM"; }
}
