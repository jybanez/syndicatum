export function createRealtimeEmitter() {
    const listeners = new Map();

    return {
        on(eventName, handler) {
            if (typeof handler !== "function") {
                return () => {};
            }

            const key = String(eventName || "").trim();
            if (!listeners.has(key)) {
                listeners.set(key, new Set());
            }
            listeners.get(key).add(handler);
            return () => this.off(key, handler);
        },
        off(eventName, handler) {
            const key = String(eventName || "").trim();
            const bucket = listeners.get(key);
            if (!bucket) {
                return;
            }

            bucket.delete(handler);
            if (bucket.size === 0) {
                listeners.delete(key);
            }
        },
        emit(eventName, ...args) {
            const key = String(eventName || "").trim();
            const bucket = listeners.get(key);
            if (!bucket) {
                return;
            }

            Array.from(bucket).forEach((handler) => {
                try {
                    handler(...args);
                } catch {
                    // noop
                }
            });
        },
        clear() {
            listeners.clear();
        },
    };
}
