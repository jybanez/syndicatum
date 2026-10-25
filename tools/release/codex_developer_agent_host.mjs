// Trusted Codex host orchestration. Never accepts tool callbacks/pins from a plan.
// The caller supplies the existing protected tools object and private IPC delivery.
// No HTTP, credential lookup, server export, global CAS or native launch here.
export function createTrustedAgentHost({tools, deliver, reviewed, clock = () => Date.now(),
    monotonic = () => performance.now(), deadlineMs = 30000}) {
  const profile = '1f397680dd1cddc0.2.33';
  const tool = 'mcp__syndicatum_codex__syndicatum_get_message';
  if (typeof tools?.[tool] !== 'function' || typeof deliver !== 'function' ||
      !(deadlineMs > 0 && deadlineMs <= 120000)) throw new Error('trusted host capability/deadline');
  const pins = structuredClone(reviewed);
  if (!Number.isSafeInteger(pins?.request_id) || !Number.isSafeInteger(pins?.approval_id) ||
      pins.request_id <= 0 || pins.approval_id <= 0 || !/^[a-f0-9]{64}$/.test(pins.context_sha256))
    throw new Error('trusted reviewed context');
  const used = new Set();
  let terminal = false, busy = false, sequence = 0, session = null;
  return {
    async handle(challenge) {
      if (terminal || busy) throw new Error('terminal/busy host; no replay');
      busy = true;
      let timer;
      const started = clock(), began = monotonic();
      try {
        if (!challenge || challenge.tool !== tool || challenge.arguments?.profile_id !== profile ||
            !Number.isSafeInteger(challenge.arguments.message_id) ||
            ![pins.request_id, pins.approval_id].includes(challenge.arguments.message_id) ||
            challenge.request_id !== pins.request_id || challenge.approval_id !== pins.approval_id ||
            challenge.context_sha256 !== pins.context_sha256 ||
            !/^[a-f0-9]{48}$/.test(challenge.invocation_id) || used.has(challenge.invocation_id) || used.size >= 512 ||
            challenge.sequence !== sequence + 1 || typeof challenge.session !== 'string' || !challenge.session ||
            (session !== null && challenge.session !== session)) throw new Error('host challenge substitution/context');
        const invocation = structuredClone(challenge);
        used.add(invocation.invocation_id); sequence = invocation.sequence; session = invocation.session;
        const limit = new Promise((_, reject) => {
          timer = setTimeout(() => reject(new Error('host deadline; outcome retained, no replay')), deadlineMs);
        });
        const checkTime = () => {
          const now = clock(), elapsed = monotonic() - began;
          if (terminal || !Number.isSafeInteger(now) || now < started || now - started > deadlineMs || elapsed < 0 || elapsed > deadlineMs)
            throw new Error('host receipt stale/clock');
          return now;
        };
        const work = (async () => {
          // Direct invocation of the EXISTING protected tool by the trusted agent.
          const response = await tools[tool]({profile_id: profile, message_id: invocation.arguments.message_id});
          checkTime();
          if (response?.isError || !Array.isArray(response?.content) || response.content.length !== 1 ||
              response.content[0]?.type !== 'text') throw new Error('host tool result unavailable/shape');
          const text = response.content[0].text;
          if (typeof text !== 'string' || new TextEncoder().encode(text).length > 32768) throw new Error('host raw result bound');
          const canonical = JSON.parse(text);
          if (canonical.profile?.profile_id !== profile || canonical.profile.project_id !== 2 ||
              canonical.profile.participant_id !== 36 || canonical.profile.agent_id !== 33 ||
              canonical.profile.syndicatum_url !== 'https://syndicatum.wizaya.com' ||
              canonical.message?.id !== invocation.arguments.message_id) throw new Error('host canonical identity');
          // Keep original tool text bytes. Delivery is private pinned local IPC;
          // Python retains/read-backs them before returning any canonical result.
          const envelope = {invocation, raw_base64: Buffer.from(text, 'utf8').toString('base64'),
            started_ms: started, finished_ms: checkTime()};
          checkTime();
          const result = await deliver(envelope);
          checkTime();
          return result;
        })();
        // No late tool result may begin delivery after timeout: checkTime guards
        // the async continuation. An already uncertain delivery is never retried.
        return await Promise.race([work, limit]);
      } catch (error) {
        terminal = true;
        throw error;
      } finally {
        clearTimeout(timer);
        busy = false;
      }
    },
    close() { terminal = true; },
  };
}
