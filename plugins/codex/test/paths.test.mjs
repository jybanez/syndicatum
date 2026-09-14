import assert from "node:assert/strict";
import test from "node:test";
import { mkdtemp, mkdir, readFile, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { legacyWindowsPluginDataDirectory, migrateLegacyWindowsPluginData, pluginDataDirectory } from "../mcp/paths.mjs";

test("Windows defaults to an unvirtualized user-profile data directory", async () => {
  const home = await mkdtemp(path.join(os.tmpdir(), "syndicatum-home-"));
  const env = { USERPROFILE: home, LOCALAPPDATA: path.join(home, "AppData", "Local") };
  assert.equal(pluginDataDirectory(env, "win32"), path.join(home, ".syndicatum", "codex-plugin"));
  assert.equal(legacyWindowsPluginDataDirectory(env), path.join(home, "AppData", "Local", "Syndicatum", "CodexPlugin"));
});

test("Windows migrates an existing virtualized data root once without reading credentials", async () => {
  const home = await mkdtemp(path.join(os.tmpdir(), "syndicatum-migration-"));
  const env = { USERPROFILE: home, LOCALAPPDATA: path.join(home, "AppData", "Local") };
  const source = legacyWindowsPluginDataDirectory(env);
  const target = pluginDataDirectory(env, "win32");
  await mkdir(path.join(source, "agent-identities", "profile-1"), { recursive: true });
  await writeFile(path.join(source, "credential"), "dpapi:opaque\n", "ascii");
  await writeFile(path.join(source, "agent-identities", "profile-1", "metadata.json"), "{}\n", "utf8");

  const first = await migrateLegacyWindowsPluginData(env, "win32");
  assert.equal(first.migrated, true);
  assert.equal(await readFile(path.join(target, "credential"), "ascii"), "dpapi:opaque\n");
  assert.equal(await readFile(path.join(target, "agent-identities", "profile-1", "metadata.json"), "utf8"), "{}\n");

  await writeFile(path.join(source, "credential"), "dpapi:changed\n", "ascii");
  const second = await migrateLegacyWindowsPluginData(env, "win32");
  assert.equal(second.reason, "target_exists");
  assert.equal(await readFile(path.join(target, "credential"), "ascii"), "dpapi:opaque\n");
});

test("an explicit plugin data override is never migrated", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "syndicatum-explicit-"));
  const result = await migrateLegacyWindowsPluginData({ SYNDICATUM_PLUGIN_DATA: root }, "win32");
  assert.deepEqual(result, { migrated: false, reason: "not_applicable" });
});
