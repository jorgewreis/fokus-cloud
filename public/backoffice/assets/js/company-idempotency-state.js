// Laboratory-only state interpretation. This module is not imported by the UI.
// A status response cannot prove that an uncompleted request will not commit.
export function companyIdempotencyOutcome(response) {
    if (!response || typeof response !== "object") return "indeterminate";
    if (response.state === "completed" && response.result && typeof response.result.id === "string"
        && Number.isInteger(response.result.status) && response.result.status >= 200 && response.result.status < 300) {
        return "completed";
    }
    return "indeterminate";
}
