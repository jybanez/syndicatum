export function buildAppEventPublishPayload(eventType, data = {}, options = {}) {
    return {
        event_type: String(eventType || "").trim(),
        data: (data && typeof data === "object") ? data : {},
        correlation_id: String(options.correlationId || "").trim() || null,
    };
}
