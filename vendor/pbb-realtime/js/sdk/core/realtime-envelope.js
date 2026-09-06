export const REALTIME_NAMESPACE = "pbb.realtime.v1";

export function buildRealtimeRequestEnvelope({ requestId, type, room = null, payload = {}, meta = {} }) {
    return {
        namespace: REALTIME_NAMESPACE,
        phase: "request",
        id: String(requestId || "").trim(),
        type,
        room,
        payload,
        meta,
    };
}

export function parseRealtimeEnvelope(raw) {
    return JSON.parse(String(raw || ""));
}
