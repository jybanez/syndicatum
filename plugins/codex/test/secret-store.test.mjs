import assert from "node:assert/strict";
import test from "node:test";
import { EventEmitter } from "node:events";
import { PassThrough } from "node:stream";
import { mkdtemp, readFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { deleteToken, loadToken, storeToken } from "../mcp/secret-store.mjs";

test("macOS stores only a Keychain reference on disk", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-keychain-"));
  const file = path.join(root, "credential"); const calls = [];
  const spawnImpl = (command, args) => {
    calls.push([command, args]); const child = new EventEmitter(); child.stdout = new PassThrough(); child.stderr = new PassThrough();
    process.nextTick(() => { if (args[0] === "find-generic-password") child.stdout.write("mac-secret\n"); child.stdout.end(); child.stderr.end(); child.emit("close", 0); });
    return child;
  };
  await storeToken(file, "mac-secret", { platform: "darwin", spawnImpl });
  const marker = await readFile(file, "ascii");
  assert.match(marker, /^keychain:/); assert.doesNotMatch(marker, /mac-secret/);
  assert.equal(await loadToken(file, {}, { platform: "darwin", spawnImpl }), "mac-secret");
  assert.equal(calls[0][0], "/usr/bin/security");
  assert.equal(calls[1][1][0], "find-generic-password");
  await deleteToken(file, { platform: "darwin", spawnImpl });
  assert.equal(calls[2][1][0], "delete-generic-password");
});
