import assert from "node:assert/strict";
import test from "node:test";
import { mkdtemp } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { ListenerLock } from "../mcp/listener-lock.mjs";

test("only one plugin host owns the device listener", async () => {
  const directory = await mkdtemp(path.join(os.tmpdir(), "syndicatum-listener-lock-"));
  const file = path.join(directory, "listener.lock");
  const first = new ListenerLock(file);
  const second = new ListenerLock(file);
  assert.equal((await first.acquire()).acquired, true);
  assert.equal((await second.acquire()).acquired, false);
  await first.release();
  assert.equal((await second.acquire()).acquired, true);
  await second.release();
});
