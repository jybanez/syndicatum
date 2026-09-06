export function inferAttachmentKind(file) {
    const type = String(file?.type || "");
    if (type.startsWith("image/")) return "image";
    if (type.startsWith("video/")) return "video";
    if (type.startsWith("audio/")) return "audio";
    return "file";
}

export function shouldPreviewAttachmentFile(file) {
    const type = String(file?.type || "");
    return type.startsWith("image/") || type.startsWith("video/");
}

export function formatAttachmentFileSize(size) {
    const value = Number(size);
    if (!Number.isFinite(value) || value <= 0) {
        return "";
    }
    if (value >= 1024 * 1024) {
        return `${(value / (1024 * 1024)).toFixed(1)} MB`;
    }
    if (value >= 1024) {
        return `${Math.round(value / 1024)} KB`;
    }
    return `${value} B`;
}

export function getAttachmentMimeType(kind) {
    switch (String(kind || "")) {
        case "image":
            return "image/jpeg";
        case "video":
            return "video/mp4";
        case "audio":
            return "audio/mpeg";
        default:
            return "application/octet-stream";
    }
}

export function createThreadAttachment(item) {
    return {
        id: item.id,
        kind: item.kind,
        name: item.name,
        previewUrl: item.previewUrl || item.transportUrl || "",
        url: item.transportUrl || item.previewUrl || "",
        sizeLabel: item.sizeLabel || "",
        byteSize: Number(item.byteSize) || 0,
    };
}

export function createTransportAttachment(item) {
    return {
        kind: item.kind,
        name: item.name,
        transfer_id: item.transferId || "",
        attachment_id: item.id,
        mime_type: item.mimeType || getAttachmentMimeType(item.kind),
        url: "",
        preview_url: "",
        poster_url: "",
        size_label: item.sizeLabel || "",
        byte_size: Number(item.byteSize) || 0,
    };
}

export function validateDraftAttachments({ existingItems = [], files = [], policy = {} }) {
    const accepted = [];
    const rejected = [];
    let totalCount = existingItems.length;
    let totalBytes = existingItems.reduce((sum, item) => sum + (Number(item.byteSize) || 0), 0);

    Array.from(files || []).forEach((file) => {
        const size = Number(file?.size) || 0;
        const name = String(file?.name || "attachment");

        if (policy.maxAttachmentBytes > 0 && size > policy.maxAttachmentBytes) {
            rejected.push(`${name} exceeds the per-file limit of ${formatAttachmentFileSize(policy.maxAttachmentBytes)}.`);
            return;
        }

        if (policy.maxAttachmentCount > 0 && totalCount + 1 > policy.maxAttachmentCount) {
            rejected.push(`Attachment limit reached. Maximum allowed is ${policy.maxAttachmentCount} file(s) per message.`);
            return;
        }

        if (policy.maxTotalBytesPerMessage > 0 && totalBytes + size > policy.maxTotalBytesPerMessage) {
            rejected.push(`Adding ${name} exceeds the total attachment limit of ${formatAttachmentFileSize(policy.maxTotalBytesPerMessage)} per message.`);
            return;
        }

        accepted.push(file);
        totalCount += 1;
        totalBytes += size;
    });

    return { accepted, rejected };
}

export function splitDataUrlIntoChunks(transportUrl, fallbackKind, chunkSize = 48 * 1024) {
    const match = String(transportUrl || "").match(/^data:([^;,]+)?;base64,(.+)$/);
    const mimeType = match?.[1] || getAttachmentMimeType(fallbackKind);
    const base64 = match?.[2] || "";
    const chunks = base64 !== ""
        ? base64.match(new RegExp(`.{1,${chunkSize}}`, "g")) || []
        : [];

    return {
        mimeType,
        chunks,
    };
}

export async function transferAttachmentInChunks(item, options = {}) {
    const { mimeType, chunks } = splitDataUrlIntoChunks(String(item?.transportUrl || ""), item?.kind, Number(options.chunkSize) || 48 * 1024);
    const onProgress = typeof options.onProgress === "function" ? options.onProgress : null;
    const onChunk = typeof options.onChunk === "function" ? options.onChunk : null;

    if (onProgress) {
        onProgress(chunks.length > 0 ? 0 : 100, chunks.length > 0 ? "Transferring..." : "Ready");
    }

    if (chunks.length > 0) {
        for (let index = 0; index < chunks.length; index += 1) {
            onChunk?.({
                transfer_id: item.transferId || "",
                attachment_id: item.id,
                name: item.name,
                kind: item.kind,
                mime_type: mimeType,
                size_label: item.sizeLabel || "",
                total_bytes: Number(item.byteSize) || 0,
                chunk_index: index,
                chunk_total: chunks.length,
                chunk_data: chunks[index],
            });

            onProgress?.(Math.round(((index + 1) / chunks.length) * 100), `Chunk ${index + 1} of ${chunks.length}`);
        }
    }

    onProgress?.(100, "Delivered to message payload");
    return {
        ...createTransportAttachment({
            ...item,
            mimeType,
        }),
        mime_type: mimeType,
    };
}

export function reduceAttachmentChunkStore(store, payload) {
    const transferId = String(payload?.transfer_id || "").trim();
    if (!transferId) {
        return store || {};
    }

    const next = {
        ...(store || {}),
    };
    const current = next[transferId] || {
        kind: String(payload?.kind || "file"),
        name: String(payload?.name || "attachment"),
        mimeType: String(payload?.mime_type || ""),
        sizeLabel: String(payload?.size_label || ""),
        chunks: [],
        total: Number(payload?.chunk_total || 0),
        completed: false,
        url: "",
    };

    current.kind = String(payload?.kind || current.kind || "file");
    current.name = String(payload?.name || current.name || "attachment");
    current.mimeType = String(payload?.mime_type || current.mimeType || "");
    current.sizeLabel = String(payload?.size_label || current.sizeLabel || "");
    current.total = Number(payload?.chunk_total || current.total || 0);

    const chunkIndex = Number(payload?.chunk_index || 0);
    current.chunks[chunkIndex] = String(payload?.chunk_data || "");

    if (!current.completed && current.total > 0 && current.chunks.filter(Boolean).length === current.total) {
        current.completed = true;
        current.url = `data:${current.mimeType || getAttachmentMimeType(current.kind)};base64,${current.chunks.join("")}`;
    }

    next[transferId] = current;
    return next;
}

export function resolveAttachmentUrlFromStore(store, attachment, field) {
    const direct = String(attachment?.[field] || attachment?.url || "");
    if (direct) {
        return direct;
    }

    const transferId = String(attachment?.transfer_id || "");
    const received = transferId ? store?.[transferId] : null;
    if (received?.completed && received.url) {
        return received.url;
    }

    return "";
}
