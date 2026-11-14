// Laboratory-only state interpretation. This module is not imported by the UI.
// A status response cannot prove that an uncompleted request will not commit.
export function companyIdempotencyOutcome(response, operation) {
    if (!response || typeof response !== "object") return "indeterminate";
    const result = response.result;
    if (response.state !== "completed" || !result || typeof result.id !== "string" || !result.id.trim()
        || new TextEncoder().encode(result.id).length > 128) return "indeterminate";
    if (operation === "company.create" && result.status === 201) return "completed";
    // The caller's target is not proof of the target originally bound to this
    // token, especially after reload. Updates remain indeterminate until the
    // server exposes an independently verifiable target binding.
    return "indeterminate";
}
