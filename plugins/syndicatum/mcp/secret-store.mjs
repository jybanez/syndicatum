import { spawn } from "node:child_process";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";

const PROTECT = "Add-Type -AssemblyName System.Security; $plain=[Console]::In.ReadToEnd(); $bytes=[Text.Encoding]::UTF8.GetBytes($plain); $value=[Security.Cryptography.ProtectedData]::Protect($bytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser); [Console]::Out.Write([Convert]::ToBase64String($value))";
const UNPROTECT = "Add-Type -AssemblyName System.Security; $encoded=[Console]::In.ReadToEnd().Trim(); $value=[Convert]::FromBase64String($encoded); $bytes=[Security.Cryptography.ProtectedData]::Unprotect($value,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser); [Console]::Out.Write([Text.Encoding]::UTF8.GetString($bytes))";

export async function storeToken(file, token) {
  const value = String(token || "").trim();
  if (!value) throw new Error("A Syndicatum agent token is required.");
  await mkdir(path.dirname(file), { recursive: true });
  if (process.platform === "win32") {
    await writeFile(file, `dpapi:${await powershell(PROTECT, value)}\n`, { encoding: "ascii", mode: 0o600 });
    return;
  }
  await writeFile(file, `plain:${Buffer.from(value, "utf8").toString("base64")}\n`, { encoding: "ascii", mode: 0o600 });
  await import("node:fs/promises").then(({ chmod }) => chmod(file, 0o600));
}

export async function loadToken(file, env = process.env) {
  const override = String(env.SYNDICATUM_AGENT_TOKEN || "").trim();
  if (override) return override;
  let stored;
  try { stored = (await readFile(file, "ascii")).trim(); }
  catch (error) {
    if (error.code === "ENOENT") throw new Error("Syndicatum is not configured for this device.");
    throw error;
  }
  if (stored.startsWith("dpapi:")) {
    if (process.platform !== "win32") throw new Error("This credential belongs to a Windows device.");
    return (await powershell(UNPROTECT, stored.slice(6))).trim();
  }
  if (stored.startsWith("plain:")) return Buffer.from(stored.slice(6), "base64").toString("utf8").trim();
  // Accept credentials written by the retired Windows proof of concept during
  // the one-time migration. New credentials always carry an explicit prefix.
  if (process.platform === "win32") return (await powershell(UNPROTECT, stored)).trim();
  throw new Error("The Syndicatum credential format is not supported on this device.");
}

function powershell(script, input) {
  return new Promise((resolve, reject) => {
    const child = spawn("powershell.exe", ["-NoLogo", "-NoProfile", "-NonInteractive", "-Command", script], {
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
