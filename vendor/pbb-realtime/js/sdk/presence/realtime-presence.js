export function buildRoomJoinPayload() {
    return {};
}

export function buildPresenceSubscribePayload(room) {
    return {
        room: String(room || "").trim(),
    };
}

export function buildPresencePublishPayload(room, presenceState = "online", statusText = "") {
    return {
        room: String(room || "").trim(),
        state: String(presenceState || "online").trim() || "online",
        status_text: String(statusText || "").trim(),
    };
}

export function derivePresenceRosterKey(payload) {
    const subject = payload?.subject || {};
    return String(subject.session_id || subject.user_id || "").trim();
}

export function reducePresenceRosterEvent(roster, payload) {
    const rosterKey = derivePresenceRosterKey(payload);
    if (!rosterKey) {
        return roster;
    }

    const subject = payload?.subject || {};
    const presenceState = String(payload?.state || "online").trim() || "online";
    const next = {
        ...(roster || {}),
    };

    if (presenceState === "offline") {
        delete next[rosterKey];
        return next;
    }

    next[rosterKey] = {
        key: rosterKey,
        sessionId: String(subject.session_id || "").trim(),
        userId: String(subject.user_id || "").trim(),
        displayName: String(payload?.display_name || payload?.status_text || subject.user_id || "").trim(),
        projectCode: String(subject.project_code || "").trim(),
        appCode: String(subject.app_code || "").trim(),
        state: presenceState,
        statusText: String(payload?.status_text || "").trim(),
        updatedAt: String(payload?.updated_at || "").trim(),
        expiresAt: String(payload?.expires_at || "").trim(),
    };

    return next;
}

export function listPresenceRosterItems(roster) {
    return Object.values(roster || {})
        .filter((entry) => entry && entry.state !== "offline")
        .sort((a, b) => String(a.updatedAt || "").localeCompare(String(b.updatedAt || "")) * -1);
}
