import { ListenerLock } from "./listener-lock.mjs";
import { pluginPaths } from "./paths.mjs";
import { PluginRuntime } from "./runtime.mjs";

const files = pluginPaths();
const serviceLock = new ListenerLock(files.backgroundLock);
const ownership = await serviceLock.acquire();
if (!ownership.acquired) process.exit(0);

const runtime = new PluginRuntime(process.env, { manageBackground: false });
let stopping = false;
let retryTimer = null;
const keepAlive = setInterval(() => {}, 60_000);

async function run() {
  const status = await runtime.start();
  if (!stopping && (status.role === "standby" || status.state === "error" || status.state === "unconfigured")) {
    retryTimer = setTimeout(() => void run(), 5000);
  }
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
