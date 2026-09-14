import {
  agentProfileExists,
  agentProfileId,
  listAgentProfiles,
  loadAgentProfile,
  normalizeSyndicatumOrigin,
  removeAgentProfile,
  storeAgentProfile,
} from "./agent-profile-store.mjs";
import { loadConfig, saveDeviceConfig } from "./config.mjs";
import { DeviceSyndicatumClient, SyndicatumClient, validateSyndicatumServer } from "./syndicatum-client.mjs";

export async function migrateSyndicatumServer(syndicatumUrl, env = process.env, dependencies = {}) {
  const loadConfigImpl = dependencies.loadConfigImpl ?? loadConfig;
  const saveDeviceConfigImpl = dependencies.saveDeviceConfigImpl ?? saveDeviceConfig;
  const listProfilesImpl = dependencies.listProfilesImpl ?? listAgentProfiles;
  const loadProfileImpl = dependencies.loadProfileImpl ?? loadAgentProfile;
  const storeProfileImpl = dependencies.storeProfileImpl ?? storeAgentProfile;
  const removeProfileImpl = dependencies.removeProfileImpl ?? removeAgentProfile;
  const profileExistsImpl = dependencies.profileExistsImpl ?? agentProfileExists;
  const validateServerImpl = dependencies.validateServerImpl ?? validateSyndicatumServer;
  const deviceClientFactory = dependencies.deviceClientFactory ?? (config => new DeviceSyndicatumClient(config));
  const agentClientFactory = dependencies.agentClientFactory ?? (config => new SyndicatumClient(config));

  const config = await loadConfigImpl(env);
  if (config.mode !== "device") throw new Error("Server migration requires a device-authorized Syndicatum connector.");
  const sourceOrigin = normalizeSyndicatumOrigin(config.syndicatumUrl);
  const targetOrigin = normalizeSyndicatumOrigin(syndicatumUrl);
  if (sourceOrigin === targetOrigin) {
    return { state: "ready", changed: false, syndicatumUrl: targetOrigin, deviceId: config.deviceId, migratedProfiles: [] };
  }

  await validateServerImpl(targetOrigin);
  const targetDeviceConfig = { ...config, syndicatumUrl: targetOrigin };
  await deviceClientFactory(targetDeviceConfig).bindings();

  const sourceMetadata = (await listProfilesImpl(env)).filter(profile => normalizeSyndicatumOrigin(profile.syndicatum_url) === sourceOrigin);
  const migrations = [];
  for (const metadata of sourceMetadata) {
    const profile = await loadProfileImpl(metadata.profile_id, env);
    const targetProfileId = agentProfileId(targetOrigin, profile.project_id, profile.agent_id);
    if (await profileExistsImpl(targetProfileId, env)) {
      throw new Error(`The target Syndicatum profile already exists: ${targetProfileId}. Resolve the duplicate before migrating.`);
    }
    const targetProfile = { ...profile, syndicatum_url: targetOrigin };
    await agentClientFactory({
      syndicatumUrl: targetOrigin,
      projectId: String(profile.project_id),
      participantId: String(profile.participant_id),
      token: profile.token,
    }).validateBinding();
    migrations.push({ source: profile, target: targetProfile, targetProfileId });
  }

  const created = [];
  try {
    for (const migration of migrations) {
      const stored = await storeProfileImpl({
        syndicatumUrl: targetOrigin,
        projectId: migration.source.project_id,
        participantId: migration.source.participant_id,
        agentId: migration.source.agent_id,
        projectName: migration.source.project_name,
        identity: migration.source.identity,
        token: migration.source.token,
        tokenPrefix: migration.source.token_prefix,
        claimedAt: migration.source.claimed_at,
      }, env);
      created.push(stored.profile_id);
    }
    await saveDeviceConfigImpl({
      syndicatumUrl: targetOrigin,
      deviceId: config.deviceId,
      token: config.token,
      codexPath: config.codexPath,
    }, env);
  } catch (error) {
    for (const profileId of created.reverse()) await removeProfileImpl(profileId, env).catch(() => {});
    throw error;
  }

  const cleanupWarnings = [];
  for (const migration of migrations) {
    try { await removeProfileImpl(migration.source.profile_id, env); }
    catch (error) { cleanupWarnings.push({ profileId: migration.source.profile_id, error: String(error?.message || error) }); }
  }

  return {
    state: cleanupWarnings.length ? "ready_with_cleanup_warning" : "ready",
    changed: true,
    sourceSyndicatumUrl: sourceOrigin,
    syndicatumUrl: targetOrigin,
    deviceId: config.deviceId,
    migratedProfiles: migrations.map(item => ({
      previousProfileId: item.source.profile_id,
      profileId: item.targetProfileId,
      projectId: item.source.project_id,
      agentId: item.source.agent_id,
      identity: item.source.identity,
    })),
    ...(cleanupWarnings.length ? { cleanupWarnings } : {}),
  };
}
