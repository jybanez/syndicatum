export const HEALTH_HEARTBEAT_MS = 15_000;
export const HEALTH_MAX_AGE_MS = 45_000;

export function isHealthFresh(health, now = Date.now(), maxAgeMs = HEALTH_MAX_AGE_MS) {
  const updatedAt = Date.parse(String(health?.updatedAt || ""));
  return Number.isFinite(updatedAt) && updatedAt <= now + 5_000 && now - updatedAt <= maxAgeMs;
}

export function startHealthHeartbeat({ snapshot, write, onError = () => {}, intervalMs = HEALTH_HEARTBEAT_MS }) {
  if (typeof snapshot !== "function") throw new TypeError("snapshot must be a function");
  if (typeof write !== "function") throw new TypeError("write must be a function");
  if (typeof onError !== "function") throw new TypeError("onError must be a function");
  if (!Number.isFinite(intervalMs) || intervalMs <= 0) throw new TypeError("intervalMs must be positive");

  let stopped = false;
  let pending = null;
  const tick = () => {
    if (stopped || pending) return pending;
    pending = Promise.resolve()
      .then(() => write(snapshot()))
      .finally(() => { pending = null; });
    return pending;
  };
  const timer = setInterval(() => void tick().catch(onError), intervalMs);
  timer.unref?.();

  return {
    tick,
    async stop() {
      stopped = true;
      clearInterval(timer);
      await pending?.catch(() => {});
    },
  };
}
