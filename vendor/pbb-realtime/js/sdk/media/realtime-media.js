function normalizeOptionalString(value) {
    const normalized = String(value || "").trim();

    return normalized || null;
}

function normalizeOptionalInteger(value) {
    if (value === null || value === undefined || value === "") {
        return null;
    }

    const normalized = Number(value);

    if (!Number.isFinite(normalized)) {
        return null;
    }

    return Math.max(0, Math.trunc(normalized));
}

export function buildMediaChunkPublishPayload(input = {}) {
    const payload = (input && typeof input === "object") ? input : {};

    return normalizeMediaChunkPayload(payload, true);
}

export function buildMediaChunkPreparePayload(input = {}) {
    const payload = (input && typeof input === "object") ? input : {};

    return normalizeMediaChunkPayload(payload, false);
}

function normalizeMediaChunkPayload(payload, includeChunkData) {
    const normalized = {
        transfer_id: normalizeOptionalString(payload.transfer_id),
        incident_id: normalizeOptionalString(payload.incident_id),
        call_session_id: normalizeOptionalString(payload.call_session_id),
        media_id: normalizeOptionalString(payload.media_id),
        segment_key: normalizeOptionalString(payload.segment_key),
        type: String(payload.type || "").trim(),
        peer_user_id: normalizeOptionalString(payload.peer_user_id),
        peer_role: normalizeOptionalString(payload.peer_role),
        track_kind: String(payload.track_kind || "").trim(),
        mime_type: String(payload.mime_type || "").trim(),
        extension: normalizeOptionalString(payload.extension),
        chunk_index: normalizeOptionalInteger(payload.chunk_index) ?? 0,
        chunk_total: normalizeOptionalInteger(payload.chunk_total),
        total_bytes: normalizeOptionalInteger(payload.total_bytes),
        correlation_id: normalizeOptionalString(payload.correlation_id),
    };

    if (includeChunkData) {
        normalized.chunk_data = String(payload.chunk_data || "").trim();
    }

    return normalized;
}

export async function buildBinaryMediaChunkFrame(transferId, chunk) {
    const normalizedTransferId = normalizeOptionalString(transferId);
    if (!normalizedTransferId) {
        throw new Error("transferId is required.");
    }

    const bytes = await normalizeBinaryChunk(chunk);
    const headerBytes = new TextEncoder().encode(JSON.stringify({
        transfer_id: normalizedTransferId,
    }));

    if (headerBytes.byteLength < 2 || headerBytes.byteLength > 4096) {
        throw new Error("Binary media frame header is too large.");
    }

    const frame = new Uint8Array(9 + headerBytes.byteLength + bytes.byteLength);
    frame[0] = 0x50;
    frame[1] = 0x42;
    frame[2] = 0x42;
    frame[3] = 0x4d;
    frame[4] = 1;
    new DataView(frame.buffer).setUint32(5, headerBytes.byteLength, false);
    frame.set(headerBytes, 9);
    frame.set(bytes, 9 + headerBytes.byteLength);

    return frame.buffer;
}

async function normalizeBinaryChunk(chunk) {
    if (chunk instanceof Uint8Array) {
        return chunk;
    }

    if (chunk instanceof ArrayBuffer) {
        return new Uint8Array(chunk);
    }

    if (typeof Blob !== "undefined" && chunk instanceof Blob) {
        return new Uint8Array(await chunk.arrayBuffer());
    }

    if (ArrayBuffer.isView(chunk)) {
        return new Uint8Array(chunk.buffer, chunk.byteOffset, chunk.byteLength);
    }

    throw new Error("Binary media chunk must be a Blob, ArrayBuffer, or typed array.");
}
