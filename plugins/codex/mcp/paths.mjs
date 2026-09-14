import os from "node:os";
import path from "node:path";
import { cp, mkdir, rename, rm, stat } from "node:fs/promises";
import { randomUUID } from "node:crypto";

export function pluginDataDirectory(env = process.env, platform = process.platform) {
  if (String(env.SYNDICATUM_PLUGIN_DATA || "").trim()) {
    return path.resolve(env.SYNDICATUM_PLUGIN_DATA);
  }
  const home = platform === "win32" ? windowsUserProfile(env) : env.HOME || env.USERPROFILE || os.homedir();
  if (platform === "win32") {
    // Packaged desktop apps can receive a private, virtualized view of AppData.
    // The background process is launched by Windows outside that package and
    // must use a location both process contexts can see.
    return path.join(home, ".syndicatum", "codex-plugin");
  }
  if (platform === "darwin") {
    return path.join(home, "Library", "Application Support", "Syndicatum", "CodexPlugin");
  }
  return path.join(env.XDG_CONFIG_HOME || path.join(home, ".config"), "syndicatum", "codex-plugin");
}

export function legacyWindowsPluginDataDirectory(env = process.env) {
  const home = windowsUserProfile(env);
  return path.join(env.LOCALAPPDATA || path.join(home, "AppData", "Local"), "Syndicatum", "CodexPlugin");
}

export async function migrateLegacyWindowsPluginData(env = process.env, platform = process.platform) {
  if (platform !== "win32" || String(env.SYNDICATUM_PLUGIN_DATA || "").trim()) return { migrated: false, reason: "not_applicable" };
  const source = legacyWindowsPluginDataDirectory(env);
  const target = pluginDataDirectory(env, platform);
  if (samePath(source, target) || await exists(target)) return { migrated: false, reason: "target_exists", source, target };
  if (!await exists(source)) return { migrated: false, reason: "source_missing", source, target };

  await mkdir(path.dirname(target), { recursive: true });
  const temporary = `${target}.migration-${process.pid}-${randomUUID()}`;
  try {
    await cp(source, temporary, { recursive: true, force: false, errorOnExist: true, preserveTimestamps: true });
    try {
      await rename(temporary, target);
      return { migrated: true, source, target };
    } catch (error) {
      if (!["EEXIST", "ENOTEMPTY", "EPERM"].includes(error.code) || !await exists(target)) throw error;
      return { migrated: false, reason: "concurrent_migration", source, target };
    }
  } finally {
    await rm(temporary, { recursive: true, force: true }).catch(() => {});
  }
}

export function pluginPaths(env = process.env, platform = process.platform) {
  const root = pluginDataDirectory(env, platform);
  const home = env.HOME || env.USERPROFILE || os.homedir();
  return {
    root,
    agentProfiles: path.join(root, "agent-identities"),
    profileAliases: path.join(root, "agent-profile-aliases.json"),
    config: path.join(root, "connector.config.json"),
    credential: path.join(root, "credential"),
    pendingCredential: path.join(root, "pending-login-credential"),
    pendingLogin: path.join(root, "pending-login.json"),
    pairingState: path.join(root, "pairing-state.json"),
    state: path.join(root, "state.json"),
    log: path.join(root, "connector.log"),
    listenerLock: path.join(root, "listener.lock"),
    backgroundLock: path.join(root, "background.lock"),
    startupLock: path.join(root, "startup.lock"),
    backgroundMetadata: path.join(root, "background.json"),
    backgroundHealth: path.join(root, "background-health.json"),
    backgroundStartupLog: path.join(root, "background-startup.log"),
    backgroundRuntime: path.join(root, "runtime"),
    backgroundLauncher: path.join(root, "background-launcher.ps1"),
    launchAgent: path.join(home, "Library", "LaunchAgents", "ph.pbb.syndicatum.codex-connector.plist"),
  };
}

function windowsUserProfile(env) {
  if (env.USERPROFILE || env.HOME) return env.USERPROFILE || env.HOME;
  const local = String(env.LOCALAPPDATA || "");
  if (local && path.win32.basename(local).toLowerCase() === "local" && path.win32.basename(path.win32.dirname(local)).toLowerCase() === "appdata") {
    return path.win32.dirname(path.win32.dirname(local));
  }
  return local || os.homedir();
}

async function exists(file) { try { await stat(file); return true; } catch (error) { if (error.code === "ENOENT") return false; throw error; } }
function samePath(left, right) { return path.resolve(left).toLowerCase() === path.resolve(right).toLowerCase(); }
