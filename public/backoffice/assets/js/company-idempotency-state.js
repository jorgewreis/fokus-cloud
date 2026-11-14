// Laboratory-only state interpretation. This module is not imported by the UI.
// A status response cannot prove that an uncompleted request will not commit.
export function companyIdempotencyOutcome(response, operation, expectedTarget) {
    if (!response || typeof response !== "object") return "indeterminate";
    const result = response.result;
    if (response.state !== "completed" || !result || typeof result.id !== "string" || !result.id.trim()
        || new TextEncoder().encode(result.id).length > 128) return "indeterminate";
    if (operation === "company.create" && result.status === 201) return "completed";
    if (operation === "company.update" && result.status === 200 && typeof expectedTarget === "string"
        && expectedTarget.trim() && result.id === expectedTarget) return "completed";
    return "indeterminate";
}
