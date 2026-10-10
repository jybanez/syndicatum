import { stat } from "node:fs/promises";
import path from "node:path";
import { listAgentProfiles, storeAgentProfile } from "./agent-profile-store.mjs";

export async function claimAgentProfile(input, { fetchImpl = fetch, cwd = process.cwd(), env = process.env, storeTokenImpl } = {}) {
  const syndicatumUrl = normalizeBaseUrl(input.syndicatumUrl);
  const project = String(input.project || "").trim();
  const identity = String(input.identity || "").trim();
  const claimCode = String(input.claimCode || "").trim();
  if (!project || !identity || !claimCode) throw new Error("Project, identity, and claim code are required.");
  const hasProjectId = input.projectId !== undefined && input.projectId !== null && String(input.projectId).trim() !== "";
  const hasAgentId = input.agentId !== undefined && input.agentId !== null && String(input.agentId).trim() !== "";
  if (hasProjectId !== hasAgentId) throw new Error("Project ID and agent ID must be provided together.");
  const projectId = hasProjectId ? positiveId(input.projectId, "project") : null;
  const agentId = hasAgentId ? positiveId(input.agentId, "agent") : null;

  const projectRoot = path.resolve(String(input.projectRoot || cwd));
  const rootStatus = await stat(projectRoot).catch(() => null);
  if (!rootStatus?.isDirectory()) throw new Error("The project root does not exist or is not a directory.");
  const existing = (await listAgentProfiles(env)).find(profile => profile.syndicatum_url === new URL(syndicatumUrl).origin.toLowerCase()
    && (projectId
      ? Number(profile.project_id) === Number(projectId) && Number(profile.agent_id) === Number(agentId)
      : String(profile.project_name || "").toLowerCase() === project.toLowerCase()
        && String(profile.identity || "").toLowerCase() === identity.toLowerCase()));
  if (existing && !input.replaceExisting) {
    throw new Error(`Syndicatum profile ${existing.profile_id} already exists. Set replace_existing only when intentionally rotating this agent's credential.`);
  }

  const response = await fetchImpl(new URL("/api/v1/agent-claim.php", `${syndicatumUrl}/`), {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    body: JSON.stringify(projectId
      ? { project_id: Number(projectId), agent_id: Number(agentId), project, identity, claim_code: claimCode }
      : { project, identity, claim_code: claimCode }),
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    const error = new Error(payload?.message || payload?.code || `Syndicatum claim failed with HTTP ${response.status}.`);
    error.status = response.status;
    throw error;
  }
  const claimed = payload?.data || {};
  if (!String(claimed.token || "").trim()) throw new Error("Syndicatum accepted the claim but did not return an agent token.");
  if (projectId && (Number(claimed.project_id) !== Number(projectId) || Number(claimed.agent_id) !== Number(agentId))) {
    throw new Error("Syndicatum returned a different project or agent than the requested claim. No credential was stored.");
  }

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

function positiveId(value, label) {
  const normalized = String(value ?? "").trim();
  if (!/^[1-9][0-9]*$/.test(normalized)) throw new Error(`A valid Syndicatum ${label} ID is required.`);
  return normalized;
}
