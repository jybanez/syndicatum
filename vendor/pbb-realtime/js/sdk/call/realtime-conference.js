export function isRealtimeCallActive(callState) {
    return ["incoming", "outgoing", "connecting", "connected"].includes(String(callState || ""));
}

export function createRealtimeConferenceState() {
    return {
        pendingIceCandidatesByUser: {},
        incomingOffers: {},
        peerConnections: {},
        remoteStreams: {},
    };
}

export function parseRealtimeSignalJson(value) {
    if (!value) {
        return null;
    }

    try {
        return JSON.parse(String(value));
    } catch {
        return null;
    }
}

export function normalizeRealtimeSdp(value) {
    const raw = String(value || "");
    if (!raw) {
        return "";
    }

    const normalized = raw.replace(/\r?\n/g, "\r\n");
    return normalized.endsWith("\r\n") ? normalized : `${normalized}\r\n`;
}

export function buildCallSignalPayload(signalType, options = {}) {
    return {
        signal_type: String(signalType || "").trim(),
        target_user_id: String(options.targetUserId || "").trim() || null,
        sdp: options.sdp || null,
        candidate_json: options.candidate ? JSON.stringify(options.candidate) : null,
        meta_json: options.meta ? JSON.stringify(options.meta) : null,
    };
}

export function getMeshConferenceWarning(count, options = {}) {
    const cautionAt = Number(options.cautionAt || 4);
    const limit = Number(options.limit || 5);
    if (count >= limit) {
        return "Mesh conference limit reached. Rooms are capped at 5 participants.";
    }
    if (count >= cautionAt) {
        return "Mesh conference warning: 4+ participants is the caution zone on the current design.";
    }
    return "";
}

export function ensureConferencePeerConnection(peerConnections, remoteUserId, factory) {
    if (peerConnections?.[remoteUserId]) {
        return peerConnections[remoteUserId];
    }

    const connection = typeof factory === "function" ? factory(remoteUserId) : null;
    if (connection && peerConnections) {
        peerConnections[remoteUserId] = connection;
    }
    return connection;
}

export function ensureConferenceRemoteStream(remoteStreams, remoteUserId, factory) {
    if (remoteStreams?.[remoteUserId]) {
        return remoteStreams[remoteUserId];
    }

    const stream = typeof factory === "function" ? factory(remoteUserId) : null;
    if (stream && remoteStreams) {
        remoteStreams[remoteUserId] = stream;
    }
    return stream;
}

export function bindMediaElementStream(mediaEl, stream, options = {}) {
    if (!(mediaEl instanceof HTMLMediaElement)) {
        return;
    }

    if (mediaEl.srcObject !== stream) {
        mediaEl.srcObject = stream;
    }
    mediaEl.autoplay = true;
    mediaEl.playsInline = true;
    mediaEl.muted = Boolean(options.muted);

    if (stream) {
        void mediaEl.play().catch(() => {});
    }
}
