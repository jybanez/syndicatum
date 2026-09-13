import { stat } from "node:fs/promises";
import path from "node:path";
import { listAgentProfiles, migrateLegacyProjectCredential, storeAgentProfile } from "./agent-profile-store.mjs";

export async function claimAgentProfile(input, { fetchImpl = fetch, cwd = process.cwd(), env = process.env, storeTokenImpl } = {}) {
  const syndicatumUrl = normalizeBaseUrl(input.syndicatumUrl);
  const project = String(input.project || "").trim();
  const identity = String(input.identity || "").trim();
  const claimCode = String(input.claimCode || "").trim();
  if (!project || !identity || !claimCode) throw new Error("Project, identity, and claim code are required.");

  const projectRoot = path.resolve(String(input.projectRoot || cwd));
  const rootStatus = await stat(projectRoot).catch(() => null);
  if (!rootStatus?.isDirectory()) throw new Error("The project root does not exist or is not a directory.");
  await migrateLegacyProjectCredential(projectRoot, env, { ...(storeTokenImpl ? { storeTokenImpl } : {}) });
  const existing = (await listAgentProfiles(env)).find(profile => profile.syndicatum_url === new URL(syndicatumUrl).origin.toLowerCase()
    && String(profile.project_name || "").toLowerCase() === project.toLowerCase()
    && String(profile.identity || "").toLowerCase() === identity.toLowerCase());
  if (existing && !input.replaceExisting) {
    throw new Error(`Syndicatum profile ${existing.profile_id} already exists. Set replace_existing only when intentionally rotating this agent's credential.`);
  }

  const response = await fetchImpl(new URL("/api/v1/agent-claim.php", `${syndicatumUrl}/`), {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify({ project, identity, claim_code: claimCode }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    const error = new Error(payload?.message || payload?.code || `Syndicatum claim failed with HTTP ${response.status}.`);
    error.status = response.status;
    throw error;
  }
  const claimed = payload?.data || {};
  if (!String(claimed.token || "").trim()) throw new Error("Syndicatum accepted the claim but did not return an agent token.");

  const credential = await storeAgentProfile({
    syndicatumUrl,
    projectId: claimed.project_id,
    participantId: claimed.participant_id,
    agentId: claimed.agent_id,
    projectName: claimed.project_name || project,
    identity: claimed.display_name || identity,
    token: claimed.token,
    tokenPrefix: claimed.token_prefix || String(claimed.token).slice(0, 24),
  }, env, { ...(storeTokenImpl ? { storeTokenImpl } : {}) });
  return {
    state: "claimed",
    profileId: credential.profile_id,
    projectId: String(credential.project_id),
    participantId: String(credential.participant_id),
    agentId: String(credential.agent_id),
    project: credential.project_name,
    identity: credential.identity,
    credentialStore: "user_profile",
  };
}

function normalizeBaseUrl(value) {
  const url = new URL(String(value || "").trim().replace(/\/+$/, ""));
  if (url.protocol !== "https:" && url.hostname !== "localhost" && url.hostname !== "127.0.0.1") {
    throw new Error("Syndicatum must use HTTPS except during localhost development.");
  }
  return url.href.replace(/\/+$/, "");
}
