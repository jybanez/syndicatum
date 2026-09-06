import os from "node:os";
import path from "node:path";

export function pluginDataDirectory(env = process.env, platform = process.platform) {
  if (String(env.SYNDICATUM_PLUGIN_DATA || "").trim()) {
    return path.resolve(env.SYNDICATUM_PLUGIN_DATA);
  }
  const home = env.HOME || env.USERPROFILE || os.homedir();
  if (platform === "win32") {
    return path.join(env.LOCALAPPDATA || path.join(home, "AppData", "Local"), "Syndicatum", "CodexPlugin");
  }
  if (platform === "darwin") {
    return path.join(home, "Library", "Application Support", "Syndicatum", "CodexPlugin");
  }
  return path.join(env.XDG_CONFIG_HOME || path.join(home, ".config"), "syndicatum", "codex-plugin");
}

export function pluginPaths(env = process.env, platform = process.platform) {
  const root = pluginDataDirectory(env, platform);
  const home = env.HOME || env.USERPROFILE || os.homedir();
  return {
    root,
    config: path.join(root, "connector.config.json"),
    credential: path.join(root, "credential"),
    pendingCredential: path.join(root, "pending-login-credential"),
    pendingLogin: path.join(root, "pending-login.json"),
    pairingState: path.join(root, "pairing-state.json"),
    state: path.join(root, "state.json"),
    log: path.join(root, "connector.log"),
    listenerLock: path.join(root, "listener.lock"),
    backgroundLock: path.join(root, "background.lock"),
    backgroundMetadata: path.join(root, "background.json"),
    backgroundHealth: path.join(root, "background-health.json"),
    backgroundRuntime: path.join(root, "runtime"),
    backgroundLauncher: path.join(root, "background-launcher.ps1"),
    launchAgent: path.join(home, "Library", "LaunchAgents", "ph.pbb.syndicatum.codex-connector.plist"),
  };
}
