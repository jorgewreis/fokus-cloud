// Inert experiment. No production code imports this adapter.
// The caller must provide a same-origin transport and a verified CSRF source.
export function createCompanyIdempotencyTransportLab({ fetchResponse, csrf }) {
    async function post(path, body, token, method = "POST") {
        let csrfToken;
        try { csrfToken = await csrf(); } catch (_) { throw new Error("Solicitação indisponível."); }
        if (typeof csrfToken !== "string" || !csrfToken) throw new Error("Solicitação indisponível.");
        const headers = { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken };
        if (token) headers["Idempotency-Key"] = token;
        try {
            return await fetchResponse(`/api/backoffice/${path}`, {
                method, headers, body: JSON.stringify(body), credentials: "same-origin", cache: "no-store", redirect: "error",
            });
        } catch (_) { throw new Error("Solicitação indisponível."); }
    }
    async function json(response) {
        try { return await response.json(); } catch (_) { return null; }
    }
    return {
        async issue(operation) {
            const response = await post("company-idempotency/issue", { operation });
            const token = response.status === 201 ? response.headers.get("Idempotency-Key") : null;
            if (typeof token !== "string" || !token || token.length > 256) throw new Error("Emissão indisponível.");
            return token;
        },
        async status(operation, token) {
            const response = await post("company-idempotency/status", { operation }, token);
            if (response.status !== 200) return null;
            return json(response);
        },
        async submit(operation, token, body) {
            if (operation === "company.create") {
                const response = await post("companies", body, token);
                if (response.status !== 201) return null;
                // A legacy node may ignore the header; even a well-formed 201
                // alone cannot prove the idempotent transaction was used.
                await json(response);
                return null;
            }
            // No PATCH until its original target can be verified independently.
            return null;
        },
    };
}
