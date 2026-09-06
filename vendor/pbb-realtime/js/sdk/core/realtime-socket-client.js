import { buildRealtimeRequestEnvelope, parseRealtimeEnvelope } from "./realtime-envelope.js";
import { createRealtimeEmitter } from "./realtime-emitter.js";

export function buildRealtimeSocketUrl(websocketUrl, token) {
    const base = String(websocketUrl || "").trim();
    const sessionToken = String(token || "").trim();
    if (!base) {
        throw new Error("Realtime websocket URL is required.");
    }

    return `${base}?token=${encodeURIComponent(sessionToken)}`;
}

export class RealtimeSocketClient {
    constructor(options = {}) {
        this.websocketUrl = String(options.websocketUrl || "").trim();
        this.token = String(options.token || "").trim();
        this.namespace = String(options.namespace || "pbb.realtime.v1").trim();
        this.requestPrefix = String(options.requestPrefix || "req").trim();
        this.onOpen = typeof options.onOpen === "function" ? options.onOpen : null;
        this.onMessage = typeof options.onMessage === "function" ? options.onMessage : null;
        this.onError = typeof options.onError === "function" ? options.onError : null;
        this.onClose = typeof options.onClose === "function" ? options.onClose : null;
        this.events = createRealtimeEmitter();
        this.requestSeq = 0;
        this.socket = null;
        this.pendingRequests = new Map();
        this.connectionState = "idle";
        this.authenticated = false;
        this.activeHealthProbe = null;
    }

    connect() {
        this.close();
        this.authenticated = false;
        this.setConnectionState("connecting");
        this.socket = new WebSocket(buildRealtimeSocketUrl(this.websocketUrl, this.token));
        const socket = this.socket;
        this.socket.onopen = (event) => {
            if (this.socket !== socket) return;
            this.setConnectionState("open");
            this.onOpen?.(event, this);
            this.events.emit("open", event, this);
        };
        this.socket.onmessage = (event) => {
            if (this.socket !== socket) return;
            this.onMessage?.(event.data, event, this);
            this.events.emit("message", event.data, event, this);
            this.handleEnvelopeMessage(event.data);
        };
        this.socket.onerror = (event) => {
            if (this.socket !== socket) return;
            this.setConnectionState("error");
            this.onError?.(event, this);
            this.events.emit("error", event, this);
        };
        this.socket.onclose = (event) => {
            if (this.socket !== socket) return;
            this.socket = null;
            this.authenticated = false;
            this.rejectPendingRequests({
                phase: "error",
                type: "socket.closed",
                payload: {
                    code: "socket.closed",
                    message: "Realtime socket closed before a response was received.",
                },
            });
            this.setConnectionState("closed");
            this.onClose?.(event, this);
            this.events.emit("close", event, this);
        };
        return this.socket;
    }

    close() {
        if (!this.socket) {
            return;
        }

        try {
            this.socket.close();
        } catch {
            // noop
        }
    }

    isOpen() {
        return Boolean(this.socket) && this.socket.readyState === WebSocket.OPEN;
    }

    sendRequest(type, room, payload = {}, meta = {}) {
        if (!this.isOpen()) {
            return null;
        }

        this.requestSeq += 1;
        const requestId = `${this.requestPrefix}_${String(this.requestSeq).padStart(4, "0")}`;
        const envelope = buildRealtimeRequestEnvelope({
            requestId,
            type,
            room,
            payload,
            meta,
        });

        this.socket.send(JSON.stringify(envelope));
        return requestId;
    }

    measureLatency(options = {}) {
        const timeoutMs = Math.max(1, Number(options.timeoutMs ?? 3000) || 3000);
        const allowConcurrent = Boolean(options.allowConcurrent);

        if (!this.isOpen()) {
            return Promise.resolve({
                ok: false,
                reason: "socket-not-open",
                connection_state: this.getConnectionState(),
                measured_at: new Date().toISOString(),
            });
        }

        if (this.activeHealthProbe && !allowConcurrent) {
            return this.activeHealthProbe;
        }

        const startedAt = Date.now();
        const requestId = this.sendRequest("session.health.request", null, {
            client_time: new Date(startedAt).toISOString(),
        });

        if (!requestId) {
            return Promise.resolve({
                ok: false,
                reason: "socket-not-open",
                connection_state: this.getConnectionState(),
                measured_at: new Date().toISOString(),
            });
        }

        const probe = this.waitForResponse(requestId, timeoutMs)
            .then((envelope) => {
                const measuredAt = new Date().toISOString();
                const rttMs = Date.now() - startedAt;

                if (envelope?.phase !== "ack") {
                    return {
                        ok: false,
                        rtt_ms: rttMs,
                        measured_at: measuredAt,
                        code: envelope?.payload?.code || "health.failed",
                        message: envelope?.payload?.message || "Realtime health request failed.",
                    };
                }

                const payload = envelope.payload || {};
                const snapshot = {
                    ok: Boolean(payload.ok ?? true),
                    rtt_ms: rttMs,
                    measured_at: measuredAt,
                    server_time: payload.server_time || null,
                    authenticated: Boolean(payload.authenticated),
                    rooms_joined_count: Number(payload.rooms_joined_count || 0),
                    session_id: payload.session_id || null,
                    connection_id: payload.connection_id || null,
                    heartbeat_interval_seconds: payload.heartbeat_interval_seconds ?? null,
                };

                this.events.emit("latency", snapshot, this);
                this.events.emit("health", snapshot, this);

                return snapshot;
            })
            .catch((error) => {
                const snapshot = {
                    ok: false,
                    timed_out: error?.code === "request.timeout",
                    code: error?.code || "health.failed",
                    message: error?.message || "Realtime health request failed.",
                    measured_at: new Date().toISOString(),
                    connection_state: this.getConnectionState(),
                };

                this.events.emit("health", snapshot, this);

                return snapshot;
            })
            .finally(() => {
                if (this.activeHealthProbe === probe) {
                    this.activeHealthProbe = null;
                }
            });

        if (!allowConcurrent) {
            this.activeHealthProbe = probe;
        }

        return probe;
    }

    getConnectionState() {
        if (this.authenticated && this.isOpen()) {
            return "authenticated";
        }

        if (!this.socket) {
            return this.connectionState || "idle";
        }

        if (this.socket.readyState === WebSocket.CONNECTING) {
            return "connecting";
        }

        if (this.socket.readyState === WebSocket.OPEN) {
            return "open";
        }

        if (this.socket.readyState === WebSocket.CLOSED || this.socket.readyState === WebSocket.CLOSING) {
            return "closed";
        }

        return this.connectionState || "idle";
    }

    sendRaw(data) {
        if (!this.isOpen()) {
            return false;
        }

        this.socket.send(data);
        return true;
    }

    on(eventName, handler) {
        return this.events.on(eventName, handler);
    }

    off(eventName, handler) {
        this.events.off(eventName, handler);
    }

    handleEnvelopeMessage(raw) {
        let envelope = null;
        try {
            envelope = parseRealtimeEnvelope(raw);
        } catch {
            return;
        }

        this.events.emit("envelope", envelope, this);

        if (envelope?.phase === "ack" && envelope?.type === "session.auth.request") {
            this.authenticated = true;
            this.setConnectionState("authenticated");
        }

        if ((envelope?.phase === "ack" || envelope?.phase === "error") && envelope?.id) {
            this.resolvePendingRequest(envelope.id, envelope);
        }
    }

    waitForResponse(requestId, timeoutMs) {
        return new Promise((resolve, reject) => {
            const timeout = globalThis.setTimeout(() => {
                this.pendingRequests.delete(requestId);
                reject({
                    code: "request.timeout",
                    message: "Realtime request timed out.",
                });
            }, timeoutMs);

            this.pendingRequests.set(requestId, {
                resolve,
                reject,
                timeout,
            });
        });
    }

    resolvePendingRequest(requestId, envelope) {
        const pending = this.pendingRequests.get(requestId);
        if (!pending) {
            return;
        }

        globalThis.clearTimeout(pending.timeout);
        this.pendingRequests.delete(requestId);
        pending.resolve(envelope);
    }

    rejectPendingRequests(error) {
        Array.from(this.pendingRequests.entries()).forEach(([requestId, pending]) => {
            globalThis.clearTimeout(pending.timeout);
            this.pendingRequests.delete(requestId);
            pending.reject(error);
        });
    }

    setConnectionState(state) {
        const next = String(state || "idle");
        if (this.connectionState === next) {
            return;
        }

        this.connectionState = next;
        this.events.emit("state", {
            state: next,
            authenticated: this.authenticated,
        }, this);
    }
}
