import { createHash, randomUUID } from "node:crypto";
import { mkdir, readFile, readdir, rename, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { loadToken, storeToken } from "./secret-store.mjs";
import { pluginPaths } from "./paths.mjs";

const PROFILE_PATTERN = /^[a-f0-9]{16}\.[1-9][0-9]*\.[1-9][0-9]*$/;

export function agentProfileId(syndicatumUrl, projectId, agentId) {
  const origin = normalizedOrigin(syndicatumUrl);
  const project = positiveId(projectId, "project");
  const agent = positiveId(agentId, "agent");
  const server = createHash("sha256").update(origin).digest("hex").slice(0, 16);
  return `${server}.${project}.${agent}`;
}

export async function storeAgentProfile(input, env = process.env, { storeTokenImpl = storeToken } = {}) {
  const profileId = agentProfileId(input.syndicatumUrl, input.projectId, input.agentId);
  const directory = profileDirectory(profileId, env);
  const metadata = {
    version: 1,
    profile_id: profileId,
    syndicatum_url: normalizedOrigin(input.syndicatumUrl),
    project_id: Number(input.projectId),
    participant_id: Number(input.participantId),
    agent_id: Number(input.agentId),
    project_name: String(input.projectName || "").trim(),
    identity: String(input.identity || "").trim(),
    token_prefix: String(input.tokenPrefix || "").trim(),
    claimed_at: String(input.claimedAt || new Date().toISOString()),
  };
  await mkdir(directory, { recursive: true });
  await storeTokenImpl(path.join(directory, "credential"), input.token, { profileId });
  await writeJsonAtomic(path.join(directory, "profile.json"), metadata);
  return metadata;
}

export async function loadAgentProfile(profileId, env = process.env, { loadTokenImpl = loadToken } = {}) {
  const directory = profileDirectory(profileId, env);
  const metadata = JSON.parse(await readFile(path.join(directory, "profile.json"), "utf8"));
  if (metadata.profile_id !== profileId || agentProfileId(metadata.syndicatum_url, metadata.project_id, metadata.agent_id) !== profileId) {
    throw new Error("The Syndicatum agent profile metadata is invalid.");
  }
  // The background launcher exposes the device credential through this legacy
  // environment variable. A claimed agent profile must never inherit it:
  // every profile has its own protected credential and identity boundary.
  const profileEnv = { ...env, SYNDICATUM_AGENT_TOKEN: "" };
  const token = await loadTokenImpl(path.join(directory, "credential"), profileEnv, { profileId });
  return Object.freeze({ ...metadata, token });
}

export async function listAgentProfiles(env = process.env) {
  const root = pluginPaths(env).agentProfiles;
  let entries;
  try { entries = await readdir(root, { withFileTypes: true }); }
  catch (error) { if (error.code === "ENOENT") return []; throw error; }
  const profiles = [];
  for (const entry of entries) {
    if (!entry.isDirectory() || !PROFILE_PATTERN.test(entry.name)) continue;
    try {
      const metadata = JSON.parse(await readFile(path.join(root, entry.name, "profile.json"), "utf8"));
      if (metadata.profile_id === entry.name) profiles.push(metadata);
    } catch { /* A damaged profile is unavailable and never exposes another credential. */ }
  }
  return profiles.sort((left, right) => String(left.identity).localeCompare(String(right.identity)));
}

export async function migrateLegacyProjectCredential(projectRoot, env = process.env, dependencies = {}) {
  const legacyFile = path.join(path.resolve(projectRoot), "pbb-chat-token.local.json");
  let legacy;
  try { legacy = JSON.parse(await readFile(legacyFile, "utf8")); }
  catch (error) {
    if (error.code === "ENOENT") return null;
    throw new Error("The legacy Syndicatum project credential could not be read safely.");
  }
  if (!legacy?.token || !legacy?.project_id || !legacy?.agent_id || !legacy?.participant_id || !legacy?.chatviewer_url) {
    throw new Error("The legacy project credential cannot be safely namespaced. Keep it unchanged and ask the operator to reissue that agent credential.");
  }
  const metadata = await storeAgentProfile({
    syndicatumUrl: legacy.chatviewer_url,
    projectId: legacy.project_id,
    participantId: legacy.participant_id,
    agentId: legacy.agent_id,
    projectName: legacy.project_name,
    identity: legacy.identity,
    token: legacy.token,
    tokenPrefix: legacy.token_prefix,
    claimedAt: legacy.claimed_at,
  }, env, dependencies);
  await rm(legacyFile, { force: true });
  return metadata;
}

export function publicAgentProfile(profile) {
  return Object.fromEntries(Object.entries(profile).filter(([key]) => key !== "token"));
}

function profileDirectory(profileId, env) {
  const value = String(profileId || "").trim();
  if (!PROFILE_PATTERN.test(value)) throw new Error("The Syndicatum profile ID is invalid.");
  return path.join(pluginPaths(env).agentProfiles, value);
}

function normalizedOrigin(value) {
  const url = new URL(String(value || "").trim());
  if (url.protocol !== "https:" && url.hostname !== "localhost" && url.hostname !== "127.0.0.1") {
    throw new Error("Syndicatum must use HTTPS except during localhost development.");
  }
  return url.origin.toLowerCase();
}

function positiveId(value, label) {
  const normalized = String(value ?? "").trim();
  if (!/^[1-9][0-9]*$/.test(normalized)) throw new Error(`A valid Syndicatum ${label} ID is required.`);
  return normalized;
}

async function writeJsonAtomic(file, value) {
  const temporary = `${file}.${process.pid}.${randomUUID()}.tmp`;
  await writeFile(temporary, `${JSON.stringify(value, null, 2)}\n`, { encoding: "utf8", mode: 0o600 });
  await rename(temporary, file);
}
