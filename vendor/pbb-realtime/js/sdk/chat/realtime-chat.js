export function formatRealtimeTimestamp(value) {
    if (!value) {
        return "";
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return String(value);
    }

    return parsed.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

export function buildChatPublishPayload(text, attachments = []) {
    return {
        text: String(text || "").trim(),
        attachments: Array.isArray(attachments) ? attachments : [],
    };
}

export function normalizeChatMessageEvent(payload, options = {}) {
    const senderUserId = String(payload?.sender?.user_id || "").trim();
    const currentUserId = String(options.currentUserId || "").trim();
    const isOutgoing = senderUserId !== "" && senderUserId === currentUserId;
    const resolveAttachmentUrl = typeof options.resolveAttachmentUrl === "function"
        ? options.resolveAttachmentUrl
        : (attachment, field) => String(attachment?.[field] || attachment?.url || "");

    return {
        id: String(payload?.message_id || `evt_${Date.now()}`),
        direction: isOutgoing ? "outgoing" : "incoming",
        senderName: String(payload?.sender?.display_name || options.fallbackSenderName || "Realtime user"),
        text: String(payload?.text || ""),
        timestamp: formatRealtimeTimestamp(payload?.sent_at),
        state: isOutgoing ? "delivered" : undefined,
        attachments: Array.isArray(payload?.attachments)
            ? payload.attachments.map((attachment, index) => {
                if (typeof attachment === "string") {
                    return {
                        id: `${String(payload?.message_id || "msg")}_${index}`,
                        kind: "file",
                        name: attachment || "attachment",
                    };
                }

                return {
                    id: `${String(payload?.message_id || "msg")}_${index}`,
                    kind: String(attachment?.kind || "file"),
                    name: String(attachment?.name || "attachment"),
                    url: resolveAttachmentUrl(attachment, "url"),
                    previewUrl: resolveAttachmentUrl(attachment, "preview_url"),
                    posterUrl: resolveAttachmentUrl(attachment, "poster_url"),
                    sizeLabel: String(attachment?.size_label || ""),
                    mimeType: String(attachment?.mime_type || ""),
                    byteSize: Number(attachment?.byte_size) || 0,
                    transfer_id: String(attachment?.transfer_id || ""),
                    attachment_id: String(attachment?.attachment_id || ""),
                };
            })
            : [],
        meta: {
            senderUserId,
        },
    };
}
