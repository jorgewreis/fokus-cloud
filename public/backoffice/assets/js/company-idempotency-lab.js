import { companyIdempotencyOutcome } from "./company-idempotency-state.js";

// Isolated experiment: the application does not import or activate this module.
// Transport and session verification must be implemented and reviewed separately.
export function createCompanyIdempotencyLab({ storage, transport, sessionBinding, newId, now = Date.now }) {
    const prefix = "fokus.company.reconciliation.";
    let epoch = 0;
    const active = new Set();
    const validBinding = (value) => typeof value === "string" && /^[0-9a-f]{64}$/.test(value);
    const validOperation = (value) => value === "company.create" || value === "company.update";
    const storageKey = (id) => {
        if (typeof id !== "string" || !/^[a-zA-Z0-9_-]{16,80}$/.test(id)) throw new Error("Registro indisponível.");
        return prefix + id;
    };
    const binding = async () => {
        let value;
        try { value = await sessionBinding(); } catch (_) { throw new Error("Sessão não verificada."); }
        if (!validBinding(value)) throw new Error("Sessão não verificada.");
        return value;
    };
    const read = async (id) => {
        let record;
        try { record = JSON.parse(storage.getItem(storageKey(id))); } catch (_) { throw new Error("Registro indisponível."); }
        if (!record || !validOperation(record.operation) || !validBinding(record.binding)
            || typeof record.token !== "string" || !record.token || record.token.length > 256
            || !Number.isSafeInteger(record.issuedAt) || record.issuedAt < 0) throw new Error("Registro indisponível.");
        if (record.binding !== await binding()) throw new Error("Sessão não verificada.");
        return record;
    };
    const run = async (id, action) => {
        const key = storageKey(id);
        if (active.has(key)) throw new Error("Solicitação em andamento.");
        active.add(key);
        const generation = epoch;
        try {
            const record = await read(id);
            if (generation !== epoch) throw new Error("Sessão não verificada.");
            const response = await action(record);
            if (generation !== epoch || record.binding !== await binding()) throw new Error("Sessão não verificada.");
            return response;
        } finally { active.delete(key); }
    };
    return {
        async issue(operation) {
            if (!validOperation(operation)) throw new Error("Operação indisponível.");
            const generation = epoch;
            const currentBinding = await binding();
            if (generation !== epoch) throw new Error("Sessão não verificada.");
            const id = newId();
            const key = storageKey(id);
            if (active.has(key) || storage.getItem(key) !== null) throw new Error("Registro indisponível.");
            active.add(key);
            try {
                let token;
                try { token = await transport.issue(operation); } catch (_) { throw new Error("Emissão indisponível."); }
                if (generation !== epoch || currentBinding !== await binding()) throw new Error("Sessão não verificada.");
                if (typeof token !== "string" || !token || token.length > 256) throw new Error("Emissão indisponível.");
                const issuedAt = now();
                if (!Number.isSafeInteger(issuedAt) || issuedAt < 0 || storage.getItem(key) !== null) throw new Error("Emissão indisponível.");
                try { storage.setItem(key, JSON.stringify({ operation, token, issuedAt, binding: currentBinding })); }
                catch (_) { throw new Error("Registro indisponível."); }
                return id;
            } finally { active.delete(key); }
        },
        async status(id) {
            return run(id, async (record) => {
                try { return companyIdempotencyOutcome(await transport.status(record.operation, record.token)); }
                catch (_) { return "indeterminate"; }
            });
        },
        async submit(id, body) {
            return run(id, async (record) => {
                try {
                    const result = await transport.submit(record.operation, record.token, body);
                    return companyIdempotencyOutcome(result);
                } catch (_) { return "indeterminate"; }
            });
        },
        clear() {
            epoch++;
            for (let index = storage.length - 1; index >= 0; index--) {
                const key = storage.key(index);
                if (key?.startsWith(prefix)) storage.removeItem(key);
            }
        },
    };
}
