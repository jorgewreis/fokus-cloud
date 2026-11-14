import { test } from "node:test";
import assert from "node:assert/strict";
import { companyIdempotencyOutcome } from "../../public/backoffice/assets/js/company-idempotency-state.js";

test("only a valid completed result is conclusive", () => {
    assert.equal(companyIdempotencyOutcome({ state: "completed", revoked: false, result: { id: "synthetic", status: 201 } }), "completed");
    assert.equal(companyIdempotencyOutcome({ state: "completed", revoked: true, result: { id: "synthetic", status: 200 } }), "completed");
    for (const state of ["issued", "observed", "absent", "unknown"]) {
        for (const revoked of [true, false, null]) {
            assert.equal(companyIdempotencyOutcome({ state, revoked }), "indeterminate");
        }
    }
    for (const response of [null, {}, { state: "completed" }, { state: "completed", result: { id: "synthetic", status: 503 } }]) {
        assert.equal(companyIdempotencyOutcome(response), "indeterminate");
    }
});
