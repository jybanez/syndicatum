import { mkdir, readFile, rename, writeFile } from "node:fs/promises";
import path from "node:path";
import { randomUUID } from "node:crypto";
import { pluginPaths } from "./paths.mjs";

export async function savePairingState(state, env = process.env) {
  const file = pluginPaths(env).pairingState;
  await mkdir(path.dirname(file), { recursive: true });
  const value = { ...state, revision: randomUUID(), updatedAt: new Date().toISOString() };
  const temporary = `${file}.${process.pid}.${randomUUID()}.tmp`;
  await writeFile(temporary, `${JSON.stringify(value, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
  await rename(temporary, file);
  return value;
}

export async function loadPairingState(env = process.env) {
  try { return JSON.parse(await readFile(pluginPaths(env).pairingState, "utf8")); }
  catch (error) { if (error.code === "ENOENT") return null; throw error; }
}
