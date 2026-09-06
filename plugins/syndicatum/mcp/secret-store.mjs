import { spawn } from "node:child_process";
import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import os from "node:os";

const PROTECT = "Add-Type -AssemblyName System.Security; $plain=[Console]::In.ReadToEnd(); $bytes=[Text.Encoding]::UTF8.GetBytes($plain); $value=[Security.Cryptography.ProtectedData]::Protect($bytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser); [Console]::Out.Write([Convert]::ToBase64String($value))";
const UNPROTECT = "Add-Type -AssemblyName System.Security; $encoded=[Console]::In.ReadToEnd().Trim(); $value=[Convert]::FromBase64String($encoded); $bytes=[Security.Cryptography.ProtectedData]::Unprotect($value,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser); [Console]::Out.Write([Text.Encoding]::UTF8.GetString($bytes))";

export async function storeToken(file, token, { platform = process.platform, spawnImpl = spawn } = {}) {
  const value = String(token || "").trim();
  if (!value) throw new Error("A Syndicatum agent token is required.");
  await mkdir(path.dirname(file), { recursive: true });
  if (platform === "win32") {
    await writeFile(file, `dpapi:${await powershell(PROTECT, value, spawnImpl)}\n`, { encoding: "ascii", mode: 0o600 });
    return;
  }
  if (platform === "darwin") {
    const service = `ph.pbb.syndicatum.codex.${path.basename(file)}`;
    const account = os.userInfo().username || "device";
    await command(spawnImpl, "/usr/bin/security", ["add-generic-password", "-a", account, "-s", service, "-w", value, "-U"]);
    await writeFile(file, `keychain:${Buffer.from(JSON.stringify({ service, account }), "utf8").toString("base64")}\n`, { encoding: "ascii", mode: 0o600 });
    return;
  }
  await writeFile(file, `plain:${Buffer.from(value, "utf8").toString("base64")}\n`, { encoding: "ascii", mode: 0o600 });
  await import("node:fs/promises").then(({ chmod }) => chmod(file, 0o600));
}

export async function loadToken(file, env = process.env, { platform = process.platform, spawnImpl = spawn } = {}) {
  const override = String(env.SYNDICATUM_AGENT_TOKEN || "").trim();
  if (override) return override;
  let stored;
  try { stored = (await readFile(file, "ascii")).trim(); }
  catch (error) {
    if (error.code === "ENOENT") throw new Error("Syndicatum is not configured for this device.");
    throw error;
  }
  if (stored.startsWith("dpapi:")) {
    if (platform !== "win32") throw new Error("This credential belongs to a Windows device.");
    return (await powershell(UNPROTECT, stored.slice(6), spawnImpl)).trim();
  }
  if (stored.startsWith("keychain:")) {
    if (platform !== "darwin") throw new Error("This credential belongs to a macOS device.");
    const reference = JSON.parse(Buffer.from(stored.slice(9), "base64").toString("utf8"));
    return (await command(spawnImpl, "/usr/bin/security", ["find-generic-password", "-a", reference.account, "-s", reference.service, "-w"])).trim();
  }
  if (stored.startsWith("plain:")) return Buffer.from(stored.slice(6), "base64").toString("utf8").trim();
  // Accept credentials written by the retired Windows proof of concept during
  // the one-time migration. New credentials always carry an explicit prefix.
  if (platform === "win32") return (await powershell(UNPROTECT, stored, spawnImpl)).trim();
  throw new Error("The Syndicatum credential format is not supported on this device.");
}

export async function deleteToken(file, { platform = process.platform, spawnImpl = spawn } = {}) {
  let stored;
  try { stored = (await readFile(file, "ascii")).trim(); }
  catch (error) { if (error.code === "ENOENT") return; throw error; }
  if (platform === "darwin" && stored.startsWith("keychain:")) {
    const reference = JSON.parse(Buffer.from(stored.slice(9), "base64").toString("utf8"));
    await command(spawnImpl, "/usr/bin/security", ["delete-generic-password", "-a", reference.account, "-s", reference.service], [0, 44]);
  }
  await rm(file, { force: true });
}

function powershell(script, input, spawnImpl = spawn) {
  return new Promise((resolve, reject) => {
    const child = spawnImpl("powershell.exe", ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script], {
      windowsHide: true,
      stdio: ["pipe", "pipe", "pipe"],
    });
    let stdout = "", stderr = "";
    child.stdout.setEncoding("utf8"); child.stderr.setEncoding("utf8");
    child.stdout.on("data", chunk => { stdout += chunk; });
    child.stderr.on("data", chunk => { stderr += chunk; });
    child.once("error", reject);
    child.once("close", code => code === 0 ? resolve(stdout) : reject(new Error(stderr.trim() || "Credential protection failed.")));
    child.stdin.end(input);
  });
}

function command(spawnImpl, executable, args, allowedExitCodes = [0]) {
  return new Promise((resolve, reject) => {
    const child = spawnImpl(executable, args, { stdio: ["ignore", "pipe", "pipe"] });
    let stdout = "", stderr = "";
    child.stdout?.setEncoding("utf8"); child.stderr?.setEncoding("utf8");
    child.stdout?.on("data", chunk => { stdout += chunk; });
    child.stderr?.on("data", chunk => { stderr += chunk; });
    child.once("error", reject);
    child.once("close", code => allowedExitCodes.includes(code) ? resolve(stdout) : reject(new Error(stderr.trim() || `${executable} exited with code ${code}.`)));
  });
}
